<?php
/**
 * Daily Telegram messages, run on the server by a Hostinger cron job:
 *   php /path/to/api/cron.php
 * Sends repairs overdue, warranties expiring and the morning summary once a day after the time in Settings.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
$_GET['a'] = 'cron';
$_SERVER['REQUEST_METHOD'] = 'GET';
define('CRON_CLI', true);
require __DIR__ . '/index.php';
