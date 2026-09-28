<?php
declare(strict_types=1);

$required = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'];
$missing = array_filter($required, static fn(string $key): bool => getenv($key) === false || getenv($key) === '');
if ($missing !== []) {
    fwrite(STDERR, 'Missing database environment variables: ' . implode(', ', $missing) . PHP_EOL);
    exit(1);
}

$host = (string) getenv('DB_HOST');
$port = getenv('DB_PORT') ?: '3306';
$name = (string) getenv('DB_NAME');
$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
    (string) getenv('DB_USER'),
    (string) getenv('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS users (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(120) NOT NULL,
        email VARCHAR(255) NOT NULL,
        interested_in VARCHAR(120) NULL,
        budget_in_usd VARCHAR(80) NULL,
        project_details TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY users_email_unique (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

fwrite(STDOUT, "users table is ready\n");