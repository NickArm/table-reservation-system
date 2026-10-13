<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * A QR encoder, written here rather than pulled in.
 *
 * Three reasons, in order of weight:
 *
 *  1. The voucher QR has to reach a phone through an email client, and every
 *     client worth counting strips SVG and inline data URIs. That leaves a
 *     real PNG — which the usual packages produce only through GD or Imagick.
 *     Neither is guaranteed on the shared hosting this is meant to run on.
 *  2. What we encode is always one short URL: byte mode, error correction M,
 *     under a hundred characters. That is a small, fixed corner of the format,
 *     not the whole specification.
 *  3. A QR that fails is a guest standing at a door, so the code that makes it
 *     should be ours to read and fix.
 *
 * Scope is deliberately narrow: byte mode, level M, versions 1 to 10 (up to
 * 213 characters). Anything longer throws rather than silently degrading.
 *
 * Correctness is not taken on trust — tests/Unit/QrCodeTest decodes what this
 * produces and compares it with what went in.
 */
final class QrCode
{
    /** Error correction level M: recovers about 15% of a damaged symbol. */
    private const EC_LEVEL_BITS = 0b00;

    private const MODE_BYTE = 0b0100;

    /** Generator polynomials for the format and version bit patterns. */
    private const G15 = 0x537;

    private const G15_MASK = 0x5412;

    private const G18 = 0x1F25;

    /**
     * Per version, at error correction level M:
     *
     *   [error correction codewords per block, [[blocks, data codewords per block], ...]]
     *
     * The numbers come from ISO/IEC 18004 table 9 and are checked by the test
     * suite against the total codeword count each version holds.
     *
     * @var array<int, array{int, array<int, array{int, int}>}>
     */
    private const BLOCKS = [
        1 => [10, [[1, 16]]],
        2 => [16, [[1, 28]]],
        3 => [26, [[1, 44]]],
        4 => [18, [[2, 32]]],
        5 => [24, [[2, 43]]],
        6 => [16, [[4, 27]]],
        7 => [18, [[4, 31]]],
        8 => [22, [[2, 38], [2, 39]]],
        9 => [22, [[3, 36], [2, 37]]],
        10 => [26, [[4, 43], [1, 44]]],
    ];

    /**
     * Centres of the alignment patterns, per version.
     *
     * @var array<int, array<int, int>>
     */
    private const ALIGNMENT = [
        1 => [],
        2 => [6, 18],
        3 => [6, 22],
        4 => [6, 26],
        5 => [6, 30],
        6 => [6, 34],
        7 => [6, 22, 38],
        8 => [6, 24, 42],
        9 => [6, 26, 46],
        10 => [6, 28, 50],
    ];

    /** Unused bits left over after interleaving, per version. */
    private const REMAINDER_BITS = [
        1 => 0, 2 => 7, 3 => 7, 4 => 7, 5 => 7, 6 => 7,
        7 => 0, 8 => 0, 9 => 0, 10 => 0,
    ];

    /** @var array<int, int>|null */
    private static ?array $exp = null;

    /** @var array<int, int>|null */
    private static ?array $log = null;

    /**
     * The symbol as rows of booleans, true meaning a dark module.
     *
     * No quiet zone: that belongs to whatever draws the thing.
     *
     * @return array<int, array<int, bool>>
     */
    public static function matrix(string $data): array
    {
        if ($data === '') {
            throw new InvalidArgumentException('There is nothing to encode.');
        }

        $version = self::versionFor($data);
        $codewords = self::codewords($data, $version);
        $size = 17 + 4 * $version;

        [$modules, $reserved] = self::functionPatterns($version, $size);

        $best = null;
        $bestPenalty = PHP_INT_MAX;

        for ($mask = 0; $mask < 8; $mask++) {
            $candidate = self::placeData($modules, $reserved, $codewords, $size, $mask);
            self::placeFormat($candidate, $size, $mask);

            $penalty = self::penalty($candidate, $size);

            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $best = $candidate;
            }
        }

        /** @var array<int, array<int, bool>> $best */
        return $best;
    }

    /**
     * A PNG of the symbol, built by hand so that neither GD nor Imagick has to
     * be present. Monochrome, one bit a pixel, which keeps a voucher QR around
     * 300 bytes — small enough to embed in an email without a second thought.
     *
     * @param  int  $scale  pixels per module
     * @param  int  $quiet  modules of white margin; the standard asks for four
     */
    public static function png(string $data, int $scale = 6, int $quiet = 4): string
    {
        $matrix = self::matrix($data);
        $modules = count($matrix) + 2 * $quiet;
        $pixels = $modules * $scale;

        $raw = '';

        for ($y = 0; $y < $pixels; $y++) {
            // Filter type 0 (none) at the head of every scanline.
            $row = "\x00";
            $bits = '';

            for ($x = 0; $x < $pixels; $x++) {
                $row_ = intdiv($y, $scale) - $quiet;
                $col = intdiv($x, $scale) - $quiet;

                $dark = isset($matrix[$row_][$col]) && $matrix[$row_][$col];

                // 0 is black in a one-bit greyscale image.
                $bits .= $dark ? '0' : '1';
            }

            foreach (str_split(str_pad($bits, (int) (ceil(strlen($bits) / 8) * 8), '1'), 8) as $byte) {
                $row .= chr((int) bindec($byte));
            }

            $raw .= $row;
        }

        return "\x89PNG\r\n\x1a\n"
            .self::chunk('IHDR', pack('NNCCCCC', $pixels, $pixels, 1, 0, 0, 0, 0))
            .self::chunk('IDAT', gzcompress($raw, 9))
            .self::chunk('IEND', '');
    }

    /**
     * The same symbol as SVG, for anywhere a browser is doing the drawing.
     */
    public static function svg(string $data, int $size = 240, int $quiet = 4): string
    {
        $matrix = self::matrix($data);
        $modules = count($matrix) + 2 * $quiet;

        $path = '';

        foreach ($matrix as $row => $cells) {
            foreach ($cells as $col => $dark) {
                if ($dark) {
                    $path .= 'M'.($col + $quiet).','.($row + $quiet).'h1v1h-1z';
                }
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="'.$size.'" height="'.$size.'" '
            .'viewBox="0 0 '.$modules.' '.$modules.'" shape-rendering="crispEdges" role="img">'
            .'<rect width="'.$modules.'" height="'.$modules.'" fill="#fff"/>'
            .'<path d="'.$path.'" fill="#000"/>'
            .'</svg>';
    }

    // -----------------------------------------------------------------
    // Encoding
    // -----------------------------------------------------------------

    private static function versionFor(string $data): int
    {
        $length = strlen($data);

        foreach (array_keys(self::BLOCKS) as $version) {
            // The character count indicator widens at version 10.
            $countBits = $version < 10 ? 8 : 16;
            $capacity = self::dataCodewords($version) * 8 - 4 - $countBits;

            if ($length * 8 <= $capacity) {
                return $version;
            }
        }

        throw new InvalidArgumentException(
            'Too long for a version 10 symbol: '.$length.' bytes, 213 is the most this encoder takes.'
        );
    }

    private static function dataCodewords(int $version): int
    {
        $total = 0;

        foreach (self::BLOCKS[$version][1] as [$blocks, $perBlock]) {
            $total += $blocks * $perBlock;
        }

        return $total;
    }

    /**
     * Mode, length, payload, padding, error correction, interleaved.
     *
     * @return array<int, int>
     */
    private static function codewords(string $data, int $version): array
    {
        $countBits = $version < 10 ? 8 : 16;

        $bits = str_pad(decbin(self::MODE_BYTE), 4, '0', STR_PAD_LEFT)
            .str_pad(decbin(strlen($data)), $countBits, '0', STR_PAD_LEFT);

        foreach (str_split($data) as $character) {
            $bits .= str_pad(decbin(ord($character)), 8, '0', STR_PAD_LEFT);
        }

        $capacity = self::dataCodewords($version) * 8;

        // Terminator: up to four zeroes, then out to a whole codeword.
        $bits .= str_repeat('0', min(4, $capacity - strlen($bits)));
        $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);

        // Then the two pad codewords, alternating, until the block is full.
        $pad = ['11101100', '00010001'];
        $index = 0;

        while (strlen($bits) < $capacity) {
            $bits .= $pad[$index % 2];
            $index++;
        }

        $all = array_map(static fn (string $byte): int => (int) bindec($byte), str_split($bits, 8));

        return self::interleave($all, $version);
    }

    /**
     * Split into blocks, give each its own error correction, then read the
     * blocks column by column — which is what makes a QR survive a thumbprint
     * over one corner.
     *
     * @param  array<int, int>  $data
     * @return array<int, int>
     */
    private static function interleave(array $data, int $version): array
    {
        [$ecPerBlock, $structure] = self::BLOCKS[$version];

        $dataBlocks = [];
        $ecBlocks = [];
        $offset = 0;

        foreach ($structure as [$blocks, $perBlock]) {
            for ($i = 0; $i < $blocks; $i++) {
                $block = array_slice($data, $offset, $perBlock);
                $offset += $perBlock;

                $dataBlocks[] = $block;
                $ecBlocks[] = self::errorCorrection($block, $ecPerBlock);
            }
        }

        $out = [];

        $longest = max(array_map('count', $dataBlocks));

        for ($i = 0; $i < $longest; $i++) {
            foreach ($dataBlocks as $block) {
                if (isset($block[$i])) {
                    $out[] = $block[$i];
                }
            }
        }

        for ($i = 0; $i < $ecPerBlock; $i++) {
            foreach ($ecBlocks as $block) {
                $out[] = $block[$i];
            }
        }

        return $out;
    }

    /**
     * Reed-Solomon over GF(256), the arithmetic that lets a damaged symbol
     * still read.
     *
     * @param  array<int, int>  $block
     * @return array<int, int>
     */
    private static function errorCorrection(array $block, int $count): array
    {
        self::buildTables();

        $generator = self::generator($count);
        $remainder = array_merge(array_values($block), array_fill(0, $count, 0));

        for ($i = 0; $i < count($block); $i++) {
            $factor = $remainder[$i];

            if ($factor === 0) {
                continue;
            }

            $factor = self::$log[$factor];

            foreach ($generator as $offset => $term) {
                $remainder[$i + $offset] ^= self::$exp[($term + $factor) % 255];
            }
        }

        return array_slice($remainder, count($block));
    }

    /**
     * The generator polynomial, as exponents of alpha.
     *
     * @return array<int, int>
     */
    private static function generator(int $degree): array
    {
        $polynomial = [0];

        for ($i = 0; $i < $degree; $i++) {
            $next = array_fill(0, count($polynomial) + 1, 0);

            foreach ($polynomial as $index => $term) {
                // Multiplying by (x + alpha^i): the x shifts a coefficient one
                // place down the polynomial, the constant scales it in place.
                $next[$index] ^= self::$exp[$term];
                $next[$index + 1] ^= self::$exp[($term + $i) % 255];
            }

            $polynomial = array_map(static fn (int $value): int => self::$log[$value], $next);
        }

        return $polynomial;
    }

    private static function buildTables(): void
    {
        if (self::$exp !== null) {
            return;
        }

        $exp = array_fill(0, 256, 0);
        $log = array_fill(0, 256, 0);

        $value = 1;

        for ($i = 0; $i < 255; $i++) {
            $exp[$i] = $value;
            $log[$value] = $i;

            $value <<= 1;

            // The primitive polynomial x^8 + x^4 + x^3 + x^2 + 1.
            if ($value & 0x100) {
                $value ^= 0x11D;
            }
        }

        $exp[255] = $exp[0];

        self::$exp = $exp;
        self::$log = $log;
    }

    // -----------------------------------------------------------------
    // Layout
    // -----------------------------------------------------------------

    /**
     * The parts of the symbol a scanner uses to find and square it up. These
     * modules are fixed, and the mask must never touch them.
     *
     * @return array{array<int, array<int, bool|null>>, array<int, array<int, bool>>}
     */
    private static function functionPatterns(int $version, int $size): array
    {
        /** @var array<int, array<int, bool|null>> $modules */
        $modules = array_fill(0, $size, array_fill(0, $size, null));

        /** @var array<int, array<int, bool>> $reserved */
        $reserved = array_fill(0, $size, array_fill(0, $size, false));

        $finders = [[0, 0], [0, $size - 7], [$size - 7, 0]];

        foreach ($finders as [$top, $left]) {
            for ($r = -1; $r <= 7; $r++) {
                for ($c = -1; $c <= 7; $c++) {
                    $row = $top + $r;
                    $col = $left + $c;

                    if ($row < 0 || $row >= $size || $col < 0 || $col >= $size) {
                        continue;
                    }

                    $inside = $r >= 0 && $r <= 6 && $c >= 0 && $c <= 6;

                    $modules[$row][$col] = $inside && (
                        $r === 0 || $r === 6 || $c === 0 || $c === 6
                        || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4)
                    );

                    $reserved[$row][$col] = true;
                }
            }
        }

        foreach (self::ALIGNMENT[$version] as $centreRow) {
            foreach (self::ALIGNMENT[$version] as $centreCol) {
                // The three corners already carry finder patterns.
                if ($reserved[$centreRow][$centreCol]) {
                    continue;
                }

                for ($r = -2; $r <= 2; $r++) {
                    for ($c = -2; $c <= 2; $c++) {
                        $modules[$centreRow + $r][$centreCol + $c] =
                            abs($r) === 2 || abs($c) === 2 || ($r === 0 && $c === 0);
                        $reserved[$centreRow + $r][$centreCol + $c] = true;
                    }
                }
            }
        }

        for ($i = 8; $i < $size - 8; $i++) {
            $modules[6][$i] = $i % 2 === 0;
            $reserved[6][$i] = true;

            $modules[$i][6] = $i % 2 === 0;
            $reserved[$i][6] = true;
        }

        // The dark module: always set, for no reason beyond the standard
        // saying so.
        $modules[$size - 8][8] = true;
        $reserved[$size - 8][8] = true;

        for ($i = 0; $i < 9; $i++) {
            if (! $reserved[8][$i]) {
                $modules[8][$i] = false;
                $reserved[8][$i] = true;
            }

            if (! $reserved[$i][8]) {
                $modules[$i][8] = false;
                $reserved[$i][8] = true;
            }
        }

        for ($i = 0; $i < 8; $i++) {
            $reserved[8][$size - 1 - $i] = true;
            $modules[8][$size - 1 - $i] ??= false;

            $reserved[$size - 1 - $i][8] = true;
            $modules[$size - 1 - $i][8] ??= false;
        }

        if ($version >= 7) {
            $bits = self::versionBits($version);

            for ($i = 0; $i < 18; $i++) {
                $bit = (($bits >> $i) & 1) === 1;

                $modules[intdiv($i, 3)][$i % 3 + $size - 11] = $bit;
                $reserved[intdiv($i, 3)][$i % 3 + $size - 11] = true;

                $modules[$i % 3 + $size - 11][intdiv($i, 3)] = $bit;
                $reserved[$i % 3 + $size - 11][intdiv($i, 3)] = true;
            }
        }

        return [$modules, $reserved];
    }

    /**
     * Lay the codewords into the symbol, two columns at a time from the bottom
     * right, going up and down in turn and stepping over anything reserved.
     *
     * @param  array<int, array<int, bool|null>>  $modules
     * @param  array<int, array<int, bool>>  $reserved
     * @param  array<int, int>  $codewords
     * @return array<int, array<int, bool>>
     */
    private static function placeData(array $modules, array $reserved, array $codewords, int $size, int $mask): array
    {
        $bits = '';

        foreach ($codewords as $codeword) {
            $bits .= str_pad(decbin($codeword), 8, '0', STR_PAD_LEFT);
        }

        $version = ($size - 17) / 4;
        $bits .= str_repeat('0', self::REMAINDER_BITS[(int) $version]);

        $index = 0;
        $row = $size - 1;
        $upward = true;

        for ($right = $size - 1; $right > 0; $right -= 2) {
            // Column six carries the vertical timing pattern and is skipped
            // entirely rather than stepped over.
            if ($right === 6) {
                $right--;
            }

            while (true) {
                foreach ([$right, $right - 1] as $col) {
                    if ($reserved[$row][$col]) {
                        continue;
                    }

                    $dark = isset($bits[$index]) && $bits[$index] === '1';
                    $index++;

                    if (self::maskApplies($mask, $row, $col)) {
                        $dark = ! $dark;
                    }

                    $modules[$row][$col] = $dark;
                }

                $row += $upward ? -1 : 1;

                if ($row < 0 || $row >= $size) {
                    $row -= $upward ? -1 : 1;
                    $upward = ! $upward;
                    break;
                }
            }
        }

        /** @var array<int, array<int, bool>> $filled */
        $filled = array_map(
            static fn (array $cells): array => array_map(static fn (?bool $cell): bool => $cell ?? false, $cells),
            $modules
        );

        return $filled;
    }

    private static function maskApplies(int $mask, int $row, int $col): bool
    {
        return match ($mask) {
            0 => ($row + $col) % 2 === 0,
            1 => $row % 2 === 0,
            2 => $col % 3 === 0,
            3 => ($row + $col) % 3 === 0,
            4 => (intdiv($row, 2) + intdiv($col, 3)) % 2 === 0,
            5 => ($row * $col) % 2 + ($row * $col) % 3 === 0,
            6 => (($row * $col) % 2 + ($row * $col) % 3) % 2 === 0,
            7 => ((($row + $col) % 2) + (($row * $col) % 3)) % 2 === 0,
            default => false,
        };
    }

    /**
     * @param  array<int, array<int, bool>>  $modules
     */
    private static function placeFormat(array &$modules, int $size, int $mask): void
    {
        $bits = self::formatBits($mask);

        for ($i = 0; $i < 15; $i++) {
            $bit = (($bits >> $i) & 1) === 1;

            if ($i < 6) {
                $modules[$i][8] = $bit;
            } elseif ($i < 8) {
                $modules[$i + 1][8] = $bit;
            } else {
                $modules[$size - 15 + $i][8] = $bit;
            }

            if ($i < 8) {
                $modules[8][$size - 1 - $i] = $bit;
            } elseif ($i === 8) {
                $modules[8][7] = $bit;
            } else {
                $modules[8][14 - $i] = $bit;
            }
        }

        $modules[$size - 8][8] = true;
    }

    private static function formatBits(int $mask): int
    {
        $data = (self::EC_LEVEL_BITS << 3) | $mask;
        $remainder = $data << 10;

        while (self::bitLength($remainder) - self::bitLength(self::G15) >= 0) {
            $remainder ^= self::G15 << (self::bitLength($remainder) - self::bitLength(self::G15));
        }

        return (($data << 10) | $remainder) ^ self::G15_MASK;
    }

    private static function versionBits(int $version): int
    {
        $remainder = $version << 12;

        while (self::bitLength($remainder) - self::bitLength(self::G18) >= 0) {
            $remainder ^= self::G18 << (self::bitLength($remainder) - self::bitLength(self::G18));
        }

        return ($version << 12) | $remainder;
    }

    private static function bitLength(int $value): int
    {
        $length = 0;

        while ($value !== 0) {
            $length++;
            $value >>= 1;
        }

        return $length;
    }

    /**
     * How badly a masked symbol reads, by the four rules in the standard. The
     * mask with the lowest score wins — the point being to avoid large blank
     * areas and anything that looks like a finder pattern.
     *
     * @param  array<int, array<int, bool>>  $modules
     */
    private static function penalty(array $modules, int $size): int
    {
        $score = 0;
        $dark = 0;

        // Rule 1: runs of five or more of the same colour, each way.
        foreach ([true, false] as $byRow) {
            for ($a = 0; $a < $size; $a++) {
                $run = 1;
                $previous = null;

                for ($b = 0; $b < $size; $b++) {
                    $cell = $byRow ? $modules[$a][$b] : $modules[$b][$a];

                    if ($cell === $previous) {
                        $run++;
                    } else {
                        if ($run >= 5) {
                            $score += $run - 2;
                        }

                        $run = 1;
                        $previous = $cell;
                    }

                    if ($byRow && $cell) {
                        $dark++;
                    }
                }

                if ($run >= 5) {
                    $score += $run - 2;
                }
            }
        }

        // Rule 2: every two by two block of one colour.
        for ($row = 0; $row < $size - 1; $row++) {
            for ($col = 0; $col < $size - 1; $col++) {
                $cell = $modules[$row][$col];

                if ($cell === $modules[$row][$col + 1]
                    && $cell === $modules[$row + 1][$col]
                    && $cell === $modules[$row + 1][$col + 1]) {
                    $score += 3;
                }
            }
        }

        // Rule 3: anything resembling the 1:1:3:1:1 of a finder pattern.
        $patterns = [
            [true, false, true, true, true, false, true, false, false, false, false],
            [false, false, false, false, true, false, true, true, true, false, true],
        ];

        for ($row = 0; $row < $size; $row++) {
            for ($col = 0; $col < $size; $col++) {
                foreach ($patterns as $pattern) {
                    if ($col + 11 <= $size) {
                        $match = true;

                        for ($i = 0; $i < 11; $i++) {
                            if ($modules[$row][$col + $i] !== $pattern[$i]) {
                                $match = false;
                                break;
                            }
                        }

                        if ($match) {
                            $score += 40;
                        }
                    }

                    if ($row + 11 <= $size) {
                        $match = true;

                        for ($i = 0; $i < 11; $i++) {
                            if ($modules[$row + $i][$col] !== $pattern[$i]) {
                                $match = false;
                                break;
                            }
                        }

                        if ($match) {
                            $score += 40;
                        }
                    }
                }
            }
        }

        // Rule 4: how far the balance of dark to light strays from even.
        $percent = (int) (abs($dark * 100 / ($size * $size) - 50) / 5);
        $score += $percent * 10;

        return $score;
    }

    private static function chunk(string $type, string $payload): string
    {
        return pack('N', strlen($payload))
            .$type
            .$payload
            .pack('N', crc32($type.$payload));
    }
}
