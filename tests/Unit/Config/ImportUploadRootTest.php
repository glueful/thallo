<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;

/**
 * The admin's import upload writes to the site's `storage/uploads`; the importers read from
 * `import_export.source_roots.uploads`, and the framework's import service takes that root as an
 * absolute path. So the root has to be computed by a file that lives IN the site — the core
 * package's own config cannot know where the install is (on an install it sits at
 * vendor/glueful/thallo-core/config/, and a `dirname(__DIR__, 2)` there is vendor/glueful/).
 * Every admin-started import failed to find its file for exactly that reason. The site's
 * config/import_export.php owns the root; the core default names no root at all.
 */
final class ImportUploadRootTest extends TestCase
{
    public function testTheCoreDefaultNamesNoRootBecauseItCannotKnowTheInstall(): void
    {
        $config = require dirname(__DIR__, 3) . '/core/config/import_export.php';
        self::assertSame([], $config['source_roots']);
    }

    public function testTheSitesOwnConfigPointsTheUploadsRootAtItsStorage(): void
    {
        foreach (['config', 'skeleton/config'] as $dir) {
            $file = dirname(__DIR__, 3) . "/{$dir}/import_export.php";
            self::assertFileExists($file, "{$dir} must ship the file: it is the only place that knows the root");
            $config = require $file;
            self::assertSame(
                realpath(dirname($file, 2)) . '/storage/uploads',
                $config['source_roots']['uploads'],
                $dir,
            );
        }
    }
}
