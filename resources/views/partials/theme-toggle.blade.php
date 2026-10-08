{{-- Tombol ganti mode gelap dan terang. `$compact` hanya menampilkan ikon. --}}
<button type="button" class="theme-toggle {{ ($compact ?? false) ? 'is-compact' : '' }}" data-theme-toggle
    aria-label="Ganti mode tampilan" title="Ganti mode tampilan">
    <svg class="theme-icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
        stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
        <circle cx="12" cy="12" r="4" />
        <path d="M12 2.5v2M12 19.5v2M4.6 4.6l1.4 1.4M18 18l1.4 1.4M2.5 12h2M19.5 12h2M4.6 19.4 6 18M18 6l1.4-1.4" />
    </svg>
    <svg class="theme-icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
        stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
        <path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5Z" />
    </svg>
    @unless ($compact ?? false)
        <span data-theme-label>Mode tampilan</span>
    @endunless
</button>
