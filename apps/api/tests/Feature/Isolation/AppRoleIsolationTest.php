<?php

declare(strict_types=1);

namespace Tests\Feature\Isolation;

use Tests\TestCase;

/**
 * Guards the api/portal route split (ADR 0007, threat model S18).
 *
 * The split fails silently: a wrong APP_ROLE leaves everything working while
 * quietly removing the isolation. These assertions are what would notice.
 *
 * Scope note — this covers only the layer the app can see: which routes exist.
 * Secret and network isolation live in compose.yaml, which is not mounted into
 * the container, so they are checked host-side by `make verify-isolation`.
 * Both layers must hold; neither alone is the guarantee.
 */
final class AppRoleIsolationTest extends TestCase
{
    /** @return list<string> */
    private function routeUris(): array
    {
        return collect(app('router')->getRoutes())
            ->map(fn ($route) => $route->uri())
            ->values()
            ->all();
    }

    public function test_test_suite_runs_in_the_portal_role(): void
    {
        // If this fails the other assertions below are meaningless, so state it
        // explicitly rather than letting them pass for the wrong reason.
        $this->assertSame('portal', env('APP_ROLE', 'portal'));
    }

    public function test_portal_registers_staff_routes(): void
    {
        $this->assertContains(
            'staff/login',
            $this->routeUris(),
            'Staff Portal must expose its login route when APP_ROLE=portal.'
        );
    }

    public function test_portal_does_not_register_driver_api(): void
    {
        $apiRoutes = array_values(array_filter(
            $this->routeUris(),
            fn (string $uri) => str_starts_with($uri, 'api/v1')
        ));

        $this->assertSame(
            [],
            $apiRoutes,
            'Driver API routes must not exist in the portal process.'
        );
    }
}
