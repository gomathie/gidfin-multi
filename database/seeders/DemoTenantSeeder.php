<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use App\Services\TenantService;
use Database\Seeders\Tenant\ProjectSeeder;
use Database\Seeders\Tenant\UserSeeder;
use Illuminate\Database\Seeder;

class DemoTenantSeeder extends Seeder
{
    public function run(TenantService $tenantService): void
    {
        if (Tenant::find('demo')) {
            $this->command->info('Demo tenant already exists, skipping...');

            return;
        }

        $tenant = $tenantService->createTenant([
            'domain' => 'demo',
            'name' => 'Demo Company',
            'admin_email' => 'admin@demo.com',
            'admin_password' => 'password',
        ]);

        tenancy()->initialize($tenant);

        try {
            $this->call([
                UserSeeder::class,
                ProjectSeeder::class,
            ]);
        } finally {
            tenancy()->end();
        }

        $this->command->info('');
        $this->command->info('Demo tenant created successfully!');
        $this->command->info('');
        $this->command->info('  URL: http://demo.'.config('app.domain'));
        $this->command->info('');
        $this->command->info('  Demo Users:');
        $this->command->info('  ┌────────────────────┬───────────────────┬──────────┐');
        $this->command->info('  │ Email              │ Role              │ Password │');
        $this->command->info('  ├────────────────────┼───────────────────┼──────────┤');
        $this->command->info('  │ admin@demo.com     │ Admin             │ password │');
        $this->command->info('  │ john@demo.com      │ User              │ password │');
        $this->command->info('  │ jane@demo.com      │ User              │ password │');
        $this->command->info('  └────────────────────┴───────────────────┴──────────┘');
        $this->command->info('');
    }
}
