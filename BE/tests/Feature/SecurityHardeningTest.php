<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\Support\WorkplaceFixtures;
use Tests\TestCase;

/**
 * Route-level guarantees that hold for EVERY route, including ones added later:
 *  - the API never answers an anonymous caller with data,
 *  - the web admin never answers an anonymous caller, nor a signed-in user without admin.access,
 *  - finalized payroll and payslips have no destructive routes,
 *  - secrets never appear in API responses.
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase, WorkplaceFixtures;

    /** API routes that are intentionally public. */
    private const PUBLIC_API = ['api/v1/ping', 'api/v1/auth/login'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorkplace();
    }

    /** @return list<LaravelRoute> */
    private function routes(string $prefix): array
    {
        return array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            fn (LaravelRoute $r) => str_starts_with($r->uri(), $prefix) && ! in_array($r->uri(), self::PUBLIC_API, true),
        ));
    }

    private function fill(string $uri): string
    {
        $uri = str_replace('{key}', 'attendance.daily', $uri);
        $uri = str_replace('{format}', 'csv', $uri);

        return '/'.preg_replace('/\{[^}]+\}/', '1', $uri);
    }

    public function test_every_api_route_rejects_anonymous_callers(): void
    {
        $routes = $this->routes('api/v1');
        $this->assertGreaterThan(80, count($routes), 'route discovery looks broken');

        foreach ($routes as $route) {
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $response = $this->json($method, $this->fill($route->uri()));
                $this->assertSame(401, $response->status(), "{$method} {$route->uri()} answered {$response->status()} to an anonymous caller");
            }
        }
    }

    public function test_every_admin_route_rejects_anonymous_callers(): void
    {
        $routes = $this->routes('admin');
        $this->assertGreaterThan(60, count($routes));

        foreach ($routes as $route) {
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $response = $this->call($method, $this->fill($route->uri()));
                $this->assertSame(302, $response->status(), "{$method} {$route->uri()} answered {$response->status()} to an anonymous caller");
                $this->assertStringContainsString('/login', (string) $response->headers->get('Location'));
            }
        }
    }

    public function test_every_admin_route_is_forbidden_for_a_plain_employee(): void
    {
        [$employee] = $this->makeStaff();
        $this->actingAs($employee);

        foreach ($this->routes('admin') as $route) {
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $status = $this->call($method, $this->fill($route->uri()))->status();
                $this->assertContains($status, [403, 302], "{$method} {$route->uri()} let an employee through ({$status})");
                $this->assertNotSame(200, $status);
            }
        }
    }

    public function test_finalized_payroll_and_payslips_have_no_destructive_routes(): void
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();
            if (! preg_match('#(payroll|payslip)#', $uri)) {
                continue;
            }
            foreach (['DELETE', 'PUT', 'PATCH'] as $method) {
                if (! in_array($method, $route->methods(), true)) {
                    continue;
                }
                // Setup data, and entries of a DRAFT adjustment period (the service refuses locked periods).
                $allowed = preg_match('#salary|components|structures|adjustment-entries|adjustments/\{|admin/payroll/\{period\}$#', $uri) || str_contains($uri, 'payroll-periods/{') && $method === 'PATCH';
                $this->assertTrue((bool) $allowed, "{$method} {$uri} could modify or delete payroll data");
            }
        }
        $this->assertTrue(true);
    }

    public function test_api_responses_never_contain_secrets(): void
    {
        [$user] = $this->makeStaff();
        $login = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password']);
        $token = $login->json('data.token');

        $bodies = [$login->getContent()];
        foreach (['/api/v1/auth/me', '/api/v1/employees/me', '/api/v1/attendance/today'] as $url) {
            $this->app['auth']->forgetGuards();
            $bodies[] = $this->withToken($token)->getJson($url)->getContent();
        }

        foreach ($bodies as $body) {
            $this->assertStringNotContainsString('password', strtolower($body));
            $this->assertStringNotContainsString('remember_token', $body);
            $this->assertStringNotContainsString('$2y$', $body, 'a password hash leaked');
            $this->assertStringNotContainsString('file_path', $body);
            $this->assertStringNotContainsString('selfie_path', $body);
        }
    }

    public function test_tokens_expire(): void
    {
        [$user] = $this->makeStaff();
        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->json('data.token');

        $this->travel(config('peopleos.api.token_expiration_minutes') + 5)->minutes();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_unexpected_errors_never_leak_internals_in_production_mode(): void
    {
        config(['app.debug' => false]);
        [$hr] = $this->makeStaff('hr');

        Route::middleware(['api', 'auth:sanctum'])->get('api/v1/__boom', fn () => throw new \RuntimeException('secret database host 10.0.0.5'));
        $response = $this->actingAsApi($hr)->getJson('/api/v1/__boom');

        $response->assertStatus(500)->assertJsonPath('error.code', 'SERVER_ERROR');
        $this->assertStringNotContainsString('10.0.0.5', $response->getContent());
        $this->assertStringNotContainsString('RuntimeException', $response->getContent());
    }
}
