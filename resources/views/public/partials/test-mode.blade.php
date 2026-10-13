@if ($testMode ?? false)
    {{-- Across the whole page on purpose: a venue that forgets it is in test
         mode loses real bookings and blames us (SPEC §24.3). --}}
    <div class="test-mode" role="status">
        Test mode — no real payments are taken.
    </div>
@endif
