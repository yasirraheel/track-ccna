<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'config.php';

function trackerBootDatabase(): array
{
    static $boot = null;

    if ($boot !== null) {
        return $boot;
    }

    $config = trackerConfig();
    trackerEnsureDirectories($config);

    $pdo = new PDO(
        $config['dsn'],
        $config['db_user'],
        $config['db_pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    trackerEnsureSchema($pdo);
    $seeded = trackerSeedDatabaseFromJsonIfEmpty($pdo);

    $boot = [
        'pdo' => $pdo,
        'config' => $config,
        'seeded_from_json' => $seeded,
    ];

    return $boot;
}

function trackerEnsureDirectories(array $config): void
{
    $storageDir = dirname($config['sqlite_path']);
    if (!is_dir($storageDir)) {
        mkdir($storageDir, 0775, true);
    }

    if (!is_dir($config['attachments_path'])) {
        mkdir($config['attachments_path'], 0775, true);
    }
}

function trackerEnsureSchema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tracker_tasks (
            id VARCHAR(190) PRIMARY KEY,
            order_index INTEGER NOT NULL,
            file_name TEXT NOT NULL,
            status VARCHAR(32) NOT NULL,
            notes TEXT NOT NULL DEFAULT "",
            updated_at VARCHAR(64) NULL,
            review_interval_days INTEGER NOT NULL DEFAULT 3,
            last_reviewed_at VARCHAR(64) NULL,
            next_review_at VARCHAR(64) NULL,
            last_score INTEGER NULL,
            screen_recorded INTEGER NOT NULL DEFAULT 0
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tracker_meta (
            meta_key VARCHAR(190) PRIMARY KEY,
            meta_value TEXT NULL
        )'
    );
}

function trackerSeedDatabaseFromJsonIfEmpty(PDO $pdo): bool
{
    $count = (int) $pdo->query('SELECT COUNT(*) FROM tracker_tasks')->fetchColumn();
    if ($count > 0) {
        return false;
    }

    $tasks = trackerLoadTasksFromJsonSeed();
    if ($tasks === []) {
        return false;
    }

    trackerSaveTasks($pdo, $tasks, 'json-seed');
    return true;
}

function trackerLoadTasksFromJsonSeed(): array
{
    $config = trackerConfig();
    if (!is_file($config['json_seed_path'])) {
        return [];
    }

    $raw = file_get_contents($config['json_seed_path']);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['tasks']) || !is_array($data['tasks'])) {
        return [];
    }

    return $data['tasks'];
}

function trackerFetchTasks(PDO $pdo): array
{
    $statement = $pdo->query(
        'SELECT
            id,
            order_index,
            file_name,
            status,
            notes,
            updated_at,
            review_interval_days,
            last_reviewed_at,
            next_review_at,
            last_score,
            screen_recorded
         FROM tracker_tasks
         ORDER BY order_index ASC, file_name ASC'
    );

    $rows = $statement->fetchAll();

    return array_map(static function (array $row): array {
        return [
            'id' => (string) $row['id'],
            'order' => (int) $row['order_index'],
            'fileName' => (string) $row['file_name'],
            'status' => trackerNormalizeStatus($row['status'] ?? 'pending'),
            'notes' => (string) ($row['notes'] ?? ''),
            'updatedAt' => trackerNullableString($row['updated_at'] ?? null),
            'reviewIntervalDays' => max(1, (int) ($row['review_interval_days'] ?? 3)),
            'lastReviewedAt' => trackerNullableString($row['last_reviewed_at'] ?? null),
            'nextReviewAt' => trackerNullableString($row['next_review_at'] ?? null),
            'lastScore' => trackerNullableInt($row['last_score'] ?? null),
            'screenRecorded' => ((int) ($row['screen_recorded'] ?? 0)) === 1,
        ];
    }, $rows);
}

function trackerSaveTasks(PDO $pdo, array $tasks, string $source = 'api'): void
{
    $normalizedTasks = [];
    foreach ($tasks as $index => $task) {
        if (!is_array($task)) {
            continue;
        }
        $normalizedTasks[] = trackerNormalizeTaskRecord($task, $index + 1);
    }

    $pdo->beginTransaction();

    try {
        $pdo->exec('DELETE FROM tracker_tasks');

        $insert = $pdo->prepare(
            'INSERT INTO tracker_tasks (
                id,
                order_index,
                file_name,
                status,
                notes,
                updated_at,
                review_interval_days,
                last_reviewed_at,
                next_review_at,
                last_score,
                screen_recorded
            ) VALUES (
                :id,
                :order_index,
                :file_name,
                :status,
                :notes,
                :updated_at,
                :review_interval_days,
                :last_reviewed_at,
                :next_review_at,
                :last_score,
                :screen_recorded
            )'
        );

        foreach ($normalizedTasks as $task) {
            $insert->execute($task);
        }

        trackerSaveMeta($pdo, 'last_saved_at', gmdate(DATE_ATOM));
        trackerSaveMeta($pdo, 'last_source', $source);

        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function trackerSaveMeta(PDO $pdo, string $key, string $value): void
{
    $pdo->prepare('DELETE FROM tracker_meta WHERE meta_key = :meta_key')->execute([
        'meta_key' => $key,
    ]);

    $pdo->prepare(
        'INSERT INTO tracker_meta (meta_key, meta_value) VALUES (:meta_key, :meta_value)'
    )->execute([
        'meta_key' => $key,
        'meta_value' => $value,
    ]);
}

function trackerNormalizeTaskRecord(array $task, int $fallbackOrder): array
{
    $fileName = (string) ($task['fileName'] ?? $task['file_name'] ?? '');
    $id = (string) ($task['id'] ?? trackerMakeId($fileName));

    return [
        'id' => $id,
        'order_index' => max(1, (int) ($task['order'] ?? $task['order_index'] ?? $fallbackOrder)),
        'file_name' => $fileName,
        'status' => trackerNormalizeStatus((string) ($task['status'] ?? 'pending')),
        'notes' => (string) ($task['notes'] ?? ''),
        'updated_at' => trackerNullableString($task['updatedAt'] ?? $task['updated_at'] ?? null),
        'review_interval_days' => max(1, (int) ($task['reviewIntervalDays'] ?? $task['review_interval_days'] ?? 3)),
        'last_reviewed_at' => trackerNullableString($task['lastReviewedAt'] ?? $task['last_reviewed_at'] ?? null),
        'next_review_at' => trackerNullableString($task['nextReviewAt'] ?? $task['next_review_at'] ?? null),
        'last_score' => trackerNullableInt($task['lastScore'] ?? $task['last_score'] ?? null),
        'screen_recorded' => trackerBoolToInt($task['screenRecorded'] ?? $task['screen_recorded'] ?? false),
    ];
}

function trackerNormalizeStatus(string $status): string
{
    return in_array($status, ['pending', 'review', 'completed'], true) ? $status : 'pending';
}

function trackerNullableString(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }

    $string = trim((string) $value);
    return $string === '' ? null : $string;
}

function trackerNullableInt(mixed $value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }

    return is_numeric($value) ? (int) $value : null;
}

function trackerBoolToInt(mixed $value): int
{
    return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
}

function trackerMakeId(string $value): string
{
    $normalized = strtolower($value);
    $normalized = preg_replace('/[^a-z0-9]+/', '-', $normalized) ?? '';
    return trim($normalized, '-');
}

function trackerUploadedImagePath(array $file): string
{
    if (!isset($file['tmp_name'], $file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Image upload failed.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']) ?: '';
    if (strpos($mimeType, 'image/') !== 0) {
        throw new RuntimeException('Only image uploads are allowed.');
    }

    $extensionMap = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
    ];
    $extension = $extensionMap[$mimeType] ?? 'png';

    $config = trackerConfig();
    if (!is_dir($config['attachments_path'])) {
        mkdir($config['attachments_path'], 0775, true);
    }

    $fileName = 'img-' . round(microtime(true) * 1000) . '-' . random_int(100, 999) . '.' . $extension;
    $targetPath = $config['attachments_path'] . DIRECTORY_SEPARATOR . $fileName;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new RuntimeException('Image could not be stored on the server.');
    }

    return 'attachments/' . $fileName;
}

function trackerJsonResponse(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function trackerRequestJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}
