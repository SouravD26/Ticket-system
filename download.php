<?php
require_once __DIR__ . '/includes/functions.php';
require_login();

$a = q('SELECT * FROM attachments WHERE id = ?', [(int) get_('id')])->fetch();
if (!$a) { http_response_code(404); die('404 — File not found.'); }

// Permission is inherited from the ticket.
if (!find_ticket((int) $a['ticket_id'])) { http_response_code(403); die('403 — Not your file.'); }

$path = UPLOAD_DIR . '/' . basename($a['stored_name']);
if (!is_file($path)) { http_response_code(404); die('404 — File missing on disk.'); }

header('Content-Type: ' . $a['mime']);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $a['original_name']) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
