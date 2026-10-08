@php
    $user = auth()->user();
    $homeUrl = route($user->homeRoute());

    // Menu admin disusun menurut urutan kerja: yang dipantau setiap hari di atas,
    // data yang diisi sekali di tengah, lalu alat, laporan, dan pengaturan.
    $adminGroups = [
        'Hari Ini' => [
            [
                'label' => 'Dashboard',
                'href' => route('dashboard'),
                'active' => request()->routeIs('dashboard'),
                'icon' => '<path d="M3 10.6 12 3.5l9 7.1"/><path d="M5.6 9.6V20.5h12.8V9.6"/>',
            ],
            [
                'label' => 'Sesi Absen',
                'href' => route('sessions.index'),
                'active' => request()->routeIs('sessions.*'),
                'icon' =>
                    '<rect x="3.5" y="4.5" width="17" height="16" rx="2.5"/><path d="M3.5 9.5h17M8 3.5v3M16 3.5v3"/><path d="m8.2 15.5 2.3 2.3 4.8-5"/>',
            ],
        ],
        'Data Sekolah' => [
            [
                'label' => 'Anggota',
                'href' => route('members.index'),
                'active' => request()->routeIs('members.*'),
                'icon' =>
                    '<circle cx="9.5" cy="8.5" r="3.5"/><path d="M3.6 20.5c0-3.2 2.7-5.9 5.9-5.9s5.9 2.7 5.9 5.9"/><path d="M16.9 5.6a3.4 3.4 0 0 1 0 6.5"/><path d="M18 14.9c2 .6 3.4 2.5 3.4 4.6"/>',
            ],
            [
                'label' => 'Kelas',
                'href' => route('classes.index'),
                'active' => request()->routeIs('classes.*'),
                'icon' =>
                    '<path d="M3.5 8.6 12 4l8.5 4.6L12 13.2z"/><path d="M6.6 10.5v5.2c0 1.5 2.4 2.7 5.4 2.7s5.4-1.2 5.4-2.7v-5.2"/>',
            ],
            [
                'label' => 'Jam Pelajaran',
                'href' => route('lesson-hours.index'),
                'active' => request()->routeIs('lesson-hours.*'),
                'icon' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.4V12l3.1 2.1"/>',
            ],
            [
                'label' => 'Jadwal Pelajaran',
                'href' => route('schedules.index'),
                'active' => request()->routeIs('schedules.*'),
                'icon' =>
                    '<rect x="3.5" y="4.5" width="17" height="16" rx="2.5"/><path d="M3.5 9.5h17M8 3.5v3M16 3.5v3"/><path d="M7.5 13.5h3M13.5 13.5h3M7.5 17h3"/>',
            ],
        ],
        'Alat' => [
            [
                'label' => 'Alat Sensor',
                'href' => route('devices.index'),
                'active' => request()->routeIs('devices.*'),
                'icon' => '<rect x="5" y="2.5" width="14" height="19" rx="2.5"/><path d="M10 18.4h4"/>',
            ],
            [
                'label' => 'Sidik Jari',
                'href' => route('fingerprints.index'),
                'active' => request()->routeIs('fingerprints.*'),
                'icon' =>
                    '<path d="M12 4.5C8.4 4.5 5.5 7.4 5.5 11v2.6"/><path d="M18.5 13.2V11c0-3.6-2.9-6.5-6.5-6.5"/><path d="M8.8 20.5c-1.1-1.5-1.7-3.3-1.7-5.2V11a4.9 4.9 0 0 1 9.8 0v1.3"/><path d="M12 20.5c-1-1.3-1.5-2.8-1.5-4.4v-4.6a1.5 1.5 0 0 1 3 0v4.8"/>',
            ],
            [
                'label' => 'Data Wajah',
                'href' => route('face.index'),
                'active' => request()->routeIs('face.index'),
                'icon' =>
                    '<path d="M4 8V6.5A2.5 2.5 0 0 1 6.5 4H8M16 4h1.5A2.5 2.5 0 0 1 20 6.5V8M20 16v1.5A2.5 2.5 0 0 1 17.5 20H16M8 20H6.5A2.5 2.5 0 0 1 4 17.5V16"/><path d="M9 10v1M15 10v1"/><path d="M9.2 15c.8.8 1.8 1.2 2.8 1.2s2-.4 2.8-1.2"/>',
            ],
        ],
        'Laporan' => [
            [
                'label' => 'Laporan',
                'href' => route('reports.index'),
                'active' => request()->routeIs('reports.*'),
                'icon' => '<path d="M5 20.5V10.2M12 20.5V3.5M19 20.5v-7"/>',
            ],
        ],
        'Pengaturan' => [
            [
                'label' => 'Presensi Gerbang',
                'href' => route('attendance.index'),
                'active' => request()->routeIs('attendance.*'),
                'icon' =>
                    '<rect x="4" y="5" width="16" height="16" rx="2.5"/><path d="M8 3.5v3M16 3.5v3M4 10.5h16"/><path d="m9 15.4 2 2 4-4"/>',
            ],
            [
                'label' => 'Pengaturan',
                'href' => route('settings.index'),
                'active' => request()->routeIs('settings.*'),
                'icon' =>
                    '<circle cx="12" cy="12" r="3"/><path d="M12 3.5v2M12 18.5v2M4.9 7.8l1.7 1M17.4 15.2l1.7 1M4.9 16.2l1.7-1M17.4 8.8l1.7-1"/>',
            ],
        ],
    ];

    $teacherGroups = [
        'Mengajar' => [
            [
                'label' => 'Jadwal Mengajar',
                'href' => route('teacher.index'),
                'active' => request()->routeIs('teacher.index') || request()->routeIs('teacher.sessions.*'),
                'icon' =>
                    '<rect x="3.5" y="4.5" width="17" height="16" rx="2.5"/><path d="M3.5 9.5h17M8 3.5v3M16 3.5v3"/><path d="M7.5 13.5h3M13.5 13.5h3M7.5 17h3"/>',
            ],
            [
                'label' => 'Rekap Mengajar',
                'href' => route('teacher.recaps'),
                'active' => request()->routeIs('teacher.recaps'),
                'icon' =>
                    '<path d="M6 3.5h9.5L20 8v12.5H6A1.5 1.5 0 0 1 4.5 19V5A1.5 1.5 0 0 1 6 3.5Z"/><path d="M15 3.5V8h4.5"/><path d="M8.4 12.5h6M8.4 16h4"/>',
            ],
        ],
    ];

    if ($user->canScanFace()) {
        $teacherGroups['Presensi'][] = [
            'label' => 'Kamera Absen Wajah',
            'href' => route('face.attendance'),
            'active' => request()->routeIs('face.attendance*'),
            'icon' =>
                '<path d="M4 8V6.5A2.5 2.5 0 0 1 6.5 4H8M16 4h1.5A2.5 2.5 0 0 1 20 6.5V8M20 16v1.5A2.5 2.5 0 0 1 17.5 20H16M8 20H6.5A2.5 2.5 0 0 1 4 17.5V16"/><path d="M9 10v1M15 10v1"/><path d="M9.2 15c.8.8 1.8 1.2 2.8 1.2s2-.4 2.8-1.2"/>',
        ];
    }

    $studentGroups = [
        'Akademik' => [
            [
                'label' => 'Jadwal Pelajaran Saya',
                'href' => route('student.index'),
                'active' => request()->routeIs('student.index'),
                'icon' =>
                    '<rect x="3.5" y="4.5" width="17" height="16" rx="2.5"/><path d="M3.5 9.5h17M8 3.5v3M16 3.5v3"/><path d="m8.2 15.5 2.3 2.3 4.8-5"/>',
            ],
        ],
        'Presensi Saya' => [
            [
                'label' => 'Datang dan Pulang',
                'href' => route('student.gate-attendance'),
                'active' => request()->routeIs('student.gate-attendance'),
                'icon' => '<path d="M4 12h16M12 4v16"/><path d="m7.5 8 4.5-4 4.5 4M7.5 16l4.5 4 4.5-4"/>',
            ],
        ],
    ];

    $accountGroup = [
        'Akun' => [
            [
                'label' => 'Akun Saya',
                'href' => route('account.index'),
                'active' => request()->routeIs('account.*'),
                'icon' => '<circle cx="12" cy="8.5" r="3.8"/><path d="M4.8 20.5a7.2 7.2 0 0 1 14.4 0"/>',
            ],
        ],
    ];

    $navGroups = match ($user->role) {
        \App\Models\User::RoleTeacher => $teacherGroups + $accountGroup,
        \App\Models\User::RoleStudent => $studentGroups + $accountGroup,
        default => $adminGroups + $accountGroup,
    };
@endphp

<aside class="sidebar" id="sidebar-navigation" aria-label="Navigasi utama">
    <a class="sidebar-brand" href="{{ $homeUrl }}">
        @include('partials.tekara-logo')
        <span>
            <span class="sidebar-brand-name">{{ config('app.name') }}</span>
            <span class="sidebar-brand-note">Pusat Presensi Sekolah</span>
        </span>
    </a>

    <nav class="nav-menu">
        @foreach ($navGroups as $groupLabel => $items)
            <div class="nav-group">
                <p class="nav-group-label">{{ $groupLabel }}</p>
                @foreach ($items as $item)
                    @if ($item['href'])
                        <a class="nav-link {{ $item['active'] ? 'active' : '' }}"
                            href="{{ $item['href'] }}"{!! $item['active'] ? ' aria-current="page"' : '' !!}>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                                stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                                {!! $item['icon'] !!}
                            </svg>
                            <span>{{ $item['label'] }}</span>
                        </a>
                    @else
                        <span class="nav-link is-disabled" aria-disabled="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                                stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                                {!! $item['icon'] !!}
                            </svg>
                            <span>{{ $item['label'] }}</span>
                            <span class="nav-tag">Segera</span>
                        </span>
                    @endif
                @endforeach
            </div>
        @endforeach
    </nav>

    <div class="sidebar-foot">
        <div class="sidebar-user">
            <span class="sidebar-user-name">{{ $user->name }}</span>
            <span class="sidebar-user-role">{{ $user->roleLabel() }}@if (filled($user->class_name))
                    &middot; {{ $user->class_name }}
                @endif
            </span>
        </div>
        @include('partials.theme-toggle')
        <form method="POST" action="{{ route('logout') }}" class="sidebar-logout">
            @csrf
            <button type="submit" class="button-ghost">Keluar</button>
        </form>
    </div>
</aside>

<button type="button" class="nav-backdrop" data-nav-backdrop hidden aria-label="Tutup navigasi"></button>

<div class="topbar-mobile">
    <button type="button" class="nav-toggle" data-nav-toggle aria-expanded="false" aria-controls="sidebar-navigation"
        aria-label="Buka navigasi">
        <svg class="nav-toggle-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"
            stroke-linecap="round" aria-hidden="true" focusable="false">
            <path d="M4 7h16M4 12h16M4 17h16" />
        </svg>
        <svg class="nav-toggle-close" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"
            stroke-linecap="round" aria-hidden="true" focusable="false">
            <path d="M6 6l12 12M18 6 6 18" />
        </svg>
    </button>
    <a class="mobile-brand" href="{{ $homeUrl }}">
        @include('partials.tekara-logo')
        <span>{{ config('app.name') }}</span>
    </a>
    @include('partials.theme-toggle', ['compact' => true])
</div>
