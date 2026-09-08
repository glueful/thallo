<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\Controllers\HealthAdminController;
use PHPUnit\Framework\TestCase;

/**
 * The admin health endpoint used to flatten every framework check to name/status/message, so
 * "Configuration warnings detected" reached the operator with no way to learn WHICH setting.
 * The check's detail lists (issues, warnings, recommendations) now pass through when present.
 */
final class HealthAdminCheckShapeTest extends TestCase
{
    public function testDetailListsPassThroughWhenPresent(): void
    {
        $shaped = HealthAdminController::shapeCheck('config', [
            'status' => 'ok',
            'message' => 'Configuration is valid',
            'environment' => 'production',
            'recommendations' => ['Production: CSP_HEADER is empty - …'],
        ]);

        self::assertSame([
            'name' => 'config',
            'status' => 'ok',
            'message' => 'Configuration is valid',
            'recommendations' => ['Production: CSP_HEADER is empty - …'],
        ], $shaped);
    }

    public function testIssuesAndWarningsPassThroughAsStringLists(): void
    {
        $shaped = HealthAdminController::shapeCheck('config', [
            'status' => 'error',
            'message' => 'Critical configuration issues detected',
            'issues' => ['JWT_KEY not properly configured', 42, null],
            'warnings' => ['Production: APP_DEBUG is enabled'],
        ]);

        self::assertSame(['JWT_KEY not properly configured'], $shaped['issues'], 'non-strings are dropped');
        self::assertSame(['Production: APP_DEBUG is enabled'], $shaped['warnings']);
        self::assertArrayNotHasKey('recommendations', $shaped);
    }

    public function testAPlainCheckStaysPlain(): void
    {
        $shaped = HealthAdminController::shapeCheck(
            'cache',
            ['status' => 'ok', 'message' => 'Cache is working properly'],
        );

        self::assertSame(['name' => 'cache', 'status' => 'ok', 'message' => 'Cache is working properly'], $shaped);
    }

    public function testANonArrayCheckIsReportedAsUnknown(): void
    {
        self::assertSame(
            ['name' => 'weird', 'status' => 'unknown', 'message' => ''],
            HealthAdminController::shapeCheck('weird', 'not-an-array'),
        );
    }
}
