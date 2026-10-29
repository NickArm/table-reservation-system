{{--
    One stroke icon, drawn from a path. Inline rather than a sprite or a font:
    nine of them on one page is less bytes than a request, and a stroke icon
    inherits its colour from the card it sits in.
--}}
@php
    $paths = [
        'sun' => 'M12 16.5a4.5 4.5 0 100-9 4.5 4.5 0 000 9z M12 2.8v2.2 M12 19v2.2 M21.2 12H19 M5 12H2.8 M18.5 5.5l-1.6 1.6 M7.1 16.9l-1.6 1.6 M18.5 18.5l-1.6-1.6 M7.1 7.1L5.5 5.5',
        'card' => 'M3.5 6.5h17v11h-17z M3.5 10h17 M6.8 14h3.4',
        'tag' => 'M11.4 3.5H20v8.6l-8.6 8.6-8.6-8.6z M16.2 8.3h.01',
        'bell' => 'M6.5 10a5.5 5.5 0 0111 0c0 4 1.5 5.5 1.5 5.5H5s1.5-1.5 1.5-5.5z M10 18.5a2.2 2.2 0 004 0',
        'people' => 'M10 11.4a3.4 3.4 0 100-6.8 3.4 3.4 0 000 6.8z M4 19.6c0-3.3 2.7-6 6-6s6 2.7 6 6 M16.6 5.7a3.4 3.4 0 010 5.5 M18.2 14.2a6 6 0 012.8 5',
        'globe' => 'M12 20.6a8.6 8.6 0 100-17.2 8.6 8.6 0 000 17.2z M3.6 12h16.8 M12 3.4c2.1 2.3 3.3 5.4 3.3 8.6s-1.2 6.3-3.3 8.6c-2.1-2.3-3.3-5.4-3.3-8.6S9.9 5.7 12 3.4z',
        'mail' => 'M3.5 6h17v12h-17z M3.5 7l8.5 6 8.5-6',
        'shield' => 'M12 3.5l7 2.8v5.4c0 4-3 7.2-7 8.8-4-1.6-7-4.8-7-8.8V6.3z M9 12l2.2 2.2L15.5 10',
        'chart' => 'M4.5 19.5V4.5 M4.5 19.5h15 M8.5 16V11 M12.5 16V7 M16.5 16v-3',
    ];
@endphp

<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <path d="{{ $paths[$name] ?? $paths['sun'] }}"></path>
</svg>
