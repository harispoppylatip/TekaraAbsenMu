<?php

namespace Tests\Feature;

use App\Models\LessonHour;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LessonHourTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signInAsAdmin();
    }

    public function test_page_lists_the_hours_in_order(): void
    {
        $this->lessonHour(2);
        $this->lessonHour(1);

        $this->withoutVite()
            ->get(route('lesson-hours.index'))
            ->assertOk()
            ->assertSee('Jam Pelajaran')
            ->assertSee('Jam ke-1')
            ->assertSee('07:00 - 07:45')
            ->assertSee('Jam ke-2')
            ->assertSee('2 jam');
    }

    public function test_page_suggests_the_next_number_and_the_generator_while_empty(): void
    {
        $this->lessonHour(1);

        $this->withoutVite()
            ->get(route('lesson-hours.index'))
            ->assertOk()
            ->assertDontSee('Isi Otomatis');

        LessonHour::query()->delete();

        $this->withoutVite()
            ->get(route('lesson-hours.index'))
            ->assertOk()
            ->assertSee('Isi Otomatis')
            ->assertSee('Tambah Jam');
    }

    public function test_admin_can_add_an_hour(): void
    {
        $this->post(route('lesson-hours.store'), [
            'number' => 1,
            'starts_at' => '07:00',
            'ends_at' => '07:45',
        ])->assertRedirect(route('lesson-hours.index'))
            ->assertSessionHas('success', 'Jam ke-1 07:00 - 07:45 berhasil ditambahkan.');

        $this->assertDatabaseHas('lesson_hours', [
            'number' => 1,
            'starts_at' => '07:00',
            'ends_at' => '07:45',
        ]);
    }

    public function test_hour_number_must_be_unique_and_the_range_must_make_sense(): void
    {
        $this->lessonHour(1);

        $this->from(route('lesson-hours.index'))
            ->post(route('lesson-hours.store'), [
                'number' => 1,
                'starts_at' => '09:00',
                'ends_at' => '09:45',
            ])
            ->assertRedirect(route('lesson-hours.index'))
            ->assertSessionHasErrors(['number' => 'Nomor jam itu sudah dipakai jam lain.']);

        $this->from(route('lesson-hours.index'))
            ->post(route('lesson-hours.store'), [
                'number' => 2,
                'starts_at' => '10:00',
                'ends_at' => '09:00',
            ])
            ->assertRedirect(route('lesson-hours.index'))
            ->assertSessionHasErrors('ends_at');

        $this->assertSame(1, LessonHour::count());
    }

    public function test_overlapping_hours_are_refused(): void
    {
        $this->lessonHour(1, '07:00', '07:45');

        $this->from(route('lesson-hours.index'))
            ->post(route('lesson-hours.store'), [
                'number' => 2,
                'starts_at' => '07:30',
                'ends_at' => '08:15',
            ])
            ->assertRedirect(route('lesson-hours.index'))
            ->assertSessionHasErrors(['ends_at' => 'Jam bertabrakan dengan Jam ke-1 07:00 - 07:45.']);

        $this->assertSame(1, LessonHour::count());
    }

    public function test_hour_can_be_updated_but_not_into_an_overlap(): void
    {
        $first = $this->lessonHour(1, '07:00', '07:45');
        $second = $this->lessonHour(2, '07:45', '08:30');

        $this->put(route('lesson-hours.update', $second), [
            'number' => 2,
            'starts_at' => '07:45',
            'ends_at' => '08:35',
        ])->assertRedirect(route('lesson-hours.index'))
            ->assertSessionHas('success', 'Jam ke-2 07:45 - 08:35 berhasil diperbarui.');

        $this->assertSame('08:35', $second->fresh()->ends_at);

        $this->from(route('lesson-hours.index'))
            ->put(route('lesson-hours.update', $second), [
                'number' => 2,
                'starts_at' => '07:30',
                'ends_at' => '08:35',
            ])
            ->assertRedirect(route('lesson-hours.index'))
            ->assertSessionHasErrors('ends_at');

        $this->assertSame('07:45', $second->fresh()->starts_at);
        $this->assertSame('07:45', $first->fresh()->ends_at);
    }

    public function test_saving_the_same_values_reports_that_nothing_changed(): void
    {
        $hour = $this->lessonHour(1, '07:00', '07:45');

        $this->put(route('lesson-hours.update', $hour), [
            'number' => 1,
            'starts_at' => '07:00',
            'ends_at' => '07:45',
        ])->assertRedirect(route('lesson-hours.index'))
            ->assertSessionHas('warning', 'Jam ini masih sama, tidak ada yang diperbarui.');
    }

    public function test_hour_that_is_still_used_by_a_schedule_cannot_be_deleted(): void
    {
        $hour = $this->lessonHour(1);
        $teacher = User::factory()->teacher()->create();
        $class = SchoolClass::create(['name' => 'X TKJ 1']);

        $this->lessonSchedule($class, $teacher, $hour);

        $this->delete(route('lesson-hours.destroy', $hour))
            ->assertRedirect(route('lesson-hours.index'))
            ->assertSessionHasErrors('hour');

        $this->assertDatabaseHas('lesson_hours', ['id' => $hour->getKey()]);
    }

    public function test_unused_hour_can_be_deleted(): void
    {
        $hour = $this->lessonHour(3);

        $this->delete(route('lesson-hours.destroy', $hour))
            ->assertRedirect(route('lesson-hours.index'))
            ->assertSessionHas('success', 'Jam ke-3 08:30 - 09:15 berhasil dihapus.');

        $this->assertSame(0, LessonHour::count());
    }

    public function test_admin_can_fill_the_whole_day_at_once(): void
    {
        $this->post(route('lesson-hours.generate'), [
            'starts_at' => '07:00',
            'duration' => 45,
            'total' => 4,
        ])->assertRedirect(route('lesson-hours.index'))
            ->assertSessionHas('success');

        $this->assertSame(4, LessonHour::count());
        $this->assertSame('07:00', LessonHour::query()->where('number', 1)->value('starts_at'));
        $this->assertSame('09:15', LessonHour::query()->where('number', 4)->value('starts_at'));
        $this->assertSame('10:00', LessonHour::query()->where('number', 4)->value('ends_at'));
    }

    public function test_generator_refuses_to_overwrite_an_existing_list(): void
    {
        $this->lessonHour(1);

        $this->post(route('lesson-hours.generate'), [
            'starts_at' => '07:00',
            'duration' => 45,
            'total' => 4,
        ])->assertRedirect(route('lesson-hours.index'))
            ->assertSessionHasErrors('hour');

        $this->assertSame(1, LessonHour::count());
    }

    public function test_generator_refuses_a_list_that_runs_past_midnight(): void
    {
        $this->post(route('lesson-hours.generate'), [
            'starts_at' => '20:00',
            'duration' => 60,
            'total' => 8,
        ])->assertRedirect(route('lesson-hours.index'))
            ->assertSessionHasErrors('total');

        $this->assertSame(0, LessonHour::count());
    }
}
