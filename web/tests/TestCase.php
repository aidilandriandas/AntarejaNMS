<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

/**
 * Tes memakai PostgreSQL sungguhan (skema memakai JSONB, INET, trigram) di database terpisah yang namanya WAJIB
 * berakhiran _test. Skema dibangun sekali per proses dari db/migrations/*.sql (sumber skema yang sama dengan produksi);
 * tiap tes dibungkus transaksi (DatabaseTransactions) sehingga data tidak tertinggal.
 */
abstract class TestCase extends BaseTestCase
{
    private static bool $schemaReady = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        if (! self::$schemaReady) {
            $db = (string) config('database.connections.pgsql.database');
            if (config('database.default') !== 'pgsql' || ! str_ends_with($db, '_test')) {
                throw new RuntimeException("Tes hanya boleh memakai database PostgreSQL berakhiran _test (sekarang: {$db}). Lihat README bagian pengembangan.");
            }
            // Koneksi PDO terpisah: koneksi Laravel sudah berada di dalam transaksi tes (akan di-rollback)
            $c = config('database.connections.pgsql');
            $pdo = new \PDO("pgsql:host={$c['host']};port={$c['port']};dbname={$c['database']}", $c['username'], $c['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('DROP SCHEMA IF EXISTS public CASCADE; CREATE SCHEMA public;');
            $files = glob(base_path('../db/migrations/*.sql'));
            if (! $files) {
                throw new RuntimeException('Berkas db/migrations/*.sql tidak ditemukan.');
            }
            sort($files);
            foreach ($files as $f) {
                $pdo->exec(file_get_contents($f));
            }
            self::$schemaReady = true;
        }
    }

    protected function user(string $role = 'admin', array $attr = []): User
    {
        static $n = 0;
        $n++;

        return User::create($attr + ['name' => ucfirst($role) . " Uji {$n}", 'username' => "{$role}{$n}" . bin2hex(random_bytes(2)),
            'password' => 'Rahasia12345', 'role' => $role, 'active' => true]);
    }
}
