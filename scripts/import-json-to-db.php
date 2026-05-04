<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'lib.php';

try {
    $boot = trackerBootDatabase();
    $tasks = trackerLoadTasksFromJsonSeed();

    if ($tasks === []) {
        fwrite(STDERR, "No tasks found in ccna-progress-data.json\n");
        exit(1);
    }

    trackerSaveTasks($boot['pdo'], $tasks, 'json-import-script');
    fwrite(STDOUT, "Imported " . count($tasks) . " tasks into the database.\n");
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
