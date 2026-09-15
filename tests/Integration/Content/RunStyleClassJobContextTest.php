<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Queue\JobHandlerResolver;
use PHPUnit\Framework\TestCase;
use Thallo\Core\Content\Jobs\RunStyleClassJob;

/** The queued job keeps the context the resolver hands it (framework 1.85.7 rule). */
final class RunStyleClassJobContextTest extends TestCase
{
    public function testTheJobKeepsTheContextTheResolverHandsIt(): void
    {
        $context = new ApplicationContext(sys_get_temp_dir() . '/style_job_' . uniqid());
        $job = JobHandlerResolver::resolve(RunStyleClassJob::class, ['job_id' => 'x'], $context);
        $property = new \ReflectionProperty($job, 'context');
        self::assertSame($context, $property->getValue($job));
    }
}
