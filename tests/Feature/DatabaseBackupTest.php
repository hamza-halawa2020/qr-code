<?php

namespace Tests\Feature;

use App\Services\DatabaseBackupService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use Tests\TestCase;

class DatabaseBackupTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'queue-backup-test-'.Str::uuid();
        mkdir($this->directory, 0700);
        config(['backups.directory' => $this->directory]);
    }

    protected function tearDown(): void
    {
        DB::purge('backup_test');
        foreach (glob($this->directory.DIRECTORY_SEPARATOR.'*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_sqlite_backup_can_be_restored_and_is_a_consistent_independent_snapshot(): void
    {
        $source = $this->directory.DIRECTORY_SEPARATOR.'source.sqlite';
        touch($source);
        config(['database.connections.backup_test' => ['driver' => 'sqlite', 'database' => $source, 'foreign_key_constraints' => true]]);
        $connection = DB::connection('backup_test');
        $connection->statement('CREATE TABLE notes (id INTEGER PRIMARY KEY, content TEXT)');
        $connection->table('notes')->insert(['content' => 'before backup']);
        $backup = app(DatabaseBackupService::class)->create('backup_test');
        $connection->table('notes')->insert(['content' => 'after backup']);
        $restored = new PDO('sqlite:'.$backup['path']);
        $this->assertSame(1, (int) $restored->query('SELECT COUNT(*) FROM notes')->fetchColumn());
        $this->assertSame('before backup', $restored->query('SELECT content FROM notes')->fetchColumn());
        $this->assertGreaterThan(0, $backup['bytes']);
        $this->assertSame('sqlite', $backup['driver']);
        $restored = null;
    }

    public function test_missing_mysql_binary_fails_without_leaving_credentials_or_partial_output(): void
    {
        config(['database.connections.backup_test' => ['driver' => 'mysql', 'database' => 'test', 'username' => 'test', 'password' => 'private-test-password'], 'backups.mysqldump_binary' => 'nonexistent-mysqldump-'.Str::uuid()]);
        try {
            app(DatabaseBackupService::class)->create('backup_test');
            $this->fail('Missing binary should fail');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('private-test-password', $exception->getMessage());
        }
        $this->assertSame([], glob($this->directory.DIRECTORY_SEPARATOR.'*'));
    }
}
