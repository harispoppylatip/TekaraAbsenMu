/**
 * Kamera absen wajah.
 *
 * Halaman ini dipakai dari HP petugas. Gambar diambil berkala dari kamera,
 * dikirim ke server, lalu balasannya ditampilkan apa adanya: nama yang dikenali
 * beserta jenis absennya, atau alasan kenapa belum bisa dicatat.
 *
 * Pengiriman berikutnya tidak dimulai sebelum balasan sebelumnya datang, jadi
 * jaringan lambat tidak menumpuk permintaan.
 */
const CaptureIntervalMs = 1500;
const CaptureWidth = 640;

const elements = {
    video: document.querySelector("[data-camera-video]"),
    placeholder: document.querySelector("[data-camera-placeholder]"),
    status: document.querySelector("[data-camera-status]"),
    result: document.querySelector("[data-camera-result]"),
    name: document.querySelector("[data-camera-name]"),
    detail: document.querySelector("[data-camera-detail]"),
    start: document.querySelector("[data-camera-start]"),
    stop: document.querySelector("[data-camera-stop]"),
};

export const initFaceCamera = () => {
    if (!elements.video || !elements.start || !elements.stop) {
        return;
    }

    const scanUrl = document.body.dataset.faceScanUrl;
    const csrfToken = document.querySelector(
        'meta[name="csrf-token"]',
    )?.content;
    const canvas = document.createElement("canvas");

    let stream = null;
    let timer = null;
    let busy = false;
    let announced = false;
    let lastMessage = "";

    const setStatus = (message) => {
        if (elements.status) {
            elements.status.textContent = message;
        }
    };

    const showResult = (name, detail) => {
        if (!elements.result || !elements.name || !elements.detail) {
            return;
        }

        elements.result.hidden = false;
        elements.name.textContent = name;
        elements.detail.textContent = detail;
    };

    const announce = (name, detail) => {
        if (name === lastMessage) {
            return;
        }

        lastMessage = name;
        showResult(name, detail);

        if (!announced && "vibrate" in navigator) {
            navigator.vibrate(60);
            announced = true;
        }
    };

    const capture = () => {
        if (busy || stream === null || document.hidden) {
            return;
        }

        const width = elements.video.videoWidth;

        if (!width) {
            return;
        }

        const scale = Math.min(1, CaptureWidth / width);
        canvas.width = Math.round(elements.video.videoWidth * scale);
        canvas.height = Math.round(elements.video.videoHeight * scale);

        const context = canvas.getContext("2d");

        if (!context) {
            return;
        }

        context.drawImage(elements.video, 0, 0, canvas.width, canvas.height);
        canvas.toBlob(send, "image/jpeg", 0.8);
    };

    const send = async (blob) => {
        if (!blob || !scanUrl) {
            return;
        }

        busy = true;

        try {
            const body = new FormData();
            body.append("image", blob, "wajah.jpg");

            const response = await fetch(scanUrl, {
                method: "POST",
                credentials: "same-origin",
                headers: {
                    Accept: "application/json",
                    "X-CSRF-TOKEN": csrfToken ?? "",
                },
                body,
            });

            const data = await response.json().catch(() => null);

            if (!data) {
                setStatus("Balasan server tidak terbaca. Coba lagi.");
                return;
            }

            if (data.identified) {
                announce(
                    data.name ?? "Wajah dikenali",
                    `${data.attendance_label ?? "Tercatat"} · ${data.message ?? ""}`,
                );
                setStatus("Kamera aktif");
                return;
            }

            announce("Wajah belum dikenali", data.message ?? "");
            setStatus("Kamera aktif");
        } catch (error) {
            setStatus(
                "Koneksi ke server terputus. Periksa jaringan lalu coba lagi.",
            );
        } finally {
            busy = false;
        }
    };

    const start = async () => {
        if (!navigator.mediaDevices?.getUserMedia) {
            setStatus("Browser ini tidak mendukung kamera.");
            return;
        }

        try {
            stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: "user", width: { ideal: 1280 } },
                audio: false,
            });
        } catch (error) {
            setStatus(
                "Kamera tidak bisa dibuka. Izinkan akses kamera di browser, lalu coba lagi.",
            );
            return;
        }

        elements.video.srcObject = stream;
        await elements.video.play().catch(() => {});
        elements.placeholder?.setAttribute("hidden", "hidden");
        elements.result?.setAttribute("hidden", "hidden");
        elements.start.disabled = true;
        elements.stop.disabled = false;
        lastMessage = "";
        setStatus("Kamera aktif, menunggu wajah");

        timer = window.setInterval(capture, CaptureIntervalMs);
    };

    const stop = () => {
        if (timer !== null) {
            window.clearInterval(timer);
            timer = null;
        }

        stream?.getTracks().forEach((track) => track.stop());
        stream = null;
        elements.video.srcObject = null;
        elements.placeholder?.removeAttribute("hidden");
        elements.start.disabled = false;
        elements.stop.disabled = true;
        setStatus("Kamera dimatikan");
    };

    elements.start.addEventListener("click", start);
    elements.stop.addEventListener("click", stop);
    document.addEventListener("visibilitychange", () => {
        if (document.hidden) {
            setStatus("Kamera dijeda karena halaman tidak aktif");
        } else if (stream !== null) {
            setStatus("Kamera aktif");
        }
    });
    window.addEventListener("pagehide", stop);
};
