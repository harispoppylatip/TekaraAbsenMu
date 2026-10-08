<?php

namespace App\Http\Controllers;

use App\Models\EnrollmentSession;
use App\Models\User;
use App\Services\FingerprintScanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EnrollmentController extends Controller
{
    public function store(Request $request, FingerprintScanService $service): RedirectResponse
    {
        $data = $request->validate([
            'enrollment_session_id' => ['required', 'integer', 'exists:enrollment_sessions,id'],
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'unique:users,email'],
            'identifier_number' => ['nullable', 'string', 'max:50', 'unique:users,identifier_number'],
            'role' => ['required', 'in:student,teacher,admin'],
            'class_name' => ['nullable', 'string', 'max:50'],
            'subject' => ['nullable', 'string', 'max:50'],
            'finger_position' => ['required', 'string', 'max:30'],
        ]);

        $data = User::applyStudyFields($data);

        $session = EnrollmentSession::findOrFail($data['enrollment_session_id']);
        $service->saveEnrollment($session, $data + ['password' => str()->random(32)]);

        return back()->with('success', 'Pengguna dan sidik jari berhasil disimpan.');
    }

    public function destroy(EnrollmentSession $enrollmentSession): RedirectResponse
    {
        abort_unless(in_array($enrollmentSession->status, ['waiting_tap_1', 'waiting_tap_2', 'ready'], true), 403, 'Sesi yang sudah selesai tidak dapat dihapus.');

        $enrollmentSession->delete();

        return back()->with('success', 'Sesi sidik jari berhasil dihapus.');
    }
}
