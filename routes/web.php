<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\FaceApiController;
use App\Http\Controllers\FaceAttendanceController;
use App\Http\Controllers\FaceDataController;
use App\Http\Controllers\FingerprintApiController;
use App\Http\Controllers\FingerprintController;
use App\Http\Controllers\FingerprintScanController;
use App\Http\Controllers\LessonHourController;
use App\Http\Controllers\LessonScheduleController;
use App\Http\Controllers\LessonSessionController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SchoolClassController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\StudentPortalController;
use App\Http\Controllers\TeacherPortalController;
use App\Http\Controllers\UserDirectoryController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

/*
| Halaman aplikasi dipisah menurut peran. Semua orang masuk lewat satu pintu
| (`/masuk`), lalu diarahkan ke halamannya sendiri: admin ke dasbor, guru ke
| halaman mengajar, siswa ke halaman jadwalnya.
|
| Alur yang dijaga:
| - `auth` menahan tamu;
| - `password.changed` menahan akun yang masih memakai kata sandi bawaan sampai
|   kata sandinya diganti di `/ubah-sandi`;
| - `role:...` menahan peran yang tidak berhak atas halaman itu.
*/

Route::get('/masuk', [LoginController::class, 'show'])->name('login');
Route::post('/masuk', [LoginController::class, 'store'])->name('login.attempt');

Route::middleware('auth')->group(function (): void {
    Route::post('/keluar', [LoginController::class, 'destroy'])->name('logout');

    // Ganti kata sandi wajib bagi akun yang masih memakai kata sandi bawaan,
    // jadi halaman ini sengaja tidak memakai `password.changed`.
    Route::get('/ubah-sandi', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('/ubah-sandi', [PasswordController::class, 'update'])->name('password.update');
});

Route::middleware(['auth', 'password.changed'])->group(function (): void {
    Route::get('/akun', [AccountController::class, 'index'])->name('account.index');
    Route::put('/akun', [AccountController::class, 'update'])->name('account.update');

    // Halaman guru: jadwal sendiri, membuka dan menutup absen, dan rekap kelasnya.
    Route::middleware('role:teacher')->group(function (): void {
        Route::get('/guru', [TeacherPortalController::class, 'index'])->name('teacher.index');
        Route::get('/guru/rekap', [TeacherPortalController::class, 'recaps'])->name('teacher.recaps');
        Route::get('/guru/rekap/unduh', [TeacherPortalController::class, 'exportRecaps'])->name('teacher.recaps.export');
        Route::get('/guru/sesi/{session}/unduh', [TeacherPortalController::class, 'exportRecap'])->name('teacher.sessions.export');
        Route::post('/guru/sesi', [TeacherPortalController::class, 'open'])->name('teacher.sessions.store');
        Route::post('/guru/sesi/{session}/tutup', [TeacherPortalController::class, 'close'])->name('teacher.sessions.close');
        Route::get('/guru/sesi/{session}', [TeacherPortalController::class, 'recap'])->name('teacher.sessions.recap');
    });

    // Halaman siswa: jadwal kelasnya dan rekap kehadirannya sendiri.
    Route::middleware('role:student')->group(function (): void {
        Route::get('/siswa', [StudentPortalController::class, 'index'])->name('student.index');
        Route::get('/siswa/presensi-gerbang', [StudentPortalController::class, 'gateAttendance'])
            ->name('student.gate-attendance');
    });

    Route::middleware('role:admin')->group(function (): void {
        Route::get('/', DashboardController::class)->name('dashboard');
        Route::get('/fingerprints', [FingerprintController::class, 'index'])->name('fingerprints.index');
        Route::get('/data', UserDirectoryController::class)->name('directory.index');

        Route::get('/anggota', [MemberController::class, 'index'])->name('members.index');
        Route::post('/anggota', [MemberController::class, 'store'])->name('members.store');
        Route::post('/anggota/impor', [MemberController::class, 'import'])->name('members.import');
        Route::get('/anggota/impor/contoh', [MemberController::class, 'importTemplate'])->name('members.import.template');
        Route::put('/anggota/{user}', [MemberController::class, 'update'])->name('members.update');
        Route::post('/anggota/{user}/fingerprint', [MemberController::class, 'enroll'])->name('members.fingerprint.enroll');
        Route::post('/anggota/{user}/fingerprint/replace', [MemberController::class, 'replaceFingerprint'])->name('members.fingerprint.replace');
        Route::put('/anggota/{user}/sandi', [MemberController::class, 'resetPassword'])->name('members.password.reset');
        Route::delete('/anggota/{user}', [MemberController::class, 'destroy'])->name('members.destroy');

        Route::get('/kelas', [SchoolClassController::class, 'index'])->name('classes.index');
        Route::post('/kelas', [SchoolClassController::class, 'store'])->name('classes.store');
        Route::put('/kelas/{schoolClass}', [SchoolClassController::class, 'update'])->name('classes.update');
        Route::delete('/kelas/{schoolClass}', [SchoolClassController::class, 'destroy'])->name('classes.destroy');

        // Jam pelajaran: berapa sesi dalam sehari dan pukul berapa saja.
        Route::get('/jam-pelajaran', [LessonHourController::class, 'index'])->name('lesson-hours.index');
        Route::post('/jam-pelajaran', [LessonHourController::class, 'store'])->name('lesson-hours.store');
        Route::post('/jam-pelajaran/isi-otomatis', [LessonHourController::class, 'generate'])->name('lesson-hours.generate');
        Route::put('/jam-pelajaran/{lessonHour}', [LessonHourController::class, 'update'])->name('lesson-hours.update');
        Route::delete('/jam-pelajaran/{lessonHour}', [LessonHourController::class, 'destroy'])->name('lesson-hours.destroy');

        // Jadwal pelajaran mingguan: pelajaran apa, di kelas mana, jam berapa,
        // dan guru siapa yang mengajar.
        Route::get('/jadwal', [LessonScheduleController::class, 'index'])->name('schedules.index');
        Route::post('/jadwal', [LessonScheduleController::class, 'store'])->name('schedules.store');
        Route::put('/jadwal/{schedule}', [LessonScheduleController::class, 'update'])->name('schedules.update');
        Route::delete('/jadwal/{schedule}', [LessonScheduleController::class, 'destroy'])->name('schedules.destroy');

        // Pemantauan sesi absen jam pelajaran. Guru membuka absennya sendiri dari
        // halaman mengajar; admin bisa membuka, menutup, dan menunjuk guru
        // pengganti kapan saja.
        Route::get('/sesi-absen', [LessonSessionController::class, 'index'])->name('sessions.index');
        Route::post('/sesi-absen', [LessonSessionController::class, 'store'])->name('sessions.store');
        Route::get('/sesi-absen/{session}', [LessonSessionController::class, 'show'])->name('sessions.show');
        Route::put('/sesi-absen/{session}', [LessonSessionController::class, 'update'])->name('sessions.update');
        Route::post('/sesi-absen/{session}/tutup', [LessonSessionController::class, 'close'])->name('sessions.close');
        Route::post('/sesi-absen/{session}/buka', [LessonSessionController::class, 'reopen'])->name('sessions.reopen');
        Route::delete('/sesi-absen/{session}', [LessonSessionController::class, 'destroy'])->name('sessions.destroy');

        // Laporan kehadiran untuk rentang tanggal: siswa per jam pelajaran, guru
        // mengajar, dan presensi gerbang. Unduhan memakai filter yang sama.
        Route::get('/laporan', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/laporan/unduh', [ReportController::class, 'export'])->name('reports.export');

        Route::get('/debug/fingerprint', function () {
            return view('debug.fingerprint', [
                'requestData' => Cache::get('debug.last_fingerprint_scan'),
            ]);
        })->name('debug.fingerprint');

        Route::get('/presensi', [AttendanceController::class, 'index'])->name('attendance.index');
        Route::post('/presensi/jam', [AttendanceController::class, 'storeWindow'])->name('attendance.windows.store');
        Route::put('/presensi/jam/{window}', [AttendanceController::class, 'updateWindow'])->name('attendance.windows.update');
        Route::delete('/presensi/jam/{window}', [AttendanceController::class, 'destroyWindow'])->name('attendance.windows.destroy');

        Route::get('/pengaturan', [SettingController::class, 'index'])->name('settings.index');
        Route::delete('/pengaturan/log', [SettingController::class, 'destroyLogs'])->name('settings.logs.destroy');

        // Pusat kontrol perangkat sensor. Perangkat baru yang terbaca API masuk
        // sebagai `unmapped` dan baru boleh dipakai setelah didaftarkan lewat
        // route `devices.link`.
        Route::get('/perangkat', [DeviceController::class, 'index'])->name('devices.index');
        Route::put('/perangkat/{device}', [DeviceController::class, 'update'])->name('devices.update');
        Route::put('/perangkat/{device}/pendaftaran', [DeviceController::class, 'link'])->name('devices.link');
        Route::delete('/perangkat/{device}/pendaftaran', [DeviceController::class, 'unlink'])->name('devices.unlink');
        Route::put('/perangkat/{device}/status', [DeviceController::class, 'setStatus'])->name('devices.status');
        Route::put('/perangkat/{device}/layanan', [DeviceController::class, 'updateServices'])->name('devices.services');
        Route::delete('/perangkat/{device}', [DeviceController::class, 'destroy'])->name('devices.destroy');

        Route::post('/enrollments', [EnrollmentController::class, 'store'])->name('enrollments.store');
        Route::delete('/enrollments/{enrollmentSession}', [EnrollmentController::class, 'destroy'])->name('enrollments.destroy');

        // Data wajah: siapa yang terdaftar, foto mana saja yang tersimpan, dan
        // siapa guru yang ditugaskan memegang kamera wajah.
        Route::get('/wajah', [FaceDataController::class, 'index'])->name('face.index');
        Route::post('/wajah/unggah', [FaceDataController::class, 'storePhotos'])->name('face.photos.store');
        Route::post('/wajah/unggah-berkas', [FaceDataController::class, 'uploadFolderFile'])->name('face.photos.upload');
        Route::delete('/wajah/foto/{faceEmbedding}', [FaceDataController::class, 'destroyEmbedding'])->name('face.embeddings.destroy');
        Route::delete('/wajah/anggota/{user}', [FaceDataController::class, 'destroyUser'])->name('face.users.destroy');
        Route::put('/wajah/anggota/{user}/petugas', [FaceDataController::class, 'toggleOperator'])->name('face.operators.toggle');
    });

    // Kamera absen wajah. Dipakai dari HP petugas, jadi tidak semua guru boleh
    // membukanya; penugasannya lewat penanda "Petugas kamera wajah" di halaman
    // Data Wajah.
    Route::middleware('face.operator')->group(function (): void {
        Route::get('/absen-wajah', [FaceAttendanceController::class, 'index'])->name('face.attendance');
        Route::post('/absen-wajah/scan', [FaceAttendanceController::class, 'scan'])->name('face.attendance.scan');
    });
});

// API perangkat sensor. Alat memakai token perangkat, bukan sesi login, jadi
// alamat ini sengaja berada di luar pengelompokan menurut peran di atas.
Route::middleware('device.paired')->group(function (): void {
    Route::post('/api/fingerprint/register', [FingerprintApiController::class, 'register'])
        ->middleware('throttle:60,1')
        ->name('api.fingerprint.register');
    Route::get('/api/fingerprint/template/{user_id}', [FingerprintApiController::class, 'template'])
        ->middleware('throttle:60,1')
        ->name('api.fingerprint.template');
    Route::post('/api/fingerprint/match', [FingerprintApiController::class, 'match'])
        ->middleware('throttle:60,1')
        ->name('api.fingerprint.match');
    Route::get('/api/fingerprint/command', [FingerprintApiController::class, 'command'])
        ->middleware('throttle:120,1')
        ->name('api.fingerprint.command');
    Route::get('/api/fingerprint/templates', [FingerprintApiController::class, 'templates'])
        ->middleware('throttle:30,1')
        ->name('api.fingerprint.templates');
    Route::get('/api/fingerprint/users', [FingerprintApiController::class, 'users'])
        ->middleware('throttle:30,1')
        ->name('api.fingerprint.users');

    Route::post('/api/fingerprint/scan', FingerprintScanController::class)
        ->withoutMiddleware([ValidateCsrfToken::class])
        ->middleware('throttle:60,1')
        ->name('api.fingerprint.scan');

    // API pendaftaran sidik wajah dipakai skrip pendaftaran di server, bukan
    // kamera HP. Karena itu gerbangnya sama dengan API sidik jari: perangkat
    // pengirim wajib sudah didaftarkan dan aktif.
    Route::post('/api/face/register', [FaceApiController::class, 'register'])
        ->middleware('throttle:60,1')
        ->name('api.face.register');
    Route::get('/api/face/embeddings', [FaceApiController::class, 'embeddings'])
        ->middleware('throttle:60,1')
        ->name('api.face.embeddings');
});
