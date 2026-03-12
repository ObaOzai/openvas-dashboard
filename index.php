<?php
/**
 * OpenVAS / GVM Dashboard
 * JD Correa-Landreau AstroPema AI LLC | mail.astropema.ai
 * Communicates with gvmd via GMP XML protocol over Unix socket
 */

define('GVM_SOCKET', '/run/gvmd/gvmd.sock');
define('GVM_USER',   getenv('GVM_USER')   ?: 'admin');
define('GVM_PASS',   getenv('GVM_PASS')   ?: '');
define('CACHE_FILE', '/tmp/gvm_cache.json');
define('CACHE_TTL',  120); // seconds

// ─── GMP Client ────────────────────────────────────────────────────────────

class GmpClient {
    private $socket;
    private $authenticated = false;

    public function connect(): bool {
        if (!file_exists(GVM_SOCKET)) return false;
        $this->socket = @stream_socket_client('unix://' . GVM_SOCKET, $errno, $errstr, 10);
        if (!$this->socket) return false;
        stream_set_timeout($this->socket, 15);
        // read banner
        $this->read();
        return true;
    }

    public function authenticate(string $user, string $pass): bool {
        $xml = "<authenticate><credentials><username>{$user}</username><password>{$pass}</password></credentials></authenticate>";
        $resp = $this->send($xml);
        if ($resp && strpos($resp, 'status="200"') !== false) {
            $this->authenticated = true;
            return true;
        }
        return false;
    }

    public function send(string $xml): ?string {
        if (!$this->socket) return null;
        fwrite($this->socket, $xml);
        return $this->read();
    }

    private function read(): string {
        $buf = '';
        $start = microtime(true);
        while (!feof($this->socket)) {
            $chunk = fread($this->socket, 65536);
            if ($chunk === false) break;
            $buf .= $chunk;
            // stop when we have a complete XML response
            if ($buf && $this->isComplete($buf)) break;
            if (microtime(true) - $start > 15) break;
            if (!strlen($chunk)) usleep(10000);
        }
        return $buf;
    }

    private function isComplete(string $xml): bool {
        // Heuristic: balanced root element
        libxml_use_internal_errors(true);
        $doc = @simplexml_load_string($xml);
        return $doc !== false;
    }

    public function close(): void {
        if ($this->socket) fclose($this->socket);
    }
}

// ─── Data Collection ────────────────────────────────────────────────────────

function fetch_gvm_data(): array {
    // Return cached if fresh
    if (file_exists(CACHE_FILE)) {
        $cached = json_decode(file_get_contents(CACHE_FILE), true);
        if ($cached && (time() - ($cached['ts'] ?? 0)) < CACHE_TTL) {
            return $cached;
        }
    }

    $data = [
        'ts'            => time(),
        'connected'     => false,
        'error'         => null,
        'tasks'         => [],
        'results_by_severity' => ['Critical'=>0,'High'=>0,'Medium'=>0,'Low'=>0,'Log'=>0],
        'top_hosts'     => [],
        'last_scan'     => null,
        'total_results' => 0,
        'nvt_count'     => 0,
    ];

    $gmp = new GmpClient();
    if (!$gmp->connect()) {
        $data['error'] = 'Cannot connect to gvmd socket. Check permissions and container mount.';
        return $data;
    }

    if (!$gmp->authenticate(GVM_USER, GVM_PASS)) {
        $gmp->close();
        $data['error'] = 'Authentication failed. Check GVM_USER / GVM_PASS environment variables.';
        return $data;
    }

    $data['connected'] = true;

    // ── Tasks ──
    $resp = $gmp->send('<get_tasks/>');
    if ($resp) {
        libxml_use_internal_errors(true);
        $xml = @simplexml_load_string($resp);
        if ($xml) {
            foreach ($xml->task as $task) {
                $last_report = null;
                $last_run    = null;
                if (isset($task->last_report->report)) {
                    $lr = $task->last_report->report;
                    $last_report = (string)($lr['id'] ?? '');
                    $last_run    = (string)($lr->timestamp ?? '');
                }
                $data['tasks'][] = [
                    'id'          => (string)$task['id'],
                    'name'        => (string)$task->name,
                    'status'      => (string)$task->status,
                    'progress'    => (int)$task->progress,
                    'last_report' => $last_report,
                    'last_run'    => $last_run,
                    'result_count'=> (int)($task->result_count->full ?? 0),
                ];
                if ($last_run) {
                    if (!$data['last_scan'] || $last_run > $data['last_scan']) {
                        $data['last_scan'] = $last_run;
                    }
                }
            }
        }
    }

    // ── Results summary (severity counts) ──
    $resp = $gmp->send('<get_results filter="rows=500 sort-reverse=date"/>');
    if ($resp) {
        libxml_use_internal_errors(true);
        $xml = @simplexml_load_string($resp);
        if ($xml) {
            $host_counts = [];
            foreach ($xml->result as $result) {
                $sev = (float)($result->severity ?? 0);
                $host = trim((string)($result->host ?? ''));
                if ($sev >= 9.0)      $data['results_by_severity']['Critical']++;
                elseif ($sev >= 7.0)  $data['results_by_severity']['High']++;
                elseif ($sev >= 4.0)  $data['results_by_severity']['Medium']++;
                elseif ($sev > 0.0)   $data['results_by_severity']['Low']++;
                else                  $data['results_by_severity']['Log']++;
                $data['total_results']++;
                if ($host) {
                    $host_counts[$host] = ($host_counts[$host] ?? 0) + 1;
                }
            }
            arsort($host_counts);
            $data['top_hosts'] = array_slice($host_counts, 0, 8, true);
        }
    }

    // ── NVT feed count ──
    $resp = $gmp->send('<get_nvts filter="rows=1"/>');
    if ($resp) {
        libxml_use_internal_errors(true);
        $xml = @simplexml_load_string($resp);
        if ($xml) {
            $data['nvt_count'] = (int)($xml['nvts_count'] ?? 0);
        }
    }

    $gmp->close();

    file_put_contents(CACHE_FILE, json_encode($data));
    return $data;
}

// ─── Run ────────────────────────────────────────────────────────────────────

$d = fetch_gvm_data();
$refresh_in = CACHE_TTL - (time() - ($d['ts'] ?? time()));

function severity_color(string $sev): string {
    return match($sev) {
        'Critical' => '#ff3b3b',
        'High'     => '#ff7c2a',
        'Medium'   => '#f5c518',
        'Low'      => '#4fc3f7',
        'Log'      => '#78909c',
        default    => '#aaa',
    };
}

function format_ts(?string $ts): string {
    if (!$ts) return '—';
    $dt = DateTime::createFromFormat('Y-m-d\TH:i:sP', $ts)
       ?: DateTime::createFromFormat('Y-m-d H:i:s', $ts);
    return $dt ? $dt->format('Y-m-d H:i') : $ts;
}

function task_status_badge(string $status): string {
    $color = match(strtolower($status)) {
        'done'      => '#2e7d52',
        'running'   => '#1565c0',
        'requested' => '#7b1fa2',
        'stopped'   => '#616161',
        'new'       => '#4a4a4a',
        default     => '#555',
    };
    return "<span style='background:{$color};color:#fff;padding:2px 8px;border-radius:3px;font-size:11px;letter-spacing:.5px;text-transform:uppercase'>{$status}</span>";
}

$total_actionable = $d['results_by_severity']['Critical']
                  + $d['results_by_severity']['High']
                  + $d['results_by_severity']['Medium'];

$risk_level = 'LOW';
$risk_color = '#2e7d52';
if ($d['results_by_severity']['Critical'] > 0) { $risk_level = 'CRITICAL'; $risk_color = '#ff3b3b'; }
elseif ($d['results_by_severity']['High'] > 0)  { $risk_level = 'HIGH';     $risk_color = '#ff7c2a'; }
elseif ($d['results_by_severity']['Medium'] > 5) { $risk_level = 'MEDIUM';  $risk_color = '#f5c518'; }

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="refresh" content="<?= $refresh_in > 10 ? $refresh_in : 120 ?>">
<title>OpenVAS — AstroPema Security</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Barlow:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --bg:        #0a0c10;
    --panel:     #10141c;
    --border:    #1e2535;
    --text:      #c8d0e0;
    --muted:     #4a5570;
    --accent:    #00d4ff;
    --accent2:   #0066cc;
    --mono:      'Share Tech Mono', monospace;
    --sans:      'Barlow', sans-serif;
    --risk:      <?= $risk_color ?>;
  }

  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    background: var(--bg);
    color: var(--text);
    font-family: var(--sans);
    font-weight: 300;
    min-height: 100vh;
    padding: 0;
  }

  /* Scanline overlay */
  body::before {
    content: '';
    position: fixed; inset: 0;
    background: repeating-linear-gradient(0deg, transparent, transparent 2px, rgba(0,212,255,.015) 2px, rgba(0,212,255,.015) 4px);
    pointer-events: none;
    z-index: 1000;
  }

  /* Header */
  header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 18px 32px;
    border-bottom: 1px solid var(--border);
    background: linear-gradient(90deg, #0a0c10 0%, #0d1220 100%);
    position: sticky; top: 0; z-index: 50;
  }

  .logo {
    display: flex; align-items: center; gap: 14px;
  }

  .logo-mark {
    width: 36px; height: 36px;
    border: 2px solid var(--accent);
    border-radius: 4px;
    display: flex; align-items: center; justify-content: center;
    font-family: var(--mono);
    font-size: 13px;
    color: var(--accent);
    text-shadow: 0 0 8px var(--accent);
    box-shadow: 0 0 12px rgba(0,212,255,.15), inset 0 0 8px rgba(0,212,255,.05);
  }

  .logo-text {
    font-size: 15px;
    font-weight: 600;
    letter-spacing: 2px;
    text-transform: uppercase;
    color: #e0e8f0;
  }

  .logo-sub {
    font-size: 11px;
    color: var(--muted);
    letter-spacing: 1px;
    font-family: var(--mono);
  }

  .header-right {
    display: flex; align-items: center; gap: 24px;
    font-family: var(--mono);
    font-size: 11px;
    color: var(--muted);
  }

  .status-dot {
    display: inline-block;
    width: 7px; height: 7px;
    border-radius: 50%;
    background: <?= $d['connected'] ? '#00ff88' : '#ff3b3b' ?>;
    box-shadow: 0 0 6px <?= $d['connected'] ? '#00ff88' : '#ff3b3b' ?>;
    margin-right: 6px;
    animation: pulse 2s ease-in-out infinite;
  }

  @keyframes pulse {
    0%, 100% { opacity: 1; }
    50%       { opacity: .4; }
  }

  .risk-badge {
    padding: 4px 12px;
    border: 1px solid var(--risk);
    border-radius: 3px;
    color: var(--risk);
    font-weight: 700;
    font-size: 11px;
    letter-spacing: 2px;
    text-shadow: 0 0 8px var(--risk);
    box-shadow: 0 0 12px rgba(255,59,59,.1);
  }

  /* Layout */
  main {
    max-width: 1400px;
    margin: 0 auto;
    padding: 28px 32px;
  }

  /* Error banner */
  .error-banner {
    background: rgba(255,59,59,.08);
    border: 1px solid rgba(255,59,59,.3);
    border-radius: 4px;
    padding: 16px 20px;
    margin-bottom: 24px;
    font-family: var(--mono);
    font-size: 13px;
    color: #ff8080;
  }

  /* Stat cards row */
  .stat-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px;
    margin-bottom: 28px;
  }

  .stat-card {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 4px;
    padding: 20px 20px 16px;
    position: relative;
    overflow: hidden;
    transition: border-color .2s;
  }

  .stat-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 2px;
    background: var(--card-accent, var(--accent));
  }

  .stat-card:hover { border-color: var(--accent); }

  .stat-label {
    font-size: 10px;
    letter-spacing: 2px;
    text-transform: uppercase;
    color: var(--muted);
    font-family: var(--mono);
    margin-bottom: 10px;
  }

  .stat-value {
    font-size: 36px;
    font-weight: 700;
    line-height: 1;
    color: var(--card-accent, var(--accent));
    text-shadow: 0 0 20px var(--card-accent, rgba(0,212,255,.3));
    font-family: var(--mono);
  }

  .stat-sub {
    font-size: 11px;
    color: var(--muted);
    margin-top: 6px;
    font-family: var(--mono);
  }

  /* Two-column grid */
  .grid-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 28px;
  }

  @media (max-width: 900px) { .grid-2 { grid-template-columns: 1fr; } }

  /* Panel */
  .panel {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 4px;
    overflow: hidden;
  }

  .panel-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 20px;
    border-bottom: 1px solid var(--border);
    font-size: 10px;
    letter-spacing: 2px;
    text-transform: uppercase;
    color: var(--muted);
    font-family: var(--mono);
  }

  .panel-header .count {
    color: var(--accent);
    font-weight: 700;
  }

  /* Severity bars */
  .sev-list { padding: 16px 20px; }

  .sev-row {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 14px;
  }

  .sev-label {
    font-family: var(--mono);
    font-size: 11px;
    width: 65px;
    color: var(--text);
    flex-shrink: 0;
  }

  .sev-bar-wrap {
    flex: 1;
    height: 6px;
    background: rgba(255,255,255,.04);
    border-radius: 3px;
    overflow: hidden;
  }

  .sev-bar {
    height: 100%;
    border-radius: 3px;
    transition: width .6s cubic-bezier(.22,1,.36,1);
  }

  .sev-count {
    font-family: var(--mono);
    font-size: 12px;
    width: 36px;
    text-align: right;
    flex-shrink: 0;
  }

  /* Host table */
  .host-table { width: 100%; border-collapse: collapse; }
  .host-table th {
    text-align: left;
    padding: 8px 20px;
    font-size: 10px;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    color: var(--muted);
    font-family: var(--mono);
    font-weight: 400;
  }
  .host-table td {
    padding: 9px 20px;
    font-family: var(--mono);
    font-size: 12px;
    border-top: 1px solid rgba(255,255,255,.03);
  }
  .host-table tr:hover td { background: rgba(0,212,255,.03); }
  .host-bar-cell { width: 40%; }
  .host-bar-wrap {
    height: 4px;
    background: rgba(255,255,255,.04);
    border-radius: 2px;
    overflow: hidden;
  }
  .host-bar {
    height: 100%;
    background: linear-gradient(90deg, var(--accent2), var(--accent));
    border-radius: 2px;
  }

  /* Tasks table */
  .tasks-wrap { overflow-x: auto; }
  .tasks-table { width: 100%; border-collapse: collapse; }
  .tasks-table th {
    text-align: left;
    padding: 10px 16px;
    font-size: 10px;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    color: var(--muted);
    font-family: var(--mono);
    font-weight: 400;
    white-space: nowrap;
  }
  .tasks-table td {
    padding: 10px 16px;
    font-size: 12px;
    border-top: 1px solid rgba(255,255,255,.03);
  }
  .tasks-table tr:hover td { background: rgba(0,212,255,.025); }
  .task-name { font-weight: 600; color: #d0daf0; max-width: 240px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .task-meta { font-family: var(--mono); color: var(--muted); font-size: 11px; }

  /* Progress bar */
  .prog-wrap { width: 80px; height: 4px; background: rgba(255,255,255,.05); border-radius: 2px; display: inline-block; vertical-align: middle; }
  .prog-fill  { height: 100%; background: var(--accent); border-radius: 2px; }

  /* Footer */
  footer {
    text-align: center;
    padding: 20px 32px;
    font-family: var(--mono);
    font-size: 10px;
    color: var(--muted);
    border-top: 1px solid var(--border);
    margin-top: 12px;
  }
</style>
</head>
<body>

<header>
  <div class="logo">
    <div class="logo-mark">OV</div>
    <div>
      <div class="logo-text">OpenVAS</div>
      <div class="logo-sub">AstroPema AI LLC · mail.astropema.ai</div>
    </div>
  </div>
  <div class="header-right">
    <span><span class="status-dot"></span><?= $d['connected'] ? 'GVMD CONNECTED' : 'GVMD OFFLINE' ?></span>
    <span class="risk-badge"><?= $risk_level ?> RISK</span>
    <span>REFRESH <?= max(0, $refresh_in) ?>s</span>
  </div>
</header>

<main>

<?php if ($d['error']): ?>
<div class="error-banner">⚠ <?= htmlspecialchars($d['error']) ?></div>
<?php endif; ?>

<!-- Stat cards -->
<div class="stat-row">
  <div class="stat-card" style="--card-accent:#ff3b3b">
    <div class="stat-label">Critical</div>
    <div class="stat-value"><?= $d['results_by_severity']['Critical'] ?></div>
    <div class="stat-sub">CVSS ≥ 9.0</div>
  </div>
  <div class="stat-card" style="--card-accent:#ff7c2a">
    <div class="stat-label">High</div>
    <div class="stat-value"><?= $d['results_by_severity']['High'] ?></div>
    <div class="stat-sub">CVSS 7.0–8.9</div>
  </div>
  <div class="stat-card" style="--card-accent:#f5c518">
    <div class="stat-label">Medium</div>
    <div class="stat-value"><?= $d['results_by_severity']['Medium'] ?></div>
    <div class="stat-sub">CVSS 4.0–6.9</div>
  </div>
  <div class="stat-card" style="--card-accent:#4fc3f7">
    <div class="stat-label">Low</div>
    <div class="stat-value"><?= $d['results_by_severity']['Low'] ?></div>
    <div class="stat-sub">CVSS 0.1–3.9</div>
  </div>
  <div class="stat-card" style="--card-accent:#78909c">
    <div class="stat-label">Log / Info</div>
    <div class="stat-value"><?= $d['results_by_severity']['Log'] ?></div>
    <div class="stat-sub">CVSS 0.0</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Tasks</div>
    <div class="stat-value"><?= count($d['tasks']) ?></div>
    <div class="stat-sub"><?= $d['last_scan'] ? 'Last: ' . format_ts($d['last_scan']) : 'No scans yet' ?></div>
  </div>
  <?php if ($d['nvt_count']): ?>
  <div class="stat-card" style="--card-accent:#7c4dff">
    <div class="stat-label">NVT Feed</div>
    <div class="stat-value" style="font-size:24px"><?= number_format($d['nvt_count']) ?></div>
    <div class="stat-sub">vulnerability checks</div>
  </div>
  <?php endif; ?>
</div>

<!-- Severity breakdown + Top hosts -->
<div class="grid-2">

  <!-- Severity bars -->
  <div class="panel">
    <div class="panel-header">
      <span>Severity Breakdown</span>
      <span class="count"><?= $d['total_results'] ?> total</span>
    </div>
    <div class="sev-list">
      <?php
      $sevs = ['Critical','High','Medium','Low','Log'];
      $max = max(1, max(array_values($d['results_by_severity'])));
      foreach ($sevs as $sev):
        $count = $d['results_by_severity'][$sev];
        $pct = round($count / $max * 100);
        $color = severity_color($sev);
      ?>
      <div class="sev-row">
        <div class="sev-label"><?= $sev ?></div>
        <div class="sev-bar-wrap">
          <div class="sev-bar" style="width:<?= $pct ?>%;background:<?= $color ?>"></div>
        </div>
        <div class="sev-count" style="color:<?= $color ?>"><?= $count ?></div>
      </div>
      <?php endforeach; ?>
      <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--border);font-family:var(--mono);font-size:11px;color:var(--muted)">
        Actionable (C+H+M): <span style="color:var(--text);font-weight:700"><?= $total_actionable ?></span>
      </div>
    </div>
  </div>

  <!-- Top hosts -->
  <div class="panel">
    <div class="panel-header">
      <span>Top Affected Hosts</span>
      <span class="count"><?= count($d['top_hosts']) ?> hosts</span>
    </div>
    <?php if (empty($d['top_hosts'])): ?>
      <div style="padding:24px 20px;font-family:var(--mono);font-size:12px;color:var(--muted)">No host data available.</div>
    <?php else:
      $maxh = max(array_values($d['top_hosts']));
    ?>
    <table class="host-table">
      <thead>
        <tr><th>Host</th><th>Findings</th><th class="host-bar-cell"></th></tr>
      </thead>
      <tbody>
        <?php foreach ($d['top_hosts'] as $host => $count):
          $pct = round($count / $maxh * 100);
        ?>
        <tr>
          <td><?= htmlspecialchars($host) ?></td>
          <td><?= $count ?></td>
          <td class="host-bar-cell">
            <div class="host-bar-wrap">
              <div class="host-bar" style="width:<?= $pct ?>%"></div>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

</div>

<!-- Tasks -->
<div class="panel" style="margin-bottom:28px">
  <div class="panel-header">
    <span>Scan Tasks</span>
    <span class="count"><?= count($d['tasks']) ?></span>
  </div>
  <?php if (empty($d['tasks'])): ?>
    <div style="padding:24px 20px;font-family:var(--mono);font-size:12px;color:var(--muted)">No tasks found. Create a scan target and task in gvm-cli.</div>
  <?php else: ?>
  <div class="tasks-wrap">
    <table class="tasks-table">
      <thead>
        <tr>
          <th>Task</th>
          <th>Status</th>
          <th>Progress</th>
          <th>Last Run</th>
          <th>Results</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($d['tasks'] as $t): ?>
        <tr>
          <td><div class="task-name"><?= htmlspecialchars($t['name']) ?></div></td>
          <td><?= task_status_badge($t['status']) ?></td>
          <td>
            <?php if ($t['progress'] > 0 && $t['progress'] < 100): ?>
            <span class="task-meta"><?= $t['progress'] ?>%</span>
            <div class="prog-wrap"><div class="prog-fill" style="width:<?= $t['progress'] ?>%"></div></div>
            <?php else: echo '<span class="task-meta">—</span>'; ?>
            <?php endif; ?>
          </td>
          <td class="task-meta"><?= format_ts($t['last_run']) ?></td>
          <td class="task-meta"><?= $t['result_count'] ?: '—' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

</main>

<footer>
  AstroPema AI LLC &nbsp;·&nbsp; OpenVAS Dashboard &nbsp;·&nbsp;
  Cache updated <?= date('Y-m-d H:i:s', $d['ts']) ?> &nbsp;·&nbsp;
  <a href="?flush=1" style="color:var(--muted);text-decoration:none">flush cache</a>
</footer>

<?php
// Cache flush
if (isset($_GET['flush']) && file_exists(CACHE_FILE)) {
    unlink(CACHE_FILE);
    header('Location: /');
    exit;
}
?>

</body>
</html>
