<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'config.php';

final class TrackerHttpException extends RuntimeException
{
    public int $statusCode;

    public function __construct(string $message, int $statusCode = 400)
    {
        parent::__construct($message, $statusCode);
        $this->statusCode = $statusCode;
    }
}

function trackerBootDatabase(): array
{
    static $boot = null;

    if ($boot !== null) {
        return $boot;
    }

    $config = trackerConfig();
    trackerEnsureDirectories($config);
    trackerStartSession();

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

function trackerStartSession(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_name('ccna_tracker_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
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

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tracker_users (
            id VARCHAR(64) PRIMARY KEY,
            username VARCHAR(120) NOT NULL UNIQUE,
            display_name VARCHAR(190) NOT NULL,
            password_hash TEXT NOT NULL,
            role VARCHAR(32) NOT NULL DEFAULT "student",
            created_at VARCHAR(64) NOT NULL,
            updated_at VARCHAR(64) NOT NULL,
            last_login_at VARCHAR(64) NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tracker_task_templates (
            id VARCHAR(190) PRIMARY KEY,
            order_index INTEGER NOT NULL,
            file_name TEXT NOT NULL,
            notes TEXT NOT NULL DEFAULT "",
            review_interval_days INTEGER NOT NULL DEFAULT 3
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tracker_user_tasks (
            user_id VARCHAR(64) NOT NULL,
            id VARCHAR(190) NOT NULL,
            order_index INTEGER NOT NULL,
            file_name TEXT NOT NULL,
            status VARCHAR(32) NOT NULL,
            notes TEXT NOT NULL DEFAULT "",
            updated_at VARCHAR(64) NULL,
            review_interval_days INTEGER NOT NULL DEFAULT 3,
            last_reviewed_at VARCHAR(64) NULL,
            next_review_at VARCHAR(64) NULL,
            last_score INTEGER NULL,
            screen_recorded INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (user_id, id)
        )'
    );
}

function trackerSeedDatabaseFromJsonIfEmpty(PDO $pdo): bool
{
    $seededLegacy = trackerSeedLegacySharedTasksIfEmpty($pdo);
    $seededTemplates = trackerSeedTaskTemplatesIfEmpty($pdo);
    return $seededLegacy || $seededTemplates;
}

function trackerSeedLegacySharedTasksIfEmpty(PDO $pdo): bool
{
    $count = (int) $pdo->query('SELECT COUNT(*) FROM tracker_tasks')->fetchColumn();
    if ($count > 0) {
        return false;
    }

    $tasks = trackerLoadTasksFromJsonSeed();
    if ($tasks === []) {
        return false;
    }

    trackerSaveLegacyTasks($pdo, $tasks, 'json-seed');
    return true;
}

function trackerSeedTaskTemplatesIfEmpty(PDO $pdo): bool
{
    $count = (int) $pdo->query('SELECT COUNT(*) FROM tracker_task_templates')->fetchColumn();
    if ($count > 0) {
        return false;
    }

    $templateSource = trackerFetchLegacySharedTasks($pdo);
    if ($templateSource === []) {
        $templateSource = trackerLoadTasksFromJsonSeed();
    }

    if ($templateSource === []) {
        return false;
    }

    trackerSaveTaskTemplates($pdo, $templateSource, 'template-seed');
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

function trackerBuildBootstrapPayload(array $boot): array
{
    $pdo = $boot['pdo'];
    $currentUser = trackerCurrentUser($pdo);
    $driver = str_starts_with($boot['config']['dsn'], 'mysql:') ? 'mysql' : 'sqlite';

    if ($currentUser === null) {
        return [
            'ok' => true,
            'mode' => 'database',
            'driver' => $driver,
            'seededFromJson' => $boot['seeded_from_json'],
            'hasUsers' => trackerAnyUsers($pdo),
            'legacyImportPending' => trackerLegacyImportPending($pdo),
            'requiresAuth' => true,
            'user' => null,
            'tasks' => [],
            'ownerOverview' => null,
        ];
    }

    $ownerOverview = trackerUserIsOwner($currentUser)
        ? trackerFetchOwnerStudentOverview($pdo, (string) $currentUser['id'])
        : null;

    return [
        'ok' => true,
        'mode' => 'database',
        'driver' => $driver,
        'seededFromJson' => $boot['seeded_from_json'],
        'hasUsers' => true,
        'legacyImportPending' => trackerLegacyImportPending($pdo),
        'requiresAuth' => false,
        'user' => trackerUserPublicPayload($currentUser),
        'tasks' => trackerFetchUserTasks($pdo, (string) $currentUser['id']),
        'ownerOverview' => $ownerOverview,
    ];
}

function trackerFetchTasks(PDO $pdo): array
{
    return trackerFetchLegacySharedTasks($pdo);
}

function trackerFetchLegacySharedTasks(PDO $pdo): array
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

    return array_map('trackerMapTaskRow', $statement->fetchAll());
}

function trackerFetchTaskTemplates(PDO $pdo): array
{
    $statement = $pdo->query(
        'SELECT
            id,
            order_index,
            file_name,
            notes,
            review_interval_days
         FROM tracker_task_templates
         ORDER BY order_index ASC, file_name ASC'
    );

    return array_map(static function (array $row): array {
        return [
            'id' => (string) $row['id'],
            'order_index' => max(1, (int) ($row['order_index'] ?? 1)),
            'file_name' => (string) ($row['file_name'] ?? ''),
            'notes' => (string) ($row['notes'] ?? ''),
            'review_interval_days' => max(1, (int) ($row['review_interval_days'] ?? 3)),
        ];
    }, $statement->fetchAll());
}

function trackerFetchUserTasks(PDO $pdo, string $userId): array
{
    trackerEnsureUserTaskCoverage($pdo, $userId);

    $statement = $pdo->prepare(
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
         FROM tracker_user_tasks
         WHERE user_id = :user_id
         ORDER BY order_index ASC, file_name ASC'
    );
    $statement->execute([
        'user_id' => $userId,
    ]);

    return array_map('trackerMapTaskRow', $statement->fetchAll());
}

function trackerFetchOwnerStudentOverview(PDO $pdo, string $ownerUserId): array
{
    $students = trackerFetchStudentSummaries($pdo, $ownerUserId);

    return [
        'studentCount' => count($students),
        'students' => $students,
    ];
}

function trackerFetchStudentSummaries(PDO $pdo, string $ownerUserId): array
{
    $statement = $pdo->prepare(
        'SELECT
            id,
            username,
            display_name,
            role,
            created_at,
            updated_at,
            last_login_at
         FROM tracker_users
         WHERE role = :role
           AND id <> :owner_id
         ORDER BY COALESCE(last_login_at, created_at) DESC, username ASC'
    );
    $statement->execute([
        'role' => 'student',
        'owner_id' => $ownerUserId,
    ]);

    $students = [];
    foreach ($statement->fetchAll() as $row) {
        $userId = (string) $row['id'];
        $tasks = trackerFetchUserTasks($pdo, $userId);
        $stats = trackerSummarizeUserTasks($tasks);

        $students[] = [
            'id' => $userId,
            'username' => (string) $row['username'],
            'displayName' => (string) $row['display_name'],
            'role' => (string) ($row['role'] ?? 'student'),
            'createdAt' => trackerNullableString($row['created_at'] ?? null),
            'updatedAt' => trackerNullableString($row['updated_at'] ?? null),
            'lastLoginAt' => trackerNullableString($row['last_login_at'] ?? null),
            'lastActivityAt' => $stats['lastActivityAt'],
            'totalLabs' => $stats['totalLabs'],
            'completedLabs' => $stats['completedLabs'],
            'reviewLabs' => $stats['reviewLabs'],
            'recordedLabs' => $stats['recordedLabs'],
            'dueNowLabs' => $stats['dueNowLabs'],
            'overdueLabs' => $stats['overdueLabs'],
            'completionRate' => $stats['completionRate'],
        ];
    }

    return $students;
}

function trackerSummarizeUserTasks(array $tasks): array
{
    $now = new DateTimeImmutable('now');
    $summary = [
        'totalLabs' => count($tasks),
        'completedLabs' => 0,
        'reviewLabs' => 0,
        'recordedLabs' => 0,
        'dueNowLabs' => 0,
        'overdueLabs' => 0,
        'completionRate' => 0,
        'lastActivityAt' => null,
    ];

    foreach ($tasks as $task) {
        $status = trackerNormalizeStatus((string) ($task['status'] ?? 'pending'));
        if ($status === 'completed') {
            $summary['completedLabs'] += 1;
        } elseif ($status === 'review') {
            $summary['reviewLabs'] += 1;
        }

        if (!empty($task['screenRecorded'])) {
            $summary['recordedLabs'] += 1;
        }

        $reviewBucket = trackerReviewTimingBucket($task['nextReviewAt'] ?? null, $now);
        if ($reviewBucket === 'due') {
            $summary['dueNowLabs'] += 1;
        } elseif ($reviewBucket === 'overdue') {
            $summary['overdueLabs'] += 1;
        }

        $summary['lastActivityAt'] = trackerLaterIsoValue(
            $summary['lastActivityAt'],
            trackerNullableString($task['updatedAt'] ?? null)
        );
    }

    if ($summary['totalLabs'] > 0) {
        $summary['completionRate'] = (int) round(($summary['completedLabs'] / $summary['totalLabs']) * 100);
    }

    return $summary;
}

function trackerReviewTimingBucket(?string $nextReviewAt, ?DateTimeImmutable $now = null): string
{
    $normalized = trackerNullableString($nextReviewAt);
    if ($normalized === null) {
        return 'none';
    }

    try {
        $dueAt = new DateTimeImmutable($normalized);
    } catch (Throwable $error) {
        return 'none';
    }

    $now = $now ?? new DateTimeImmutable('now');
    if ($dueAt > $now) {
        return 'scheduled';
    }

    $overdueSeconds = $now->getTimestamp() - $dueAt->getTimestamp();
    $overdueHours = (int) floor($overdueSeconds / 3600);
    $dueLocal = $dueAt->setTimezone($now->getTimezone());

    if ($overdueHours >= 24 || $dueLocal->format('Y-m-d') !== $now->format('Y-m-d')) {
        return 'overdue';
    }

    return 'due';
}

function trackerLaterIsoValue(?string $current, ?string $candidate): ?string
{
    if ($candidate === null) {
        return $current;
    }

    if ($current === null) {
        return $candidate;
    }

    try {
        $currentDate = new DateTimeImmutable($current);
        $candidateDate = new DateTimeImmutable($candidate);
    } catch (Throwable $error) {
        return $current;
    }

    return $candidateDate > $currentDate ? $candidate : $current;
}

function trackerSaveTasks(PDO $pdo, array $tasks, string $source = 'api'): void
{
    trackerSaveLegacyTasks($pdo, $tasks, $source);
}

function trackerSaveLegacyTasks(PDO $pdo, array $tasks, string $source = 'api'): void
{
    $normalizedTasks = trackerNormalizeTaskRecords($tasks);

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

function trackerSaveTaskTemplates(PDO $pdo, array $tasks, string $source = 'api'): void
{
    $normalizedTemplates = [];
    foreach ($tasks as $index => $task) {
        if (!is_array($task)) {
            continue;
        }
        $normalizedTemplates[] = trackerNormalizeTemplateRecord($task, $index + 1);
    }

    $pdo->beginTransaction();

    try {
        $pdo->exec('DELETE FROM tracker_task_templates');
        $insert = $pdo->prepare(
            'INSERT INTO tracker_task_templates (
                id,
                order_index,
                file_name,
                notes,
                review_interval_days
            ) VALUES (
                :id,
                :order_index,
                :file_name,
                :notes,
                :review_interval_days
            )'
        );

        foreach ($normalizedTemplates as $template) {
            $insert->execute($template);
        }

        trackerSaveMeta($pdo, 'last_template_sync_at', gmdate(DATE_ATOM));
        trackerSaveMeta($pdo, 'last_template_source', $source);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function trackerSaveUserTasks(PDO $pdo, string $userId, array $tasks, string $source = 'api'): void
{
    $normalizedTasks = trackerNormalizeTaskRecords($tasks);

    $pdo->beginTransaction();

    try {
        trackerReplaceUserTasks($pdo, $userId, $normalizedTasks);
        trackerSaveMeta($pdo, 'user_last_saved_at:' . $userId, gmdate(DATE_ATOM));
        trackerSaveMeta($pdo, 'user_last_source:' . $userId, $source);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function trackerReplaceUserTasks(PDO $pdo, string $userId, array $normalizedTasks): void
{
    $delete = $pdo->prepare('DELETE FROM tracker_user_tasks WHERE user_id = :user_id');
    $delete->execute([
        'user_id' => $userId,
    ]);

    trackerInsertUserTasks($pdo, $userId, $normalizedTasks);
}

function trackerInsertUserTasks(PDO $pdo, string $userId, array $normalizedTasks): void
{
    $insert = $pdo->prepare(
        'INSERT INTO tracker_user_tasks (
            user_id,
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
            :user_id,
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
        $insert->execute([
            'user_id' => $userId,
            'id' => $task['id'],
            'order_index' => $task['order_index'],
            'file_name' => $task['file_name'],
            'status' => $task['status'],
            'notes' => $task['notes'],
            'updated_at' => $task['updated_at'],
            'review_interval_days' => $task['review_interval_days'],
            'last_reviewed_at' => $task['last_reviewed_at'],
            'next_review_at' => $task['next_review_at'],
            'last_score' => $task['last_score'],
            'screen_recorded' => $task['screen_recorded'],
        ]);
    }
}

function trackerEnsureUserTaskCoverage(PDO $pdo, string $userId): void
{
    $templates = trackerFetchTaskTemplates($pdo);
    if ($templates === []) {
        trackerSeedTaskTemplatesIfEmpty($pdo);
        $templates = trackerFetchTaskTemplates($pdo);
    }

    if ($templates === []) {
        return;
    }

    $existingStatement = $pdo->prepare(
        'SELECT id, order_index, file_name
         FROM tracker_user_tasks
         WHERE user_id = :user_id'
    );
    $existingStatement->execute([
        'user_id' => $userId,
    ]);

    $existingRows = [];
    foreach ($existingStatement->fetchAll() as $row) {
        $existingRows[(string) $row['id']] = $row;
    }

    $insert = $pdo->prepare(
        'INSERT INTO tracker_user_tasks (
            user_id,
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
            :user_id,
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

    $update = $pdo->prepare(
        'UPDATE tracker_user_tasks
         SET order_index = :order_index,
             file_name = :file_name
         WHERE user_id = :user_id
           AND id = :id'
    );

    foreach ($templates as $template) {
        $taskId = (string) $template['id'];
        if (!isset($existingRows[$taskId])) {
            $freshTask = trackerBuildFreshUserTaskFromTemplate($template);
            $insert->execute([
                'user_id' => $userId,
                'id' => $freshTask['id'],
                'order_index' => $freshTask['order_index'],
                'file_name' => $freshTask['file_name'],
                'status' => $freshTask['status'],
                'notes' => $freshTask['notes'],
                'updated_at' => $freshTask['updated_at'],
                'review_interval_days' => $freshTask['review_interval_days'],
                'last_reviewed_at' => $freshTask['last_reviewed_at'],
                'next_review_at' => $freshTask['next_review_at'],
                'last_score' => $freshTask['last_score'],
                'screen_recorded' => $freshTask['screen_recorded'],
            ]);
            continue;
        }

        $existing = $existingRows[$taskId];
        $needsCatalogRefresh = (int) ($existing['order_index'] ?? 0) !== (int) $template['order_index']
            || (string) ($existing['file_name'] ?? '') !== (string) $template['file_name'];

        if ($needsCatalogRefresh) {
            $update->execute([
                'user_id' => $userId,
                'id' => $taskId,
                'order_index' => $template['order_index'],
                'file_name' => $template['file_name'],
            ]);
        }
    }
}

function trackerRegisterUser(PDO $pdo, array $payload): array
{
    $username = trackerNormalizeUsername((string) ($payload['username'] ?? ''));
    $displayName = trackerNormalizeDisplayName((string) ($payload['displayName'] ?? ''), $username);
    $password = trackerNormalizePassword((string) ($payload['password'] ?? ''));
    $existingUser = trackerFindUserByUsername($pdo, $username);

    if ($existingUser !== null) {
        throw new TrackerHttpException('That username is already in use.', 409);
    }

    $now = gmdate(DATE_ATOM);
    $userId = trackerGenerateOpaqueId('usr_');
    $isFirstUser = !trackerAnyUsers($pdo);

    $pdo->beginTransaction();

    try {
        $insert = $pdo->prepare(
            'INSERT INTO tracker_users (
                id,
                username,
                display_name,
                password_hash,
                role,
                created_at,
                updated_at,
                last_login_at
            ) VALUES (
                :id,
                :username,
                :display_name,
                :password_hash,
                :role,
                :created_at,
                :updated_at,
                :last_login_at
            )'
        );
        $insert->execute([
            'id' => $userId,
            'username' => $username,
            'display_name' => $displayName,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $isFirstUser ? 'owner' : 'student',
            'created_at' => $now,
            'updated_at' => $now,
            'last_login_at' => $now,
        ]);

        $seedMode = trackerInitializeUserTaskSet($pdo, $userId, false);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }

    session_regenerate_id(true);
    $_SESSION['tracker_user_id'] = $userId;

    $user = trackerFetchUserById($pdo, $userId);
    if ($user === null) {
        throw new RuntimeException('The new user account could not be loaded.');
    }

    return [
        'user' => $user,
        'seedMode' => $seedMode,
    ];
}

function trackerInitializeUserTaskSet(PDO $pdo, string $userId, bool $claimLegacy): string
{
    if ($claimLegacy) {
        $legacyTasks = trackerFetchLegacySharedTasks($pdo);
        if ($legacyTasks !== []) {
            trackerReplaceUserTasks($pdo, $userId, trackerNormalizeTaskRecords($legacyTasks));
            trackerSaveMeta($pdo, 'legacy_owner_user_id', $userId);
            trackerSaveMeta($pdo, 'user_last_saved_at:' . $userId, gmdate(DATE_ATOM));
            trackerSaveMeta($pdo, 'user_last_source:' . $userId, 'legacy-claim');
            return 'legacy';
        }
    }

    $templates = trackerFetchTaskTemplates($pdo);
    if ($templates === []) {
        trackerSeedTaskTemplatesIfEmpty($pdo);
        $templates = trackerFetchTaskTemplates($pdo);
    }

    $freshTasks = array_map('trackerBuildFreshUserTaskFromTemplate', $templates);
    trackerReplaceUserTasks($pdo, $userId, trackerNormalizeTaskRecords($freshTasks));
    trackerSaveMeta($pdo, 'user_last_saved_at:' . $userId, gmdate(DATE_ATOM));
    trackerSaveMeta($pdo, 'user_last_source:' . $userId, 'user-template-seed');
    return 'template';
}

function trackerLoginUser(PDO $pdo, array $payload): array
{
    $username = trackerNormalizeUsername((string) ($payload['username'] ?? ''));
    $password = trackerNormalizePassword((string) ($payload['password'] ?? ''));
    $user = trackerFindUserByUsername($pdo, $username);

    if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
        throw new TrackerHttpException('Username or password is incorrect.', 401);
    }

    $now = gmdate(DATE_ATOM);
    $pdo->prepare(
        'UPDATE tracker_users
         SET updated_at = :updated_at,
             last_login_at = :last_login_at
         WHERE id = :id'
    )->execute([
        'updated_at' => $now,
        'last_login_at' => $now,
        'id' => $user['id'],
    ]);

    session_regenerate_id(true);
    $_SESSION['tracker_user_id'] = (string) $user['id'];

    $reloadedUser = trackerFetchUserById($pdo, (string) $user['id']);
    if ($reloadedUser === null) {
        throw new RuntimeException('The signed-in user account could not be loaded.');
    }

    return [
        'user' => $reloadedUser,
    ];
}

function trackerLogoutUser(): void
{
    trackerStartSession();
    unset($_SESSION['tracker_user_id']);
    session_regenerate_id(true);
}

function trackerCurrentUser(PDO $pdo): ?array
{
    trackerStartSession();
    $userId = isset($_SESSION['tracker_user_id']) ? trim((string) $_SESSION['tracker_user_id']) : '';
    if ($userId === '') {
        return null;
    }

    return trackerFetchUserById($pdo, $userId);
}

function trackerFetchUserById(PDO $pdo, string $userId): ?array
{
    $statement = $pdo->prepare(
        'SELECT
            id,
            username,
            display_name,
            password_hash,
            role,
            created_at,
            updated_at,
            last_login_at
         FROM tracker_users
         WHERE id = :id
         LIMIT 1'
    );
    $statement->execute([
        'id' => $userId,
    ]);

    $row = $statement->fetch();
    return is_array($row) ? $row : null;
}

function trackerFindUserByUsername(PDO $pdo, string $username): ?array
{
    $statement = $pdo->prepare(
        'SELECT
            id,
            username,
            display_name,
            password_hash,
            role,
            created_at,
            updated_at,
            last_login_at
         FROM tracker_users
         WHERE username = :username
         LIMIT 1'
    );
    $statement->execute([
        'username' => $username,
    ]);

    $row = $statement->fetch();
    return is_array($row) ? $row : null;
}

function trackerAnyUsers(PDO $pdo): bool
{
    return (int) $pdo->query('SELECT COUNT(*) FROM tracker_users')->fetchColumn() > 0;
}

function trackerLegacyImportPending(PDO $pdo): bool
{
    $legacyOwnerId = trackerLoadMeta($pdo, 'legacy_owner_user_id');
    if ($legacyOwnerId !== null && trim($legacyOwnerId) !== '') {
        return false;
    }

    return (int) $pdo->query('SELECT COUNT(*) FROM tracker_tasks')->fetchColumn() > 0;
}

function trackerUserPublicPayload(array $user): array
{
    return [
        'id' => (string) $user['id'],
        'username' => (string) $user['username'],
        'displayName' => (string) $user['display_name'],
        'role' => (string) ($user['role'] ?? 'student'),
        'createdAt' => trackerNullableString($user['created_at'] ?? null),
        'lastLoginAt' => trackerNullableString($user['last_login_at'] ?? null),
    ];
}

function trackerUserIsOwner(array $user): bool
{
    return (string) ($user['role'] ?? '') === 'owner';
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

function trackerLoadMeta(PDO $pdo, string $key): ?string
{
    $statement = $pdo->prepare(
        'SELECT meta_value
         FROM tracker_meta
         WHERE meta_key = :meta_key
         LIMIT 1'
    );
    $statement->execute([
        'meta_key' => $key,
    ]);

    $value = $statement->fetchColumn();
    return $value === false ? null : trackerNullableString($value);
}

function trackerNormalizeTaskRecords(array $tasks): array
{
    $normalizedTasks = [];
    foreach ($tasks as $index => $task) {
        if (!is_array($task)) {
            continue;
        }
        $normalizedTasks[] = trackerNormalizeTaskRecord($task, $index + 1);
    }

    return $normalizedTasks;
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

function trackerNormalizeTemplateRecord(array $task, int $fallbackOrder): array
{
    $normalized = trackerNormalizeTaskRecord($task, $fallbackOrder);
    return [
        'id' => $normalized['id'],
        'order_index' => $normalized['order_index'],
        'file_name' => $normalized['file_name'],
        'notes' => $normalized['notes'],
        'review_interval_days' => $normalized['review_interval_days'],
    ];
}

function trackerBuildFreshUserTaskFromTemplate(array $template): array
{
    return [
        'id' => (string) ($template['id'] ?? ''),
        'order_index' => max(1, (int) ($template['order_index'] ?? 1)),
        'file_name' => (string) ($template['file_name'] ?? ''),
        'status' => 'pending',
        'notes' => (string) ($template['notes'] ?? ''),
        'updated_at' => null,
        'review_interval_days' => max(1, (int) ($template['review_interval_days'] ?? 3)),
        'last_reviewed_at' => null,
        'next_review_at' => null,
        'last_score' => null,
        'screen_recorded' => 0,
    ];
}

function trackerMapTaskRow(array $row): array
{
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
}

function trackerNormalizeUsername(string $username): string
{
    $normalized = strtolower(trim($username));
    if ($normalized === '') {
        throw new TrackerHttpException('Username is required.', 422);
    }

    if (!preg_match('/^[a-z0-9._-]{3,40}$/', $normalized)) {
        throw new TrackerHttpException('Username must be 3-40 characters using letters, numbers, dot, dash, or underscore.', 422);
    }

    return $normalized;
}

function trackerNormalizeDisplayName(string $displayName, string $fallbackUsername): string
{
    $normalized = trim($displayName);
    if ($normalized === '') {
        $normalized = $fallbackUsername;
    }

    if (mb_strlen($normalized) > 90) {
        throw new TrackerHttpException('Display name is too long.', 422);
    }

    return $normalized;
}

function trackerNormalizePassword(string $password): string
{
    if (strlen($password) < 6) {
        throw new TrackerHttpException('Password must be at least 6 characters.', 422);
    }

    return $password;
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

function trackerGenerateOpaqueId(string $prefix = 'id_'): string
{
    return $prefix . bin2hex(random_bytes(9));
}

function trackerUploadedImagePath(array $file, ?string $userId = null): string
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
    $relativeDirectory = 'attachments';
    $targetDirectory = $config['attachments_path'];

    if ($userId !== null && $userId !== '') {
        $safeUserSegment = 'user-' . preg_replace('/[^a-zA-Z0-9_-]+/', '', $userId);
        $relativeDirectory .= '/' . $safeUserSegment;
        $targetDirectory .= DIRECTORY_SEPARATOR . $safeUserSegment;
    }

    if (!is_dir($targetDirectory)) {
        mkdir($targetDirectory, 0775, true);
    }

    $fileName = 'img-' . round(microtime(true) * 1000) . '-' . random_int(100, 999) . '.' . $extension;
    $targetPath = $targetDirectory . DIRECTORY_SEPARATOR . $fileName;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new RuntimeException('Image could not be stored on the server.');
    }

    return $relativeDirectory . '/' . $fileName;
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
