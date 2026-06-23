<?php

$sessionLifetime = 86400;
ini_set('session.gc_maxlifetime', $sessionLifetime);
session_set_cookie_params([
    'lifetime' => $sessionLifetime,
    'path' => '/',
    'domain' => '',
    'secure' => isset($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Strict'
]);
session_start();

if (!empty($_SESSION['user_id'])) {
    require_once __DIR__ . '/UserDatabase.php';
    $userDb = new UserDatabase();
    $user = $userDb->getUser((int)$_SESSION['user_id']);
    $_SESSION['user_role'] = $user['role'] ?? 'user';
}
