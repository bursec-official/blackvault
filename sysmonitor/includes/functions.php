<?php
/**
 * Monitor — reading the system's numbers
 *
 * Everything is read straight from /proc and /sys (no shell_exec).
 *
 * "Per second" values (CPU, network, disk, processes) come from TWO readings
 * inside the same request, ~400 ms apart, so several open tabs (e.g. phone +
 * computer) never disturb each other's numbers.
 */

const SM_SAMPLE_US = 400000; // 0,4 s

function sm_read(string $path): string {
    $h = @fopen($path, 'r');
    if ($h === false) return '';
    $out = '';
    while (!feof($h)) {
        $chunk = fread($h, 65536);
        if ($chunk === false) break;
        $out .= $chunk;
    }
    fclose($h);
    return $out;
}

/* ---------------- CPU ---------------- */
function sm_cpu_times(): array {
    $cpus = [];
    foreach (explode("\n", sm_read('/proc/stat')) as $line) {
        if (preg_match('/^(cpu\d*)\s+(.*)$/', $line, $m)) {
            $p = array_map('intval', preg_split('/\s+/', trim($m[2])));
            // user nice system idle iowait irq softirq steal (guest is already included in user)
            $p = array_slice($p, 0, 8);
            $idle = ($p[3] ?? 0) + ($p[4] ?? 0);
            $cpus[$m[1]] = ['idle' => $idle, 'total' => array_sum($p), 'iowait' => $p[4] ?? 0];
        }
    }
    return $cpus;
}

function sm_cpu_model(): string {
    if (preg_match('/^model name\s*:\s*(.+)$/m', sm_read('/proc/cpuinfo'), $m)) {
        return trim(preg_replace('/\s+/', ' ', str_replace(['(R)', '(TM)', 'CPU '], '', $m[1])));
    }
    return php_uname('m');
}

/* ---------------- Red ---------------- */
function sm_net_bytes(): array {
    $out = [];
    foreach (explode("\n", sm_read('/proc/net/dev')) as $line) {
        if (strpos($line, ':') === false) continue;
        [$iface, $data] = explode(':', $line, 2);
        $iface = trim($iface);
        // Skip virtual interfaces that would count the traffic twice
        if ($iface === 'lo' || preg_match('/^(veth|docker|br-|virbr|vnet|ifb)/', $iface)) continue;
        $f = preg_split('/\s+/', trim($data));
        $out[$iface] = ['rx' => (int)($f[0] ?? 0), 'tx' => (int)($f[8] ?? 0)];
    }
    return $out;
}

/* ---------------- Disco (E/S) ---------------- */
function sm_disk_devices(): array {
    $devs = [];
    foreach ((@scandir('/sys/block') ?: []) as $d) {
        if ($d[0] === '.' || preg_match('/^(loop|ram|zram|sr|fd|dm-|md)/', $d)) continue;
        $devs[] = $d;
    }
    return $devs;
}

function sm_disk_io(array $devs): array {
    $read = 0; $write = 0;
    foreach (explode("\n", sm_read('/proc/diskstats')) as $line) {
        $f = preg_split('/\s+/', trim($line));
        if (count($f) < 10 || !in_array($f[2], $devs, true)) continue;
        $read += (int)$f[5] * 512;   // sectors read
        $write += (int)$f[9] * 512;  // sectores escritos
    }
    return ['read' => $read, 'write' => $write];
}

/* ---------------- Procesos ---------------- */
function sm_proc_snapshot(): array {
    $out = [];
    foreach ((@scandir('/proc') ?: []) as $pid) {
        if (!ctype_digit($pid)) continue;
        $raw = @file_get_contents("/proc/$pid/stat");
        if (!$raw) continue;
        $l = strpos($raw, '(');
        $r = strrpos($raw, ')');
        if ($l === false || $r === false) continue;
        $comm = substr($raw, $l + 1, $r - $l - 1);
        $f = explode(' ', substr($raw, $r + 2));
        // after ")": 0=state ... 11=utime 12=stime ... 21=rss (pages)
        $out[(int)$pid] = [
            'name' => $comm,
            'state' => $f[0] ?? '?',
            'ticks' => (int)($f[11] ?? 0) + (int)($f[12] ?? 0),
            'rss' => (int)($f[21] ?? 0) * 4096,
        ];
    }
    return $out;
}

function sm_proc_user(int $pid): string {
    if (preg_match('/^Uid:\s+(\d+)/m', sm_read("/proc/$pid/status"), $m)) {
        $uid = (int)$m[1];
        if (function_exists('posix_getpwuid')) {
            $pw = @posix_getpwuid($uid);
            if ($pw) return $pw['name'];
        }
        return (string)$uid;
    }
    return '?';
}

/* ---------------- Memoria ---------------- */
function sm_mem(): array {
    $d = [];
    foreach (explode("\n", sm_read('/proc/meminfo')) as $line) {
        if (preg_match('/^(\w+):\s+(\d+)/', $line, $m)) $d[$m[1]] = (int)$m[2] * 1024;
    }
    $total = $d['MemTotal'] ?? 0;
    $avail = $d['MemAvailable'] ?? ($d['MemFree'] ?? 0);
    $cache = ($d['Cached'] ?? 0) + ($d['Buffers'] ?? 0) + ($d['SReclaimable'] ?? 0);
    $swapT = $d['SwapTotal'] ?? 0;
    $swapU = $swapT - ($d['SwapFree'] ?? 0);
    return [
        'total' => $total,
        'used' => $total - $avail,
        'cache' => $cache,
        'available' => $avail,
        'percent' => $total > 0 ? round(($total - $avail) / $total * 100, 1) : 0,
        'swap_total' => $swapT,
        'swap_used' => $swapU,
        'swap_percent' => $swapT > 0 ? round($swapU / $swapT * 100, 1) : 0,
    ];
}

/* ---------------- Temperature ----------------
 * 1) /sys/class/hwmon (coretemp on Intel, k10temp on AMD, applesmc on Apple hardware...)
 * 2) /sys/class/thermal as a fallback
 */
function sm_temps(): array {
    $sensors = [];
    foreach (glob('/sys/class/hwmon/hwmon*') ?: [] as $hw) {
        $chip = trim(sm_read("$hw/name")) ?: basename($hw);
        foreach (glob("$hw/temp*_input") ?: [] as $in) {
            $v = (float)trim(sm_read($in)) / 1000;
            if ($v <= 0 || $v >= 125) continue;
            $label = trim(sm_read(str_replace('_input', '_label', $in))) ?: basename($in, '_input');
            $sensors[] = ['chip' => $chip, 'label' => $label, 'value' => round($v, 1)];
        }
    }
    if (!$sensors) {
        foreach (glob('/sys/class/thermal/thermal_zone*') ?: [] as $z) {
            $v = (float)trim(sm_read("$z/temp")) / 1000;
            if ($v <= 0 || $v >= 125) continue;
            $sensors[] = ['chip' => trim(sm_read("$z/type")) ?: basename($z), 'label' => basename($z), 'value' => round($v, 1)];
        }
    }
    if (!$sensors) return ['main' => null, 'max' => null, 'source' => null, 'sensors' => []];

    // Main value: the CPU "Package" if there is one; otherwise the hottest CPU sensor; otherwise the hottest one.
    $main = null; $source = null;
    foreach ($sensors as $s) {
        if (in_array($s['chip'], ['coretemp', 'k10temp', 'zenpower', 'cpu_thermal'], true)
            && preg_match('/package|tctl|tdie/i', $s['label'])) { $main = $s['value']; $source = $s['chip'] . ' · ' . $s['label']; break; }
    }
    if ($main === null) {
        foreach ($sensors as $s) {
            if (in_array($s['chip'], ['coretemp', 'k10temp', 'zenpower', 'cpu_thermal', 'x86_pkg_temp'], true) && $s['value'] > ($main ?? -1)) {
                $main = $s['value']; $source = $s['chip'] . ' · ' . $s['label'];
            }
        }
    }
    $max = max(array_column($sensors, 'value'));
    if ($main === null) {
        foreach ($sensors as $s) if ($s['value'] === $max) { $source = $s['chip'] . ' · ' . $s['label']; break; }
        $main = $max;
    }
    usort($sensors, fn($a, $b) => $b['value'] <=> $a['value']);
    return ['main' => $main, 'max' => $max, 'source' => $source, 'sensors' => array_slice($sensors, 0, 8)];
}

/* ---------------- Otros ---------------- */
function sm_uptime(): float {
    $raw = trim(sm_read('/proc/uptime'));
    return $raw === '' ? 0 : (float)explode(' ', $raw)[0];
}

function sm_os_name(): string {
    $os = @parse_ini_file('/etc/os-release');
    return $os['PRETTY_NAME'] ?? php_uname('s');
}

function sm_disk_usage(string $path): array {
    $total = @disk_total_space($path) ?: 0;
    $free = @disk_free_space($path) ?: 0;
    return [
        'total' => $total,
        'used' => $total - $free,
        'free' => $free,
        'percent' => $total > 0 ? round(($total - $free) / $total * 100, 1) : 0,
    ];
}

/**
 * Takes every reading. Speeds come from two readings
 * SM_SAMPLE_US microseconds apart.
 */
function sm_collect_stats(): array {
    $devs = sm_disk_devices();

    $t0 = microtime(true);
    $cpu0 = sm_cpu_times();
    $net0 = sm_net_bytes();
    $io0 = sm_disk_io($devs);
    $pr0 = sm_proc_snapshot();

    usleep(SM_SAMPLE_US);

    $t1 = microtime(true);
    $cpu1 = sm_cpu_times();
    $net1 = sm_net_bytes();
    $io1 = sm_disk_io($devs);
    $pr1 = sm_proc_snapshot();
    $dt = max($t1 - $t0, 0.001);

    // CPU overall, per core and iowait
    $cpuPct = 0; $iowait = 0; $cores = [];
    foreach ($cpu1 as $k => $v) {
        if (!isset($cpu0[$k])) continue;
        $dTotal = $v['total'] - $cpu0[$k]['total'];
        $dIdle = $v['idle'] - $cpu0[$k]['idle'];
        $pct = $dTotal > 0 ? max(0, min(100, round((1 - $dIdle / $dTotal) * 100, 1))) : 0;
        if ($k === 'cpu') {
            $cpuPct = $pct;
            $iowait = $dTotal > 0 ? round(($v['iowait'] - $cpu0[$k]['iowait']) / $dTotal * 100, 1) : 0;
        } else {
            $cores[(int)substr($k, 3)] = $pct;
        }
    }
    ksort($cores);
    $nCores = max(1, count($cores));
    $dCpuTotal = ($cpu1['cpu']['total'] ?? 0) - ($cpu0['cpu']['total'] ?? 0);

    // Red
    $rx = 0; $tx = 0; $ifaces = [];
    foreach ($net1 as $if => $v) {
        if (!isset($net0[$if])) continue;
        $r = max(0, ($v['rx'] - $net0[$if]['rx']) / $dt);
        $t = max(0, ($v['tx'] - $net0[$if]['tx']) / $dt);
        $rx += $r; $tx += $t;
        $ifaces[] = ['name' => $if, 'rx' => $r, 'tx' => $t, 'rx_total' => $v['rx'], 'tx_total' => $v['tx']];
    }
    usort($ifaces, fn($a, $b) => ($b['rx_total'] + $b['tx_total']) <=> ($a['rx_total'] + $a['tx_total']));

    // Processes using the most CPU (htop style: 100% = one whole core)
    $top = [];
    if ($dCpuTotal > 0) {
        foreach ($pr1 as $pid => $p) {
            if (!isset($pr0[$pid])) continue;
            $d = $p['ticks'] - $pr0[$pid]['ticks'];
            $top[] = ['pid' => $pid, 'name' => $p['name'], 'state' => $p['state'], 'mem' => $p['rss'],
                      'cpu' => round(max(0, $d) / $dCpuTotal * $nCores * 100, 1)];
        }
    }
    usort($top, fn($a, $b) => [$b['cpu'], $b['mem']] <=> [$a['cpu'], $a['mem']]);
    $top = array_slice($top, 0, 8);
    foreach ($top as &$p) $p['user'] = sm_proc_user($p['pid']);
    unset($p);

    $running = 0;
    foreach ($pr1 as $p) if ($p['state'] === 'R') $running++;

    $la = function_exists('sys_getloadavg') ? sys_getloadavg() : [0, 0, 0];

    return [
        'time' => time(),
        'hostname' => gethostname() ?: 'servidor',
        'ip' => $_SERVER['SERVER_ADDR'] ?? '',
        'os' => sm_os_name(),
        'kernel' => php_uname('r'),
        'arch' => php_uname('m'),
        'uptime' => sm_uptime(),
        'cpu' => [
            'model' => sm_cpu_model(),
            'percent' => $cpuPct,
            'iowait' => $iowait,
            'cores' => array_values($cores),
            'load' => array_map(fn($x) => round($x, 2), $la),
        ],
        'mem' => sm_mem(),
        'disk' => sm_disk_usage('/') + [
            'read_rate' => max(0, ($io1['read'] - $io0['read']) / $dt),
            'write_rate' => max(0, ($io1['write'] - $io0['write']) / $dt),
            'devices' => $devs,
        ],
        'net' => [
            'rx_rate' => $rx,
            'tx_rate' => $tx,
            'ifaces' => array_slice($ifaces, 0, 4),
        ],
        'temp' => sm_temps(),
        'procs' => ['total' => count($pr1), 'running' => $running, 'top' => $top],
    ];
}
