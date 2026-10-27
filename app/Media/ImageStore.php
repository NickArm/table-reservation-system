<?php

declare(strict_types=1);

namespace App\Media;

use App\Enums\VenueImage;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Takes what came off a phone and makes it fit for a booking page
 * (SPEC §5.5, §30.1).
 *
 * The file an owner uploads is a 6 MB photograph straight out of a camera,
 * and the page it lands on is loaded by a tourist on a beach with one bar of
 * signal. So nothing is stored as it arrived: every image is decoded,
 * resized to the width in `config/media.php` and re-encoded.
 *
 * Re-encoding has three effects worth naming, and only the first is the one
 * anybody asks for:
 *
 *  - **the file gets small enough to serve**, which is the point
 *  - **every piece of metadata is stripped**, including the GPS coordinates a
 *    phone writes into a photograph. A venue's hero shot carrying the owner's
 *    home address is not a theoretical problem
 *  - **whatever the file actually was, what we serve is an image.** An upload
 *    that passed the extension check but is not a picture does not survive
 *    being decoded and written back out
 *
 * Done with GD rather than a package. GD is compiled into practically every
 * PHP on shared hosting, Imagick is not, and one dependency avoided is one
 * `composer install` that cannot fail on the host (§31.1).
 */
final readonly class ImageStore
{
    /**
     * Replaces a venue's image and returns the new path.
     *
     * The old file is removed afterwards rather than before: an upload that
     * fails halfway should leave the venue with the logo it already had
     * rather than with none.
     */
    public function put(
        VenueImage $kind,
        UploadedFile $file,
        string $tenantUuid,
        ?string $replacing = null,
    ): string {
        $path = $kind->pathFor($tenantUuid, (string) Str::uuid7());

        /*
         * No visibility argument. Laravel turns `public` into an
         * `ACL: public-read` header, and Cloudflare R2 — which is what this
         * disk is for in production (§30.1) — does not implement per-object
         * ACLs, so the upload fails outright. The disk's own configuration
         * decides, and on R2 the bucket is made readable once rather than
         * per file.
         */
        $written = Storage::disk($this->disk())->put($path, $this->encode($kind, $file));

        /*
         * Checked, because a Laravel disk with `throw` off answers a failed
         * write with a quiet `false`. Unchecked, the venue's row would hold
         * the path we meant to write, the booking page would show a broken
         * image, and nothing anywhere would say why. The `media` disk is
         * configured to throw for the same reason; this covers the case where
         * it is not.
         */
        if ($written === false) {
            throw new RuntimeException(
                'The image could not be saved. Please try again in a moment.'
            );
        }

        if ($replacing !== null && $replacing !== '' && $replacing !== $path) {
            $this->forget($replacing);
        }

        return $path;
    }

    /**
     * Removes a stored image, and does not mind if it has already gone.
     *
     * Object storage is a separate system that can be having a bad
     * afternoon, and a venue left unable to change its logo because a delete
     * failed would be the wrong way round: the row is what the page reads, so
     * the row wins and an orphaned file is logged.
     */
    public function forget(?string $path): void
    {
        if ($path === null || $path === '' || str_starts_with($path, 'http')) {
            return;
        }

        try {
            Storage::disk($this->disk())->delete($path);
        } catch (Throwable $exception) {
            Log::warning('Could not delete a venue image.', [
                'path' => $path,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Decodes, turns the right way up, resizes and re-encodes.
     */
    private function encode(VenueImage $kind, UploadedFile $file): string
    {
        $contents = (string) file_get_contents($file->getRealPath());

        if (! function_exists('imagecreatefromstring')) {
            /*
             * GD is missing, which on a modern host it is not. Storing the
             * original is the lesser evil: the owner gets a logo instead of
             * an error they cannot act on, the upload limit bounds the
             * damage, and the log line says why the page got heavier.
             */
            Log::warning('GD is unavailable, so a venue image was stored unprocessed.', [
                'kind' => $kind->value,
                'bytes' => strlen($contents),
            ]);

            return $contents;
        }

        $image = @imagecreatefromstring($contents);

        if (! $image instanceof GdImage) {
            // Reached only by a file that passed validation and still is not
            // an image. The message is for the owner, not the log.
            throw new RuntimeException('That file is not an image we can read.');
        }

        try {
            $image = $this->turnUpright($image, $file->getRealPath());
            $image = $this->resize($image, $kind->maxWidth());

            return $this->render($kind, $image);
        } finally {
            imagedestroy($image);
        }
    }

    /**
     * Honours the rotation flag a phone writes instead of rotating pixels.
     *
     * Without this the most likely visible bug in the whole feature: a hero
     * photograph taken in portrait appears on its side, looks like our fault,
     * and the owner has no way to fix it.
     */
    private function turnUpright(GdImage $image, string $path): GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);

        if (! is_array($exif) || ! isset($exif['Orientation'])) {
            return $image;
        }

        $rotation = match ((int) $exif['Orientation']) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($rotation === 0) {
            return $image;
        }

        $turned = imagerotate($image, $rotation, 0);

        if (! $turned instanceof GdImage) {
            return $image;
        }

        imagedestroy($image);

        return $turned;
    }

    private function resize(GdImage $image, int $maxWidth): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);

        // Only ever down. Stretching a small logo to 600px makes it blurry
        // and bigger, which is both halves of wrong.
        if ($width <= $maxWidth || $width === 0) {
            return $image;
        }

        $targetHeight = max(1, (int) round($height * ($maxWidth / $width)));

        $resized = imagecreatetruecolor($maxWidth, $targetHeight);

        if (! $resized instanceof GdImage) {
            return $image;
        }

        // Keeps a logo's transparent background transparent through the
        // resize; without these two calls it comes out black.
        imagealphablending($resized, false);
        imagesavealpha($resized, true);

        imagecopyresampled(
            $resized, $image,
            0, 0, 0, 0,
            $maxWidth, $targetHeight,
            $width, $height,
        );

        imagedestroy($image);

        return $resized;
    }

    private function render(VenueImage $kind, GdImage $image): string
    {
        ob_start();

        if ($kind->keepsTransparency()) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            imagepng($image, null, 9);
        } else {
            /*
             * A photograph has nothing to keep transparent, and a PNG of one
             * is several times the size. Anything transparent in it is
             * flattened onto white first, because JPEG has no alpha channel
             * and the alternative is black edges.
             */
            $flattened = imagecreatetruecolor(imagesx($image), imagesy($image));

            if ($flattened instanceof GdImage) {
                $white = (int) imagecolorallocate($flattened, 255, 255, 255);
                imagefilledrectangle($flattened, 0, 0, imagesx($image), imagesy($image), $white);
                imagecopy($flattened, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));

                imagejpeg($flattened, null, (int) config('media.hero.quality'));
                imagedestroy($flattened);
            } else {
                imagejpeg($image, null, (int) config('media.hero.quality'));
            }
        }

        return (string) ob_get_clean();
    }

    private function disk(): string
    {
        return (string) config('media.disk');
    }
}
