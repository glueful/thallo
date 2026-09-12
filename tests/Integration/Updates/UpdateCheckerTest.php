<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Updates;

use Thallo\Contracts\Settings\SystemChannel;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\ScriptedReleaseFeed;
use Thallo\Core\Updates\UpdateChecker;

/**
 * The daily check asks Packagist once, keeps the newest version the install may move to in the
 * system flags, and stays silent on failure. Status reads the installed version at request
 * time, so the notice clears the moment `composer update` has run, before the next check.
 */
final class UpdateCheckerTest extends AppTestCase
{
    private const SETTINGS = [
        'enabled' => true,
        'package' => 'glueful/thallo-core',
        'notes_url' => 'https://github.com/glueful/thallo/blob/main/CHANGELOG.md',
    ];

    private function flags(): SystemChannel
    {
        return $this->container()->get(SystemChannel::class);
    }

    /** @param list<string>|\Throwable $feed */
    private function checker(
        array|\Throwable $feed,
        string $current = '1.0.0-beta.21',
        bool $development = false,
        array $settings = [],
    ): array {
        $scripted = new ScriptedReleaseFeed($feed);
        $checker = new UpdateChecker(
            $scripted,
            $this->flags(),
            array_merge(self::SETTINGS, $settings),
            $current,
            $development,
        );

        return [$checker, $scripted];
    }

    public function testACheckStoresTheNewestMovableVersionAndReportsItAvailable(): void
    {
        [$checker, $feed] = $this->checker(['v1.0.0-beta.22', 'v1.0.0-beta.21', 'dev-main']);

        $status = $checker->check();

        self::assertSame(['glueful/thallo-core'], $feed->calls);
        self::assertSame('1.0.0-beta.22', $status->latest);
        self::assertSame('1.0.0-beta.21', $status->current);
        self::assertTrue($status->available);
        self::assertSame('1.0.0-beta.22', $this->flags()->get('update.latest'));
        self::assertNotNull($status->checkedAt);
        self::assertSame(self::SETTINGS['notes_url'], $status->notesUrl);
    }

    public function testASecondCheckWithinTheIntervalDoesNotAskAgainUnlessForced(): void
    {
        [$checker, $feed] = $this->checker(['v1.0.0-beta.22']);
        $checker->check();

        $checker->check();
        self::assertCount(1, $feed->calls, 'a check inside the interval is a no-op');

        $checker->check(force: true);
        self::assertCount(2, $feed->calls);
    }

    public function testAnOldCheckIsRefreshed(): void
    {
        $this->flags()->put('update.latest', '1.0.0-beta.22');
        $this->flags()->put('update.checked_at', date(DATE_ATOM, time() - 25 * 3600));
        [$checker, $feed] = $this->checker(['v1.0.0-beta.23']);

        $status = $checker->check();

        self::assertCount(1, $feed->calls);
        self::assertSame('1.0.0-beta.23', $status->latest);
    }

    public function testAFailedFetchKeepsThePreviousResultAndRecordsTheFailure(): void
    {
        $this->flags()->put('update.latest', '1.0.0-beta.22');
        [$checker] = $this->checker(new \RuntimeException('packagist down'));

        $status = $checker->check(force: true);

        self::assertSame('1.0.0-beta.22', $status->latest);
        self::assertTrue($status->available);
        self::assertNotNull($this->flags()->get('update.failed_at'));
        self::assertNull($this->flags()->get('update.checked_at'), 'a failure is not a check');
    }

    public function testNothingNewerClearsAStaleLatest(): void
    {
        $this->flags()->put('update.latest', '1.0.0-beta.22');
        [$checker] = $this->checker(['v1.0.0-beta.21']);

        $status = $checker->check(force: true);

        self::assertNull($status->latest);
        self::assertFalse($status->available);
        self::assertNull($this->flags()->get('update.latest'));
    }

    public function testDisabledNeverAsksAndReportsSo(): void
    {
        [$checker, $feed] = $this->checker(['v1.0.0-beta.22'], settings: ['enabled' => false]);

        $status = $checker->check(force: true);

        self::assertSame([], $feed->calls);
        self::assertFalse($status->enabled);
        self::assertFalse($status->available);
    }

    public function testADevelopmentCheckoutNeverAsksAndNeverHasAnUpdate(): void
    {
        [$checker, $feed] = $this->checker(['v1.0.0-beta.22'], current: '1.0.0', development: true);

        $status = $checker->check(force: true);

        self::assertSame([], $feed->calls);
        self::assertTrue($status->development);
        self::assertNull($status->current);
        self::assertFalse($status->available);
    }

    public function testStatusClearsTheMomentTheInstallIsCurrent(): void
    {
        $this->flags()->put('update.latest', '1.0.0-beta.22');
        [$checker] = $this->checker([], current: '1.0.0-beta.22');

        $status = $checker->status();

        self::assertSame('1.0.0-beta.22', $status->latest);
        self::assertFalse($status->available, 'the installed version is read at request time');
        self::assertSame(
            ['current', 'latest', 'available', 'development', 'enabled', 'checkedAt', 'notesUrl'],
            array_keys($status->toArray()),
        );
    }
}
