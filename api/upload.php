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
        throw new TrackerHttpException('Image file is required.', 422);
    }

    $boot = trackerBootDatabase();
    $currentUser = trackerCurrentUser($boot['pdo']);
    if ($currentUser === null) {
        throw new TrackerHttpException('Please sign in to upload images.', 401);
    }

    $relativePath = trackerUploadedImagePath($_FILES['image'], (string) $currentUser['id']);

    trackerJsonResponse([
        'ok' => true,
        'url' => $relativePath,
    ]);
} catch (TrackerHttpException $error) {
    trackerJsonResponse([
        'ok' => false,
        'message' => $error->getMessage(),
    ], $error->statusCode);
} catch (Throwable $error) {
    trackerJsonResponse([
        'ok' => false,
        'message' => $error->getMessage(),
    ], 500);
}
