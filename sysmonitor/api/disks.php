<?php
/**
 * Disk health for the Monitor (administrators only).
 *   GET   -> every disk with its role and SMART verdict (cached 10 min by the disk service)
 *   POST  -> ask every disk again now (at most once a minute), CSRF protected
 * Health is read with "smartctl -n standby": a sleeping disk is never woken up.
 */
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';
sm_require_login();
session_write_close();

function sm_disk_call(array $req, int $timeout): array {
    $sock = defined('SM_DISK_SOCKET') ? SM_DISK_SOCKET : '/run/blackvault-disk.sock';
    if (!file_exists($sock)) throw new RuntimeException('Disk health is not installed yet. Run the installer again.');
    $fp = @stream_socket_client('unix://' . $sock, $errno, $err, 5);
    if (!$fp) throw new RuntimeException('The disk service did not answer.');
    stream_set_timeout($fp, $timeout);
    fwrite($fp, json_encode($req) . "\n");
    @stream_socket_shutdown($fp, STREAM_SHUT_WR);
    $res = json_decode((string)stream_get_contents($fp, 4 * 1048576), true);
    fclose($fp);
    if (!is_array($res) || empty($res['ok'])) throw new RuntimeException((string)($res['error'] ?? 'The disk service gave no answer.'));
    return $res;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!bv_csrf_ok($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) sm_json(['error' => 'Invalid CSRF token'], 403);
        sm_disk_call(['action' => 'smart', 'refresh' => true], 240);
    }
    $l = sm_disk_call(['action' => 'list'], 120);
} catch (Throwable $e) {
    sm_json(['available' => false, 'error' => $e->getMessage(), 'disks' => []]);
}

// Only what the Monitor shows (no partition tables, no ids)
$out = [];
foreach ($l['disks'] ?? [] as $d) {
    $h = $d['health'] ?? [];
    $out[] = [
        'model' => (string)($d['model'] ?? 'Disk'),
        'size' => (int)($d['size'] ?? 0),
        'transport' => $d['transport'] ?? null,
        'rotational' => (bool)($d['rotational'] ?? false),
        'role' => (string)($d['role'] ?? ''),
        'holds_drives' => !empty($d['holds_drives']) || ($d['role'] ?? '') === 'storage',
        'status' => (string)($h['status'] ?? 'unknown'),
        'temp' => $h['temp'] ?? null,
        'hours' => $h['hours'] ?? null,
        'wear' => $h['wear'] ?? null,
        'asleep' => (bool)($h['asleep'] ?? false),
        'notes' => array_slice((array)($h['notes'] ?? []), 0, 3),
        'checked' => $h['checked'] ?? null,
    ];
}
foreach ($l['missing'] ?? [] as $m) {
    $out[] = ['model' => (string)($m['model'] ?? 'Disk'), 'size' => (int)($m['size'] ?? 0), 'transport' => null, 'rotational' => false,
              'role' => 'missing', 'holds_drives' => !empty($m['active']), 'status' => 'missing', 'temp' => null, 'hours' => null,
              'wear' => null, 'asleep' => false, 'notes' => [], 'checked' => null];
}
sm_json(['available' => true, 'smart_tool' => (bool)($l['smart_tool'] ?? false), 'disks' => $out]);
