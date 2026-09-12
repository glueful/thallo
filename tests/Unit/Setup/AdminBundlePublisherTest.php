<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Setup;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Setup\AdminBundlePublisher;

/**
 * The admin bundle ships inside core/resources/admin (and, after the package split, inside
 * vendor/); provision PUBLISHES a copy into public/admin so the web server serves the assets
 * from disk, as it does today, and no operator has to change a location block. The published
 * copy is derived output: refreshed on every provision, stale files removed, never edited.
 */
final class AdminBundlePublisherTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/thallo-bundle-' . uniqid();
        mkdir("$this->root/source/assets", 0755, true);
        file_put_contents("$this->root/source/index.html", '<html>v2</html>');
        file_put_contents("$this->root/source/assets/index-NEW.js", 'new');
        file_put_contents("$this->root/source/assets/logo.svg", '<svg/>');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testPublishesEveryFileAndRemovesWhatTheSourceNoLongerHas(): void
    {
        mkdir("$this->root/public/admin/assets", 0755, true);
        file_put_contents("$this->root/public/admin/index.html", '<html>v1</html>');
        file_put_contents("$this->root/public/admin/assets/index-OLD.js", 'old');

        $report = (new AdminBundlePublisher())->publish("$this->root/source", "$this->root/public/admin");

        self::assertSame('<html>v2</html>', file_get_contents("$this->root/public/admin/index.html"));
        self::assertFileExists("$this->root/public/admin/assets/index-NEW.js");
        self::assertFileExists("$this->root/public/admin/assets/logo.svg");
        self::assertFileDoesNotExist("$this->root/public/admin/assets/index-OLD.js", 'stale hashed files go');
        self::assertSame(['published' => 3, 'removed' => 1], $report);
    }

    public function testASecondRunIsANoOpReport(): void
    {
        $publisher = new AdminBundlePublisher();
        $publisher->publish("$this->root/source", "$this->root/public/admin");

        self::assertSame(['published' => 3, 'removed' => 0], $publisher->publish("$this->root/source", "$this->root/public/admin"));
    }

    public function testAMissingSourceIsReportedNotFatal(): void
    {
        self::assertNull((new AdminBundlePublisher())->publish("$this->root/nope", "$this->root/public/admin"));
        self::assertDirectoryDoesNotExist("$this->root/public/admin", 'nothing is created for a missing bundle');
    }
}
