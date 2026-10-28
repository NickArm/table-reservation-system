<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Phone numbers in one shape (SPEC §24.4).
 *
 * Since a guest can only change or cancel by ringing the venue, the number is
 * the venue's way back to them — not a form field. It is stored in E.164
 * (+306941234567) so it is always dialable, and shown as a tap-to-call link.
 *
 * Deliberately small: full validation needs a library the size of a phone
 * book, and the cost of being slightly permissive is a number the venue has
 * to read out loud. The cost of being strict is a tourist who cannot book.
 */
final class PhoneNumber
{
    /**
     * The country codes worth offering in a Greek beach bar, Greece first.
     *
     * @return array<string, string>
     */
    public static function callingCodes(): array
    {
        return [
            '+30' => 'Greece +30',
            '+44' => 'United Kingdom +44',
            '+49' => 'Germany +49',
            '+39' => 'Italy +39',
            '+33' => 'France +33',
            '+31' => 'Netherlands +31',
            '+43' => 'Austria +43',
            '+41' => 'Switzerland +41',
            '+46' => 'Sweden +46',
            '+47' => 'Norway +47',
            '+45' => 'Denmark +45',
            '+48' => 'Poland +48',
            '+1' => 'USA / Canada +1',
            '+61' => 'Australia +61',
            '+972' => 'Israel +972',
            '+7' => 'Russia +7',
        ];
    }

    /**
     * A stored E.164 number back onto the two fields the booking form uses.
     *
     * The longest matching code wins, because +1 is a prefix of nothing but
     * +44 and +4 would both match a German number if the shortest did. A
     * number whose code is not one we offer is dropped in whole rather than
     * guessed at: wrong by a digit is worse than obviously unsplit.
     *
     * @return array{code: string, national: string}
     */
    public static function split(?string $number, string $fallbackCode = '+30'): array
    {
        $number = trim((string) $number);

        if ($number === '') {
            return ['code' => $fallbackCode, 'national' => ''];
        }

        $codes = array_keys(self::callingCodes());
        usort($codes, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        foreach ($codes as $code) {
            if (str_starts_with($number, $code)) {
                return ['code' => $code, 'national' => mb_substr($number, mb_strlen($code))];
            }
        }

        return ['code' => $fallbackCode, 'national' => $number];
    }

    /**
     * Null when there is nothing usable in what was typed.
     */
    public static function normalize(?string $number, string $callingCode = '+30'): ?string
    {
        if (blank($number)) {
            return null;
        }

        $digits = preg_replace('/[^0-9+]/', '', $number) ?? '';

        if ($digits === '') {
            return null;
        }

        // Already international: trust it.
        if (str_starts_with($digits, '+')) {
            $national = ltrim(substr($digits, 1), '0');

            return self::isPlausible($national) ? '+'.$national : null;
        }

        // 0030 and 00 44 are how half of Europe writes a country code.
        if (str_starts_with($digits, '00')) {
            $national = ltrim(substr($digits, 2), '0');

            return self::isPlausible($national) ? '+'.$national : null;
        }

        // A national number: drop the trunk zero and prefix the country.
        $national = ltrim($digits, '0');
        $code = ltrim(preg_replace('/[^0-9]/', '', $callingCode) ?? '30', '0');

        return self::isPlausible($code.$national) ? '+'.$code.$national : null;
    }

    public static function isValid(?string $number, string $callingCode = '+30'): bool
    {
        return self::normalize($number, $callingCode) !== null;
    }

    /**
     * E.164 allows up to fifteen digits. Anything under eight is a typo
     * rather than a phone number.
     */
    private static function isPlausible(string $digits): bool
    {
        $length = strlen($digits);

        return $length >= 8 && $length <= 15;
    }
}
