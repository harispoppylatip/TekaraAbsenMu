<?php

return [
    /*
    | Alamat layanan wajah Python yang menghitung angka pembanding dari sebuah
    | foto (embedding). Bawaannya localhost karena layanan biasanya jalan di
    | mesin yang sama. Kalau layanannya dipindah ke komputer lain, ubah
    | FACE_SERVICE_URL di .env menjadi alamat komputer itu, pastikan HOST di
    | face_service.py sudah 0.0.0.0, lalu jalankan "php artisan config:clear".
    */
    'service_url' => rtrim((string) env('FACE_SERVICE_URL', 'http://127.0.0.1:5005'), '/'),

    /*
    | Batas jarak yang masih dianggap wajah yang sama. Semakin kecil, semakin
    | ketat. Nilai 0.6 adalah nilai lazim untuk model wajah yang dipakai
    | layanan, dan sudah teruji cukup aman untuk presensi sekolah.
    */
    'tolerance' => (float) env('FACE_TOLERANCE', 0.6),

    /* Nama model mesin wajah, disimpan pada setiap baris sidik wajah. */
    'model' => (string) env('FACE_MODEL', 'dlib-resnet-v1'),

    /*
    | Lama menunggu jawaban layanan wajah sebelum dianggap tidak merespons.
    | Dibuat longgar karena satu foto bisa butuh belasan detik di mesin tanpa
    | kartu grafis, dan percobaan pertama selalu lebih lama akibat pemuatan
    | model. Nilai 15 detik ternyata terlalu mepet dan sempat membuat foto yang
    | sebenarnya baik dianggap gagal.
    */
    'timeout' => (int) env('FACE_SERVICE_TIMEOUT', 90),

    /*
    | Identitas perangkat kamera wajah pada catatan presensi. Nama ini dipakai
    | sebagai pengenal di riwayat, jadi sebaiknya cocok dengan perangkat yang
    | didaftarkan di halaman Alat Sensor bila admin ingin memantaunya.
    */
    'device_id' => (string) env('FACE_DEVICE_ID', 'kamera-wajah'),
];
