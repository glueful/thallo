<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Updater;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Updater\Barrier;
use Thallo\Core\Updater\FileLock;
use Thallo\Core\Updater\Guard;
use Thallo\Core\Updater\OperationStore;
use Thallo\Core\Updater\Paths;

final class GuardAdmissionTest extends TestCase
{
    private string $base;
    private Paths $paths;
    private Barrier $barrier;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/thallo-guard-' . bin2hex(random_bytes(4));
        mkdir($this->base . '/storage/framework/updates', 0777, true);
        $this->paths = new Paths($this->base);
        $this->barrier = new Barrier($this->paths);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->base));
    }

    public function testAnOpenSiteAdmitsAndHoldsASharedLock(): void
    {
        $guard = $this->guard();
        self::assertNull($guard->web('GET', '/about', [], ''));
        self::assertNotNull($guard->held());
        self::assertNull($this->barrier->acquire(0, static fn (): int => 0, static function (): void {
        }), 'the admitted request blocks the barrier');
    }

    public function testAClosedSiteAnswersEveryRequestItselfAdminIncluded(): void
    {
        $this->barrier->close();
        foreach (['/', '/admin', '/v1/admin/entries', '/v1/auth/login'] as $path) {
            $r = $this->guard()->web('GET', $path, [], '');
            self::assertNotNull($r, $path);
            self::assertSame(503, $r->status, $path);
            self::assertSame('120', $r->headers['Retry-After'], $path);
        }
        $health = $this->guard()->web('GET', '/health', [], '');
        self::assertSame(['status' => 'updating'], json_decode($health->body, true));
    }

    public function testTheFlagIsRecheckedAfterTheSharedLock(): void
    {
        // A request that passed the first flag check but locks after the runner closed: refused.
        $guard = $this->guard(beforeLock: fn () => $this->barrier->close());
        self::assertSame(503, $guard->web('GET', '/', [], '')->status);
        self::assertNull($guard->held(), 'it released its shared lock');
    }

    public function testConsoleRules(): void
    {
        $this->barrier->close();
        $scheduler = $this->guard()->console(['glueful', 'queue:scheduler', 'run'], []);
        self::assertSame(0, $scheduler, 'the scheduler exits quietly');
        self::assertSame(1, $this->guard()->console(['glueful', 'migrate:run'], []), 'other commands are refused');
        $child = $this->guard()->console(['glueful', 'thallo:provision'], ['THALLO_UPDATE_CHILD' => '1']);
        self::assertNull($child, 'the runner\'s children pass');
    }

    public function testAWorkerWaitsUntilTheSiteReopens(): void
    {
        $this->barrier->close();
        $sleeps = 0;
        $guard = $this->guard(sleep: function () use (&$sleeps): void {
            if (++$sleeps === 3) {
                $this->barrier->open();
            }
        });
        self::assertNull($guard->console(['glueful', 'queue:work', '--queue=default'], []));
        self::assertSame(3, $sleeps);
        self::assertNotNull($guard->held(), 'once admitted the worker holds a shared lock for its life');
    }

    private function guard(?\Closure $sleep = null, ?\Closure $beforeLock = null): Guard
    {
        $barrier = $beforeLock === null ? $this->barrier : new class ($this->paths, $beforeLock) extends Barrier {
            public function __construct(Paths $p, private \Closure $hook)
            {
                parent::__construct($p);
            }
            public function admit()
            {
                // Simulate the race: the flag appears after the first check, before the shared lock.
                if ($this->isClosed()) {
                    return null;
                }
                ($this->hook)();
                return parent::admitAfterFirstCheck();
            }
        };
        $ops = new OperationStore($this->paths, static fn (): int => 1);
        return new Guard($this->paths, $ops, $barrier, $sleep ?? static function (): void {
        });
    }
}
