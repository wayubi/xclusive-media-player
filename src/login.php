<?php

require_once __DIR__ . '/lib/UserDatabase.php';

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
    header('Location: index.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } else {
        $userDb = new UserDatabase();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        if ($userDb->isRateLimited($ip)) {
            $error = 'Too many login attempts. Please try again later.';
        } else {
            $userId = $userDb->verifyPassword($username, $password);

            if ($userId !== null) {
                $userDb->logAttempt($ip, true);
                $userDb->updateLastLogin($userId);

                $user = $userDb->getUser($userId);
                session_regenerate_id(true);
                $_SESSION['user_id'] = $userId;
                $_SESSION['username'] = $username;
                $_SESSION['user_role'] = $user['role'] ?? 'user';

                header('Location: index.php');
                exit;
            }

            $userDb->logAttempt($ip, false);
            $error = 'Invalid username or password.';
        }
    }

    if ($error) {
        $_SESSION['flash_error'] = $error;
        header('Location: login.php');
        exit;
    }
}

$flashError = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#0a0a0f">
    <title>Sign In — Xclusive Media Player</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="stylesheet" href="/assets/css/app.css?v=<?= filemtime(__DIR__ . '/assets/css/app.css') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="login-body">
    <div class="login-card">
        <div class="login-header">
            <div class="login-logo">▶</div>
            <h1 class="login-title">Xclusive Media</h1>
            <p class="login-subtitle">Sign in to continue</p>
        </div>

        <?php if ($flashError): ?>
            <div class="login-error"><?= htmlspecialchars($flashError) ?></div>
        <?php endif; ?>

        <form method="post" action="login.php" autocomplete="off">
            <div class="login-field">
                <label class="login-label" for="username">Username</label>
                <input class="login-input" type="text" id="username" name="username"
                       placeholder="Enter your username" autocomplete="username"
                       autocapitalize="off" spellcheck="false" required>
            </div>

            <div class="login-field">
                <label class="login-label" for="password">Password</label>
                <input class="login-input" type="password" id="password" name="password"
                       placeholder="Enter your password" autocomplete="current-password" required>
            </div>

            <button class="login-submit" type="submit">Sign In</button>
        </form>

        <div class="login-footer">Xclusive Media Player</div>
    </div>
</body>
</html>
