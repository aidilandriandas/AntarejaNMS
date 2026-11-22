<?php

namespace Tests\Unit;

use App\Services\ServiceSla;
use PHPUnit\Framework\TestCase;

class ServiceSlaTest extends TestCase
{
    public function test_union_dan_kurangi(): void
    {
        $this->assertSame([[0, 15], [20, 30]], ServiceSla::union([[20, 30], [0, 10], [5, 15], [3, 3]]));
        $this->assertSame([[0, 5], [8, 10], [40, 50]], ServiceSla::subtract([[0, 10], [30, 50]], [[5, 8], [25, 40]]));
        $this->assertSame([], ServiceSla::subtract([[10, 20]], [[0, 30]]));
        $this->assertSame(25.0, (float) ServiceSla::length([[0, 15], [20, 30]]));
    }

    public function test_komponen_dengan_cadangan(): void
    {
        $a = [[0, 100]];          // ISP 1 down 0–100
        $b = [[50, 150]];         // ISP 2 down 50–150
        // cukup 1 dari 2 hidup → down hanya saat keduanya down
        $this->assertSame([[50, 100]], ServiceSla::belowMin([$a, $b], 2, 1));
        // keduanya wajib → down saat salah satu down
        $this->assertSame([[0, 150]], ServiceSla::belowMin([$a, $b], 2, 2));
        // bersambung tepat di detik yang sama: tidak dianggap tumpang tindih
        $this->assertSame([], ServiceSla::belowMin([[[0, 50]], [[50, 90]]], 2, 1));
        // 2 dari 3
        $this->assertSame([[20, 30]], ServiceSla::belowMin([[[0, 30]], [[20, 40]], []], 3, 2));
        $this->assertSame([], ServiceSla::belowMin([], 0, 1));
    }
}
