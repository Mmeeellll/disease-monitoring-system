<?php
/**
 * FILE: config.php
 * PURPOSE: Core configuration, database connection, session management,
 *          and global security helper functions (XSS, CSRF, Auth).
 * SYSTEM ROLE: Included at the top of every protected page to provide
 *              unified database access and security enforcement.
 */

// ========================================
// LOCAL XAMPP DATABASE CONFIGURATION
// ========================================
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306'); // Change to '3307' if your XAMPP MySQL runs on port 3307
define('DB_NAME', 'disease_monitoring');
define('DB_USER', 'root');
define('DB_PASS', '');

// ========================================
// SESSION INITIALIZATION
// ========================================
// Starts a PHP session if none exists so user login state and flash messages persist across pages.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ========================================
// DATABASE CONNECTION (SINGLETON PDO)
// ========================================
/**
 * Returns a single shared PDO database instance.
 * Uses prepared statements (ATTR_EMULATE_PREPARES => false) to prevent SQL Injection.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                DB_HOST,
                DB_PORT,
                DB_NAME
            );

            $pdo = new PDO(
                $dsn,
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,       // Throw exceptions on SQL errors
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,  // Return rows as associative arrays
                    PDO::ATTR_EMULATE_PREPARES => false                // Enforce native prepared statements
                ]
            );
        } catch (PDOException $e) {
            error_log('Database Error: ' . $e->getMessage());
            throw new RuntimeException('Database connection failed: ' . $e->getMessage());
        }
    }

    return $pdo;
}

// ========================================
// HTML ESCAPING (XSS PROTECTION)
// ========================================
/**
 * Sanitizes dynamic output before rendering in HTML to prevent Cross-Site Scripting (XSS).
 */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// ========================================
// AUTHENTICATION GUARDS
// ========================================
/**
 * Checks if a user is currently authenticated in the session.
 */
function logged_in(): bool
{
    return isset($_SESSION['user_id']);
}

/**
 * Protects private routes by redirecting unauthenticated users to login.php.
 */
function require_login(): void
{
    if (!logged_in()) {
        header('Location: login.php');
        exit;
    }
}

// ========================================
// FLASH MESSAGES (ONE-TIME NOTIFICATIONS)
// ========================================
/**
 * Stores a temporary status message in the session to display after a page redirect.
 */
function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = [
        'message' => $message,
        'type' => $type
    ];
}

// ========================================
// CSRF PROTECTION
// ========================================
/**
 * Generates and stores a cryptographic 32-byte token to protect forms against Cross-Site Request Forgery.
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

/**
 * Validates the submitted POST CSRF token against the session token; aborts with HTTP 403 on mismatch.
 */
function verify_csrf(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(403);
        exit('Invalid security token. Please go back and retry.');
    }
}