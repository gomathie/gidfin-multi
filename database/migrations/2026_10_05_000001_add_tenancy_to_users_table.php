<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('tenant_id')->nullable()->after('id');
            $table->string('role')->default('user')->after('password');

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->restrictOnDelete();

            $table->dropUnique(['email']);
            $table->unique(['tenant_id', 'email']);
        });

        // NULLs are distinct in a unique index, so platform admins (no tenant) need their own.
        DB::statement('CREATE UNIQUE INDEX users_central_email_unique ON users (email) WHERE tenant_id IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX users_central_email_unique');

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'email']);
            $table->unique('email');
            $table->dropForeign(['tenant_id']);
            $table->dropColumn(['tenant_id', 'role']);
        });
    }
};
