/**
 * Pilihan filter yang langsung diterapkan begitu diganti, supaya pengguna
 * tidak perlu mencari tombol Cari setelah memilih kelas atau status.
 */
export function initAutoSubmit() {
    document.querySelectorAll("select[data-auto-submit]").forEach((select) => {
        select.addEventListener("change", () => {
            select.form?.requestSubmit();
        });
    });
}
