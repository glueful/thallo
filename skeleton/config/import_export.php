<?php

/**
 * Import/Export: where the admin's import upload lands.
 *
 * POST /v1/admin/import-export/upload writes to the `uploads` disk (storage/uploads), and an
 * import job reads its source back through this root. It has to be an absolute path to THIS
 * site, which is why the file is here and not in the package: this file knows where the site is.
 * Everything else about imports keeps the package's defaults.
 */

declare(strict_types=1);

$root = dirname(__DIR__);

return [
    'source_roots' => [
        'uploads' => $root . '/storage/uploads',
    ],
];
