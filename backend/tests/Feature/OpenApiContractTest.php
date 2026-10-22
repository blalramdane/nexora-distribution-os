<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class OpenApiContractTest extends TestCase
{
    public function test_openapi_contract_covers_exactly_the_registered_api_v1_operations(): void
    {
        $path = base_path('../docs/api/openapi.yaml');
        self::assertFileExists($path, 'The OpenAPI contract is required.');
        $document = file_get_contents($path);
        self::assertIsString($document);
        self::assertStringContainsString('openapi: 3.1.0', $document);
        self::assertStringContainsString('bearerAuth:', $document);

        preg_match_all(
            '/^  (\/[^:\r\n]+):[ \t]*\r?\n(.*?)(?=^  \/|^components:|\z)/ms',
            $document,
            $matches,
            PREG_SET_ORDER
        );

        $documented = [];
        foreach ($matches as $match) {
            $pathKey = $match[1];
            preg_match_all('/^    (get|post|put|patch|delete|options):[ \t]*$/m', $match[2], $methods);
            foreach ($methods[1] as $method) {
                $documented[$method.' '.$pathKey] = true;
            }
        }

        $registered = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/v1/')) {
                continue;
            }

            $pathKey = '/'.substr($uri, strlen('api/v1/'));
            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'TRACE'], true)) {
                    continue;
                }
                $registered[strtolower($method).' '.$pathKey] = true;
            }
        }

        $missing = array_values(array_diff(array_keys($registered), array_keys($documented)));
        $stale = array_values(array_diff(array_keys($documented), array_keys($registered)));
        sort($missing);
        sort($stale);

        self::assertSame([], $missing, 'Registered API operations missing from docs/api/openapi.yaml.');
        self::assertSame([], $stale, 'OpenAPI operations not present in the current Laravel route table.');
        self::assertCount(113, $documented, 'Update this expectation only after checking the live route table and updating the contract.');
    }
}
