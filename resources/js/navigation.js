/**
 * Buka dan tutup navigasi samping pada layar kecil.
 * Hanya mengatur atribut aksesibilitas dan kelas pada body, tidak menyentuh
 * area yang disinkronkan live-sync.
 */
export function initNavigation() {
    const toggle = document.querySelector("[data-nav-toggle]");
    const backdrop = document.querySelector("[data-nav-backdrop]");
    const sidebar = document.querySelector("#sidebar-navigation");

    if (!toggle || !sidebar) {
        return;
    }

    const setOpen = (open) => {
        document.body.classList.toggle("nav-open", open);
        toggle.setAttribute("aria-expanded", open ? "true" : "false");

        if (backdrop) {
            backdrop.hidden = !open;
        }
    };

    toggle.addEventListener("click", () => {
        setOpen(!document.body.classList.contains("nav-open"));
    });

    backdrop?.addEventListener("click", () => setOpen(false));

    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape") {
            setOpen(false);
        }
    });

    document.querySelectorAll(".nav-link").forEach((link) => {
        link.addEventListener("click", () => setOpen(false));
    });

    window
        .matchMedia("(min-width: 1024px)")
        .addEventListener("change", (event) => {
            if (event.matches) {
                setOpen(false);
            }
        });
}
