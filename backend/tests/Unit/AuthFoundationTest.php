<?php

namespace Tests\Unit;

use App\Models\Role;
use App\Models\User;
use App\Shared\Enums\UserRole;
use App\Shared\Http\Middleware\EnsureAccountIsActive;
use App\Shared\Http\Middleware\EnsureSupervisorIsApproved;
use App\Shared\Http\Middleware\EnsureUserHasRole;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class AuthFoundationTest extends TestCase
{
    public function test_user_roles_match_the_canonical_database_values(): void
    {
        $this->assertSame([
            'STUDENT',
            'COMPANY_SUPERVISOR',
            'ACADEMIC_COORDINATOR',
            'ADMIN',
        ], array_column(UserRole::cases(), 'value'));
    }

    public function test_auth_foundation_is_registered_with_the_application(): void
    {
        $router = app('router');
        $aliases = $router->getMiddleware();
        $apiMiddleware = $router->getMiddlewareGroups()['api'];

        $this->assertSame(EnsureAccountIsActive::class, $aliases['active']);
        $this->assertSame(EnsureUserHasRole::class, $aliases['role']);
        $this->assertSame(EnsureSupervisorIsApproved::class, $aliases['supervisor.approved']);
        $this->assertContains(EnsureFrontendRequestsAreStateful::class, $apiMiddleware);
        $this->assertTrue(config('cors.supports_credentials'));
    }

    #[DataProvider('protectedMiddlewareProvider')]
    public function test_protected_middleware_rejects_unauthenticated_requests(string $middleware): void
    {
        $response = match ($middleware) {
            'active' => (new EnsureAccountIsActive)->handle($this->requestFor(), $this->next()),
            'role' => (new EnsureUserHasRole)->handle($this->requestFor(), $this->next(), UserRole::ADMIN->value),
            'supervisor.approved' => (new EnsureSupervisorIsApproved)->handle($this->requestFor(), $this->next()),
        };

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function test_active_account_middleware_allows_active_users(): void
    {
        $response = (new EnsureAccountIsActive)->handle(
            $this->requestFor($this->user(UserRole::STUDENT, true)),
            $this->next(),
        );

        $this->assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
    }

    public function test_active_account_middleware_rejects_inactive_users(): void
    {
        $response = (new EnsureAccountIsActive)->handle(
            $this->requestFor($this->user(UserRole::STUDENT, false)),
            $this->next(),
        );

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function test_role_middleware_allows_an_expected_role(): void
    {
        $response = (new EnsureUserHasRole)->handle(
            $this->requestFor($this->user(UserRole::ADMIN)),
            $this->next(),
            UserRole::ADMIN->value,
        );

        $this->assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
    }

    public function test_role_middleware_rejects_an_unexpected_role(): void
    {
        $response = (new EnsureUserHasRole)->handle(
            $this->requestFor($this->user(UserRole::STUDENT)),
            $this->next(),
            UserRole::ADMIN->value,
        );

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public static function protectedMiddlewareProvider(): array
    {
        return [['active'], ['role'], ['supervisor.approved']];
    }

    private function user(UserRole $role, bool $isActive = true): User
    {
        $user = new User(['is_active' => $isActive]);
        $user->setRelation('role', new Role(['name' => $role->value]));

        return $user;
    }

    private function requestFor(?User $user = null): Request
    {
        $request = Request::create('/api/protected');
        $request->setUserResolver(static fn (): ?User => $user);

        return $request;
    }

    private function next(): \Closure
    {
        return static fn (): Response => new Response(status: Response::HTTP_NO_CONTENT);
    }
}
