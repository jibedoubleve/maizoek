<?php
// ── Auth ──────────────────────────────────────────────────────
$infra_file = __DIR__ . '/config/infra.json';
$infra      = file_exists($infra_file)
    ? json_decode(file_get_contents($infra_file), true)
    : [];

// ── First-run setup: hash plain-text password ─────────────────
if ($infra['first_setup'] ?? false) {
    $infra['logs_password'] = password_hash($infra['logs_password'], PASSWORD_BCRYPT);
    unset($infra['first_setup']);
    @file_put_contents($infra_file, json_encode($infra, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
}

$expected_user = $infra['logs_user']     ?? '';
$expected_pass = $infra['logs_password'] ?? '';

if (!$expected_user || !$expected_pass) {
    http_response_code(503);
    exit('Log viewer not configured. Add logs_user and logs_password to infra.json.');
}

$user = $_SERVER['PHP_AUTH_USER'] ?? '';
$pass = $_SERVER['PHP_AUTH_PW']   ?? '';

if ($user !== $expected_user || !password_verify($pass, $expected_pass)) {
    header('WWW-Authenticate: Basic realm="Logs"');
    http_response_code(401);
    exit;
}

require_once __DIR__ . '/lib/SqliteLogger.php';

// ── DB ────────────────────────────────────────────────────────
$db_path = __DIR__ . '/../cache.sqlite';
$db      = new PDO('sqlite:' . $db_path);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
SqliteLogger::ensureTable($db);

// ── Actions ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear') {
    $db->exec('DELETE FROM logs');
    header('Location: logs.php');
    exit;
}

// ── Filters ───────────────────────────────────────────────────
$filter_level = $_GET['level'] ?? '';
$filter_ctx   = trim($_GET['ctx'] ?? '');
$limit        = max(10, min(500, (int) ($_GET['limit'] ?? 100)));

$where  = [];
$params = [];
if ($filter_level) { $where[] = 'level = ?';   $params[] = $filter_level; }
if ($filter_ctx)   { $where[] = 'ctx LIKE ?';  $params[] = '%' . $filter_ctx . '%'; }

$sql  = 'SELECT * FROM logs';
$sql .= $where ? ' WHERE ' . implode(' AND ', $where) : '';
$sql .= ' ORDER BY ts DESC LIMIT ?';
$params[] = $limit;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total = $db->query('SELECT COUNT(*) FROM logs')->fetchColumn();

// ── Level badge colors ────────────────────────────────────────
$level_colors = [
    'emergency' => '#7c3aed', 'alert'   => '#7c3aed',
    'critical'  => '#dc2626', 'error'   => '#dc2626',
    'warning'   => '#d97706', 'notice'  => '#0369a1',
    'info'      => '#0284c7', 'debug'   => '#6b7280',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logs</title>
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <style>
        :root {
            --bg: #f0f2f5; --surface: #fff; --border: #e4e7eb;
            --text: #1a1d23; --muted: #6b7280; --radius: 8px;
            --shadow: 0 1px 3px rgba(0,0,0,.08);
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: var(--bg); color: var(--text); font-size: 14px; }
        .header { background: var(--surface); border-bottom: 1px solid var(--border); padding: 12px 20px; display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
        .header h1 { font-size: 1em; font-weight: 700; }
        .header .meta { font-size: 0.82em; color: var(--muted); }
        .filters { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-left: auto; }
        .filters select, .filters input { padding: 5px 10px; border: 1px solid var(--border); border-radius: 6px; font-size: 0.82em; background: var(--bg); }
        .btn { padding: 5px 14px; border-radius: 6px; border: 1px solid var(--border); font-size: 0.82em; cursor: pointer; background: var(--surface); }
        .btn:hover { background: var(--bg); }
        .btn-danger { border-color: #fca5a5; color: #dc2626; }
        .btn-danger:hover { background: #fef2f2; }
        .container { padding: 16px 20px; }
        table { width: 100%; border-collapse: collapse; background: var(--surface); border-radius: var(--radius); box-shadow: var(--shadow); overflow: hidden; }
        th { text-align: left; padding: 10px 14px; font-size: 0.72em; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; color: var(--muted); border-bottom: 1px solid var(--border); background: #f9fafb; }
        td { padding: 9px 14px; border-bottom: 1px solid #f3f4f6; vertical-align: top; font-size: 0.85em; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #fafbfc; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 20px; font-size: 0.75em; font-weight: 700; color: #fff; }
        .ts { white-space: nowrap; color: var(--muted); font-size: 0.8em; }
        .ctx { font-family: monospace; font-size: 0.8em; color: var(--muted); }
        .data { font-family: monospace; font-size: 0.78em; color: #374151; max-width: 400px; word-break: break-all; }
        .empty { text-align: center; padding: 40px; color: var(--muted); }
    </style>
</head>
<body>

<div class="header">
    <h1>Logs</h1>
    <span class="meta"><?= $total ?> entries total</span>

    <form method="get" class="filters">
        <select name="level" onchange="this.form.submit()">
            <option value="">All levels</option>
            <?php foreach (array_keys($level_colors) as $l): ?>
            <option value="<?= $l ?>" <?= $filter_level === $l ? 'selected' : '' ?>><?= $l ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" name="ctx" value="<?= htmlspecialchars($filter_ctx) ?>" placeholder="Filter ctx…" onchange="this.form.submit()">
        <select name="limit" onchange="this.form.submit()">
            <?php foreach ([50, 100, 200, 500] as $n): ?>
            <option value="<?= $n ?>" <?= $limit === $n ? 'selected' : '' ?>><?= $n ?> rows</option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn">Filter</button>
    </form>

    <form method="post" onsubmit="return confirm('Clear all logs?')">
        <input type="hidden" name="action" value="clear">
        <button type="submit" class="btn btn-danger">Clear all</button>
    </form>
</div>

<div class="container">
<?php if (empty($logs)): ?>
    <div class="empty">No logs found.</div>
<?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Time</th>
                <th>Level</th>
                <th>Context</th>
                <th>Message</th>
                <th>Data</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($logs as $row): ?>
            <tr>
                <td class="ts"><?= date('Y-m-d H:i:s', $row['ts']) ?></td>
                <td>
                    <span class="badge" style="background:<?= $level_colors[$row['level']] ?? '#6b7280' ?>">
                        <?= htmlspecialchars($row['level']) ?>
                    </span>
                </td>
                <td class="ctx"><?= htmlspecialchars($row['ctx'] ?? '') ?></td>
                <td><?= htmlspecialchars($row['message'] ?? '') ?></td>
                <td class="data"><?php
                    if ($row['data']) {
                        $decoded = json_decode($row['data'], true);
                        echo htmlspecialchars($decoded ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : $row['data']);
                    }
                ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</div>

</body>
</html>
