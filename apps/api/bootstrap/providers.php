<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\StaffPanelProvider;
use App\Providers\RateLimitServiceProvider;

/**
 * Filament registers its panel routes when its provider boots, which would put
 * /staff back into the API container and defeat the split in ADR 0007.
 *
 * Registering it only for APP_ROLE=portal keeps the guarantee that the
 * internet-facing container has no staff routes at all.
 */
$providers = [
    AppServiceProvider::class,
    RateLimitServiceProvider::class,
];

if (env('APP_ROLE', 'portal') === 'portal') {
    $providers[] = StaffPanelProvider::class;
}

return $providers;
