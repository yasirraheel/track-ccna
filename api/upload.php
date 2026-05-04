<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    trackerJsonResponse([
        'ok' => false,
        'message' => 'Only POST is allowed.',
    ], 405);
}

try {
    if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
        trackerJsonResponse([
            'ok' => false,
            'message' => 'Image file is required.',
        ], 422);
    }

    trackerBootDatabase();
    $relativePath = trackerUploadedImagePath($_FILES['image']);

    trackerJsonResponse([
        'ok' => true,
        'url' => $relativePath,
    ]);
} catch (Throwable $error) {
    trackerJsonResponse([
        'ok' => false,
        'message' => $error->getMessage(),
    ], 500);
}
