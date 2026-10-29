<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Two letters standing in for a picture that will never exist.
 *
 * A column of names all in one weight gives the eye nothing to land on while
 * scrolling. Venues do not upload photographs of their guests and we do not
 * ask them to, so the initials are the avatar.
 */
final class Initials
{
    public static function of(?string $name): string
    {
        $words = preg_split('/\s+/', trim((string) $name)) ?: [];

        $letters = '';

        foreach ($words as $word) {
            if ($word === '' || mb_strlen($letters) >= 2) {
                continue;
            }

            $letters .= mb_strtoupper(mb_substr($word, 0, 1));
        }

        return $letters === '' ? '?' : $letters;
    }
}
