<?php
/**
 * 007DataDestroyer — Admin-Logout
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';

Auth::logout();
header('X-Robots-Tag: noindex, nofollow');
header('Location: login.php', true, 302);
exit;
