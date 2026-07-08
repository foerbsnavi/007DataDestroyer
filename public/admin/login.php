<?php
/**
 * 007DataDestroyer — Admin-Login
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';

// Ohne Setup zuerst zum Wizard.
if (Config::needsSetup()) {
    header('Location: index.php');
    exit;
}

Auth::startSession();
header('Cache-Control: no-store, private');
if (Auth::isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::checkCsrf($_POST['csrf'] ?? null)) {
        $error = 'Sicherheits-Token abgelaufen. Bitte erneut versuchen.';
    } elseif (Auth::tooManyAttempts('login')) {
        $error = 'Zu viele Fehlversuche. Bitte in ' . human_duration(Auth::lockRemaining('login')) . ' erneut versuchen.';
    } elseif (Auth::attemptLogin((string) ($_POST['password'] ?? ''))) {
        header('Location: index.php');
        exit;
    } else {
        usleep(700000); // Verzögerung erschwert automatisiertes Durchprobieren
        $error = 'Falsches Passwort.';
    }
}

$csrf = Auth::csrfToken();
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Login — 007DataDestroyer</title>
<link rel="stylesheet" href="../assets/css/style.css?v=<?= e(APP_VERSION) ?>">
</head>
<body class="page-admin">
<main class="admin-narrow">
    <p class="brand" aria-hidden="true">007<span>DataDestroyer</span></p>
    <h1>Backend-Login</h1>

    <?php if ($error !== ''): ?>
        <p class="flash flash-err" role="alert"><?= e($error) ?></p>
    <?php endif; ?>

    <form method="post" class="stack" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label for="password">Passwort</label>
        <input type="password" id="password" name="password" autocomplete="current-password" required autofocus>
        <button type="submit" class="btn btn-primary">Anmelden</button>
    </form>
</main>
</body>
</html>
