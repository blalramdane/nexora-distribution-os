<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class LocationController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(
            DB::table('locations')
                ->where('organization_id', $request->user()->organization_id)
                ->where('status', 'active')
                ->orderBy('name')
                ->get()
        );
    }

    public function store(Request $request)
    {
        $organizationId = (string) $request->user()->organization_id;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:180', 'regex:/\\S/'],
            'code' => ['nullable', 'string', 'max:64', Rule::unique('locations', 'code')->where(fn ($query) => $query->where('organization_id', $organizationId))],
            'address' => ['nullable', 'string', 'max:2000'],
            // Creating a vehicle needs its own vehicle record and assignment workflow.
            // This form intentionally creates a real warehouse location only.
            'type' => ['sometimes', Rule::in(['warehouse'])],
        ]);

        $code = trim((string) ($data['code'] ?? ''));
        if ($code === '') {
            do {
                $code = 'WH-'.Str::upper(Str::random(8));
            } while (DB::table('locations')->where('organization_id', $organizationId)->where('code', $code)->exists());
        }

        $location = DB::transaction(function () use ($organizationId, $data, $code): object {
            $locationId = (string) Str::ulid();
            $now = now();
            DB::table('locations')->insert([
                'id' => $locationId,
                'organization_id' => $organizationId,
                'code' => $code,
                'name' => trim($data['name']),
                'type' => 'warehouse',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('warehouses')->insert([
                'id' => $locationId,
                'organization_id' => $organizationId,
                'location_id' => $locationId,
                'code' => $code,
                'name' => trim($data['name']),
                'address' => $data['address'] ?? null,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return DB::table('locations')->where('organization_id', $organizationId)->where('id', $locationId)->first();
        });

        return response()->json($location, 201);
    }
}
