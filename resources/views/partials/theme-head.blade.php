{{--
    Tema dipasang sebelum CSS dimuat supaya halaman tidak sempat berkedip dari
    gelap ke terang. Pilihan pengguna disimpan di localStorage `theme-mode`;
    kalau belum pernah memilih, tema mengikuti pengaturan perangkat.
--}}
<script>
    (() => {
        let theme = null;

        try {
            theme = localStorage.getItem('theme-mode');
        } catch (error) {}

        if (theme !== 'light' && theme !== 'dark') {
            theme = window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
        }

        document.documentElement.dataset.theme = theme;
    })();
</script>
