{{--
    "Add one of these", in the head of the thing you are adding to.

    One mechanism everywhere, because the alternative — a fold at the foot of
    whatever list it belongs to — put the control furthest from the heading
    that explains it and closest to the next section's heading, which is
    exactly where it gets read as belonging to the wrong thing.

    It opens an <x-sheet> over the page. No JavaScript: the link reloads with
    ?add=<key> and the server renders the sheet.

    @param string $key      matches the sheet's own key
    @param string $label    what the button says
    @param bool   $primary  true for the one thing the screen is for
--}}
<a class="btn btn--small @unless ($primary ?? false) btn--ghost @endunless"
   href="{{ request()->fullUrlWithQuery(['add' => $key]) }}">
    {{ $label }}
</a>
