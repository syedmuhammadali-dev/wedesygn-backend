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
        if ($driverCode === 1045 || $driverCode === 1044 || $sqlState === '28000') {
            return 'database_access_denied';
        }
        if ($driverCode === 1049) {
            return 'database_not_found';
        }
        if (in_array($driverCode, [2002, 2003, 2006, 2013], true) || $sqlState === 'HY000') {
            return 'database_unreachable';
        }
        return 'database_error';
    }
    if ($error instanceof RuntimeException && str_starts_with($error->getMessage(), 'Missing database environment')) {
        return 'database_not_configured';
    }

    return 'server_error';
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
        respond(500, ['error' => 'Unable to save user details', 'reason' => failureReason($error)]);
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
