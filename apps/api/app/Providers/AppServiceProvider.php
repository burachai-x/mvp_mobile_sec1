<?php

namespace App\Providers;

use Filament\Notifications\Notification;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Views a notification is allowed to render.
     *
     * Filament refuses any view not on this list, which stops a stored value
     * from naming an arbitrary template to render. It has to be registered here
     * rather than on the notification itself: a notification is rebuilt from an
     * array on its way to the browser, and the rebuilt instance starts with an
     * empty list. Per-instance registration leaves the view silently dropped —
     * the notification still arrives, just with none of its content.
     */
    private const SAFE_NOTIFICATION_VIEWS = [
        'filament.notifications.activation-qr',
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Notification::configureUsing(
            fn (Notification $notification) => $notification->safeViews(self::SAFE_NOTIFICATION_VIEWS),
        );
    }
}
