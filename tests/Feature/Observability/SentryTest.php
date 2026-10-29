<?php

declare(strict_types=1);

namespace Tests\Feature\Observability;

use App\Observability\SentryScrubber;
use App\Tenancy\Exceptions\TenantNotResolved;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Sentry\Frame;
use Sentry\Stacktrace;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\TestCase;
use Throwable;

/**
 * What leaves the building when something breaks (SPEC §30.2).
 *
 * Sentry is a pipe to a third party, and these tests are about what must
 * never go down it: venues' gateway keys and tourists' contact details. Both
 * arrive there the same way — not because anybody sent them, but because a
 * stack trace carries the local variables of every frame, and in the payment
 * flow those variables are a live secret.
 *
 * No DSN is set in testing, so nothing is actually transmitted. The scrubber
 * is called directly, which is also how it runs in production: as
 * `before_send`, on every event.
 */
final class SentryTest extends TestCase
{
    use ActsAsVenueOwner;
    use RefreshDatabase;

    #[Test]
    public function personal_information_is_never_collected_by_the_reporter_itself(): void
    {
        /*
         * The setting that matters most, and the one that is hard-coded
         * rather than read from the environment — because left on, Sentry
         * attaches the request body of every failed request, and the request
         * body of the page that matters most here is a booking form.
         */
        $this->assertFalse(config('sentry.send_default_pii'));
    }

    #[Test]
    public function the_scrubber_survives_config_caching(): void
    {
        $before = config('sentry.before_send');

        /*
         * A closure cannot be serialised, so `php artisan config:cache` —
         * which every production deploy runs — fails outright on one, and the
         * error names the config file rather than the closure. A static
         * method in an array survives.
         */
        $this->assertNotInstanceOf(Closure::class, $before);
        $this->assertIsCallable($before);
        $this->assertSame([SentryScrubber::class, 'handle'], $before);
    }

    #[Test]
    public function a_venues_gateway_secret_in_a_stack_frame_does_not_leave(): void
    {
        /*
         * The arguments of the frame that threw inside the payment flow.
         *
         * 🔒 Deliberately too short to be mistaken for a Stripe key. These
         * are redacted by the *name* of the variable, so the value carries no
         * weight here — and a fixture shaped like a real `sk_live_` key makes
         * GitHub's push protection reject every push to this repository until
         * somebody allowlists it. Lengthening these back is how that returns.
         */
        $frame = new Frame(
            'charge',
            'app/Payments/Gateways/StripeGateway.php',
            88,
            vars: [
                'secret_key' => 'sk_live_notreal',
                'webhook_secret' => 'whsec_notreal',
                'amount_cents' => 7000,
                'settings' => ['credentials' => ['secret_key' => 'sk_live_another']],
            ],
        );

        $event = $this->eventWithFrame($frame);

        $scrubbed = SentryScrubber::handle($event);

        $this->assertNotNull($scrubbed);

        $vars = $scrubbed->getExceptions()[0]->getStacktrace()?->getFrames()[0]->getVars() ?? [];

        $this->assertSame('[redacted]', $vars['secret_key']);
        $this->assertSame('[redacted]', $vars['webhook_secret']);
        $this->assertSame('[redacted]', $vars['settings']['credentials']);

        // And the half that makes the report worth reading is still there.
        $this->assertSame(7000, $vars['amount_cents']);
    }

    #[Test]
    public function a_guests_email_and_telephone_do_not_leave(): void
    {
        $event = Event::createEvent();

        $event->setExtra([
            'guest_email' => 'tourist@example.com',
            'phone' => '+306941234567',
            'people' => 4,
            'nested' => ['customer_name' => 'A Tourist'],
        ]);

        $scrubbed = SentryScrubber::handle($event);

        $this->assertNotNull($scrubbed);

        $extra = $scrubbed->getExtra();

        $this->assertSame('[redacted]', $extra['guest_email']);
        $this->assertSame('[redacted]', $extra['phone']);
        $this->assertSame('[redacted]', $extra['nested']['customer_name']);
        $this->assertSame(4, $extra['people']);
    }

    #[Test]
    public function a_secret_quoted_inside_a_message_is_redacted_too(): void
    {
        // Key names cannot help here: the value is in free text, written by
        // whoever threw the exception.
        $event = $this->eventWithException(new RuntimeException(
            'Stripe refused sk_live_51HxLEAKEDINAMESSAGE for tourist@example.com'
        ));

        $scrubbed = SentryScrubber::handle($event);

        $this->assertNotNull($scrubbed);

        $message = $scrubbed->getExceptions()[0]->getValue();

        $this->assertStringNotContainsString('sk_live_51HxLEAKEDINAMESSAGE', $message);
        $this->assertStringNotContainsString('tourist@example.com', $message);
        $this->assertStringContainsString('Stripe refused', $message);
    }

    #[Test]
    public function our_own_api_token_is_redacted(): void
    {
        $event = $this->eventWithException(new RuntimeException(
            'Unknown bearer tr_live_demo2222333344445555666677778888999900001111'
        ));

        $scrubbed = SentryScrubber::handle($event);

        $this->assertStringNotContainsString(
            'tr_live_demo2222',
            $scrubbed?->getExceptions()[0]->getValue() ?? '',
        );
    }

    #[Test]
    public function the_request_keeps_its_address_and_loses_everybody_in_it(): void
    {
        $event = Event::createEvent();

        $event->setRequest([
            'url' => 'https://example.test/reservation/demo/book?email=tourist@example.com',
            'method' => 'POST',
            'data' => ['guest_email' => 'tourist@example.com', 'guest_phone' => '+306941234567'],
            'cookies' => ['laravel_session' => 'abcdef'],
            'headers' => ['Authorization' => 'Bearer tr_live_something'],
            'env' => ['REMOTE_ADDR' => '1.2.3.4'],
        ]);

        $scrubbed = SentryScrubber::handle($event);

        $this->assertNotNull($scrubbed);

        $request = $scrubbed->getRequest();

        // Where it went is the useful half and names nobody.
        $this->assertSame('POST', $request['method']);
        $this->assertStringStartsWith('https://example.test/reservation/demo/book', $request['url']);

        // An email in a query string is as much a leak as one in a body.
        $this->assertStringNotContainsString('tourist@example.com', $request['url']);

        $this->assertArrayNotHasKey('data', $request);
        $this->assertArrayNotHasKey('cookies', $request);
        $this->assertArrayNotHasKey('headers', $request);
        $this->assertArrayNotHasKey('env', $request);
    }

    #[Test]
    public function the_user_block_is_emptied(): void
    {
        $event = Event::createEvent();
        $event->setUser(\Sentry\UserDataBag::createFromArray([
            'id' => 7,
            'email' => 'owner@demo.test',
            'ip_address' => '1.2.3.4',
        ]));

        $scrubbed = SentryScrubber::handle($event);

        $this->assertNull($scrubbed?->getUser());
    }

    #[Test]
    public function the_affected_venue_is_tagged(): void
    {
        $this->signInAsOwner('Demo Beach Bar');

        $event = $this->asTenant(static fn (): ?Event => SentryScrubber::handle(Event::createEvent()));

        // An id, not personal information — and the first question anybody
        // asks about an error in a multi-tenant system.
        $this->assertSame('demo-beach-bar', $event?->getTags()['tenant'] ?? null);
    }

    #[Test]
    public function with_no_venue_bound_it_simply_does_not_tag(): void
    {
        // Console work, a webhook, the scheduler. Reading the tenant must
        // never be the thing that throws inside `before_send`.
        $event = SentryScrubber::handle(Event::createEvent());

        $this->assertNotNull($event);
        $this->assertArrayNotHasKey('tenant', $event->getTags());
    }

    #[Test]
    public function the_noise_is_ignored_and_the_one_that_matters_is_not(): void
    {
        $ignored = config('sentry.ignore_exceptions');

        // A crawler asking for a page that does not exist, and somebody
        // typing a date wrong, are not faults.
        $this->assertContains(NotFoundHttpException::class, $ignored);
        $this->assertContains(ValidationException::class, $ignored);

        /*
         * And the one that must always arrive: it means a query was about to
         * cross between venues, which is the most serious thing this
         * application can do wrong.
         */
        $this->assertNotContains(TenantNotResolved::class, $ignored);
    }

    #[Test]
    public function query_bindings_are_not_attached_to_breadcrumbs(): void
    {
        // The statement says which query broke; the bindings are a guest's
        // email address.
        $this->assertTrue(config('sentry.breadcrumbs.sql_queries'));
        $this->assertFalse(config('sentry.breadcrumbs.sql_bindings'));
    }

    private function eventWithException(Throwable $exception): Event
    {
        $event = Event::createEvent();
        $event->setExceptions([new ExceptionDataBag($exception)]);

        return $event;
    }

    private function eventWithFrame(Frame $frame): Event
    {
        $event = Event::createEvent();

        $event->setExceptions([
            new ExceptionDataBag(new RuntimeException('Payment failed'), new Stacktrace([$frame])),
        ]);

        return $event;
    }
}
