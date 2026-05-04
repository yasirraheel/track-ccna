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
    $payload = trackerRequestJsonBody();
    $tasks = isset($payload['tasks']) && is_array($payload['tasks']) ? $payload['tasks'] : null;

    if ($tasks === null) {
        throw new TrackerHttpException('Tasks payload is required.', 422);
    }

    $boot = trackerBootDatabase();
    $currentUser = trackerCurrentUser($boot['pdo']);
    if ($currentUser === null) {
        throw new TrackerHttpException('Please sign in to save this tracker.', 401);
    }

    trackerSaveUserTasks($boot['pdo'], (string) $currentUser['id'], $tasks, 'api-save');

    trackerJsonResponse([
        'ok' => true,
        'savedAt' => gmdate(DATE_ATOM),
        'count' => count($tasks),
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
