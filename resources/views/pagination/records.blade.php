@php
    $noun = $noun ?? 'data';
@endphp

@if ($paginator->hasPages())
    <nav class="pagination" aria-label="Pembagian halaman {{ $noun }}">
        @if ($paginator->onFirstPage())
            <span class="page-link is-disabled" aria-disabled="true">Sebelumnya</span>
        @else
            <a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev">Sebelumnya</a>
        @endif

        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="page-gap">{{ $element }}</span>
            @endif

            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="page-link is-current" aria-current="page">{{ $page }}</span>
                    @else
                        <a class="page-link" href="{{ $url }}">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <a class="page-link" href="{{ $paginator->nextPageUrl() }}" rel="next">Berikutnya</a>
        @else
            <span class="page-link is-disabled" aria-disabled="true">Berikutnya</span>
        @endif
    </nav>

    <p class="pagination-summary">Halaman {{ $paginator->currentPage() }} dari {{ $paginator->lastPage() }}</p>
@endif
