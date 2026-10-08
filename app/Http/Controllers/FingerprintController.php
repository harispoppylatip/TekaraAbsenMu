<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\EnrollmentSession;
use App\Models\Fingerprint;
use App\Models\FingerprintApiLog;
use App\Models\SchoolClass;
use Illuminate\View\View;

class FingerprintController extends Controller
{
    public function index(): View
    {
        return view('fingerprints.index', [
            'fingerprints' => Fingerprint::with('user')->latest()->get(),
            'scanLogs' => AttendanceLog::with('user')->latest('scanned_at')->limit(50)->get(),
            'pendingEnrollments' => EnrollmentSession::whereIn('status', ['waiting_tap_1', 'waiting_tap_2'])->latest()->get(),
            'readyEnrollments' => EnrollmentSession::where('status', 'ready')->latest()->get(),
            'apiLogs' => FingerprintApiLog::with('matchedUser')->latest()->limit(50)->get(),
            'classes' => SchoolClass::query()->orderedByName()->pluck('name'),
        ]);
    }
}
