<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;

/**
 * A CMS's media must be retrievable by visitors: Thallo's upload access defaults to upload_only
 * (admin session for upload and delete, public retrieval per blob), not the framework's private,
 * which answered 401 for every image on a fresh install. The test suite pins UPLOADS_ACCESS
 * itself, so the default is read with the variable cleared.
 */
final class UploadsAccessDefaultTest extends TestCase
{
    public function testTheConfiguredDefaultIsUploadOnly(): void
    {
        $saved = [$_ENV['UPLOADS_ACCESS'] ?? null, $_SERVER['UPLOADS_ACCESS'] ?? null, getenv('UPLOADS_ACCESS')];
        unset($_ENV['UPLOADS_ACCESS'], $_SERVER['UPLOADS_ACCESS']);
        putenv('UPLOADS_ACCESS');
        try {
            foreach (['config/uploads.php', 'skeleton/config/uploads.php'] as $file) {
                $config = require dirname(__DIR__, 3) . '/' . $file;
                self::assertSame('upload_only', $config['access'], $file);
            }
        } finally {
            if ($saved[0] !== null) {
                $_ENV['UPLOADS_ACCESS'] = $saved[0];
            }
            if ($saved[1] !== null) {
                $_SERVER['UPLOADS_ACCESS'] = $saved[1];
            }
            putenv($saved[2] === false ? 'UPLOADS_ACCESS' : 'UPLOADS_ACCESS=' . $saved[2]);
        }
    }
}
