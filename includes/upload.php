<?php
require_once __DIR__ . '/functions.php';

const ALLOWED_UPLOAD_EXT = ['jpg','jpeg','png','gif','webp','pdf','doc','docx','xls','xlsx','txt','csv','zip','log'];

/**
 * Save a $_FILES['files'] multi-upload against a ticket (and optionally a reply).
 * Throws on the first file that is rejected; files already saved are kept.
 */
function store_uploads(int $ticketId, ?int $replyId, ?array $files): int
{
    if (!$files || !isset($files['name']) || !is_array($files['name'])) return 0;

    if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0775, true)) {
        throw new RuntimeException('Upload directory is not writable.');
    }

    $saved = 0;
    foreach ($files['name'] as $i => $name) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload failed for "' . $name . '".');
        }
        if ($files['size'][$i] > MAX_UPLOAD) {
            throw new RuntimeException('"' . $name . '" is larger than ' . (MAX_UPLOAD / 1048576) . ' MB.');
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ALLOWED_UPLOAD_EXT, true)) {
            throw new RuntimeException('File type ".' . $ext . '" is not allowed.');
        }

        $stored = bin2hex(random_bytes(16)) . '.' . $ext;
        if (!move_uploaded_file($files['tmp_name'][$i], UPLOAD_DIR . '/' . $stored)) {
            throw new RuntimeException('Could not store "' . $name . '".');
        }

        $mime = function_exists('finfo_open')
            ? (finfo_file($f = finfo_open(FILEINFO_MIME_TYPE), UPLOAD_DIR . '/' . $stored) ?: 'application/octet-stream')
            : ($files['type'][$i] ?: 'application/octet-stream');

        q('INSERT INTO attachments (ticket_id, reply_id, original_name, stored_name, mime, size_bytes) VALUES (?,?,?,?,?,?)',
          [$ticketId, $replyId, mb_substr($name, 0, 250), $stored, $mime, (int)$files['size'][$i]]);
        $saved++;
    }
    return $saved;
}

function human_size(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < 3) { $bytes /= 1024; $i++; }
    return round($bytes, $i ? 1 : 0) . ' ' . $units[$i];
}
