<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Tenancy;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Thallo\Tenancy\Console\StatusOutput;

/** The tenancy status commands print a table for people and JSON for scripts. */
final class StatusOutputTest extends TestCase
{
    private const STATUS = [
        'step' => 'inactive',
        'enabled' => false,
        'failure' => null,
        'blockers' => ['BASE_URL is not set', 'No owner'],
        'progress' => ['done' => 2, 'total' => 5],
    ];

    public function testThePlainOutputIsATable(): void
    {
        $output = new BufferedOutput();

        StatusOutput::write($output, self::STATUS, false);

        $text = $output->fetch();
        self::assertStringNotContainsString('{', $text);
        self::assertMatchesRegularExpression('/step\s+inactive/', $text);
        self::assertMatchesRegularExpression('/enabled\s+no/', $text);
        self::assertMatchesRegularExpression('/failure\s+—/', $text);
        self::assertStringContainsString('No owner', $text);
        self::assertStringContainsString('done: 2', $text);
    }

    public function testJsonIsTheRawStatus(): void
    {
        $output = new BufferedOutput();

        StatusOutput::write($output, self::STATUS, true);

        self::assertSame(self::STATUS, json_decode($output->fetch(), true));
    }
}
