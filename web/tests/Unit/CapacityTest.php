<?php

namespace Tests\Unit;

use App\Services\Capacity;
use PHPUnit\Framework\TestCase;

class CapacityTest extends TestCase
{
    private function series(int $days, callable $f): array
    {
        $t0 = 1_780_000_000 - 1_780_000_000 % 86400;
        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $out[$t0 + $i * 86400] = $f($i);
        }

        return $out;
    }

    public function test_tren_naik_diperkirakan_penuh(): void
    {
        // 100 Mbps port, batas 80% = 80 Mbps; naik 1 Mbps/hari dari 20 → hari ke-29 = 49 Mbps → 31 hari lagi
        $r = Capacity::forecast($this->series(30, fn ($i) => (20 + $i) * 1e6), 100e6, 80);
        $this->assertEqualsWithDelta(1e6, $r['slope'], 1);
        $this->assertEqualsWithDelta(31, $r['days_left'], 0.01);
        $this->assertSame('watch', $r['state']);
        $this->assertEqualsWithDelta(1.0, $r['r2'], 1e-9);
        $this->assertEqualsWithDelta(46e6, $r['current'], 1); // rata-rata 7 hari terakhir (43..49)
    }

    public function test_status_lain(): void
    {
        $this->assertSame('ok', Capacity::forecast($this->series(30, fn ($i) => 50e6 - $i * 1e5), 100e6, 80)['state']);
        $this->assertSame('over', Capacity::forecast($this->series(10, fn () => 90e6), 100e6, 80)['state']);
        $this->assertSame('soon', Capacity::forecast($this->series(20, fn ($i) => (60 + $i) * 1e6), 100e6, 80)['state']);
        $this->assertSame('short', Capacity::forecast($this->series(5, fn () => 1e6), 100e6, 80)['state']);
        $this->assertSame('nodata', Capacity::forecast([], 100e6, 80)['state']);
        $this->assertSame('nospeed', Capacity::forecast($this->series(10, fn ($i) => $i * 1e6), 0, 80)['state']);
    }

    public function test_format_bps(): void
    {
        $this->assertSame('950 bps', Capacity::bps(950));
        $this->assertSame('1,5 Mbps', Capacity::bps(1.5e6));
        $this->assertSame('120 Mbps', Capacity::bps(120e6));
        $this->assertSame('−2,0 Mbps', Capacity::bps(-2e6));
        $this->assertSame('tidak ada', Capacity::bps(null));
    }
}
