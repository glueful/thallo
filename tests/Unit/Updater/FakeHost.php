<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Updater;

use Thallo\Core\Updater\Host;

final class FakeHost implements Host
{
    /** @var list<list<string>> */
    public array $calls = [];
    public int $backupExit = 0;
    public int $provisionExit = 0;
    public int $stageExit = 0;
    public int $verifyExit = 0;
    public int $clearExit = 0;
    public int $restartExit = 0;
    public ?\Closure $onProvision = null;
    public int $clock = 1_000;

    public function __construct(
        private readonly string $base,
        private readonly string $from,
        private readonly string $target,
    ) {
    }

    public function run(array $argv, string $cwd, array $env, int $timeout, callable $heartbeat): int
    {
        return $this->runCapture($argv, $cwd, $env, $timeout, $heartbeat)[0];
    }

    public function runCapture(array $argv, string $cwd, array $env, int $timeout, callable $heartbeat): array
    {
        $this->calls[] = $argv;
        $heartbeat();
        $joined = implode(' ', $argv);
        if ($argv[0] === 'cp' || $argv[0] === 'rm') {
            exec(implode(' ', array_map('escapeshellarg', $argv)), $o, $code);
            return [$code, ''];
        }
        if (str_contains($joined, ' update glueful/thallo-core:')) {
            $stage = substr((string) end($argv), strlen('--working-dir='));
            @mkdir($stage . '/vendor/composer', 0777, true);
            $installed = ['packages' => [['name' => 'glueful/thallo-core', 'version' => $this->target]]];
            file_put_contents($stage . '/vendor/composer/installed.json', json_encode($installed));
            file_put_contents($stage . '/vendor/marker', 'new');
            return [$this->stageExit, ''];
        }
        if (in_array('-r', $argv, true)) {
            return [0, ''];
        }
        if (str_contains($joined, 'thallo:update:backup')) {
            foreach ($argv as $a) {
                if (str_starts_with($a, '--to=')) {
                    @mkdir(substr($a, 5), 0700, true);
                    file_put_contents(substr($a, 5) . '/manifest.json', '{"bytes":1}');
                }
            }
            return [$this->backupExit, '{"bytes":1}'];
        }
        if (str_contains($joined, 'thallo:provision')) {
            if ($this->onProvision !== null) {
                ($this->onProvision)();
            }
            return [$this->provisionExit, ''];
        }
        if (str_contains($joined, 'thallo:update:verify')) {
            $answer = (string) json_encode(['version' => $this->installed()]) . "\n";
            return [$this->verifyExit, $this->verifyExit === 0 ? $answer : ''];
        }
        if (str_contains($joined, 'thallo:update:clear-caches')) {
            return [$this->clearExit, ''];
        }
        if (str_contains($joined, 'queue:restart')) {
            return [$this->restartExit, ''];
        }
        return [0, ''];
    }
    public function capture(array $argv, string $cwd): array
    {
        $this->calls[] = $argv;
        $joined = implode(' ', $argv);
        return match (true) {
            $argv[0] === 'pgrep' => [1, ''],
            $argv[0] === 'ps' => [0, ''],
            str_contains($joined, '--estimate') => [0, '{"database_bytes":1000}'],
            str_contains($joined, 'composer') && str_contains($joined, '--version')
                => [0, 'Composer version 2.8.4 2024-12-11'],
            str_contains($joined, '--version') => [0, 'pg_dump (PostgreSQL) 16.4'],
            $argv[0] === 'du' => [0, "1000\tvendor\n"],
            default => [0, ''],
        };
    }

    public function http(string $url, array $headers, string $body): array
    {
        $mode = json_decode($body, true)['mode'] ?? '';
        return [200, (string) json_encode($mode === 'probe'
            ? [
                'sapi' => 'fpm-fcgi',
                'writable_code' => false,
                'admission_ok' => true,
                'state_writable' => true,
                'opcache' => [
                    'enabled' => true,
                    'validate_timestamps' => true,
                    'revalidate_freq' => 2,
                    'preload' => '',
                ],
            ]
            : ['version' => $this->installed(), 'missing_extensions' => [], 'container' => true])];
    }

    public function now(): int
    {
        return $this->clock;
    }

    public function sleep(int $seconds): void
    {
        $this->clock += $seconds;
    }

    private function installed(): string
    {
        $marker = trim((string) @file_get_contents($this->base . '/vendor/marker'));
        return $marker === 'new' ? $this->target : $this->from;
    }
}
