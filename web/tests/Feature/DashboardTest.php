<?php

namespace Tests\Feature;

use App\Support\Dashboard;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use DatabaseTransactions;

    public function test_normalisasi_susunan(): void
    {
        $this->assertSame(Dashboard::DEFAULT, Dashboard::normalize(null));
        $this->assertSame([], Dashboard::normalize([]));
        $this->assertSame([['k' => 'kpi', 'w' => 'full'], ['k' => 'logs', 'w' => 'full']],
            Dashboard::normalize([['k' => 'kpi', 'w' => 'half'], ['k' => 'hack', 'w' => 'half'], ['k' => 'logs', 'w' => 'full'], ['k' => 'logs', 'w' => 'half']]));
    }

    public function test_simpan_reset_dan_semua_widget_tampil(): void
    {
        $u = $this->user('viewer');
        $all = array_keys(Dashboard::WIDGETS);
        $this->actingAs($u)->put('/ringkasan/susunan', ['order' => array_reverse($all), 'show' => $all, 'size' => array_fill_keys($all, 'full')])
            ->assertRedirect(route('dashboard'));
        $this->assertSame(array_reverse($all), array_column($u->fresh()->dashboard, 'k'));
        $page = $this->actingAs($u)->get('/')->assertOk();
        foreach (Dashboard::WIDGETS as [$title]) {
            $page->assertSee($title);
        }
        $this->actingAs($u)->put('/ringkasan/susunan', ['reset' => 1])->assertRedirect(route('dashboard'));
        $this->assertNull($u->fresh()->dashboard);
    }
}
