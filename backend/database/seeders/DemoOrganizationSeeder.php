<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoOrganizationSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $org = DB::table('organizations')->where('name', 'NEXORA Demo Distribution')->first();

        if (!$org) {
            $orgId = (string) Str::ulid();
            DB::table('organizations')->insert([
                'id' => $orgId,
                'name' => 'NEXORA Demo Distribution',
                'legal_name' => 'NEXORA Demo Distribution',
                'default_currency' => 'EGP',
                'timezone' => 'Africa/Cairo',
                'country_code' => 'EG',
                'status' => 'active',
                'settings_json' => json_encode(['demo' => true], JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $org = DB::table('organizations')->where('id', $orgId)->first();
        }
        $orgId = $org->id;

        $user = DB::table('users')
            ->where('organization_id', $orgId)
            ->where('email', 'admin@nexora.local')
            ->first();

        if (!$user) {
            $userId = (string) Str::ulid();
            DB::table('users')->insert([
                'id' => $userId,
                'organization_id' => $orgId,
                'name' => 'NEXORA Admin',
                'email' => 'admin@nexora.local',
                'phone' => '01000000000',
                'password' => Hash::make('Nexora@12345'),
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $user = DB::table('users')->where('id', $userId)->first();
        }

        $role = DB::table('roles')->where('organization_id', $orgId)->where('key', 'admin')->first();
        if (!$role) {
            $roleId = (string) Str::ulid();
            DB::table('roles')->insert([
                'id' => $roleId,
                'organization_id' => $orgId,
                'name' => 'مدير النظام',
                'key' => 'admin',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $role = DB::table('roles')->where('id', $roleId)->first();
        }

        DB::table('user_roles')->insertOrIgnore([
            'organization_id' => $orgId,
            'user_id' => $user->id,
            'role_id' => $role->id,
        ]);
        foreach (DB::table('permissions')->pluck('id') as $permissionId) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $role->id, 'permission_id' => $permissionId]);
        }

        $piece = DB::table('units')->whereNull('organization_id')->where('code', 'piece')->firstOrFail();
        $category = DB::table('categories')->where('organization_id', $orgId)->where('code', 'electrical-tools')->first();
        if (!$category) {
            $categoryId = (string) Str::ulid();
            DB::table('categories')->insert([
                'id' => $categoryId,
                'organization_id' => $orgId,
                'code' => 'electrical-tools',
                'name_ar' => 'أدوات كهربائية',
                'name_en' => 'Electrical Tools',
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $category = DB::table('categories')->where('id', $categoryId)->first();
        }

        $location = $this->location($orgId, 'MAIN-WH', 'المخزن الرئيسي', 'warehouse', $now);
        if (!DB::table('warehouses')->where('organization_id', $orgId)->where('code', 'MAIN-WH')->exists()) {
            DB::table('warehouses')->insert([
                'id' => (string) Str::ulid(),
                'organization_id' => $orgId,
                'location_id' => $location->id,
                'code' => 'MAIN-WH',
                'name' => 'المخزن الرئيسي',
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $vehicleLocation = $this->location($orgId, 'VAN-01', 'سيارة التوزيع 01', 'vehicle', $now);
        if (!DB::table('vehicles')->where('organization_id', $orgId)->where('code', 'VAN-01')->exists()) {
            DB::table('vehicles')->insert([
                'id' => (string) Str::ulid(),
                'organization_id' => $orgId,
                'location_id' => $vehicleLocation->id,
                'code' => 'VAN-01',
                'plate_number' => 'د م ب 1234',
                'name' => 'سيارة التوزيع 01',
                'vehicle_type' => 'van',
                'assigned_user_id' => $user->id,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->party('suppliers', $orgId, 'SUP-DEMO', [
            'name' => 'مورد تجريبي',
            'normalized_name' => 'مورد تجريبي',
            'phone' => '01111111111',
            'address' => 'القاهرة',
            'tax_identifier' => null,
            'credit_terms_days' => 30,
            'active' => true,
        ]);
        $this->party('customers', $orgId, 'CUS-DEMO', [
            'name' => 'تاجر تجريبي',
            'normalized_name' => 'تاجر تجريبي',
            'phone' => '01222222222',
            'credit_limit' => 50000,
            'payment_terms_days' => 30,
            'status' => 'active',
        ]);

        $seedProducts = [
            ['SKU-DRILL-01', 'شنيور كهرباء 650 وات', 'Electric Drill 650W', 850, 1250],
            ['SKU-GRIND-01', 'صاروخ تجليخ 750 وات', 'Angle Grinder 750W', 700, 1050],
            ['SKU-CABLE-01', 'سلك كهرباء 2.5 مم', 'Electrical Cable 2.5mm', 18, 28],
        ];

        foreach ($seedProducts as [$sku, $nameAr, $nameEn, $cost, $price]) {
            $product = DB::table('products')->where('organization_id', $orgId)->where('sku', $sku)->first();
            if (!$product) {
                $id = (string) Str::ulid();
                DB::table('products')->insert([
                    'id' => $id,
                    'organization_id' => $orgId,
                    'category_id' => $category->id,
                    'base_unit_id' => $piece->id,
                    'sku' => $sku,
                    'name_ar' => $nameAr,
                    'name_en' => $nameEn,
                    'brand' => 'NEXORA Demo',
                    'default_cost' => $cost,
                    'default_piece_price' => $price,
                    'active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $product = DB::table('products')->where('id', $id)->first();
            }

            $openingReference = 'DEMO-OPENING-' . $sku;
            $openingMovement = DB::table('stock_movements')
                ->where('organization_id', $orgId)
                ->where('product_id', $product->id)
                ->where('location_id', $location->id)
                ->where('reference', $openingReference)
                ->first();

            if (!$openingMovement) {
                DB::table('stock_movements')->insert([
                    'id' => (string) Str::ulid(),
                    'organization_id' => $orgId,
                    'transaction_uuid' => (string) Str::uuid(),
                    'product_id' => $product->id,
                    'location_id' => $location->id,
                    'movement_type' => 'opening_balance',
                    'quantity_base' => 100,
                    'unit_cost' => $cost,
                    'source_document_type' => 'opening_balance',
                    'occurred_at' => $now,
                    'posted_at' => $now,
                    'created_by' => $user->id,
                    'reference' => $openingReference,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            if (!DB::table('stock_balances')->where('organization_id', $orgId)->where('product_id', $product->id)->where('location_id', $location->id)->exists()) {
                $openingQuantity = DB::table('stock_movements')
                    ->where('organization_id', $orgId)
                    ->where('product_id', $product->id)
                    ->where('location_id', $location->id)
                    ->sum('quantity_base');

                DB::table('stock_balances')->insert([
                    'id' => (string) Str::ulid(),
                    'organization_id' => $orgId,
                    'product_id' => $product->id,
                    'location_id' => $location->id,
                    'quantity_base' => $openingQuantity,
                    'reserved_quantity_base' => 0,
                    'average_cost' => $cost,
                    'updated_at' => $now,
                ]);
            }
        }

        if (!DB::table('financial_accounts')->where('organization_id', $orgId)->where('code', 'CASH')->exists()) {
            DB::table('financial_accounts')->insert([
                'id' => (string) Str::ulid(),
                'organization_id' => $orgId,
                'code' => 'CASH',
                'name' => 'الخزينة الرئيسية',
                'type' => 'cash',
                'currency' => 'EGP',
                'opening_balance' => 0,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (['sales_invoice' => 'SI', 'purchase_invoice' => 'PI', 'trip_load' => 'LD'] as $type => $prefix) {
            if (!DB::table('document_sequences')->where('organization_id', $orgId)->where('document_type', $type)->exists()) {
                DB::table('document_sequences')->insert([
                    'id' => (string) Str::ulid(),
                    'organization_id' => $orgId,
                    'document_type' => $type,
                    'prefix' => $prefix,
                    'next_number' => 1,
                    'padding' => 6,
                    'reset_policy' => 'never',
                    'active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function location(string $orgId, string $code, string $name, string $type, $now): object
    {
        $row = DB::table('locations')->where('organization_id', $orgId)->where('code', $code)->first();
        if (!$row) {
            $id = (string) Str::ulid();
            DB::table('locations')->insert([
                'id' => $id,
                'organization_id' => $orgId,
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $row = DB::table('locations')->where('id', $id)->first();
        }

        return $row;
    }

    private function party(string $table, string $orgId, string $code, array $data): object
    {
        $row = DB::table($table)->where('organization_id', $orgId)->where('code', $code)->first();
        if (!$row) {
            $id = (string) Str::ulid();
            DB::table($table)->insert([
                'id' => $id,
                'organization_id' => $orgId,
                'code' => $code,
                ...$data,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $row = DB::table($table)->where('id', $id)->first();
        }

        return $row;
    }
}
