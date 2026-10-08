@php
    $role = $role ?? old('role', App\Models\User::RoleStudent);
    $activeStudyField = $role === App\Models\User::RoleTeacher ? 'teacher' : 'student';
    $studyFieldHidden = fn(string $key): string => $activeStudyField === $key ? '' : ' hidden';
    $classListId = $classListId ?? 'class-suggestions';
    $classValue = old('class_name', $classValue ?? '');
    $subjectValue = old('subject', $subjectValue ?? '');
@endphp
<div class="study-field" data-study-field="student"{{ $studyFieldHidden('student') }}>
    <label>Kelas
        <input name="class_name" list="{{ $classListId }}" value="{{ $classValue }}" maxlength="50"
            placeholder="Ketik atau pilih kelas">
        <datalist id="{{ $classListId }}">
            @foreach ($classes as $className)
                <option value="{{ $className }}"></option>
            @endforeach
        </datalist>
    </label>
</div>
<div class="study-field" data-study-field="teacher"{{ $studyFieldHidden('teacher') }}>
    <label>Mata Pelajaran
        <input name="subject" value="{{ $subjectValue }}" maxlength="50" placeholder="Ketik mata pelajaran">
    </label>
</div>
