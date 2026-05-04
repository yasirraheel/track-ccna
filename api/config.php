<?php
declare(strict_types=1);

function trackerConfig(): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $rootPath = dirname(__DIR__);

    $config = [
        'root_path' => $rootPath,
        'attachments_path' => $rootPath . DIRECTORY_SEPARATOR . 'attachments',
        'json_seed_path' => $rootPath . DIRECTORY_SEPARATOR . 'ccna-progress-data.json',
        'sqlite_path' => $rootPath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'ccna-tracker.sqlite',
        'db_driver' => getenv('CCNA_TRACKER_DB_DRIVER') ?: 'sqlite',
        'db_host' => getenv('CCNA_TRACKER_DB_HOST') ?: '127.0.0.1',
        'db_port' => getenv('CCNA_TRACKER_DB_PORT') ?: '3306',
        'db_name' => getenv('CCNA_TRACKER_DB_NAME') ?: 'track_ccna',
        'db_user' => getenv('CCNA_TRACKER_DB_USER') ?: '',
        'db_pass' => getenv('CCNA_TRACKER_DB_PASS') ?: '',
        'db_charset' => getenv('CCNA_TRACKER_DB_CHARSET') ?: 'utf8mb4',
        'dsn' => getenv('CCNA_TRACKER_DB_DSN') ?: '',
    ];

    $localConfigPath = __DIR__ . DIRECTORY_SEPARATOR . 'config.local.php';
    if (is_file($localConfigPath)) {
        $overrides = require $localConfigPath;
        if (is_array($overrides)) {
            $config = array_replace($config, $overrides);
        }
    }

    if ($config['dsn'] === '') {
        if ($config['db_driver'] === 'mysql') {
            $config['dsn'] = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $config['db_host'],
                $config['db_port'],
                $config['db_name'],
                $config['db_charset']
            );
        } else {
            $config['dsn'] = 'sqlite:' . $config['sqlite_path'];
        }
    }

    return $config;
}
