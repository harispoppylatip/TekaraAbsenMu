<?php

namespace App\Http\Controllers;

use App\Models\SchoolClass;
use App\Models\User;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SchoolClassController extends Controller
{
    public function index(): View
    {
        $classes = SchoolClass::query()
            ->withCount('members')
            ->with(['devices', 'lessonSchedules.lessonHour', 'lessonSchedules.teacher'])
            ->orderedByName()
            ->get();

        return view('classes.index', [
            'classes' => $classes,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $name = SchoolClass::normalizeName($request->input('name'));

        $request->merge(['name' => $name]);

        $request->validate([
            'name' => ['required', 'string', 'max:50', $this->nameIsFreeRule()],
        ], $this->messages());

        SchoolClass::create(['name' => $name]);

        return to_route('classes.index')->with('success', 'Kelas berhasil ditambahkan.');
    }

    /**
     * Ganti nama kelas sekaligus memperbarui seluruh anggotanya supaya daftar
     * kelas dan data anggota tidak pernah berbeda.
     */
    public function update(Request $request, SchoolClass $schoolClass): RedirectResponse
    {
        $name = SchoolClass::normalizeName($request->input('name'));

        $request->merge(['name' => $name]);

        $request->validate([
            'name' => ['required', 'string', 'max:50', $this->nameIsFreeRule($schoolClass)],
        ], $this->messages());

        $oldName = $schoolClass->name;

        if ($name === $oldName) {
            return to_route('classes.index')->with('warning', 'Nama kelas masih sama, tidak ada yang diperbarui.');
        }

        $members = DB::transaction(function () use ($schoolClass, $oldName, $name): int {
            $updated = User::query()->where('class_name', $oldName)->update(['class_name' => $name]);

            $schoolClass->update(['name' => $name]);

            return $updated;
        });

        return to_route('classes.index')->with('success', 'Nama kelas berhasil diperbarui. '.$members.' anggota ikut diperbarui.');
    }

    /**
     * Kelas yang masih dipakai anggota tidak boleh dihapus supaya tidak ada
     * anggota yang kelasnya hilang dari daftar.
     */
    public function destroy(SchoolClass $schoolClass): RedirectResponse
    {
        $members = $schoolClass->members()->count();

        if ($members > 0) {
            return to_route('classes.index')->withErrors([
                'class_name' => 'Kelas ini masih memiliki '.$members.' anggota. Pindahkan anggota terlebih dahulu sebelum menghapus kelas.',
            ]);
        }

        $schoolClass->delete();

        return to_route('classes.index')->with('success', 'Kelas berhasil dihapus.');
    }

    /**
     * Nama kelas harus unik tanpa membedakan huruf besar dan kecil.
     */
    private function nameIsFreeRule(?SchoolClass $ignore = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($ignore): void {
            $exists = SchoolClass::query()
                ->whereRaw('LOWER(name) = ?', [mb_strtolower((string) $value)])
                ->when($ignore !== null, fn ($query) => $query->whereKeyNot($ignore->getKey()))
                ->exists();

            if ($exists) {
                $fail('Kelas '.$value.' sudah terdaftar.');
            }
        };
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'name.required' => 'Nama kelas wajib diisi.',
            'name.max' => 'Nama kelas maksimal 50 karakter.',
        ];
    }
}
