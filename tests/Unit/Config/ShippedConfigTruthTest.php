<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;

/**
 * The shipped config listed settings nothing reads, so changing them did nothing: queue
 * connections with no driver (sync, null), the schedule's settings block, queue_mapping and each
 * job's queue/timeout/retry_attempts (config jobs run inline in the scheduler), the extension
 * installer's auto_enable, the API's allowed_operators and MAIL_BCC. And the notification retry
 * job was handed `limit` where it reads `options.limit`.
 */
final class ShippedConfigTruthTest extends TestCase
{
    private const DIRS = ['', 'skeleton/'];

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return array<string, mixed> */
    private function load(string $dir, string $file): array
    {
        return require $this->root() . "/{$dir}config/{$file}.php";
    }

    public function testEveryScheduledJobGetsOnlySettingsItsJobReads(): void
    {
        foreach (self::DIRS as $dir) {
            $config = $this->load($dir, 'schedule');
            $jobs = array_column($config['jobs'], null, 'name');

            // The app's schedule replaces the framework's list (framework 1.86), so the framework's
            // webhook delivery cleanup runs only if it is listed here.
            self::assertSame(
                'Glueful\\Api\\Webhooks\\Jobs\\WebhookCleanupJob',
                $jobs['webhook_cleanup']['handler_class'] ?? null,
                $dir
            );
            // Deleted uploads are purged only if the app's list names the framework job (1.87).
            self::assertSame(
                'Glueful\\Uploader\\Jobs\\BlobPurgeJob',
                $jobs['blob_purge']['handler_class'] ?? null,
                $dir,
            );
            $retry = $jobs['notification_retry_processor']['parameters'];
            self::assertSame(50, (int) ($retry['options']['limit'] ?? 0), $dir);
            self::assertArrayNotHasKey('settings', $config, $dir);
            self::assertArrayNotHasKey('queue_mapping', $config, $dir);
            foreach ($jobs as $name => $job) {
                $dead = array_intersect(array_keys($job), ['queue', 'timeout', 'retry_attempts']);
                self::assertSame([], $dead, "{$dir}{$name}");
                self::assertSame([], array_diff(array_keys((array) ($job['parameters'] ?? [])), [
                    'options', 'cleanupType', 'operation', 'retryType', 'backupType', 'command', 'arguments',
                ]), "{$dir}{$name}");
            }
        }
    }

    public function testOnlyTheQueueDriversThatExistAreListed(): void
    {
        foreach (self::DIRS as $dir) {
            self::assertSame(['database', 'redis'], array_keys($this->load($dir, 'queue')['connections']), $dir);
        }
    }

    public function testSettingsNothingReadsAreNotShipped(): void
    {
        foreach (self::DIRS as $dir) {
            self::assertArrayNotHasKey('auto_enable', $this->load($dir, 'extensions')['install'], $dir);
            $api = (string) file_get_contents($this->root() . "/{$dir}config/api.php");
            $env = (string) file_get_contents($this->root() . "/{$dir}.env.example");
            self::assertStringNotContainsString('allowed_operators', $api, $dir);
            self::assertStringNotContainsString('MAIL_BCC', $env, $dir);
        }
    }
}
