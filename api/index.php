<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/mailer.php';

function respond(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function database(): PDO
{
    static $connection;

    if ($connection instanceof PDO) {
        return $connection;
    }

    $required = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'];
    $missing = array_filter($required, static fn(string $key): bool => getenv($key) === false || getenv($key) === '');
    if ($missing !== []) {
        throw new RuntimeException('Missing database environment variables: ' . implode(', ', $missing));
    }

    $host = (string) getenv('DB_HOST');
    $port = getenv('DB_PORT') ?: '3306';
    $name = (string) getenv('DB_NAME');
    $connection = new PDO(
        "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
        (string) getenv('DB_USER'),
        (string) getenv('DB_PASSWORD'),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    return $connection;
}

function requestPath(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    return rtrim(is_string($path) ? $path : '/', '/') ?: '/';
}

function failureReason(Throwable $error): string
{
    if ($error instanceof PDOException) {
        $sqlState = (string) $error->getCode();
        $driverCode = (int) ($error->errorInfo[1] ?? 0);
        if ($sqlState === '42S02' || $driverCode === 1146) {
            return 'table_missing';
        }
        if ($sqlState === '42S22' || $driverCode === 1054) {
            return 'column_missing';
        }
        if ($driverCode === 1045 || $driverCode === 1044) {
            return 'database_access_denied';
        }
        if ($driverCode === 1130 || $driverCode === 1129) {
            return 'database_host_not_allowed';
        }
        if ($driverCode === 1049) {
            return 'database_not_found';
        }
        if (in_array($driverCode, [2002, 2003], true)) {
            return 'database_unreachable';
        }
        if (in_array($driverCode, [2006, 2013], true)) {
            return 'database_connection_dropped';
        }
        if (in_array($driverCode, [2026, 2054], true)) {
            return 'database_tls_or_auth_mismatch';
        }
        if ($sqlState === '0' && $driverCode === 0 && stripos($error->getMessage(), 'could not find driver') !== false) {
            return 'pdo_mysql_missing';
        }
        return 'database_error';
    }
    if ($error instanceof RuntimeException && str_starts_with($error->getMessage(), 'Missing database environment')) {
        return 'database_not_configured';
    }

    return 'server_error';
}

function tcpProbe(string $host, int $port): string
{
    $started = microtime(true);
    $socket = @fsockopen($host, $port, $errno, $errstr, 4);
    $ms = (int) round((microtime(true) - $started) * 1000);
    if ($socket === false) {
        return 'FAILED (' . ($errstr !== '' ? $errstr : 'error ' . $errno) . ', ' . $ms . ' ms)';
    }
    fclose($socket);

    return 'open (' . $ms . ' ms)';
}

function databaseDiagnostics(): array
{
    $length = static fn(string $value): string => $value === '' ? '(empty)' : strlen($value) . ' characters';
    $environment = environmentReport();
    $report = [
        'envFileUsed' => $environment['usedFile'] ?? 'none found',
        'keysProvidedByHostEnvironment' => $environment['keysFromHostEnvironment'],
        'config' => [
            'DB_HOST' => getenv('DB_HOST') ?: '(missing)',
            'DB_PORT' => getenv('DB_PORT') ?: '(missing - 3306 would be used)',
            'DB_NAME' => $length((string) getenv('DB_NAME')),
            'DB_USER' => $length((string) getenv('DB_USER')),
            'DB_PASSWORD' => $length((string) getenv('DB_PASSWORD')),
        ],
        'php' => ['version' => PHP_VERSION, 'pdo_mysql' => extension_loaded('pdo_mysql')],
    ];

    // Fixed list of places to try from this server (never taken from the request). Shows whether the
    // hosting blocks outbound database ports or whether a different host/port works.
    $configuredHost = (string) getenv('DB_HOST');
    $configuredPort = (int) (getenv('DB_PORT') ?: 3306);
    $resolved = $configuredHost !== '' ? gethostbyname($configuredHost) : '';
    $candidates = [
        ['configured', $configuredHost, $configuredPort],
        ['configured host, port 3306', $configuredHost, 3306],
        ['resolved IPv4, configured port', $resolved, $configuredPort],
        ['localhost, port 3306', '127.0.0.1', 3306],
    ];
    $report['network'] = ['configuredHostResolvesTo' => $resolved === $configuredHost ? '(does not resolve)' : $resolved];
    foreach ($candidates as [$label, $host, $port]) {
        if ($host === '') {
            continue;
        }
        $entry = ['tcp' => tcpProbe($host, (int) $port)];
        if (str_starts_with($entry['tcp'], 'open') && extension_loaded('pdo_mysql')) {
            try {
                new PDO("mysql:host={$host};port={$port};dbname=" . getenv('DB_NAME') . ';charset=utf8mb4', (string) getenv('DB_USER'), (string) getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
                $entry['login'] = 'OK - this host/port works';
            } catch (Throwable $error) {
                $entry['login'] = 'failed, code ' . (int) ($error->errorInfo[1] ?? 0);
            }
        }
        $report['network'][$label . ' (' . $host . ':' . $port . ')'] = $entry;
    }
    $report['network']['outbound https (1.1.1.1:443)'] = tcpProbe('1.1.1.1', 443);
    $report['network']['outbound high port (portquiz.net:' . $configuredPort . ')'] = tcpProbe('portquiz.net', $configuredPort);

    try {
        $pdo = database();
        $report['connected'] = true;
        $report['serverVersion'] = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        $report['usersTableExists'] = (bool) $pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn();
    } catch (Throwable $error) {
        $message = $error->getMessage();
        foreach (['DB_PASSWORD', 'DB_USER', 'DB_NAME'] as $key) {
            $secret = (string) getenv($key);
            if ($secret !== '') {
                $message = str_replace($secret, '***', $message);
            }
        }
        $report['connected'] = false;
        $report['error'] = ['reason' => failureReason($error), 'code' => (int) ($error->errorInfo[1] ?? 0), 'message' => mb_substr($message, 0, 300)];
    }

    return $report;
}

$allowedOrigins = array_filter(array_map('trim', explode(',', getenv('CORS_ORIGIN') ?: '*')));
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && (in_array('*', $allowedOrigins, true) || in_array($origin, $allowedOrigins, true))) {
    header('Access-Control-Allow-Origin: ' . (in_array('*', $allowedOrigins, true) ? '*' : $origin));
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Admin-Key');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header_remove('X-Powered-By');

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$path = requestPath();
if ($path === '/api/health' && $method === 'GET' && ($_GET['check'] ?? '') === 'db') {
    // Admin-only connection check: shows which .env was read and why the database cannot be reached.
    $adminKey = getenv('ADMIN_API_KEY');
    if (!$adminKey || !hash_equals($adminKey, $_SERVER['HTTP_X_ADMIN_KEY'] ?? '')) {
        respond(401, ['error' => 'Unauthorized']);
    }
    respond(200, databaseDiagnostics());
}

if ($path === '/api/health' && $method === 'GET') {
    respond(200, [
        'status' => 'ok',
        'service' => 'wedesygn-backend',
        'environment' => getenv('NODE_ENV') ?: 'development',
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
    ]);
}

if (in_array($path, ['/api/users', '/api/create-user'], true) && $method === 'GET') {
    $adminKey = getenv('ADMIN_API_KEY');
    if (!$adminKey || !hash_equals($adminKey, $_SERVER['HTTP_X_ADMIN_KEY'] ?? '')) {
        respond(401, ['error' => 'Unauthorized']);
    }

    $limit = min(max(filter_var($_GET['limit'] ?? null, FILTER_VALIDATE_INT) ?: 20, 1), 100);
    $offset = max(filter_var($_GET['offset'] ?? null, FILTER_VALIDATE_INT) ?: 0, 0);

    try {
        $statement = database()->prepare(
            'SELECT id, name, email, interested_in AS interestedIn,
                    budget_in_usd AS budgetInUsd, project_details AS projectDetails,
                    created_at AS createdAt, updated_at AS updatedAt
             FROM users ORDER BY created_at DESC LIMIT :limit OFFSET :offset'
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        $users = $statement->fetchAll();
        $total = (int) database()->query('SELECT COUNT(*) FROM users')->fetchColumn();

        respond(200, ['users' => $users, 'pagination' => ['total' => $total, 'limit' => $limit, 'offset' => $offset]]);
    } catch (Throwable $error) {
        error_log('User listing failed: ' . $error->getMessage());
        respond(500, ['error' => 'Unable to load users']);
    }
}

if (in_array($path, ['/api/users', '/api/create-user'], true) && $method === 'POST') {
    $rawBody = file_get_contents('php://input') ?: '';
    $input = json_decode($rawBody, true);
    if ($rawBody !== '' && json_last_error() !== JSON_ERROR_NONE) {
        respond(400, ['error' => 'Invalid JSON body']);
    }
    $input = is_array($input) ? $input : [];

    $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';
    $email = is_string($input['email'] ?? null) ? strtolower(trim($input['email'])) : '';
    $interestedIn = is_string($input['interestedIn'] ?? null) ? trim($input['interestedIn']) : null;
    $budgetValue = $input['budgetInUsd'] ?? $input['budget'] ?? null;
    $budgetInUsd = is_string($budgetValue) ? trim($budgetValue) : null;
    $projectDetails = is_string($input['projectDetails'] ?? null) ? trim($input['projectDetails']) : null;

    if ($name === '' || mb_strlen($name) > 120) {
        respond(400, ['error' => 'Name is required and must be 120 characters or fewer']);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
        respond(400, ['error' => 'A valid email is required']);
    }

    try {
        $statement = database()->prepare(
            'INSERT INTO users (name, email, interested_in, budget_in_usd, project_details)
             VALUES (:name, :email, :interestedIn, :budgetInUsd, :projectDetails)
             ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                name = VALUES(name),
                interested_in = VALUES(interested_in),
                budget_in_usd = VALUES(budget_in_usd),
                project_details = VALUES(project_details),
                updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute([
            ':name' => $name,
            ':email' => $email,
            ':interestedIn' => $interestedIn ?: null,
            ':budgetInUsd' => $budgetInUsd ?: null,
            ':projectDetails' => $projectDetails ?: null,
        ]);

        try {
            $notificationSent = sendUserNotification($name, $email, $interestedIn, $budgetInUsd, $projectDetails);
        } catch (Throwable $mailError) {
            error_log('Notification email threw: ' . $mailError->getMessage());
            $notificationSent = false;
        }
        if (!$notificationSent) {
            error_log('User details saved, but notification email could not be sent for ' . $email);
        }

        // rowCount(): 1 = new row, 2 = an existing row for this email was updated with the new enquiry.
        $isNew = $statement->rowCount() === 1;
        respond($isNew ? 201 : 200, [
            'message' => 'User details saved successfully',
            'returningVisitor' => !$isNew,
            'notificationSent' => $notificationSent,
            'user' => [
                'id' => (int) database()->lastInsertId(),
                'name' => $name,
                'email' => $email,
                'interestedIn' => $interestedIn,
                'budgetInUsd' => $budgetInUsd,
                'projectDetails' => $projectDetails,
            ],
        ]);
    } catch (PDOException $error) {
        if ($error->getCode() === '23000') {
            respond(409, ['error' => 'A user with this email already exists']);
        }
        error_log('User creation failed: ' . $error->getMessage());
        respond(500, ['error' => 'Unable to save user details', 'reason' => failureReason($error), 'code' => (int) ($error->errorInfo[1] ?? 0)]);
    } catch (Throwable $error) {
        error_log('User creation failed: ' . $error->getMessage());
        respond(500, ['error' => 'Unable to save user details', 'reason' => failureReason($error)]);
    }
}

if (in_array($path, ['/api/health', '/api/users', '/api/create-user'], true)) {
    header('Allow: ' . ($path === '/api/health' ? 'GET, OPTIONS' : 'GET, POST, OPTIONS'));
    respond(405, ['error' => 'Method not allowed']);
}

respond(404, ['error' => 'Not found']);
