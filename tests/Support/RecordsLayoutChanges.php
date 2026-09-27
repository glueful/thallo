<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support;

use Glueful\Events\EventService;
use Thallo\Contracts\Layouts\LayoutChanged;

/**
 * Records every {@see LayoutChanged} dispatched after {@see self::recordLayoutChanges()}, as
 * `surface:target` strings. One listener per process (the app is booted once); it records only
 * while a test has asked it to.
 */
trait RecordsLayoutChanges
{
    /** @var list<string>|null */
    private static ?array $layoutChanges = null;

    private static bool $layoutChangesListening = false;

    private function recordLayoutChanges(): void
    {
        self::$layoutChanges = [];
        if (self::$layoutChangesListening) {
            return;
        }
        self::$layoutChangesListening = true;
        $this->container()->get(EventService::class)->addListener(
            LayoutChanged::class,
            static function (object $event): void {
                if (self::$layoutChanges !== null && $event instanceof LayoutChanged) {
                    self::$layoutChanges[] = $event->surface . ':' . $event->target;
                }
            },
        );
    }

    /** @return list<string> */
    private function recordedLayoutChanges(): array
    {
        $changes = self::$layoutChanges ?? [];
        self::$layoutChanges = null;
        return $changes;
    }
}
