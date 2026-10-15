{{--
    Paging, in the shell's own style.

    Written here rather than published from the framework: the stock views come
    styled for a different design system, and a paginator that looks borrowed
    is exactly the sort of seam §27 is about. Three links and a count are all
    this needs.
--}}
@if ($paginator->hasPages())
    <nav class="pager" aria-label="Pages">
        @if ($paginator->onFirstPage())
            <span class="pager__link pager__link--off" aria-disabled="true">Previous</span>
        @else
            <a class="pager__link" href="{{ $paginator->previousPageUrl() }}" rel="prev">Previous</a>
        @endif

        <span class="pager__count">
            {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }}
            of {{ $paginator->total() }}
        </span>

        @if ($paginator->hasMorePages())
            <a class="pager__link" href="{{ $paginator->nextPageUrl() }}" rel="next">Next</a>
        @else
            <span class="pager__link pager__link--off" aria-disabled="true">Next</span>
        @endif
    </nav>
@endif
