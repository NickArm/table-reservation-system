<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Every Blade directive is actually a directive.
 *
 * This exists because the same mistake has now been made twice, and it is
 * invisible both times. Blade only compiles a directive when it is not glued
 * to the character before it, so this:
 *
 *     was not accepted@if ($reason) — {{ $reason }}@endif.
 *
 * leaves `@if` as literal text — `d@if` is not a directive — while the
 * `@endif` on the end *is* compiled. The result is a PHP file with a closing
 * brace and nothing to close, so the view throws
 * `unexpected token "endif"` at render time and the page is simply a 500.
 *
 * Nothing else catches it. Pint does not read Blade, the compiler reports
 * nothing until the view is rendered, and a view only rendered on one branch
 * of one email is rendered for the first time in production.
 *
 * So: a regex over every template, in the suite, where it costs milliseconds.
 * A test rather than a script, because a script is a thing somebody has to
 * remember and `php artisan test` is the one command everybody already runs.
 */
final class BladeDirectivesTest extends TestCase
{
    /**
     * The directives worth guarding: the ones that open or close a block, or
     * that produce a statement of their own.
     *
     * Not every directive in Blade. `@foo` glued to a word is only a problem
     * when something else in the file closes it, and these are the ones that
     * come in pairs or stand alone as a statement.
     */
    private const DIRECTIVES = [
        'if', 'elseif', 'else', 'endif',
        'unless', 'endunless',
        'isset', 'endisset', 'empty', 'endempty',
        'foreach', 'endforeach', 'forelse', 'endforelse',
        'for', 'endfor', 'while', 'endwhile',
        'switch', 'case', 'break', 'default', 'endswitch',
        'php', 'endphp',
        'section', 'endsection', 'show', 'yield', 'extends', 'parent',
        'push', 'endpush', 'prepend', 'endprepend', 'stack',
        'once', 'endonce', 'verbatim', 'endverbatim',
        'auth', 'endauth', 'guest', 'endguest',
        'csrf', 'method', 'include', 'includeIf', 'each',
    ];

    #[Test]
    public function no_directive_is_glued_to_the_character_before_it(): void
    {
        $pattern = '/[A-Za-z0-9_](@(?:'.implode('|', self::DIRECTIVES).'))\b/';

        $offences = [];

        foreach ($this->templates() as $template) {
            $lines = file($template->getRealPath(), FILE_IGNORE_NEW_LINES);

            if ($lines === false) {
                continue;
            }

            foreach ($lines as $number => $line) {
                if (preg_match($pattern, $line, $matches) === 1) {
                    $offences[] = sprintf(
                        '%s:%d — %s is glued to the character before it',
                        $this->relative($template->getRealPath()),
                        $number + 1,
                        $matches[1],
                    );
                }
            }
        }

        $this->assertSame([], $offences, implode("\n", [
            'A Blade directive glued to the character before it is not compiled,',
            'but its closing directive is — so the view throws at render time:',
            '',
            ...$offences,
            '',
            'Put a space, a newline or a `}}` before it.',
        ]));
    }

    #[Test]
    public function every_block_directive_is_closed(): void
    {
        /*
         * Closing directive => everything that opens it.
         *
         * Mostly one to one, with one trap worth writing down: Blade closes
         * `@hasSection` and `@sectionMissing` with `@endif`, not with an
         * `@endhasSection`. Counting `@if` against `@endif` alone therefore
         * reports every layout in the application as broken.
         *
         * `@section` is absent on purpose. `@section('title', 'x')` is
         * complete on its own and `@section` with a body needs
         * `@endsection`, so a count cannot tell the two apart. `@empty` is
         * absent for the same kind of reason: it closes nothing in a
         * `@forelse` and opens a block after `@empty(...)`.
         */
        $blocks = [
            'endif' => ['if', 'hasSection', 'sectionMissing'],
            'endunless' => ['unless'],
            'endisset' => ['isset'],
            'endforeach' => ['foreach'],
            'endforelse' => ['forelse'],
            'endfor' => ['for'],
            'endwhile' => ['while'],
            'endphp' => ['php'],
            'endpush' => ['push'],
            'endprepend' => ['prepend'],
            'endonce' => ['once'],
            'endverbatim' => ['verbatim'],
            'endauth' => ['auth'],
            'endguest' => ['guest'],
            'enderror' => ['error'],
        ];

        $offences = [];

        foreach ($this->templates() as $template) {
            $body = (string) file_get_contents($template->getRealPath());

            foreach ($blocks as $close => $opens) {
                $opened = 0;

                foreach ($opens as $open) {
                    // `@php($x = 1)` is the inline form and closes itself;
                    // every other directive here takes its arguments in
                    // parentheses either way.
                    $suffix = $open === 'php' ? '(?!\s*\()' : '';

                    $opened += (int) preg_match_all('/@'.$open.'\b'.$suffix.'/', $body);
                }

                $closed = (int) preg_match_all('/@'.$close.'\b/', $body);

                if ($opened === $closed) {
                    continue;
                }

                $offences[] = sprintf(
                    '%s — %d opening %s against %d @%s',
                    $this->relative($template->getRealPath()),
                    $opened,
                    implode('/', array_map(static fn (string $o): string => '@'.$o, $opens)),
                    $closed,
                    $close,
                );
            }
        }

        $this->assertSame([], $offences, implode("\n", [
            'A template opens a block it never closes, or closes one it never opened:',
            '',
            ...$offences,
        ]));
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function templates(): iterable
    {
        return Finder::create()
            ->files()
            ->in(resource_path('views'))
            ->name('*.blade.php');
    }

    private function relative(string $path): string
    {
        return str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
    }
}
