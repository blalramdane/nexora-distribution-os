<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_roles', function (Blueprint $table): void {
            $table->ulid('organization_id')->nullable()->after('role_id');
        });

        // Backfill existing assignments only from the user's organization.
        DB::statement('UPDATE user_roles SET organization_id = (SELECT users.organization_id FROM users WHERE users.id = user_roles.user_id) WHERE organization_id IS NULL');

        $crossTenantAssignmentExists = DB::table('user_roles as ur')
            ->join('users as u', 'u.id', '=', 'ur.user_id')
            ->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->whereColumn('u.organization_id', '<>', 'r.organization_id')
            ->exists();

        if ($crossTenantAssignmentExists || DB::table('user_roles')->whereNull('organization_id')->exists()) {
            throw new RuntimeException('Cannot migrate user_roles: existing assignments include missing or cross-organization user/role pairs. Resolve them before retrying.');
        }

        if (DB::getDriverName() === 'mysql') {
            Schema::table('user_roles', function (Blueprint $table): void {
                $table->ulid('organization_id')->nullable(false)->change();
                $table->index(['organization_id', 'user_id'], 'user_roles_org_user_idx');
                $table->index(['organization_id', 'role_id'], 'user_roles_org_role_idx');
            });

            Schema::table('roles', function (Blueprint $table): void {
                $table->unique(['organization_id', 'id'], 'roles_org_id_uq');
            });

            Schema::table('user_roles', function (Blueprint $table): void {
                $table->foreign(['organization_id', 'user_id'], 'user_roles_org_user_fk')
                    ->references(['organization_id', 'id'])->on('users')->restrictOnDelete();
                $table->foreign(['organization_id', 'role_id'], 'user_roles_org_role_fk')
                    ->references(['organization_id', 'id'])->on('roles')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            Schema::table('user_roles', function (Blueprint $table): void {
                $table->dropForeign('user_roles_org_user_fk');
                $table->dropForeign('user_roles_org_role_fk');
                $table->dropIndex('user_roles_org_user_idx');
                $table->dropIndex('user_roles_org_role_idx');
            });

            Schema::table('roles', function (Blueprint $table): void {
                $table->dropUnique('roles_org_id_uq');
            });
        }

        Schema::table('user_roles', function (Blueprint $table): void {
            $table->dropColumn('organization_id');
        });
    }
};
