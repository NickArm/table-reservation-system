{{--
    "Add one of these", in the head of the thing you are adding to.

    One mechanism everywhere, because the alternative — a fold at the foot of
    whatever list it belongs to — put the control furthest from the heading
    that explains it and closest to the next section's heading, which is
    exactly where it gets read as belonging to the wrong thing.

    No JavaScript: the link reloads the page with ?add=<key>, the matching
    <details> is rendered open, and the fragment scrolls to it. Server-side
    state for a server-rendered screen.

    @param string $key   matches the details' own key
    @param string $label what the button says
--}}
<a class="btn btn--ghost btn--small"
   href="{{ request()->fullUrlWithQuery(['add' => $key]) }}#add-{{ $key }}">
    {{ $label }}
</a>
