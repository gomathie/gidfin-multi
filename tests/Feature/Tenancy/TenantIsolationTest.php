<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $acme;

    private Tenant $swift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $service = app(TenantService::class);

        $this->acme = $service->createTenant([
            'domain' => 'acme',
            'name' => 'Acme Loans',
            'admin_email' => 'admin@example.com',
            'admin_password' => 'acme-password',
        ]);

        $this->swift = $service->createTenant([
            'domain' => 'swift',
            'name' => 'Swift Credit',
            'admin_email' => 'admin@example.com',
            'admin_password' => 'swift-password',
        ]);
    }

    protected function tearDown(): void
    {
        tenancy()->end();

        parent::tearDown();
    }

    private function url(Tenant $tenant, string $path): string
    {
        return 'http://'.$tenant->id.'.'.config('app.domain').$path;
    }

    private function createProject(Tenant $tenant, string $name): Project
    {
        return $tenant->run(fn () => Project::create([
            'name' => $name,
            'created_by' => User::firstOrFail()->id,
        ]));
    }

    public function test_creating_a_tenant_creates_no_database_and_one_admin_user(): void
    {
        $this->assertSame(2, User::acrossTenants()->whereNotNull('tenant_id')->count());
        $this->assertSame('acme', User::acrossTenants()->where('tenant_id', 'acme')->sole()->tenant_id);
    }

    public function test_the_same_email_can_exist_in_two_tenants(): void
    {
        $this->assertSame(2, User::acrossTenants()->where('email', 'admin@example.com')->count());
    }

    public function test_records_are_filled_with_and_filtered_by_the_current_tenant(): void
    {
        $project = $this->createProject($this->acme, 'Acme project');

        $this->assertSame('acme', $project->tenant_id);
        $this->assertSame(1, $this->acme->run(fn () => Project::count()));
        $this->assertSame(0, $this->swift->run(fn () => Project::count()));
        $this->assertSame(1, Project::acrossTenants()->count());
    }

    public function test_business_records_are_invisible_without_a_tenant(): void
    {
        $this->createProject($this->acme, 'Acme project');

        $this->assertSame(0, Project::count());
    }

    public function test_only_platform_users_are_visible_without_a_tenant(): void
    {
        $platformAdmin = User::factory()->create(['is_admin' => true]);

        $this->assertSame([$platformAdmin->id], User::pluck('id')->all());
    }

    public function test_relationships_do_not_cross_tenants(): void
    {
        $project = $this->createProject($this->acme, 'Acme project');

        $creator = $this->swift->run(fn () => Project::acrossTenants()->find($project->id)->creator);

        $this->assertNull($creator);
    }

    public function test_a_user_can_only_log_in_on_their_own_tenant(): void
    {
        $this->post($this->url($this->acme, '/login'), [
            'email' => 'admin@example.com',
            'password' => 'swift-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();

        $this->post($this->url($this->acme, '/login'), [
            'email' => 'admin@example.com',
            'password' => 'acme-password',
        ])->assertRedirect();

        $this->assertSame('acme', Auth::user()->tenant_id);
    }

    public function test_a_tenant_user_cannot_log_in_to_platform_admin(): void
    {
        $this->post('http://'.config('app.domain').'/admin/login', [
            'email' => 'admin@example.com',
            'password' => 'acme-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_session_from_one_tenant_is_not_valid_on_another(): void
    {
        $acmeAdmin = User::acrossTenants()->where('tenant_id', 'acme')->sole();

        $this->actingAs($acmeAdmin)
            ->get($this->url($this->acme, '/dashboard'))
            ->assertOk();

        Auth::forgetGuards();

        $this->withSession([Auth::guard('web')->getName() => $acmeAdmin->id])
            ->get($this->url($this->swift, '/dashboard'))
            ->assertRedirect($this->url($this->swift, '/login'));
    }

    public function test_another_tenants_record_cannot_be_opened_by_id(): void
    {
        $project = $this->createProject($this->acme, 'Acme project');
        $swiftAdmin = User::acrossTenants()->where('tenant_id', 'swift')->sole();

        $this->actingAs($swiftAdmin)
            ->get($this->url($this->swift, '/projects/'.$project->id))
            ->assertNotFound();
    }

    public function test_an_api_token_from_one_tenant_is_rejected_on_another(): void
    {
        $acmeAdmin = User::acrossTenants()->where('tenant_id', 'acme')->sole();
        $token = $acmeAdmin->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->getJson($this->url($this->acme, '/api/v1/user'))
            ->assertOk();

        Auth::forgetGuards();

        $this->withToken($token)
            ->getJson($this->url($this->swift, '/api/v1/user'))
            ->assertUnauthorized();
    }

    public function test_registration_allows_an_email_used_in_another_tenant_but_not_twice_in_one(): void
    {
        $this->swift->run(fn () => User::factory()->create(['email' => 'kofi@example.com']));

        $payload = [
            'name' => 'Kofi',
            'email' => 'kofi@example.com',
            'password' => 'Str0ng-passw0rd!',
            'password_confirmation' => 'Str0ng-passw0rd!',
        ];

        $this->post($this->url($this->acme, '/register'), $payload)->assertSessionHasNoErrors();

        Auth::forgetGuards();
        $this->flushSession();

        $this->post($this->url($this->swift, '/register'), $payload)->assertSessionHasErrors('email');
    }

    public function test_password_reset_tokens_are_separate_per_tenant(): void
    {
        $acmeAdmin = User::acrossTenants()->where('tenant_id', 'acme')->sole();
        $swiftAdmin = User::acrossTenants()->where('tenant_id', 'swift')->sole();

        $token = Password::broker()->createToken($acmeAdmin);

        $this->assertTrue(Password::broker()->tokenExists($acmeAdmin, $token));
        $this->assertFalse(Password::broker()->tokenExists($swiftAdmin, $token));
    }

    public function test_a_suspended_tenant_is_blocked(): void
    {
        $this->acme->update(['is_active' => false]);

        $this->get($this->url($this->acme, '/login'))->assertForbidden();
        $this->get($this->url($this->swift, '/login'))->assertOk();
    }

    public function test_tenants_cannot_be_deleted(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(TenantService::class)->deleteTenant($this->acme);
    }

    public function test_queued_jobs_keep_their_tenant(): void
    {
        $this->acme->run(function () {
            dispatch(function () {
                Project::create(['name' => 'From a job', 'created_by' => User::firstOrFail()->id]);
            })->onConnection('database');
        });

        $this->assertNull(tenant());
        $this->assertSame(0, Project::acrossTenants()->count());

        Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);
        tenancy()->end();

        $this->assertSame('acme', Project::acrossTenants()->where('name', 'From a job')->sole()->tenant_id);
    }
}
