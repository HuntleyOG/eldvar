<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

/**
 * Lazy, reusable PDO connection.
 * Call get_pdo() only when you actually need DB access.
 */
function get_pdo(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Throw exceptions
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_PERSISTENT         => false,                  // Avoid persistent until needed
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (Throwable $e) {
        if (APP_DEBUG) {
            // In dev, show the precise error
            die('<pre>DB connection failed: ' . htmlspecialchars($e->getMessage()) . '</pre>');
        }
        // In prod, show a friendly message (prevents HTTP 500 without context)
        http_response_code(503);
        die('Service temporarily unavailable.'); 
    }
    

    return $pdo;
}
