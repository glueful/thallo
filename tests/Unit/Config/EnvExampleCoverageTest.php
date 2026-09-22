<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;

/**
 * Settings the shipped config reads from the environment were missing from `.env.example`, so an
 * operator had to find them in the code. Each has a line now, set or commented with its default.
 */
final class EnvExampleCoverageTest extends TestCase
{
    private const READ_BY_CONFIG = [
        'PREVIEW_TTL',
        'VERSION_KEEP',
        'VERSION_MAX_AGE_DAYS',
        'WORKFLOW_ALLOW_SELF_REVIEW',
        'CONTENT_SCHEDULER_ENABLED',
        'TENANCY_TRASH_RETENTION_DAYS',
        'TENANCY_HOST_COOLDOWN_DAYS',
        'PUBLIC_URL_BASE',
        'MEILISEARCH_HOST',
    ];

    public function testEverySettingTheConfigReadsHasALine(): void
    {
        foreach (['.env.example', 'skeleton/.env.example'] as $file) {
            $text = (string) file_get_contents(dirname(__DIR__, 3) . '/' . $file);
            foreach (self::READ_BY_CONFIG as $name) {
                self::assertMatchesRegularExpression("/^(# )?{$name}=/m", $text, "{$file}: {$name}");
            }
        }
    }
}
