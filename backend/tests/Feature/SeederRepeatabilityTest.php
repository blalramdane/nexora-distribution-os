<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SeederRepeatabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_reference_seeders_do_not_duplicate_or_replace_primary_keys(): void
    {
        $this->seed(\Database\Seeders\EgyptGeographySeeder::class);
        $this->seed(\Database\Seeders\ReferenceDataSeeder::class);

        $governorateIdsBefore = DB::table('governorates')
            ->orderBy('code')
            ->pluck('id', 'code')
            ->all();
        $unitIdsBefore = DB::table('units')
            ->whereNull('organization_id')
            ->orderBy('code')
            ->pluck('id', 'code')
            ->all();
        $paymentMethodIdsBefore = DB::table('payment_methods')
            ->whereNull('organization_id')
            ->orderBy('code')
            ->pluck('id', 'code')
            ->all();

        $this->seed(\Database\Seeders\EgyptGeographySeeder::class);
        $this->seed(\Database\Seeders\ReferenceDataSeeder::class);

        $this->assertSame(
            $governorateIdsBefore,
            DB::table('governorates')->orderBy('code')->pluck('id', 'code')->all()
        );
        $this->assertSame(
            $unitIdsBefore,
            DB::table('units')->whereNull('organization_id')->orderBy('code')->pluck('id', 'code')->all()
        );
        $this->assertSame(
            $paymentMethodIdsBefore,
            DB::table('payment_methods')->whereNull('organization_id')->orderBy('code')->pluck('id', 'code')->all()
        );

        $this->assertSame(27, DB::table('governorates')->count());
        $this->assertSame(5, DB::table('units')->whereNull('organization_id')->count());
        $this->assertSame(5, DB::table('payment_methods')->whereNull('organization_id')->count());
    }

    public function test_document_sequence_seeder_does_not_reset_consumed_numbers_or_replace_ids(): void
    {
        $organizationId = (string) Str::ulid();

        DB::table('organizations')->insert([
            'id' => $organizationId,
            'name' => 'Seeder Test Org',
            'default_currency' => 'EGP',
            'timezone' => 'Africa/Cairo',
            'country_code' => 'EG',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->seed(\Database\Seeders\DocumentSequenceSeeder::class);

        $sequence = DB::table('document_sequences')
            ->where('organization_id', $organizationId)
            ->where('document_type', 'sales_invoice')
            ->first();

        DB::table('document_sequences')
            ->where('id', $sequence->id)
            ->update(['next_number' => 42]);

        $this->seed(\Database\Seeders\DocumentSequenceSeeder::class);

        $reloaded = DB::table('document_sequences')->where('id', $sequence->id)->first();

        $this->assertSame($sequence->id, $reloaded->id);
        $this->assertSame(42, (int) $reloaded->next_number);
        $this->assertSame('SI', $reloaded->prefix);
        $this->assertSame(6, (int) $reloaded->padding);
        $this->assertSame(7, DB::table('document_sequences')->where('organization_id', $organizationId)->count());
    }
}
