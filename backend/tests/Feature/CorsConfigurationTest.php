<?php

namespace Tests\Feature;

use Tests\TestCase;

class CorsConfigurationTest extends TestCase
{
    public function test_local_frontend_preflight_is_allowed_for_api_login(): void
    {
        $response = $this->call('OPTIONS', '/api/v1/auth/login', [], [], [], [
            'HTTP_ORIGIN' => 'http://127.0.0.1:3017',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'accept,content-type',
        ]);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('http://127.0.0.1:3017', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertStringContainsString('POST', (string) $response->headers->get('Access-Control-Allow-Methods'));
    }
}
