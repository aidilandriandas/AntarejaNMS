<?php

namespace Tests\Feature;

use App\Models\Device;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeviceBulkTest extends TestCase
{
    use DatabaseTransactions;

    private function devs(int $n): array
    {
        return array_map(fn ($i) => DB::table('devices')->insertGetId(['name' => "uji-massal-$i", 'host' => "10.77.0.$i"]), range(1, $n));
    }

    public function test_pindah_site_kategori_dan_nonaktif(): void
    {
        $ids = $this->devs(3);
        $site = DB::table('sites')->insertGetId(['code' => 'PIT-M', 'name' => 'Pit Massal']);
        $eng = $this->user('engineer');
        $this->actingAs($eng)->get('/perangkat')->assertOk()->assertSee('data-bulk', false);
        $this->actingAs($eng)->post('/perangkat/massal', ['ids' => $ids, 'action' => 'site', 'site_id' => $site])->assertSessionHas('ok');
        $this->assertSame(3, Device::whereIn('id', $ids)->where('site_id', $site)->count());
        $this->post('/perangkat/massal', ['ids' => [$ids[0], $ids[1]], 'action' => 'category', 'category' => 'cctv']);
        $this->assertSame(2, Device::whereIn('id', $ids)->where('category', 'cctv')->count());
        $this->post('/perangkat/massal', ['ids' => $ids, 'action' => 'disable']);
        $this->assertSame(0, Device::whereIn('id', $ids)->where('enabled', true)->count());
        // satu catatan audit per perangkat
        $this->assertSame(3, DB::table('audit_logs')->whereIn('subject_id', $ids)->where('description', 'like', '%Pindahkan ke site%')->count());
    }

    public function test_induk_melingkar_dilewati(): void
    {
        [$a, $b, $c] = $this->devs(3);
        DB::table('devices')->where('id', $b)->update(['parent_id' => $a]); // a -> b
        $this->actingAs($this->user('engineer'))->post('/perangkat/massal', ['ids' => [$a, $c], 'action' => 'parent', 'parent_id' => $b])
            ->assertSessionHas('ok', fn ($m) => str_contains($m, 'melingkar') && str_contains($m, 'uji-massal-1'));
        $this->assertNull(Device::find($a)->parent_id);
        $this->assertSame($b, Device::find($c)->parent_id);
    }

    public function test_snmp_massal_terenkripsi_dan_wajib_lengkap(): void
    {
        $ids = $this->devs(2);
        $eng = $this->user('engineer');
        $this->actingAs($eng)->post('/perangkat/massal', ['ids' => $ids, 'action' => 'snmp', 'snmp_version' => '2c'])->assertSessionHasErrors('snmp_community');
        $this->actingAs($eng)->post('/perangkat/massal', ['ids' => $ids, 'action' => 'snmp', 'snmp_version' => '3', 'snmp_v3_user' => 'nms', 'snmp_v3_level' => 'authPriv', 'snmp_v3_auth_pass' => 'AuthPass99'])
            ->assertSessionHasErrors('snmp_v3_priv_pass');
        $this->actingAs($eng)->post('/perangkat/massal', ['ids' => $ids, 'action' => 'snmp', 'snmp_version' => '2c', 'snmp_community' => 'TambangRO7'])->assertSessionHas('ok');
        foreach (Device::whereIn('id', $ids)->get() as $d) {
            $this->assertTrue($d->snmp_enabled);
            $this->assertSame('TambangRO7', Crypt::decryptString($d->snmp_community_enc));
        }
        $this->assertFalse(DB::table('audit_logs')->whereRaw('changes::text like ?', ['%TambangRO7%'])->exists(), 'community tidak boleh tersimpan di audit');
    }

    public function test_hapus_butuh_konfirmasi_dan_viewer_ditolak(): void
    {
        $ids = $this->devs(2);
        $this->actingAs($this->user('viewer'))->post('/perangkat/massal', ['ids' => $ids, 'action' => 'disable'])->assertForbidden();
        $this->flushSession();
        $eng = $this->user('engineer');
        $this->actingAs($eng)->post('/perangkat/massal', ['ids' => $ids, 'action' => 'delete'])->assertSessionHasErrors('confirm');
        $this->assertSame(2, Device::whereIn('id', $ids)->count());
        $this->actingAs($eng)->post('/perangkat/massal', ['ids' => $ids, 'action' => 'delete', 'confirm' => 'HAPUS'])->assertSessionHas('ok');
        $this->assertSame(0, Device::whereIn('id', $ids)->count());
    }
}
