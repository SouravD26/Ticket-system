<?php
/**
 * Global configuration.
 * XAMPP defaults below. On cPanel just change DB_* and BASE_URL.
 */
define('APP_NAME', 'HelpDesk');
define('DB_HOST', 'localhost');
define('DB_NAME', 'ticket_db');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Folder the app lives in, relative to the domain root (auto-detected).
// XAMPP: http://localhost/ticket-system/  -> '/ticket-system'
// helpdesk.sanmarg.in (app at doc root)   -> ''
$__appDir  = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$__docRoot = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '');
$__base    = ($__docRoot !== '' && stripos($__appDir, $__docRoot) === 0)
    ? substr($__appDir, strlen($__docRoot)) : '';
define('BASE_URL', rtrim($__base, '/'));
unset($__appDir, $__docRoot, $__base);

// Signs the time-limited photo links the mobile API hands out. Keep it secret;
// changing it only invalidates links already issued.
define('APP_KEY', '94a019b908b541663bc3f89c373e94ca53153c46369b67035b8b9bf512233461');

define('UPLOAD_DIR', __DIR__ . '/../uploads');
define('MAX_UPLOAD', 5 * 1024 * 1024); // 5 MB

// India Standard Time: attendance punches are stamped with this clock.
date_default_timezone_set('Asia/Kolkata');

error_reporting(E_ALL);
ini_set('display_errors', '1'); // set to '0' on live hosting
