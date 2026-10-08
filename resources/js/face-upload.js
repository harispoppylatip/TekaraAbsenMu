/**
 * Impor massal foto wajah dari folder.
 *
 * Berkas dipilih lewat folder (webkitdirectory), lalu dikirim satu per satu ke
 * server supaya layanan wajah tidak kebanjiran permintaan dan tiap berkas
 * mendapat jawabannya sendiri. Alamat tujuan dan token CSRF dibaca dari
 * penanda di halaman, jadi skrip ini tidak menyimpan alamat apa pun.
 */

const readToken = () =>
    document
        .querySelector("meta[name='csrf-token']")
        ?.getAttribute("content") ?? "";

const relativePath = (file) => file.webkitRelativePath || file.name;

const sendPhoto = async (url, file) => {
    const body = new FormData();
    body.append("photo", file);
    body.append("relative", relativePath(file));

    const response = await fetch(url, {
        method: "POST",
        headers: {
            Accept: "application/json",
            "X-CSRF-TOKEN": readToken(),
        },
        body,
    });

    if (!response.ok) {
        return {
            status: "rejected",
            message: `Server menolak berkas ini (kode ${response.status}).`,
        };
    }

    return response.json();
};

const addRow = (rows, file, message) => {
    const row = document.createElement("tr");
    const name = document.createElement("td");
    const result = document.createElement("td");

    name.dataset.label = "Foto yang dilewati";
    name.textContent = file;
    result.dataset.label = "Alasan";
    result.textContent = message;

    row.append(name, result);
    rows.append(row);
};

export const initFaceUpload = () => {
    const folder = document.querySelector("[data-face-folder]");
    const start = document.querySelector("[data-face-import-start]");
    const stop = document.querySelector("[data-face-import-stop]");
    const log = document.querySelector("[data-face-import-log]");
    const summary = document.querySelector("[data-face-import-summary]");
    const rows = document.querySelector("[data-face-import-rows]");
    const url = document.querySelector("[data-face-upload-url]")?.dataset
        .faceUploadUrl;

    if (!folder || !start || !rows || !url) {
        return;
    }

    let running = false;
    let cancelled = false;

    const say = (text) => {
        if (summary) {
            summary.textContent = text;
        }
    };

    start.addEventListener("click", async () => {
        const files = Array.from(folder.files ?? []);

        if (files.length === 0) {
            if (log) {
                log.hidden = false;
            }
            say("Pilih folder fotonya dulu, lalu tekan Mulai kirim.");

            return;
        }

        if (running) {
            return;
        }

        running = true;
        cancelled = false;
        rows.textContent = "";

        if (log) {
            log.hidden = false;
        }

        start.disabled = true;

        if (stop) {
            stop.hidden = false;
        }

        let done = 0;
        let saved = 0;
        let skipped = 0;

        for (const file of files) {
            if (cancelled) {
                break;
            }

            done += 1;

            let result;

            try {
                result = await sendPhoto(url, file);
            } catch (error) {
                result = {
                    status: "unreachable",
                    message:
                        "Koneksi ke server terputus sebelum berkas ini selesai dikirim.",
                };
            }

            if (result.status === "saved") {
                saved += 1;
            } else {
                skipped += 1;
                addRow(
                    rows,
                    relativePath(file),
                    result.message || "Berkas ini tidak bisa dipakai.",
                );
            }

            say(
                `Mengirim ${done} dari ${files.length} foto. Tersimpan ${saved}, dilewati ${skipped}.`,
            );
        }

        running = false;
        start.disabled = false;

        if (stop) {
            stop.hidden = true;
        }

        const closing = cancelled
            ? "Pengiriman dihentikan sebelum semua berkas selesai."
            : "Semua berkas sudah selesai dikirim.";

        say(
            `${closing} ${saved} foto tersimpan, ${skipped} foto dilewati dari ${done} berkas yang dikirim.` +
                (skipped > 0
                    ? " Foto yang dilewati beserta alasannya ada di tabel bawah."
                    : ""),
        );
    });

    if (stop) {
        stop.addEventListener("click", () => {
            cancelled = true;
        });
    }
};
