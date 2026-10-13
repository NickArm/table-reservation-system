<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * An iCalendar file, built by hand because the format we need is a dozen lines
 * and the libraries that produce it are not.
 *
 * Everything is written in UTC with a trailing Z, which every calendar
 * application understands and which sidesteps timezone databases disagreeing
 * about Athens. The guest still sees their own local time, because that is
 * what their calendar does with a UTC instant.
 */
final readonly class IcsFile
{
    public function __construct(
        private string $uid,
        private string $summary,
        private CarbonImmutable $startsAt,
        private CarbonImmutable $endsAt,
        private ?string $description = null,
        private ?string $location = null,
        private ?string $url = null,
        private ?CarbonImmutable $reminderAt = null,
    ) {}

    public function toString(): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//TableReserved//Booking//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:'.$this->uid,
            'DTSTAMP:'.$this->stamp(CarbonImmutable::now()),
            'DTSTART:'.$this->stamp($this->startsAt),
            'DTEND:'.$this->stamp($this->endsAt),
            'SUMMARY:'.$this->escape($this->summary),
            'STATUS:CONFIRMED',
            'TRANSP:OPAQUE',
        ];

        if (filled($this->description)) {
            $lines[] = 'DESCRIPTION:'.$this->escape($this->description);
        }

        if (filled($this->location)) {
            $lines[] = 'LOCATION:'.$this->escape($this->location);
        }

        if (filled($this->url)) {
            $lines[] = 'URL:'.$this->escape($this->url);
        }

        if ($this->reminderAt instanceof CarbonImmutable) {
            $minutes = max(0, $this->reminderAt->diffInMinutes($this->startsAt, absolute: true));

            $lines[] = 'BEGIN:VALARM';
            $lines[] = 'ACTION:DISPLAY';
            $lines[] = 'DESCRIPTION:'.$this->escape($this->summary);
            $lines[] = 'TRIGGER:-PT'.(int) $minutes.'M';
            $lines[] = 'END:VALARM';
        }

        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map($this->fold(...), $lines))."\r\n";
    }

    private function stamp(CarbonImmutable $moment): string
    {
        return $moment->utc()->format('Ymd\THis\Z');
    }

    /**
     * Commas, semicolons, backslashes and newlines all mean something to the
     * format and have to be spelled out.
     */
    private function escape(string $value): string
    {
        return str_replace(
            ['\\', "\r\n", "\n", "\r", ';', ','],
            ['\\\\', '\\n', '\\n', '\\n', '\\;', '\\,'],
            $value
        );
    }

    /**
     * No line may exceed 75 octets. Continuations start with a single space,
     * and the split counts bytes rather than characters so that a Greek venue
     * name does not come apart in the middle.
     */
    private function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $parts = [];
        $current = '';
        $limit = 74;

        foreach (mb_str_split($line) as $character) {
            if (strlen($current) + strlen($character) > $limit) {
                $parts[] = $current;
                $current = '';
                // Continuation lines carry a leading space of their own.
                $limit = 73;
            }

            $current .= $character;
        }

        if ($current !== '') {
            $parts[] = $current;
        }

        return implode("\r\n ", $parts);
    }
}
