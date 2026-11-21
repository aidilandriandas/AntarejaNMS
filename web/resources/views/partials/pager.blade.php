@if($p->hasPages())
<nav class="pager" aria-label="Halaman">
    <span>{{ $p->firstItem() }}–{{ $p->lastItem() }} dari {{ number_format($p->total(), 0, ',', '.') }}</span>
    <span style="display:flex;gap:6px">
        @if($p->onFirstPage())<span class="btn sm" aria-disabled="true" style="opacity:.5">‹ Sebelumnya</span>@else<a class="btn sm" href="{{ $p->previousPageUrl() }}">‹ Sebelumnya</a>@endif
        @if($p->hasMorePages())<a class="btn sm" href="{{ $p->nextPageUrl() }}">Berikutnya ›</a>@else<span class="btn sm" aria-disabled="true" style="opacity:.5">Berikutnya ›</span>@endif
    </span>
</nav>
@endif
