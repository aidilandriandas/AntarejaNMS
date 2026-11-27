<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BackupOffsiteTest extends TestCase
{
    use DatabaseTransactions;

    public function test_setelan_salinan_luar_server(): void
    {
        $admin = $this->user('admin');
        $this->flushSession();
        $sftp = ['type' => 'sftp', 'keep' => 20, 'host' => 'nas.kantor.local', 'port' => 22, 'user' => 'backup', 'dir' => '/backup/antareja'];
        $this->actingAs($admin)->put('/backup/luar-server', $sftp)->assertSessionHasErrors('password');
        $this->actingAs($admin)->put('/backup/luar-server', $sftp + ['password' => 'sandi-nas-rahasia'])->assertSessionHas('ok');
        $this->assertSame('nas.kantor.local', Setting::get('backup.offsite')['host']);
        $sec = json_decode(Crypt::decryptString(Setting::get('backup.offsite_secret')), true);
        $this->assertSame('sandi-nas-rahasia', $sec['password']);
        $this->assertStringNotContainsString('sandi-nas-rahasia', (string) DB::table('settings')->where('key', 'backup.offsite_secret')->value('value'));

        // halaman tidak pernah menampilkan rahasia; kosong = pertahankan
        $this->actingAs($admin)->get('/backup')->assertOk()->assertSee('Salinan ke luar server')->assertSee('tersimpan, kosongkan bila tidak diganti')
            ->assertDontSee('sandi-nas-rahasia');
        Setting::put('backup.offsite_hostkey', 'SHA256:abc');
        $this->actingAs($admin)->put('/backup/luar-server', $sftp + ['keep' => 25])->assertSessionHas('ok');
        $this->assertSame('sandi-nas-rahasia', json_decode(Crypt::decryptString(Setting::get('backup.offsite_secret')), true)['password']);
        $this->assertSame('SHA256:abc', Setting::get('backup.offsite_hostkey'), 'host sama: host key tetap');
        // frasa enkripsi: minimal 12, harus sama; tersimpan terenkripsi; kosong = tetap; dicentang = mati
        $this->actingAs($admin)->put('/backup/luar-server', $sftp + ['passphrase' => 'pendek', 'passphrase_confirmation' => 'pendek'])->assertSessionHasErrors('passphrase');
        $this->actingAs($admin)->put('/backup/luar-server', $sftp + ['passphrase' => 'frasa-backup-tambang-2026', 'passphrase_confirmation' => 'beda'])->assertSessionHasErrors('passphrase');
        $this->actingAs($admin)->put('/backup/luar-server', $sftp + ['passphrase' => 'frasa-backup-tambang-2026', 'passphrase_confirmation' => 'frasa-backup-tambang-2026'])->assertSessionHas('ok');
        $sec = json_decode(Crypt::decryptString(Setting::get('backup.offsite_secret')), true);
        $this->assertSame(['sandi-nas-rahasia', 'frasa-backup-tambang-2026'], [$sec['password'], $sec['passphrase']]);
        $this->actingAs($admin)->get('/backup')->assertOk()->assertSee('Salinan dienkripsi (AES-256)')->assertDontSee('frasa-backup-tambang-2026');
        $this->actingAs($admin)->put('/backup/luar-server', $sftp)->assertSessionHas('ok');
        $this->assertSame('frasa-backup-tambang-2026', json_decode(Crypt::decryptString(Setting::get('backup.offsite_secret')), true)['passphrase']);
        $this->actingAs($admin)->put('/backup/luar-server', $sftp + ['clear_passphrase' => 1])->assertSessionHas('ok');
        $this->assertSame('', json_decode(Crypt::decryptString(Setting::get('backup.offsite_secret')), true)['passphrase']);

        // ganti server: host key dicatat ulang
        $this->actingAs($admin)->put('/backup/luar-server', ['host' => 'nas2.kantor.local'] + $sftp)->assertSessionHas('ok');
        $this->assertSame('', Setting::get('backup.offsite_hostkey'));

        // S3: validasi bucket & secret
        $s3 = ['type' => 's3', 'keep' => 30, 'endpoint' => 'https://minio.kantor.local:9000', 'bucket' => 'NMS_Backup', 'access_key' => 'antareja'];
        $this->actingAs($admin)->put('/backup/luar-server', $s3)->assertSessionHasErrors('bucket');
        $this->actingAs($admin)->put('/backup/luar-server', ['bucket' => 'nms-backup', 'clear_secret' => 1] + $s3)->assertSessionHasErrors('secret_key');
        $this->actingAs($admin)->put('/backup/luar-server', ['bucket' => 'nms-backup', 'secret_key' => 's3-rahasia'] + $s3)->assertSessionHas('ok');
        $this->assertSame('nms-backup', Setting::get('backup.offsite')['bucket']);

        // uji koneksi lewat collector
        config(['antareja.collector_url' => 'http://collector.test:8081']);
        Http::fake(['collector.test:8081/v1/backup/offsite/test' => Http::response(['ok' => true, 'target' => 's3', 'detail' => 'bisa menulis'])]);
        $this->actingAs($admin)->post('/backup/luar-server/uji')->assertSessionHas('ok', 'Uji berhasil: bisa menulis');

        $eng = $this->user('engineer');
        $this->flushSession();
        $this->actingAs($eng)->put('/backup/luar-server', ['type' => 'off', 'keep' => 1])->assertForbidden();
    }
}
