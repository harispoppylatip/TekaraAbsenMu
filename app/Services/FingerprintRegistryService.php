<?php

namespace App\Services;

use App\Models\Fingerprint;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class FingerprintRegistryService
{
    /**
     * Simpan tepat satu template untuk setiap kombinasi pengguna dan posisi jari.
     * Pendaftaran ulang pada jari yang sama akan memperbarui template lama, bukan menambah baris baru.
     */
    public function store(User $user, string $template, ?string $fingerPosition = null): Fingerprint
    {
        return $user->fingerprints()->updateOrCreate(
            ['finger_position' => $fingerPosition ?: Fingerprint::DefaultFingerPosition],
            ['template' => $template],
        );
    }

    /**
     * Sisakan template paling baru untuk setiap pengguna dan posisi jari.
     *
     * @return int jumlah baris duplikat yang dihapus
     */
    public function removeDuplicates(): int
    {
        $groups = DB::table('fingerprints')
            ->select('user_id', 'finger_position', DB::raw('MAX(id) as newest_id'))
            ->groupBy('user_id', 'finger_position')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $removed = 0;

        foreach ($groups as $group) {
            $removed += DB::table('fingerprints')
                ->where('user_id', $group->user_id)
                ->where('finger_position', $group->finger_position)
                ->where('id', '<', $group->newest_id)
                ->delete();
        }

        return $removed;
    }
}
