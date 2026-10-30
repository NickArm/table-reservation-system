{{--
    A form over the page, opened by a button in a section's head.

    An accordion below the list and a button above it are two controls for one
    job, and the one you did not press stays on screen looking like something
    else to do. This replaces the accordion: while it is shut there is nothing
    on the page at all.

    No JavaScript, and none needed. The button navigates to ?add=<key>, the
    server renders the sheet, and the scrim and the cross are links back to
    the same URL without it — so the browser's own back button closes it too,
    and a validation failure redirects back to the address that has it open,
    which is how the errors stay visible.

    @param string $key   matches the add-button's key
    @param string $title what the sheet is for
--}}
@props(['key', 'title'])

@if (request('add') === $key)
    @php $shut = request()->fullUrlWithoutQuery('add'); @endphp

    <div class="sheet">
        {{-- A link, not a div: clicking away from a dialog is expected to
             shut it, and that has to work without script. --}}
        <a class="sheet__scrim" href="{{ $shut }}" tabindex="-1" aria-hidden="true"></a>

        <div class="sheet__panel" role="dialog" aria-modal="true"
             aria-labelledby="sheet-{{ $key }}-title">
            <div class="sheet__head">
                <h2 class="h2" id="sheet-{{ $key }}-title">{{ $title }}</h2>

                <a class="sheet__close" href="{{ $shut }}" aria-label="Close">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                         stroke-linecap="round" aria-hidden="true">
                        <path d="M6 6l12 12M18 6L6 18"></path>
                    </svg>
                </a>
            </div>

            <div class="sheet__body">
                {{ $slot }}
            </div>
        </div>
    </div>
@endif
