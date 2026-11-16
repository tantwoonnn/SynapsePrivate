<?php
/** PDO connection for local XAMPP MySQL or hosted Supabase PostgreSQL. */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || getenv('VERCEL') === '1',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

require_once __DIR__ . '/../includes/DatabaseCompat.php';

$databaseUrl = $_ENV['SUPABASE_DATABASE_URL'] ?? getenv('SUPABASE_DATABASE_URL') ?: '';
if ($databaseUrl !== '') {
    $parts = parse_url($databaseUrl);
    if (!$parts || empty($parts['host']) || empty($parts['user']) || !isset($parts['pass'])) {
        http_response_code(500);
        exit('Database connection URL is invalid.');
    }
    $databaseName = ltrim($parts['path'] ?? '/postgres', '/');
    $dsn = sprintf(
        'pgsql:host=%s;port=%d;dbname=%s;sslmode=require',
        $parts['host'],
        (int) ($parts['port'] ?? 5432),
        $databaseName
    );
    $username = rawurldecode($parts['user']);
    $password = rawurldecode($parts['pass']);
    $driver = 'pgsql';
} else {
    $host = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'localhost';
    $username = $_ENV['DB_USERNAME'] ?? getenv('DB_USERNAME') ?: 'root';
    $password = $_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: '';
    $database = $_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE') ?: 'synapse';
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $database);
    $driver = 'mysql';
}

try {
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => true,
    ]);
    $conn = new SynapseConnection($pdo, $driver);

    if (session_status() === PHP_SESSION_NONE) {
        if ($driver === 'pgsql') {
            require_once __DIR__ . '/../includes/DatabaseSessionHandler.php';
            session_set_save_handler(new DatabaseSessionHandler($pdo), true);
        }
        session_start();
    }
} catch (Throwable $exception) {
    error_log('Synapse database connection failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('Database connection failed. Check the configured database connection.');
}
