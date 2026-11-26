<?php
declare(strict_types=1);
require __DIR__ . '/family/lib.php';

$action = $_GET['action'] ?? '';

if ($action === 'put') {
    fam_start('POST');
    $ttl = (int) ($_GET['ttl'] ?? 86400);
    if (!in_array($ttl, [3600, 86400, 604800], true)) fam_fail(400, 'bad_ttl', 'Choose how long the link should last.');
    $body = fam_body(FAM_MAX_BLOB);
    fam_sealed($body, 1);
    fam_limit('send', fam_env('SENDKEY_PER_IP_HOUR', 20), fam_env('SENDKEY_GLOBAL_HOUR', 500));
    fam_json(200, ['ok' => true] + fam_store('send', '', $body, $ttl));
}

fam_start('GET');
$id = fam_hex($_GET['id'] ?? '', 32);

if ($action === 'head') {
    $row = fam_db()->prepare("SELECT id FROM blobs WHERE id = ? AND kind = 'send' AND taken = 0 AND expires_at > ?");
    $row->execute([$id, time()]);
    if (!$row->fetch()) fam_fail(404, 'gone', 'This link was already used or has expired.');
    header('Content-Type: application/octet-stream');
    echo file_get_contents(fam_dir() . "/blobs/$id", false, null, 0, 700);
    exit;
}

if ($action === 'get') {
    $claim = fam_db()->prepare("UPDATE blobs SET taken = 1 WHERE id = ? AND kind = 'send' AND taken = 0 AND expires_at > ?");
    $claim->execute([$id, time()]);
    if ($claim->rowCount() !== 1) fam_fail(404, 'gone', 'This link was already used or has expired.');
    register_shutdown_function(function () use ($id) {
        @unlink(fam_dir() . "/blobs/$id");
        fam_db()->prepare('DELETE FROM blobs WHERE id = ?')->execute([$id]);
    });
    fam_send_file($id);
}

fam_fail(400, 'bad_action', 'Unknown request.');
