<?php
/**
 * 007DataDestroyer — Einstieg
 * Leitet auf die öffentliche Recovery-Seite weiter. Das Backend liegt unter /admin/.
 */

declare(strict_types=1);

header('X-Robots-Tag: noindex, nofollow');
header('Location: recover.php', true, 301);
exit;
