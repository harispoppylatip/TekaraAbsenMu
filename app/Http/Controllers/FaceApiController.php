<?php

namespace App\Http\Controllers;

use App\Models\FaceEmbedding;
use App\Models\User;
use App\Services\FaceEnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API pendaftaran sidik wajah.
 *
 * Alamat ini dipakai oleh skrip pendaftaran di server (folder foto per NIS),
 * bukan oleh kamera HP. Karena itu ia berada di grup `device.paired`, sama
 * seperti API sidik jari: perangkat yang mengirim data wajib sudah didaftarkan
 * dan aktif di halaman Alat Sensor.
 *
 * Aturan penyimpanannya sama dengan unggahan dari halaman Wajah, karena
 * keduanya memakai FaceEnrollmentService.
 */
class FaceApiController extends Controller
{
    /** Batas foto wajah per anggota. Aturannya ada di FaceEnrollmentService. */
    public const MaxPhotosPerUser = FaceEnrollmentService::MaxPhotosPerUser;

    public function __construct(private FaceEnrollmentService $enrollment) {}

    /**
     * Simpan angka pembanding wajah dari sebuah foto.
     *
     * Kirim `replace` bernilai benar untuk mengganti seluruh sidik wajah anggota
     * itu (dipakai saat mendaftarkan ulang dari folder foto), atau biarkan
     * kosong untuk menambah satu sudut wajah lagi.
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_id' => ['required', 'string', 'max:50'],
            'user_id' => ['required', 'string', 'max:50', 'exists:users,identifier_number'],
            'embedding' => ['required', 'array', 'size:'.FaceEmbedding::Dimensions],
            'embedding.*' => ['numeric'],
            'label' => ['nullable', 'string', 'max:50'],
            'replace' => ['nullable', 'boolean'],
        ]);

        $user = User::query()->where('identifier_number', $data['user_id'])->firstOrFail();

        $embedding = $this->enrollment->store(
            $user,
            $data['embedding'],
            $data['label'] ?? null,
            (bool) ($data['replace'] ?? false),
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Sidik wajah '.$user->name.' berhasil disimpan.',
            'data' => [
                'id' => $embedding->id,
                'user_id' => $data['user_id'],
                'label' => $embedding->label,
                'total' => $this->enrollment->photoCount($user),
            ],
        ], 201);
    }

    /**
     * Daftar anggota yang sudah punya sidik wajah, dipakai skrip pendaftaran
     * untuk melaporkan siapa saja yang sudah dan belum terdaftar.
     */
    public function embeddings(Request $request): JsonResponse
    {
        $request->validate([
            'device_id' => ['required', 'string', 'max:50'],
        ]);

        $rows = FaceEmbedding::query()
            ->with('user')
            ->get()
            ->groupBy('user_id')
            ->map(fn ($group) => [
                'user_id' => $group->first()->user?->identifier_number,
                'name' => $group->first()->user?->name,
                'photos' => $group->count(),
            ])
            ->values();

        return response()->json([
            'status' => 'success',
            'data' => $rows,
        ]);
    }
}
