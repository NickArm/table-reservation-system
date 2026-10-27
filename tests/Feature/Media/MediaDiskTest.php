<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use App\Enums\VenueImage;
use App\Media\ImageStore;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Where venue images actually live (SPEC §30.1).
 *
 * The disk is configuration rather than code, which is exactly why it is
 * worth a test: every setting in it is a trap somebody has already fallen
 * into with R2, and getting one wrong produces a booking page full of broken
 * images rather than an error anybody would see.
 */
final class MediaDiskTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_developer_machine_needs_no_bucket(): void
    {
        // The default, and the reason it is the default: a logo should show
        // on a laptop without anybody creating an account anywhere.
        $this->assertSame('public', config('media.disk'));
    }

    #[Test]
    public function the_media_disk_exists_and_is_shaped_for_r2(): void
    {
        $disk = config('filesystems.disks.media');

        $this->assertIsArray($disk, 'Without this disk, MEDIA_DISK=media is not "one env var away".');
        $this->assertSame('s3', $disk['driver']);

        // R2 has one region and calls it `auto`. Anything else is accepted
        // and quietly ignored, and the first sign of it is a signature
        // mismatch rather than a useful error.
        $this->assertSame('auto', $disk['region']);
    }

    #[Test]
    public function the_media_disk_never_fails_quietly(): void
    {
        /*
         * The setting that matters most. With `throw` off, a failed upload
         * answers with `false`, the venue's row keeps the path we meant to
         * write, and the booking page shows a broken image with nothing in
         * the log to explain it.
         */
        $this->assertTrue(config('filesystems.disks.media.throw'));
    }

    #[Test]
    public function the_media_disk_asks_for_no_per_object_acl(): void
    {
        /*
         * Laravel turns `visibility: public` into an `ACL: public-read`
         * header, and R2 does not implement per-object ACLs — the upload
         * fails outright. On R2 the bucket is made readable once, and
         * `MEDIA_URL` is how a browser reaches it.
         */
        $this->assertArrayNotHasKey('visibility', config('filesystems.disks.media'));
        $this->assertArrayHasKey('url', config('filesystems.disks.media'));
    }

    #[Test]
    public function a_refused_upload_is_not_silently_accepted(): void
    {
        /*
         * A fake disk that answers every write with `false`, which is what a
         * real one does when it is refused and `throw` is off. The point is
         * that a venue must never end up with a path pointing at nothing.
         */
        $disk = $this->mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturnFalse();

        Storage::shouldReceive('disk')->with('public')->andReturn($disk);

        $this->expectException(RuntimeException::class);

        app(ImageStore::class)->put(
            VenueImage::Logo,
            UploadedFile::fake()->image('logo.png', 200, 200),
            'some-uuid',
        );
    }

    #[Test]
    public function a_path_is_stored_rather_than_a_url(): void
    {
        Storage::fake('public');

        $path = app(ImageStore::class)->put(
            VenueImage::Logo,
            UploadedFile::fake()->image('logo.png', 200, 200),
            'some-uuid',
        );

        /*
         * What lets the disk move from a laptop to R2 without rewriting a
         * single row: nothing anywhere keeps a URL. `Venue::logoUrl()` builds
         * one from whichever disk is configured when the page is rendered.
         */
        $this->assertStringStartsNotWith('http', $path);
        $this->assertSame('tenants/some-uuid/venue/logo', dirname($path));
    }
}
