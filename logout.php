<?php
/**
 * FILE: logout.php
 * PURPOSE: Securely terminates the active user session.
 * SYSTEM ROLE: Clears session data, expires the session cookie on the client browser,
 *              destroys server-side session storage, and redirects to login.php.
 */

require_once 'config.php';

// Unset all session variables
$_SESSION = [];

// Expire the session cookie in the user's browser
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}

// Destroy the session on the server and redirect to login
session_destroy();
header('Location: login.php');
exit;