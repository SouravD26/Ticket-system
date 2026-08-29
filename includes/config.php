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

// Folder the app lives in, relative to the domain root.
// XAMPP: http://localhost/ticket-system/  -> '/ticket-system'
// cPanel document root                     -> ''
define('BASE_URL', '/ticket-system');

define('UPLOAD_DIR', __DIR__ . '/../uploads');
define('MAX_UPLOAD', 5 * 1024 * 1024); // 5 MB

date_default_timezone_set('Asia/Kathmandu');

error_reporting(E_ALL);
ini_set('display_errors', '1'); // set to '0' on live hosting
