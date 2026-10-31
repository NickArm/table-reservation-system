{{--
    "Do this one thing", in the head or the foot of what it acts on.

    One mechanism everywhere, because the alternative — a fold at the foot of
    whatever list it belongs to — put the control furthest from the heading
    that explains it and closest to the next section's heading, which is
    exactly where it gets read as belonging to the wrong thing.

    It opens an <x-sheet> over the page. No JavaScript: the link reloads with
    ?add=<key> and the server renders the sheet.

    @param string $key      matches the sheet's own key
    @param string $label    what the control says
    @param bool   $primary  the one thing the screen is for
    @param bool   $quiet    a link rather than a button, for the rare ones
    @param bool   $danger   the one that destroys something
--}}
@php
    $href = request()->fullUrlWithQuery(['add' => $key]);
@endphp

@if ($quiet ?? false)
    <a class="quietlink @if ($danger ?? false) quietlink--danger @endif" href="{{ $href }}">
        {{ $label }}
    </a>
@else
    <a class="btn btn--small @unless ($primary ?? false) btn--ghost @endunless" href="{{ $href }}">
        {{ $label }}
    </a>
@endif
