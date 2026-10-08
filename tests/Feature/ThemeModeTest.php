<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThemeModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_sets_the_theme_before_styles_and_offers_a_toggle(): void
    {
        $this->withoutVite()
            ->get(route('login'))
            ->assertOk()
            ->assertSee("localStorage.getItem('theme-mode')", false)
            ->assertSee('prefers-color-scheme: light', false)
            ->assertSee('data-theme-toggle', false);
    }

    public function test_every_portal_shows_the_theme_toggle_in_its_navigation(): void
    {
        foreach ([
            'dashboard' => User::factory()->admin()->create(),
            'teacher.index' => User::factory()->teacher()->create(),
            'student.index' => User::factory()->student()->create(),
        ] as $route => $user) {
            $this->signInAs($user);

            $response = $this->withoutVite()->get(route($route))->assertOk();

            $this->assertSame(
                2,
                substr_count($response->getContent(), 'data-theme-toggle'),
                "Halaman {$route} harus punya tombol tema di sidebar dan di topbar ponsel.",
            );
        }
    }
}
