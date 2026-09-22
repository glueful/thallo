<?php

/**
 * Storage Configuration
 *
 * Configure filesystem disks for file storage. Uses Flysystem under the hood.
 *
 * Included adapters:
 *   - local: Local filesystem (included)
 *   - memory: In-memory storage for testing (included)
 *
 * Other drivers are registered by driver packages, never by a Flysystem adapter alone:
 *   - s3 (S3, MinIO, DigitalOcean Spaces, Wasabi): composer require glueful/storage-s3
 *   - gcs (Google Cloud Storage):                   composer require glueful/storage-gcs
 *   - azure (Azure Blob Storage):                   composer require glueful/storage-azure
 *
 * Any other driver (SFTP, FTP …) needs a StorageDriverFactoryInterface tagged
 * `storage.driver_factory`. An unregistered driver fails with a message naming the package.
 */

$root = dirname(__DIR__);

return [
    'default' => env('STORAGE_DEFAULT_DISK', env('STORAGE_DRIVER', 'uploads')),

    'disks' => [
        // Local uploads disk
        'uploads' => [
            'driver' => 'local',
            'root' => $root . '/storage/uploads',
            'visibility' => 'private',
            // Used by UrlGenerator for public URLs
            'base_url' => env('CDN_URL'),
        ],

        // Optional S3-compatible disk
        's3' => [
            'driver' => 's3',
            'key' => env('S3_ACCESS_KEY_ID'),
            'secret' => env('S3_SECRET_ACCESS_KEY'),
            'region' => env('S3_REGION', 'us-east-1'),
            'bucket' => env('S3_BUCKET'),
            'endpoint' => env('S3_ENDPOINT'),
            'use_path_style_endpoint' => true,

            // Optional behavior hints
            'acl' => env('S3_ACL', 'private'),
            'signed_urls' => env('S3_SIGNED_URLS', true),
            'signed_ttl' => (int) env('S3_SIGNED_URL_TTL', 3600),
            'cdn_base_url' => env('S3_CDN_BASE_URL'),
        ],
    ],
];
