<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Tenancy\Authorization;

use Thallo\Core\Content\Authorization\CapabilityCatalog;
use Thallo\Core\Content\Authorization\PolicyManifest;
use Thallo\Core\Tests\Support\AppTestCase;

final class PolicyManifestTest extends AppTestCase
{
    public function testExportValidates(): void
    {
        $manifest = new PolicyManifest(new CapabilityCatalog());
        self::assertSame([], $manifest->validate($manifest->export($this->appContext())));
    }

    public function testTamperingAndUnknownVersionFailClosed(): void
    {
        $manifest = new PolicyManifest(new CapabilityCatalog());
        $data = $manifest->export($this->appContext());
        $data['owner_floor'] = [];
        self::assertNotSame([], $manifest->validate($data));
        $data = $manifest->export($this->appContext());
        $data['manifest_version'] = 999;
        self::assertNotSame([], $manifest->validate($data));
    }
}
