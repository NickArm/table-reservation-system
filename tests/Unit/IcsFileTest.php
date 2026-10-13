<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\IcsFile;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The calendar attachment.
 *
 * Small enough to write by hand and fussy enough to get wrong: the format
 * wants CRLF line endings, lines no longer than 75 octets, and four characters
 * escaped. A client that dislikes any of it tends to show nothing at all
 * rather than complain, so the rules are pinned here.
 */
final class IcsFileTest extends TestCase
{
    #[Test]
    public function it_writes_an_event_every_calendar_understands(): void
    {
        $ics = $this->file()->toString();

        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
        $this->assertStringEndsWith("END:VCALENDAR\r\n", $ics);

        // Every line, without exception. A lone newline is the single most
        // common reason an invitation silently fails to import.
        $this->assertSame(
            substr_count($ics, "\n"),
            substr_count($ics, "\r\n"),
            'A line is not terminated with CRLF.'
        );

        // UTC with a trailing Z, so no client has to agree with us about what
        // Europe/Athens meant on that date (SPEC §3.5).
        $this->assertStringContainsString("DTSTART:20260704T173000Z\r\n", $ics);
        $this->assertStringContainsString("DTEND:20260704T193000Z\r\n", $ics);
        $this->assertStringContainsString("UID:booking-A4F7K9@tablereserved\r\n", $ics);
        $this->assertStringContainsString("STATUS:CONFIRMED\r\n", $ics);
    }

    #[Test]
    public function commas_semicolons_and_newlines_are_escaped(): void
    {
        $ics = $this->file()->toString();

        // Unescaped, a comma would start a second value and a semicolon a
        // parameter — the line after it would simply disappear.
        $this->assertStringContainsString('Sunbeds\, front row\; set of two', $ics);
        $this->assertStringContainsString('Booking code: A4F7K9\nA12', $ics);
    }

    #[Test]
    public function long_lines_are_folded_without_splitting_a_character(): void
    {
        $ics = $this->file()->toString();

        foreach (explode("\r\n", $ics) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line), "A line exceeds 75 octets: {$line}");
        }

        // Folding counts bytes, but a Greek venue name must not come apart in
        // the middle of one.
        $this->assertSame(
            $ics,
            mb_convert_encoding($ics, 'UTF-8', 'UTF-8'),
            'Folding broke a multi-byte character.'
        );

        // Continuation lines are marked by a single leading space.
        $this->assertStringContainsString("\r\n ", $ics);
    }

    #[Test]
    public function the_reminder_becomes_a_relative_trigger(): void
    {
        $ics = $this->file()->toString();

        $this->assertStringContainsString("BEGIN:VALARM\r\n", $ics);
        $this->assertStringContainsString("TRIGGER:-PT120M\r\n", $ics);
    }

    #[Test]
    public function a_file_without_a_reminder_has_no_alarm(): void
    {
        $startsAt = CarbonImmutable::parse('2026-07-04 17:30', 'UTC');

        $ics = (new IcsFile(
            uid: 'x@tablereserved',
            summary: 'Dinner',
            startsAt: $startsAt,
            endsAt: $startsAt->addHours(2),
        ))->toString();

        $this->assertStringNotContainsString('VALARM', $ics);
        $this->assertStringNotContainsString('DESCRIPTION', $ics);
        $this->assertStringNotContainsString('LOCATION', $ics);
    }

    private function file(): IcsFile
    {
        $startsAt = CarbonImmutable::parse('2026-07-04 17:30', 'UTC');

        return new IcsFile(
            uid: 'booking-A4F7K9@tablereserved',
            summary: 'Κρύσταλλο Beach Bar — Sunbeds, front row; set of two',
            startsAt: $startsAt,
            endsAt: $startsAt->addHours(2),
            description: "Booking code: A4F7K9\nA12 · Front row\n2 people\n"
                .'https://tablereserved.com/v/A4F7K9/8fj2kd9slq03mxza',
            location: 'Kalami Beach, Kalami, 49083',
            url: 'https://tablereserved.com/v/A4F7K9/8fj2kd9slq03mxza',
            reminderAt: $startsAt->subHours(2),
        );
    }
}
