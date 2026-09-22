<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;

/**
 * The admin's import upload writes to the `uploads` storage disk; the importers read their file
 * back through `import_export.source_roots.uploads`. The core package's own config cannot say
 * where that is: on an install it sits under vendor/, and a path built from it pointed into
 * vendor/ — every admin-started import then failed to find its file. So the core default names no
 * root, and the core provider fills it from the uploads disk's own root when it merges (proved in
 * ImportUploadTest). The site's config/ holds overrides only: it ships no import_export.php.
 */
final class ImportUploadRootTest extends TestCase
{
    public function testTheCoreDefaultNamesNoRootBecauseItCannotKnowTheInstall(): void
    {
        $config = require dirname(__DIR__, 3) . '/core/config/import_export.php';
        self::assertSame([], $config['source_roots']);
    }
}
