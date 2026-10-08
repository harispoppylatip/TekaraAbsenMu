<?php

namespace App\Models;

use Database\Factories\SchoolClassFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name'])]
class SchoolClass extends Model
{
    /** @use HasFactory<SchoolClassFactory> */
    use HasFactory;

    /**
     * Anggota yang terdaftar pada kelas ini. Relasi memakai nama kelas, bukan
     * id, sebab kolom users.class_name sudah dipakai sejak awal.
     *
     * @return HasMany<User, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(User::class, 'class_name', 'name');
    }

    /**
     * Sensor yang melayani kelas ini. Sensor seperti ini hanya melayani
     * anggota kelas ini.
     *
     * @return BelongsToMany<Device, $this>
     */
    public function devices(): BelongsToMany
    {
        return $this->belongsToMany(Device::class)->withTimestamps();
    }

    /**
     * Siswa yang terdaftar pada kelas ini saja. Dipakai untuk daftar hadir
     * per jam pelajaran; admin atau guru yang kebetulan memakai nama kelas yang
     * sama tidak ikut dihitung sebagai siswa.
     *
     * @return HasMany<User, $this>
     */
    public function students(): HasMany
    {
        return $this->members()->where('role', User::RoleStudent);
    }

    /**
     * Jadwal pelajaran yang berlangsung di kelas ini.
     *
     * @return HasMany<LessonSchedule, $this>
     */
    public function lessonSchedules(): HasMany
    {
        return $this->hasMany(LessonSchedule::class)->ordered();
    }

    /**
     * Urut tanpa membedakan huruf besar dan kecil supaya daftar kelas tampil
     * dengan urutan yang sama di halaman Kelas, Anggota, dan Daftar Sidik Jari.
     *
     * @param  Builder<SchoolClass>  $query
     * @return Builder<SchoolClass>
     */
    public function scopeOrderedByName(Builder $query): Builder
    {
        return $query->orderByRaw('LOWER(name)');
    }

    /**
     * Spasi berlebih dibuang supaya "X TKJ 1 " dan "X TKJ 1" tidak tersimpan
     * sebagai dua kelas berbeda.
     */
    public static function normalizeName(mixed $value): string
    {
        return is_string($value) ? (string) preg_replace('/\s+/', ' ', trim($value)) : '';
    }

    /**
     * Ubah nama kelas yang diketik bebas menjadi nama yang benar-benar
     * tersimpan. Nama yang belum terdaftar langsung dibuat supaya anggota bisa
     * dimasukkan tanpa membuka halaman Kelas lebih dulu, sedangkan nama yang
     * sudah ada dipakai apa adanya sehingga "x tkj 1" tidak menjadi kelas
     * kedua.
     */
    public static function resolveName(?string $name): ?string
    {
        $normalized = static::normalizeName($name);

        if ($normalized === '') {
            return null;
        }

        $stored = static::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($normalized)])
            ->value('name');

        if ($stored !== null) {
            return (string) $stored;
        }

        static::create(['name' => $normalized]);

        return $normalized;
    }
}
