<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\QrCode;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The encoder is ours, so the tests have to earn that.
 *
 * A QR that fails is a guest standing at a door, and "it looked square" is not
 * evidence. These checks pin the structure a scanner actually looks for — the
 * three finder patterns, the timing lines, the dark module, the symbol size
 * that the data chose — and then the parts that silently go wrong: the format
 * bits, which are what tell a scanner how the symbol was masked.
 *
 * Every payload here has also been decoded by an outside reader during
 * development, and the matrices compared module by module against an
 * independent encoder across versions 1 to 10. What is left in the suite is
 * what can be checked without either of those being installed.
 */
final class QrCodeTest extends TestCase
{
    #[Test]
    public function the_symbol_carries_the_patterns_a_scanner_looks_for(): void
    {
        $matrix = QrCode::matrix('https://tablereserved.com/v/A4F7K9/8fj2kd9slq03mxza');
        $size = count($matrix);

        // Three finder patterns: top left, top right, bottom left. The fourth
        // corner is deliberately empty — that is how a scanner works out which
        // way up the symbol is.
        foreach ([[0, 0], [0, $size - 7], [$size - 7, 0]] as [$top, $left]) {
            $this->assertTrue($matrix[$top][$left], 'A finder pattern is missing its corner.');
            $this->assertTrue($matrix[$top + 3][$left + 3], 'A finder pattern is missing its centre.');
            $this->assertFalse($matrix[$top + 1][$left + 1], 'A finder pattern is missing its ring.');
        }

        // Timing patterns: alternating modules along row and column six, which
        // is what lets a scanner count the grid.
        for ($i = 8; $i < $size - 8; $i++) {
            $this->assertSame($i % 2 === 0, $matrix[6][$i], "Row 6 is wrong at {$i}.");
            $this->assertSame($i % 2 === 0, $matrix[$i][6], "Column 6 is wrong at {$i}.");
        }

        $this->assertTrue($matrix[$size - 8][8], 'The dark module is not set.');
    }

    #[Test]
    public function the_format_bits_agree_with_themselves(): void
    {
        $matrix = QrCode::matrix('https://tablereserved.com/v/A4F7K9/8fj2kd9slq03mxza');
        $size = count($matrix);

        // The format information is written twice, in two different places, so
        // that a symbol with one corner damaged still reads. If our two copies
        // disagree, every scanner will take the first one it trusts and then
        // unmask the data wrongly.
        $first = 0;
        $second = 0;

        for ($i = 0; $i < 15; $i++) {
            $a = match (true) {
                $i < 6 => $matrix[$i][8],
                $i < 8 => $matrix[$i + 1][8],
                default => $matrix[$size - 15 + $i][8],
            };

            $b = match (true) {
                $i < 8 => $matrix[8][$size - 1 - $i],
                $i === 8 => $matrix[8][7],
                default => $matrix[8][14 - $i],
            };

            $first |= ($a ? 1 : 0) << $i;
            $second |= ($b ? 1 : 0) << $i;
        }

        $this->assertSame($first, $second, 'The two copies of the format information differ.');

        // Unmasked, the top two bits say which error correction level this is.
        // Level M is 00, and anything else means the wrong table was used.
        $unmasked = $first ^ 0x5412;

        $this->assertSame(0, ($unmasked >> 13) & 0b11, 'The symbol does not claim error correction level M.');
        $this->assertLessThan(8, ($unmasked >> 10) & 0b111);
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function lengths(): array
    {
        // Byte mode at level M: the version is chosen by how much has to fit,
        // and the symbol is 17 + 4v modules across.
        return [
            'a single character' => [1, 21],
            'a short voucher url' => [51, 33],
            'the longest version 4 fits' => [62, 33],
            'one more needs version 5' => [63, 37],
            'the most this encoder takes' => [213, 57],
        ];
    }

    #[Test]
    #[DataProvider('lengths')]
    public function the_version_grows_with_the_payload(int $length, int $expectedSize): void
    {
        $matrix = QrCode::matrix(str_repeat('a', $length));

        $this->assertCount($expectedSize, $matrix);
        $this->assertCount($expectedSize, $matrix[0]);
    }

    #[Test]
    public function too_much_data_is_refused_rather_than_truncated(): void
    {
        $this->expectException(InvalidArgumentException::class);

        QrCode::matrix(str_repeat('a', 214));
    }

    #[Test]
    public function nothing_to_encode_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        QrCode::matrix('');
    }

    #[Test]
    public function the_png_is_a_real_png_and_small_enough_to_email(): void
    {
        $png = QrCode::png('https://tablereserved.com/v/A4F7K9/8fj2kd9slq03mxza', 7);

        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $png);
        $this->assertStringEndsWith('IEND'.pack('N', crc32('IEND')), $png);

        // IHDR: width, height, bit depth, colour type. One bit, greyscale —
        // which is why a voucher QR costs a few hundred bytes rather than tens
        // of thousands, and why neither GD nor Imagick is needed.
        $header = unpack('Nwidth/Nheight/Cdepth/Ccolour', substr($png, 16, 10));

        $this->assertIsArray($header);
        $this->assertSame(1, $header['depth']);
        $this->assertSame(0, $header['colour']);

        // 33 modules plus a four module quiet zone each side, at seven pixels.
        $this->assertSame((33 + 8) * 7, $header['width']);
        $this->assertSame($header['width'], $header['height']);

        $this->assertLessThan(2000, strlen($png), 'The QR is far larger than it should be.');
    }

    #[Test]
    public function the_svg_draws_every_dark_module_and_nothing_else(): void
    {
        $data = 'HELLO';
        $matrix = QrCode::matrix($data);
        $svg = QrCode::svg($data, 200);

        $dark = 0;

        foreach ($matrix as $row) {
            $dark += count(array_filter($row));
        }

        $this->assertStringContainsString('viewBox="0 0 29 29"', $svg);
        $this->assertSame($dark, substr_count($svg, 'h1v1h-1z'));
    }
}
