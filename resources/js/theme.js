/**
 * Tombol ganti mode gelap dan terang.
 * Tema awal sudah dipasang oleh partial `theme-head` sebelum CSS dimuat; di
 * sini hanya mengurus klik tombol, menyimpan pilihan, dan mengikuti perubahan
 * pengaturan perangkat selama pengguna belum memilih sendiri.
 */
const StorageKey = "theme-mode";

const storedTheme = () => {
    try {
        const value = localStorage.getItem(StorageKey);

        return value === "light" || value === "dark" ? value : null;
    } catch (error) {
        return null;
    }
};

const currentTheme = () =>
    document.documentElement.dataset.theme === "light" ? "light" : "dark";

const applyTheme = (theme) => {
    document.documentElement.dataset.theme = theme;

    document.querySelectorAll("[data-theme-toggle]").forEach((button) => {
        const label =
            theme === "light" ? "Ganti ke mode gelap" : "Ganti ke mode terang";

        button.setAttribute("aria-label", label);
        button.setAttribute("title", label);

        const text = button.querySelector("[data-theme-label]");

        if (text) {
            text.textContent = theme === "light" ? "Mode gelap" : "Mode terang";
        }
    });
};

export function initTheme() {
    applyTheme(currentTheme());

    document.querySelectorAll("[data-theme-toggle]").forEach((button) => {
        button.addEventListener("click", () => {
            const next = currentTheme() === "light" ? "dark" : "light";

            try {
                localStorage.setItem(StorageKey, next);
            } catch (error) {
                // Tanpa penyimpanan, tema tetap berganti untuk halaman ini.
            }

            applyTheme(next);
        });
    });

    window
        .matchMedia("(prefers-color-scheme: light)")
        .addEventListener("change", (event) => {
            if (storedTheme() === null) {
                applyTheme(event.matches ? "light" : "dark");
            }
        });
}
