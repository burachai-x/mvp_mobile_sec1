<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use Filament\Notifications\Notification;
use Tests\TestCase;

/**
 * The QR survives the trip to the browser (architecture.md §6.2).
 *
 * A notification is serialised and rebuilt on its way to the client, and
 * Filament drops any view that is not on the notification's safe list — without
 * saying so. The notification still arrives, still shows its title and body, and
 * simply has no QR in it. Staff would read that as "issued" and hand the driver
 * nothing, so it is worth a test of its own.
 */
final class ActivationQrNotificationTest extends TestCase
{
    private const VIEW = 'filament.notifications.activation-qr';

    private function roundTrip(Notification $notification): Notification
    {
        return Notification::fromArray($notification->toArray());
    }

    public function test_the_qr_view_survives_serialisation(): void
    {
        $notification = $this->roundTrip(
            Notification::make()
                ->title('Activation code issued')
                ->view(self::VIEW, ['token' => 'header.payload.signature'])
        );

        $this->assertTrue($notification->hasView());
        $this->assertSame(self::VIEW, $notification->getView());
        $this->assertSame('header.payload.signature', $notification->getViewData()['token'] ?? null);
    }

    /**
     * The failure this guards against. A view that is not on the list is
     * discarded on the way through and nothing anywhere reports it.
     */
    public function test_a_view_outside_the_safe_list_is_silently_dropped(): void
    {
        $notification = $this->roundTrip(
            Notification::make()
                ->title('Activation code issued')
                ->view('filament.notifications.not-registered')
        );

        $this->assertFalse(
            $notification->hasView(),
            'If Filament now keeps unlisted views, the safe list in AppServiceProvider '
            .'is no longer load-bearing and its comment should be revisited.',
        );
    }

    /** The view has to render a real QR, not an empty box. */
    public function test_the_view_renders_the_token_as_a_qr(): void
    {
        $svg = view(self::VIEW, ['token' => 'header.payload.signature'])->render();

        $other = view(self::VIEW, ['token' => 'a.different.token'])->render();

        $this->assertStringContainsString('<svg', $svg);
        // The renderer emits one long path rather than a rect per module, so the
        // check is on the path data: a blank QR would still carry the tags.
        $this->assertMatchesRegularExpression('/<path[^>]+\bd="M[^"]{500,}"/', $svg);
        $this->assertNotSame($svg, $other, 'The QR must encode the token, not a constant.');
    }

    /**
     * If the token is missing the view must say so rather than render a blank
     * square that staff would show to a driver.
     */
    public function test_a_missing_token_produces_a_visible_error(): void
    {
        $html = view(self::VIEW, ['token' => null])->render();

        $this->assertStringNotContainsString('<svg', $html);
        $this->assertStringContainsString('Revoke this code', $html);
    }
}
