<?php

declare(strict_types=1);

// The guard's verification route as RunnerRealHostTest's php -S answers it: from vendor/marker.

$base = __DIR__;
if (($_SERVER['REQUEST_URI'] ?? '') !== '/_update/verify') {
    http_response_code(404);
    return true;
}
$versions = json_decode((string) file_get_contents($base . '/storage/versions.json'), true);
$marker = trim((string) @file_get_contents($base . '/vendor/marker'));
$installed = $marker === 'new' ? $versions['target'] : $versions['from'];
$mode = json_decode((string) file_get_contents('php://input'), true)['mode'] ?? '';
header('Content-Type: application/json');
echo json_encode($mode === 'probe'
    ? ['sapi' => 'fpm-fcgi', 'writable_code' => false, 'admission_ok' => true, 'state_writable' => true,
        'opcache' => ['enabled' => true, 'validate_timestamps' => true, 'revalidate_freq' => 0, 'preload' => '']]
    : ['version' => $installed, 'missing_extensions' => [], 'container' => true]);
return true;
