<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib.php';

try {
    $boot = trackerBootDatabase();

    trackerJsonResponse([
        'ok' => true,
        'mode' => 'database',
        'driver' => str_starts_with($boot['config']['dsn'], 'mysql:') ? 'mysql' : 'sqlite',
        'seededFromJson' => $boot['seeded_from_json'],
        'tasks' => trackerFetchTasks($boot['pdo']),
    ]);
} catch (Throwable $error) {
    trackerJsonResponse([
        'ok' => false,
        'message' => $error->getMessage(),
    ], 500);
}
