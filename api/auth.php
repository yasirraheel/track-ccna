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
    $action = strtolower(trim((string) ($payload['action'] ?? '')));
    $boot = trackerBootDatabase();

    if ($action === 'register') {
        $result = trackerRegisterUser($boot['pdo'], $payload);
        $message = ($result['seedMode'] ?? '') === 'legacy'
            ? 'Account created. Your existing tracker data has been moved into this private login.'
            : 'Account created. Your private tracker is ready.';
    } elseif ($action === 'login') {
        trackerLoginUser($boot['pdo'], $payload);
        $message = 'Signed in successfully.';
    } elseif ($action === 'logout') {
        trackerLogoutUser();
        $message = 'Signed out successfully.';
    } else {
        throw new TrackerHttpException('A valid auth action is required.', 422);
    }

    trackerJsonResponse([
        'ok' => true,
        'message' => $message,
        'bootstrap' => trackerBuildBootstrapPayload($boot),
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
