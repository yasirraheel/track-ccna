<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib.php';

try {
    $boot = trackerBootDatabase();

    trackerJsonResponse(trackerBuildBootstrapPayload($boot));
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
