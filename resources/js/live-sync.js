/**
 * Sinkronisasi halaman tanpa muat ulang.
 *
 * Server hanya perlu menandai bagian halaman yang boleh berubah dengan
 * `data-live="kunci"`. Skrip ini mengambil ulang halaman yang sama secara
 * berkala, lalu mengganti isi bagian bertanda `data-live` dari hasil terbaru.
 *
 * Bagian yang tidak bertanda tidak pernah disentuh sehingga catatan (session
 * flash), posisi gulir, dan formulir yang sedang diisi tetap utuh. Sebuah
 * bagian dilewati bila di dalamnya ada kolom yang sedang difokuskan atau
 * nilainya sudah diubah pengguna.
 */
const DefaultIntervalMs = 5000;
const LiveRequestHeader = "X-Live-Refresh";

const clock = () => {
    const now = new Date();
    const pad = (value) => String(value).padStart(2, "0");

    return `${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`;
};

const isFieldDirty = (field) => {
    if (
        field instanceof HTMLInputElement &&
        (field.type === "checkbox" || field.type === "radio")
    ) {
        return field.checked !== field.defaultChecked;
    }

    if (field instanceof HTMLSelectElement) {
        return Array.from(field.options).some(
            (option) => option.selected !== option.defaultSelected,
        );
    }

    return field.value !== field.defaultValue;
};

const hasUnsavedInput = (region) =>
    Array.from(region.querySelectorAll("input, select, textarea")).some(
        isFieldDirty,
    );

const hasFocus = (region) => {
    const active = document.activeElement;

    return (
        active !== null && active !== document.body && region.contains(active)
    );
};

const captureViewState = (region) => ({
    details: Array.from(region.querySelectorAll("details")).map(
        (item) => item.open,
    ),
    scrollers: Array.from(region.querySelectorAll(".table-wrap")).map(
        (item) => ({
            top: item.scrollTop,
            left: item.scrollLeft,
        }),
    ),
});

const restoreViewState = (region, state) => {
    region.querySelectorAll("details").forEach((item, index) => {
        if (typeof state.details[index] === "boolean") {
            item.open = state.details[index];
        }
    });

    region.querySelectorAll(".table-wrap").forEach((item, index) => {
        const saved = state.scrollers[index];

        if (saved) {
            item.scrollTop = saved.top;
            item.scrollLeft = saved.left;
        }
    });
};

const findRegions = () => Array.from(document.querySelectorAll("[data-live]"));

const intervalMs = () => {
    const configured = Number(
        document.body.dataset.liveInterval ?? DefaultIntervalMs,
    );

    return Number.isFinite(configured) && configured >= 1000
        ? configured
        : DefaultIntervalMs;
};

export const startLiveRefresh = () => {
    const regions = findRegions();

    if (regions.length === 0) {
        return;
    }

    const status = document.querySelector("[data-live-status]");
    let busy = false;

    const setStatus = (message) => {
        if (status) {
            status.textContent = message;
        }
    };

    const applyRegion = (region, fresh) => {
        if (
            hasFocus(region) ||
            hasUnsavedInput(region) ||
            region.innerHTML === fresh.innerHTML
        ) {
            return;
        }

        const viewState = captureViewState(region);

        region.innerHTML = fresh.innerHTML;

        restoreViewState(region, viewState);
        region.dispatchEvent(
            new CustomEvent("live:updated", { bubbles: true }),
        );
    };

    const refresh = async () => {
        if (busy || document.hidden || !navigator.onLine) {
            return;
        }

        busy = true;

        try {
            const response = await fetch(window.location.href, {
                cache: "no-store",
                credentials: "same-origin",
                headers: { Accept: "text/html", [LiveRequestHeader]: "1" },
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const fresh = new DOMParser().parseFromString(
                await response.text(),
                "text/html",
            );

            regions.forEach((region) => {
                const update = fresh.querySelector(
                    `[data-live="${region.dataset.live}"]`,
                );

                if (update) {
                    applyRegion(region, update);
                }
            });

            setStatus(`Terakhir disinkronkan pukul ${clock()}`);
        } catch {
            setStatus(`Sinkronisasi gagal pukul ${clock()}, mencoba lagi`);
        } finally {
            busy = false;
        }
    };

    setStatus("Sinkronisasi otomatis aktif");

    document.addEventListener("visibilitychange", () => {
        if (!document.hidden) {
            void refresh();
        }
    });

    window.setInterval(() => void refresh(), intervalMs());
};
