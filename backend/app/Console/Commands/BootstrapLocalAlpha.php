<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BootstrapLocalAlpha extends Command
{
    protected $signature = 'nexora:local-alpha';

    protected $description = 'Create a local-only NEXORA Distribution Alpha organization and starter master data';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('This command is restricted to APP_ENV=local. No data was changed.');

            return self::FAILURE;
        }

        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->error('This local bootstrap only supports the isolated SQLite database. No data was changed.');

            return self::FAILURE;
        }

        if (! DB::table('migrations')->exists()) {
            $this->error('Database migrations are missing. Run php artisan migrate --seed first.');

            return self::FAILURE;
        }

        $plainPassword = null;
        $organization = null;
        $email = 'admin@local.nexora.test';
        $credentialPath = dirname(base_path()).DIRECTORY_SEPARATOR.'.local-alpha-credentials.txt';

        DB::transaction(function () use (&$plainPassword, &$organization, $email, $credentialPath): void {
            app(DatabaseSeeder::class)->run();

            $organization = Organization::query()->firstOrCreate(
                ['name' => 'NEXORA Local Alpha'],
                [
                    'default_currency' => 'EGP',
                    'timezone' => 'Africa/Cairo',
                    'country_code' => 'EG',
                    'status' => 'active',
                    'settings_json' => ['environment' => 'local-demo'],
                ],
            );

            $user = User::query()
                ->where('organization_id', $organization->id)
                ->where('email', $email)
                ->first();

            if (! $user) {
                $plainPassword = Str::password(24);
                $user = User::query()->create([
                    'organization_id' => $organization->id,
                    'name' => 'مدير تجربة NEXORA',
                    'email' => $email,
                    'phone' => '01000000001',
                    'password' => $plainPassword,
                    'status' => 'active',
                ]);
            } elseif (! is_file($credentialPath)) {
                // Recover a lost local-demo password once, without touching any other account.
                $plainPassword = Str::password(24);
                $user->forceFill(['password' => $plainPassword])->save();
            }

            $role = Role::query()->firstOrCreate(
                ['organization_id' => $organization->id, 'key' => 'owner'],
                ['name' => 'مالك النظام'],
            );

            $permissionIds = Permission::query()->pluck('id')->all();
            $role->permissions()->syncWithoutDetaching($permissionIds);
            $user->roles()->syncWithoutDetaching([$role->id]);

            $unitId = DB::table('units')->whereNull('organization_id')->where('code', 'piece')->value('id');
            if (! $unitId) {
                throw new \RuntimeException('The global piece unit is missing. Run the database seeders first.');
            }

            $warehouseLocationId = $this->ensureLocation($organization->id, 'DEMO-WH', 'المخزن الرئيسي', 'warehouse');
            $vehicleLocationId = $this->ensureLocation($organization->id, 'DEMO-VAN-LOC', 'مخزون عربية التوزيع', 'vehicle');

            DB::table('warehouses')->updateOrInsert(
                ['organization_id' => $organization->id, 'code' => 'DEMO-WH'],
                [
                    'id' => DB::table('warehouses')->where('organization_id', $organization->id)->where('code', 'DEMO-WH')->value('id') ?: (string) Str::ulid(),
                    'location_id' => $warehouseLocationId,
                    'name' => 'المخزن الرئيسي',
                    'address' => 'بيانات تجريبية محلية',
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );

            DB::table('vehicles')->updateOrInsert(
                ['organization_id' => $organization->id, 'code' => 'DEMO-VAN'],
                [
                    'id' => DB::table('vehicles')->where('organization_id', $organization->id)->where('code', 'DEMO-VAN')->value('id') ?: (string) Str::ulid(),
                    'location_id' => $vehicleLocationId,
                    'plate_number' => null,
                    'name' => 'عربية تجربة',
                    'vehicle_type' => 'distribution',
                    'assigned_user_id' => $user->id,
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );

            DB::table('financial_accounts')->updateOrInsert(
                ['organization_id' => $organization->id, 'code' => 'DEMO-CASH'],
                [
                    'id' => DB::table('financial_accounts')->where('organization_id', $organization->id)->where('code', 'DEMO-CASH')->value('id') ?: (string) Str::ulid(),
                    'name' => 'الخزنة التجريبية',
                    'type' => 'cash',
                    'currency' => 'EGP',
                    'opening_balance' => 0,
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );

            $supplierId = $this->ensureParty('suppliers', $organization->id, 'DEMO-SUP', 'مورد تجريبي', 'normalized_name', 'مورد تجريبي');
            $customerId = $this->ensureParty('customers', $organization->id, 'DEMO-CUS', 'عميل تجريبي', 'normalized_name', 'عميل تجريبي');

            $productId = DB::table('products')->where('organization_id', $organization->id)->where('sku', 'DEMO-001')->value('id');
            if (! $productId) {
                $productId = (string) Str::ulid();
                DB::table('products')->insert([
                    'id' => $productId,
                    'organization_id' => $organization->id,
                    'base_unit_id' => $unitId,
                    'sku' => 'DEMO-001',
                    'name_ar' => 'صنف كهرباء تجريبي',
                    'name_en' => 'Demo electrical product',
                    'brand' => 'Demo',
                    'default_cost' => 50,
                    'default_piece_price' => 75,
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('product_packagings')->updateOrInsert(
                ['organization_id' => $organization->id, 'product_id' => $productId, 'unit_id' => $unitId, 'name_ar' => 'قطعة'],
                [
                    'id' => DB::table('product_packagings')->where('organization_id', $organization->id)->where('product_id', $productId)->where('unit_id', $unitId)->where('name_ar', 'قطعة')->value('id') ?: (string) Str::ulid(),
                    'conversion_to_base' => 1,
                    'barcode_primary' => null,
                    'sale_price' => 75,
                    'purchase_price' => 50,
                    'is_default_sale_unit' => true,
                    'is_default_purchase_unit' => true,
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );

            // Keep demo master data visibly labeled; stock is created only by a posted purchase.
            DB::table('document_sequences')->insertOrIgnore([
                'id' => (string) Str::ulid(), 'organization_id' => $organization->id,
                'document_type' => 'purchase_invoice', 'prefix' => 'PUR', 'next_number' => 1,
                'padding' => 6, 'reset_policy' => 'never', 'active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $this->newLine();
        $this->info('Local Alpha bootstrap is ready. All created business records are demo data.');
        $this->line('Organization ID: '.$organization->id);
        $this->line('Login email: '.$email);
        if ($plainPassword) {
            $this->line('One-time password: '.$plainPassword);
            $credentialText = "NEXORA Distribution OS — LOCAL DEMO ONLY\r\n"
                .'Organization ID: '.$organization->id."\r\n"
                .'Login email: '.$email."\r\n"
                .'Password: '.$plainPassword."\r\n"
                ."Frontend: http://127.0.0.1:3017\r\n"
                ."API health: http://127.0.0.1:8017/api/v1/health\r\n"
                ."This file contains a local-only credential. Never commit it or reuse this password elsewhere.\r\n";
            if (file_put_contents($credentialPath, $credentialText, LOCK_EX) === false) {
                $this->warn('Could not write the ignored local credential file. Save the password shown above securely.');
            } else {
                $this->line('Local login details saved in the ignored file: ../.local-alpha-credentials.txt');
            }
        } else {
            $this->warn('The local admin already exists; its password was not changed. Check ../.local-alpha-credentials.txt for the original local password.');
        }
        $this->line('Starter records: customer, supplier, product, warehouse, vehicle and cash account.');
        $this->line('No stock quantity was inserted directly; record a purchase to create stock through the transaction engine.');

        return self::SUCCESS;
    }

    private function ensureLocation(string $organizationId, string $code, string $name, string $type): string
    {
        $id = DB::table('locations')->where('organization_id', $organizationId)->where('code', $code)->value('id');
        if ($id) {
            DB::table('locations')->where('id', $id)->update(['name' => $name, 'type' => $type, 'status' => 'active', 'updated_at' => now()]);

            return $id;
        }

        $id = (string) Str::ulid();
        DB::table('locations')->insert([
            'id' => $id, 'organization_id' => $organizationId, 'code' => $code,
            'name' => $name, 'type' => $type, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function ensureParty(string $table, string $organizationId, string $code, string $name, string $normalizedColumn, string $normalizedValue): string
    {
        $id = DB::table($table)->where('organization_id', $organizationId)->where('code', $code)->value('id');
        if ($id) {
            return $id;
        }

        $id = (string) Str::ulid();
        $row = [
            'id' => $id, 'organization_id' => $organizationId, 'code' => $code,
            'name' => $name, $normalizedColumn => $normalizedValue,
            'created_at' => now(), 'updated_at' => now(),
        ];
        if ($table === 'customers') {
            $row['status'] = 'active';
            $row['credit_limit'] = 0;
            $row['payment_terms_days'] = 0;
        } else {
            $row['active'] = true;
            $row['credit_terms_days'] = 0;
        }
        DB::table($table)->insert($row);

        return $id;
    }
}
