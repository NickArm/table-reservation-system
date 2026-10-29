{{--
    The way back up.

    Setup is a hub with eleven screens under it and some of those have screens
    under them again — areas inside a booking type, spots inside an area. The
    rail only ever points at the hub, so from three levels down the only route
    back was the browser's own button, and the heading never said where you
    were.

    One line, above the title, in the order a person walks it. The last entry
    is where they are and is not a link: a link to the page you are on is a
    dead control somebody will press once.

    @param array<int, array{0: string, 1: ?string}> $trail
--}}
<nav class="crumb" aria-label="Breadcrumb">
    @foreach ($trail as $index => [$label, $href])
        @if ($href !== null && ! $loop->last)
            <a href="{{ $href }}">{{ $label }}</a>
            <span class="crumb__sep" aria-hidden="true">›</span>
        @else
            <span aria-current="page">{{ $label }}</span>
        @endif
    @endforeach
</nav>
