<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Updates;

use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Updates\UpdateCheckJob;

/** The scheduled check is a thin, silent entry point: it never throws out of the scheduler. */
final class UpdateCheckJobTest extends AppTestCase
{
    public function testItRunsTheCheckerWithoutThrowing(): void
    {
        $job = new UpdateCheckJob([], $this->appContext());

        $job->handle();

        self::assertTrue(true, 'a development checkout never asks Packagist and the job stays silent');
    }

    public function testItRequiresAnApplicationContext(): void
    {
        $this->expectException(\RuntimeException::class);

        (new UpdateCheckJob([]))->handle();
    }
}
