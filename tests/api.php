<?php
// Run with: php tests/api.php   (needs pdo_sqlite and curl)
declare(strict_types=1);

$root = dirname(__DIR__);
$work = sys_get_temp_dir() . '/family-test-' . bin2hex(random_bytes(4));
mkdir("$work/data", 0700, true);
$passed = $failed = 0;

function check(string $name, bool $ok, string $info = ''): void {
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : "  ($info)") . "\n";
}

$port = 18100 + random_int(0, 800);
$env = [
    'FAMILY_DATA_DIR' => "$work/data", 'FAMILY_MIN_FREE_BYTES' => '0', 'SENDKEY_PER_IP_HOUR' => '4',
] + getenv();
$server = proc_open(
    [PHP_BINARY, '-d', 'post_max_size=40M', '-d', 'upload_max_filesize=40M', '-S', "127.0.0.1:$port", '-t', $root, "$root/router.php"],
    [0 => ['pipe', 'r'], 1 => ['file', "$work/server.log", 'a'], 2 => ['file', "$work/server.log", 'a']], $pipes, $root, $env
);
$base = "http://127.0.0.1:$port";
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);

function req(string $method, string $path, ?string $body = null): array {
    global $base;
    $c = curl_init($base . $path);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_PROXY => '', CURLOPT_CUSTOMREQUEST => $method]);
    if ($body !== null) curl_setopt_array($c, [CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => ['Content-Type: application/octet-stream']]);
    $raw = (string) curl_exec($c);
    $size = curl_getinfo($c, CURLINFO_HEADER_SIZE);
    $out = ['status' => curl_getinfo($c, CURLINFO_RESPONSE_CODE), 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
    curl_close($c);
    return $out;
}

function sealed(int $kdf, int $size = 2000): string {
    return 'SEAL' . chr(1) . chr($kdf) . random_bytes($size - 6);
}

function json(array $r): array {
    return json_decode($r['body'], true) ?: [];
}

try {
    $good = sealed(1);
    check('send: something that is not a Sealkey file is refused', req('POST', '/api.php?action=put', 'hello world, not sealed')['status'] === 400);
    check('send: a file locked for a public key is refused here', req('POST', '/api.php?action=put', sealed(2))['status'] === 400);
    check('send: a bad lifetime is refused', req('POST', '/api.php?action=put&ttl=5', $good)['status'] === 400);
    check('send: a file over 25 MiB is refused', req('POST', '/api.php?action=put', 'SEAL' . chr(1) . chr(1) . str_repeat('x', 26214400))['status'] === 413);
    check('send: GET on put is refused', req('GET', '/api.php?action=put')['status'] === 405);
    $put = req('POST', '/api.php?action=put&ttl=3600', $good);
    $id = json($put)['id'] ?? '';
    check('send: a locked file uploads and gets an address', $put['status'] === 200 && preg_match('/^[0-9a-f]{32}$/', $id) === 1);
    $head1 = req('GET', "/api.php?action=head&id=$id");
    $head2 = req('GET', "/api.php?action=head&id=$id");
    check('send: the start of the file can be read twice without using the link', $head1['status'] === 200 && $head1['body'] === substr($good, 0, 700) && $head2['status'] === 200);
    $get = req('GET', "/api.php?action=get&id=$id");
    check('send: the file downloads unchanged', $get['status'] === 200 && $get['body'] === $good);
    check('send: it works only once', req('GET', "/api.php?action=get&id=$id")['status'] === 404 && req('GET', "/api.php?action=head&id=$id")['status'] === 404);
    check('send: the stored file is gone from the disk', glob("$work/data/blobs/*") === []);
    check('send: a made-up address is refused', req('GET', '/api.php?action=get&id=zzz')['status'] === 400 && req('GET', '/api.php?action=get&id=' . str_repeat('a', 32))['status'] === 404);
    $second = json(req('POST', '/api.php?action=put&ttl=3600', $good))['id'];
    $db = new PDO("sqlite:$work/data/family.db");
    $db->exec('UPDATE blobs SET expires_at = 1');
    check('send: an expired link no longer works', req('GET', "/api.php?action=get&id=$second")['status'] === 404);
    check('send: expired files are removed from the disk', glob("$work/data/blobs/*") === [] || (req('GET', '/api.php?action=get&id=' . str_repeat('a', 32)) && glob("$work/data/blobs/*") === []));
    req('POST', '/api.php?action=put', $good);
    req('POST', '/api.php?action=put', $good);
    check('send: too many uploads from one place are slowed down', req('POST', '/api.php?action=put', $good)['status'] === 429);

    $page = req('GET', '/api.php?action=get&id=zzz');
    check('headers: the API sets safe headers', stripos($page['headers'], 'nosniff') !== false && stripos($page['headers'], 'no-store') !== false);
    check('data: the data folder is not inside the web root', !str_starts_with(realpath("$work/data"), realpath($root)));
} finally {
    proc_terminate($server);
    proc_close($server);
    exec('rm -rf ' . escapeshellarg($work));
}

echo "$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
