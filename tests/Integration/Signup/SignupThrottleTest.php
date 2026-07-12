<?php

declare(strict_types=1);

namespace App\Tests\Integration\Signup;

use App\Signup\SignupThrottle;
use App\Tests\Support\AppTestCase;

final class SignupThrottleTest extends AppTestCase
{
    public function testEmailWindowNeverExceedsConfiguredCap(): void
    {
        $throttle = $this->container()->get(SignupThrottle::class);
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            self::assertTrue($throttle->allowIntent(
                'member',
                '192.0.2.' . $attempt,
                'same@example.test',
            ));
        }
        self::assertFalse($throttle->allowIntent('member', '192.0.2.99', 'same@example.test'));
        $rows = $this->connection()->table('signup_rate_counters')
            ->where('dimension', '=', 'intent_email')->get();
        self::assertCount(1, $rows);
        self::assertSame(5, (int) $rows[0]['count']);
        self::assertNotSame('same@example.test', $rows[0]['bucket_hash']);
    }
}
