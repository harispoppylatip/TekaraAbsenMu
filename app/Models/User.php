<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Validation\Rule;

#[Fillable([
    'name',
    'email',
    'password',
    'must_change_password',
    'password_changed_at',
    'role',
    'identifier_number',
    'class_name',
    'subject',
    'can_scan_face',
    'phone_number',
    'status',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const RoleStudent = 'student';

    public const RoleTeacher = 'teacher';

    public const RoleAdmin = 'admin';

    /** @var array<int, string> */
    public const Roles = [self::RoleStudent, self::RoleTeacher, self::RoleAdmin];

    /** Kata sandi bawaan guru dan admin. Siswa memakai nomor induknya. */
    public const DefaultPassword = 'tekara123';

    public const StatusActive = 'active';

    public const StatusInactive = 'inactive';

    /** @var array<string, string> */
    public const RoleLabels = [
        self::RoleAdmin => 'Admin',
        self::RoleTeacher => 'Guru',
        self::RoleStudent => 'Siswa',
    ];

    public function fingerprints()
    {
        return $this->hasMany(Fingerprint::class);
    }

    /**
     * Sidik wajah anggota ini. Satu anggota bisa punya beberapa baris karena
     * pendaftaran memakai beberapa foto dari sudut yang berbeda.
     *
     * @return HasMany<FaceEmbedding, $this>
     */
    public function faceEmbeddings(): HasMany
    {
        return $this->hasMany(FaceEmbedding::class);
    }

    public function attendanceLogs()
    {
        return $this->hasMany(AttendanceLog::class);
    }

    /**
     * Jadwal pelajaran yang diampu guru ini. Inilah yang menentukan kelas mana
     * yang boleh dia buka absennya.
     *
     * @return HasMany<LessonSchedule, $this>
     */
    public function lessonSchedules(): HasMany
    {
        return $this->hasMany(LessonSchedule::class, 'user_id')->ordered();
    }

    /**
     * Sesi absen yang dibuka guru ini, dipakai admin untuk menelusuri riwayat.
     *
     * @return HasMany<LessonSession, $this>
     */
    public function openedLessonSessions(): HasMany
    {
        return $this->hasMany(LessonSession::class, 'opened_by');
    }

    public function isStudent(): bool
    {
        return $this->role === self::RoleStudent;
    }

    public function isTeacher(): bool
    {
        return $this->role === self::RoleTeacher;
    }

    public function isAdmin(): bool
    {
        return $this->role === self::RoleAdmin;
    }

    /**
     * Anggota ini ditugaskan memakai kamera absen wajah. Perannya tetap guru,
     * jadi penugasannya cukup lewat penanda ini.
     */
    public function canScanFace(): bool
    {
        return (bool) $this->can_scan_face;
    }

    public function roleLabel(): string
    {
        return self::RoleLabels[$this->role] ?? ucfirst((string) $this->role);
    }

    /**
     * Halaman utama menurut peran, dipakai setelah login dan saat sebuah
     * halaman ditolak karena bukan hak perannya.
     */
    public function homeRoute(): string
    {
        return match ($this->role) {
            self::RoleAdmin => 'dashboard',
            self::RoleTeacher => 'teacher.index',
            default => 'student.index',
        };
    }

    /**
     * Kata sandi bawaan akun baru. Guru dan admin memakai satu kata sandi
     * bersama, siswa memakai nomor induknya sendiri supaya mudah diingat pada
     * login pertama.
     */
    public static function defaultPasswordFor(string $role, mixed $identifierNumber): string
    {
        if ($role !== self::RoleStudent) {
            return self::DefaultPassword;
        }

        $identifier = is_string($identifierNumber) ? trim($identifierNumber) : '';

        return $identifier === '' ? self::DefaultPassword : $identifier;
    }

    /**
     * Kata sandi bawaan sudah diganti sehingga halaman lain boleh dibuka.
     */
    public function hasChangedPassword(): bool
    {
        return ! (bool) $this->must_change_password;
    }

    /**
     * Nama kolom akademik anggota ini. Guru memakai mata pelajaran, siswa dan
     * admin tetap memakai kelas.
     */
    public function studyFieldLabel(): string
    {
        return $this->isTeacher() ? 'Mata Pelajaran' : 'Kelas';
    }

    /**
     * Isi kolom akademik anggota ini sesuai perannya.
     */
    public function studyFieldValue(): ?string
    {
        return $this->isTeacher() ? $this->subject : $this->class_name;
    }

    /**
     * Rapikan mata pelajaran yang diketik bebas. Mata pelajaran belum punya
     * data induk, jadi hanya spasi berlebih yang dibuang.
     */
    public static function normalizeSubject(mixed $value): ?string
    {
        $normalized = is_string($value) ? (string) preg_replace('/\s+/', ' ', trim($value)) : '';

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Aturan data anggota yang sama untuk formulir tambah, ubah, dan impor
     * CSV. Saat mengubah, keunikan email dan nomor induk mengabaikan anggota
     * yang sedang diubah.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function memberRules(?self $ignore = null): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($ignore)],
            'identifier_number' => ['required', 'string', 'max:50', Rule::unique('users', 'identifier_number')->ignore($ignore)],
            'role' => ['required', Rule::in(self::Roles)],
            'class_name' => ['nullable', 'string', 'max:50'],
            'subject' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * Tentukan kolom akademik yang benar-benar disimpan menurut peran. Guru
     * hanya menyimpan mata pelajaran dan kelasnya dikosongkan, supaya nama mata
     * pelajaran tidak ikut terdaftar sebagai kelas di halaman Kelas. Peran lain
     * hanya menyimpan kelas seperti sebelumnya.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function applyStudyFields(array $data): array
    {
        $isTeacher = ($data['role'] ?? null) === self::RoleTeacher;

        $data['class_name'] = $isTeacher ? null : SchoolClass::resolveName($data['class_name'] ?? null);
        $data['subject'] = $isTeacher ? self::normalizeSubject($data['subject'] ?? null) : null;

        return $data;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'password_changed_at' => 'datetime',
            'can_scan_face' => 'boolean',
        ];
    }
}
