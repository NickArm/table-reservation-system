<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Enums\VenueImage;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\TestCase;

/**
 * Logo, hero image and the one colour (SPEC §5.5, §27).
 *
 * The uploads are the part worth testing properly. A file an owner picks off
 * a phone is large, often sideways, and carries the coordinates of wherever
 * it was taken — and the thing it is served from is a public page.
 */
final class BrandingTest extends TestCase
{
    use ActsAsVenueOwner;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->signInAsOwner('Demo Beach Bar');
    }

    #[Test]
    public function the_screen_opens_and_says_what_each_image_is_for(): void
    {
        $this->get(route('dashboard.branding.edit'))
            ->assertOk()
            ->assertSee('Logo')
            ->assertSee('Hero image')
            ->assertSee('Accent');
    }

    #[Test]
    public function a_logo_is_stored_under_the_accounts_own_folder(): void
    {
        $this->patch(route('dashboard.branding.update'), [
            'hero_overlay' => 30,
            'logo' => UploadedFile::fake()->image('logo.png', 900, 900),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $path = (string) $this->venue()->logo_path;

        // By uuid rather than id: the path reveals nothing about how many
        // venues there are, and deleting an account is one folder (§29.3).
        $this->assertStringStartsWith('tenants/'.$this->tenant->uuid.'/venue/logo/', $path);

        Storage::disk('public')->assertExists($path);
    }

    #[Test]
    public function a_photograph_larger_than_the_page_needs_is_resized(): void
    {
        $this->patch(route('dashboard.branding.update'), [
            'hero_overlay' => 30,
            'hero' => UploadedFile::fake()->image('terrace.jpg', 4000, 3000),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $stored = Storage::disk('public')->get((string) $this->venue()->hero_path);

        $size = getimagesizefromstring((string) $stored);

        // The whole point: a tourist on a beach with one bar of signal is not
        // downloading a 4000px photograph.
        $this->assertSame((int) config('media.hero.max_width'), $size[0]);
    }

    #[Test]
    public function a_small_logo_is_not_stretched(): void
    {
        $this->patch(route('dashboard.branding.update'), [
            'hero_overlay' => 30,
            'logo' => UploadedFile::fake()->image('logo.png', 120, 120),
        ])->assertRedirect();

        $stored = Storage::disk('public')->get((string) $this->venue()->logo_path);

        // Only ever down. Blowing a 120px logo up to 600 makes it blurry and
        // bigger, which is both halves of wrong.
        $this->assertSame(120, getimagesizefromstring((string) $stored)[0]);
    }

    #[Test]
    public function a_hero_is_written_as_a_jpeg_and_a_logo_as_a_png(): void
    {
        $this->patch(route('dashboard.branding.update'), [
            'hero_overlay' => 30,
            'logo' => UploadedFile::fake()->image('logo.jpg', 400, 400),
            'hero' => UploadedFile::fake()->image('terrace.png', 1200, 800),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $venue = $this->venue();

        /*
         * Whatever arrived. A logo keeps its transparent background, which
         * means PNG; a photograph has nothing to keep transparent and is a
         * third of the size as JPEG.
         */
        $this->assertStringEndsWith('.png', (string) $venue->logo_path);
        $this->assertStringEndsWith('.jpg', (string) $venue->hero_path);

        $this->assertSame(
            IMAGETYPE_PNG,
            getimagesizefromstring((string) Storage::disk('public')->get((string) $venue->logo_path))[2],
        );
        $this->assertSame(
            IMAGETYPE_JPEG,
            getimagesizefromstring((string) Storage::disk('public')->get((string) $venue->hero_path))[2],
        );
    }

    #[Test]
    public function replacing_an_image_removes_the_one_it_replaced(): void
    {
        $this->patch(route('dashboard.branding.update'), [
            'hero_overlay' => 30,
            'logo' => UploadedFile::fake()->image('first.png', 400, 400),
        ])->assertRedirect();

        $first = (string) $this->venue()->logo_path;

        $this->patch(route('dashboard.branding.update'), [
            'hero_overlay' => 30,
            'logo' => UploadedFile::fake()->image('second.png', 400, 400),
        ])->assertRedirect();

        $second = (string) $this->venue()->logo_path;

        $this->assertNotSame($first, $second);

        // Otherwise a venue that redesigns its logo four times pays for five
        // of them for ever.
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    #[Test]
    public function an_empty_file_input_leaves_the_image_alone(): void
    {
        $this->patch(route('dashboard.branding.update'), [
            'hero_overlay' => 30,
            'logo' => UploadedFile::fake()->image('logo.png', 400, 400),
        ])->assertRedirect();

        $before = (string) $this->venue()->logo_path;

        // Saving the colour should not wipe the logo. "I did not change
        // this" is what an empty file input means, never "delete it".
        $this->patch(route('dashboard.branding.update'), [
            'hero_overlay' => 50,
            'accent_color' => '#b45309',
        ])->assertRedirect();

        $venue = $this->venue();

        $this->assertSame($before, (string) $venue->logo_path);
        $this->assertSame('#b45309', $venue->accent_color);
        $this->assertSame(50, (int) $venue->hero_overlay);
    }

    #[Test]
    public function ticking_remove_clears_it_and_deletes_the_file(): void
    {
        $this->patch(route('dashboard.branding.update'), [
            'hero_overlay' => 30,
            'logo' => UploadedFile::fake()->image('logo.png', 400, 400),
        ])->assertRedirect();

        $path = (string) $this->venue()->logo_path;

        $this->patch(route('dashboard.branding.update'), [
            'hero_overlay' => 30,
            'remove' => [VenueImage::Logo->value],
        ])->assertRedirect();

        $this->assertNull($this->venue()->logo_path);
        Storage::disk('public')->assertMissing($path);
    }

    #[Test]
    public function choosing_a_new_file_wins_over_ticking_remove(): void
    {
        $this->patch(route('dashboard.branding.update'), [
            'hero_overlay' => 30,
            'logo' => UploadedFile::fake()->image('old.png', 400, 400),
        ])->assertRedirect();

        // Somebody replacing an image: they tick remove and choose a file in
        // the same submission. The obvious reading is the new one.
        $this->patch(route('dashboard.branding.update'), [
            'hero_overlay' => 30,
            'remove' => [VenueImage::Logo->value],
            'logo' => UploadedFile::fake()->image('new.png', 400, 400),
        ])->assertRedirect();

        $this->assertNotNull($this->venue()->logo_path);
    }

    #[Test]
    public function an_svg_is_refused_however_it_is_offered(): void
    {
        /*
         * The one upload rule that is a security rule rather than a size
         * rule. An SVG is a document that can carry script, and served from
         * our own domain that is stored cross-site scripting rather than a
         * picture.
         */
        $this->patch(route('dashboard.branding.update'), [
            'hero_overlay' => 30,
            'logo' => UploadedFile::fake()->create(
                'logo.svg',
                4,
                'image/svg+xml',
            ),
        ])->assertSessionHasErrors('logo');

        $this->assertNull($this->venue()->logo_path);
    }

    #[Test]
    public function a_file_that_is_not_an_image_is_refused(): void
    {
        $this->patch(route('dashboard.branding.update'), [
            'hero_overlay' => 30,
            'hero' => UploadedFile::fake()->create('menu.pdf', 40, 'application/pdf'),
        ])->assertSessionHasErrors('hero');
    }

    #[Test]
    public function the_accent_colour_has_to_be_a_colour(): void
    {
        // It goes straight into a style attribute on the public page, so a
        // field that took arbitrary text would be a way to write CSS into
        // somebody else's booking page.
        $this->patch(route('dashboard.branding.update'), [
            'hero_overlay' => 30,
            'accent_color' => 'red; background:url(http://evil.test)',
        ])->assertSessionHasErrors('accent_color');
    }

    #[Test]
    public function the_shade_over_the_photograph_stays_within_reason(): void
    {
        $this->patch(route('dashboard.branding.update'), ['hero_overlay' => 95])
            ->assertSessionHasErrors('hero_overlay');
    }

    #[Test]
    public function the_booking_page_shows_what_was_uploaded(): void
    {
        $this->patch(route('dashboard.branding.update'), [
            'hero_overlay' => 30,
            'accent_color' => '#b45309',
            'hero' => UploadedFile::fake()->image('terrace.jpg', 1600, 900),
        ])->assertRedirect();

        $hero = (string) $this->venue()->hero_path;

        $this->get(route('public.booking', $this->tenant))
            ->assertOk()
            ->assertSee($hero, false)
            ->assertSee('#b45309', false);
    }

    #[Test]
    public function another_venue_cannot_touch_this_ones_branding(): void
    {
        $this->patch(route('dashboard.branding.update'), [
            'hero_overlay' => 30,
            'logo' => UploadedFile::fake()->image('logo.png', 400, 400),
        ])->assertRedirect();

        $mine = (string) $this->venue()->logo_path;

        $this->signInAsOwner('Another Place');

        $this->patch(route('dashboard.branding.update'), [
            'hero_overlay' => 10,
            'logo' => UploadedFile::fake()->image('theirs.png', 400, 400),
        ])->assertRedirect();

        // Each resolves its own venue from the signed-in user, so there is no
        // address to edit in order to reach somebody else's.
        $theirs = (string) $this->venue()->logo_path;

        $this->assertNotSame($mine, $theirs);
        Storage::disk('public')->assertExists($mine);
    }

    private function venue(): Venue
    {
        return $this->asTenant(static fn (): Venue => Venue::query()->firstOrFail());
    }
}
