<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;

class DatabaseBackupService
{
    public function create(?string $connectionName = null): array
    {
        $connection = DB::connection($connectionName);
        $driver = $connection->getDriverName();
        if (! in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('Database backups currently support MySQL and SQLite.');
        }
        $directory = config('backups.directory');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Cannot create private backup directory.');
        }
        $path = $directory.DIRECTORY_SEPARATOR.now()->format('Ymd_His').'-'.Str::uuid().($driver === 'mysql' ? '.sql' : '.sqlite');
        $partial = $path.'.partial';
        try {
            if ($driver === 'sqlite') {
                if ($connection->transactionLevel() !== 0) {
                    throw new RuntimeException('Run SQLite backups outside a database transaction.');
                }
                $connection->getPdo()->exec('VACUUM INTO '.$connection->getPdo()->quote($partial));
            } else {
                $this->dumpMySql($connection->getConfig(), $partial);
            }
            if (! is_file($partial) || filesize($partial) === 0 || ! rename($partial, $path)) {
                throw new RuntimeException('Backup output was empty or could not be finalized.');
            }

            return ['path' => $path, 'driver' => $driver, 'bytes' => filesize($path), 'created_at' => now()->toIso8601String()];
        } finally {
            if (is_file($partial)) {
                unlink($partial);
            }
        }
    }

    private function dumpMySql(array $settings, string $output): void
    {
        $binary = config('backups.mysqldump_binary');
        $binary = is_file($binary) ? $binary : (new ExecutableFinder)->find($binary);
        if (! $binary) {
            throw new RuntimeException('mysqldump not found. Set MYSQLDUMP_BINARY to its executable path.');
        }
        $database = $settings['database'];
        if (! preg_match('/^[a-zA-Z0-9_]+$/', $database)) {
            throw new RuntimeException('Unsupported database name for backup.');
        }
        $options = tempnam(config('backups.directory'), 'client-');
        if ($options === false) {
            throw new RuntimeException('Cannot create private MySQL options file.');
        }
        $quote = fn ($value) => '"'.str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '\\r', '\\n'], (string) $value).'"';
        $lines = ['[client]', 'host='.$quote($settings['host'] ?? '127.0.0.1'), 'port='.(int) ($settings['port'] ?? 3306), 'user='.$quote($settings['username']), 'password='.$quote($settings['password'] ?? '')];
        if (filled($settings['unix_socket'] ?? null)) {
            $lines[] = 'socket='.$quote($settings['unix_socket']);
        }
        $process = null;
        try {
            file_put_contents($options, implode(PHP_EOL, $lines));
            @chmod($options, 0600);
            $pipes = [];
            $process = proc_open([$binary, '--defaults-extra-file='.$options, '--single-transaction', '--quick', '--skip-lock-tables', '--no-tablespaces', '--set-gtid-purged=OFF', '--default-character-set=utf8mb4', $database], [0 => ['pipe', 'r'], 1 => ['file', $output, 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
            if (! is_resource($process)) {
                throw new RuntimeException('Could not start mysqldump.');
            }
            fclose($pipes[0]);
            // Drain errors but never log credentials, SQL data, or raw client errors.
            stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $code = proc_close($process);
            $process = null;
            if ($code !== 0) {
                throw new RuntimeException('MySQL backup failed. Check database access and the mysqldump executable.');
            }
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            if (is_file($options)) {
                unlink($options);
            }
        }
    }
}
