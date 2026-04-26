<?php
require_once dirname(__DIR__) . '/config.php';

db_ensure_init();

/* ════════════════════════════════════════════════════
   AJAX / POST обработчики
   ════════════════════════════════════════════════════ */

$postAction = $_POST['action'] ?? '';

/* ── Создать сайт ── */
if ($postAction === 'create_site') {
    header('Content-Type: application/json');
    $name   = trim($_POST['name']   ?? '');
    $domain = strtolower(preg_replace('/^www\./i', '', trim($_POST['domain'] ?? '')));
    if (!$name)   { echo json_encode(['ok' => false, 'error' => 'Название обязательно']); R::close(); exit; }
    if (!$domain) { echo json_encode(['ok' => false, 'error' => 'Домен обязателен']);     R::close(); exit; }
    $apiKey = bin2hex(random_bytes(8));
    R::exec(
        "INSERT INTO sites (domain, name, api_key, is_active, created_at, updated_at) VALUES (?,?,?,1,datetime('now','localtime'),datetime('now','localtime'))",
        [$domain, $name, $apiKey]
    );
    $id = (int)R::getCell('SELECT last_insert_rowid()');
    echo json_encode(['ok' => true, 'id' => $id, 'key' => $apiKey]);
    R::close(); exit;
}

/* ── Обновить сайт ── */
if ($postAction === 'update_site') {
    header('Content-Type: application/json');
    $id     = (int)($_POST['id'] ?? 0);
    $name   = trim($_POST['name']   ?? '');
    $domain = strtolower(preg_replace('/^www\./i', '', trim($_POST['domain'] ?? '')));
    if (!$id || !$name) { echo json_encode(['ok' => false, 'error' => 'invalid']); R::close(); exit; }
    R::exec("UPDATE sites SET name=?, domain=?, updated_at=datetime('now','localtime') WHERE id=?",
            [$name, $domain, $id]);
    echo json_encode(['ok' => true]);
    R::close(); exit;
}

/* ── Переключить активность сайта ── */
if ($postAction === 'toggle_site') {
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    R::exec("UPDATE sites SET is_active = 1 - is_active, updated_at=datetime('now','localtime') WHERE id=?", [$id]);
    $active = (int)R::getCell('SELECT is_active FROM sites WHERE id=?', [$id]);
    echo json_encode(['ok' => true, 'active' => $active]);
    R::close(); exit;
}

/* ── Удалить сайт ── */
if ($postAction === 'delete_site') {
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    R::exec('DELETE FROM site_integrations WHERE site_id=?', [$id]);
    R::exec('DELETE FROM sites WHERE id=?', [$id]);
    echo json_encode(['ok' => true]);
    R::close(); exit;
}

/* ── Сохранить конфиг попапа ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $postAction === 'save_popup') {
    require_once __DIR__ . '/generator.php';
    $siteId  = (int)($_POST['site_id'] ?? 0);
    $variant = strtoupper($_POST['variant'] ?? '');
    $domain  = strtolower(preg_replace('/^www\./i', '', trim($_POST['editor_domain'] ?? '')));
    if (!$siteId || !in_array($variant, ['A','B','C'], true)) {
        R::close(); header('Location: ?'); exit;
    }
    $defaults = popupDefaults($variant);
    $c = [];
    foreach ($defaults as $k => $def) {
        if ($k === 'timer') {
            $c[$k] = max(30, min(600, (int)($_POST[$k] ?? $def)));
        } elseif ($k === 'headline') {
            $c[$k] = strip_tags((string)($_POST[$k] ?? $def), '<br>');
        } else {
            $c[$k] = strip_tags((string)($_POST[$k] ?? $def));
        }
    }
    $c['enabled'] = isset($_POST['enabled']) ? 1 : 0;
    $json = json_encode($c, JSON_UNESCAPED_UNICODE);
    $now  = date('Y-m-d H:i:s');
    $exists = R::getCell('SELECT COUNT(*) FROM popup_config WHERE domain=? AND variant=?', [$domain, $variant]);
    if ($exists) {
        R::exec('UPDATE popup_config SET config=?, site_id=?, updated_at=? WHERE domain=? AND variant=?',
                [$json, $siteId, $now, $domain, $variant]);
    } else {
        R::exec('INSERT INTO popup_config (domain, site_id, variant, config, updated_at) VALUES (?,?,?,?,?)',
                [$domain, $siteId, $variant, $json, $now]);
    }
    $err = null;
    if ($domain === '') {
        $err = buildAndSave($variant, $c);
        $enabled = [];
        foreach (['A','B','C'] as $pv) {
            $row = R::getRow("SELECT config FROM popup_config WHERE domain='' AND variant=?", [$pv]);
            $cfg = $row ? json_decode($row['config'], true) : [];
            if (($cfg['enabled'] ?? 1) == 1) $enabled[] = $pv;
        }
        if (!$err) $err = updateLoaderVariants($enabled);
    }
    R::close();
    $qs = '?site=' . $siteId . '&tab=popups&variant=' . $variant;
    header('Location: ' . $qs . ($err ? '&err=' . urlencode($err) : '&saved=1'));
    exit;
}

/* ── Сохранить интеграцию ── */
if ($postAction === 'save_integrations') {
    header('Content-Type: application/json');
    $siteId  = (int)($_POST['site_id'] ?? 0);
    $type    = trim($_POST['type'] ?? '');
    $enabled = (int)(bool)($_POST['enabled'] ?? 0);
    if (!$siteId || !in_array($type, ['telegram', 'bitrix24', 'yandex_metrika'], true)) {
        echo json_encode(['ok' => false, 'error' => 'invalid']); R::close(); exit;
    }
    $cfg = [];
    if ($type === 'telegram') {
        $cfg = ['tg_token' => trim($_POST['tg_token'] ?? ''), 'tg_chat_id' => trim($_POST['tg_chat_id'] ?? '')];
    } elseif ($type === 'bitrix24') {
        $keys = $_POST['cf_key'] ?? []; $vals = $_POST['cf_value'] ?? [];
        $cf = [];
        foreach ($keys as $i => $k) { $k = trim($k); $v = trim($vals[$i] ?? ''); if ($k !== '') $cf[$k] = $v; }
        $cfg = ['b24_webhook' => trim($_POST['b24_webhook'] ?? ''), 'b24_custom_fields' => $cf];
    } elseif ($type === 'yandex_metrika') {
        $cfg = [
            'counter_id' => preg_replace('/[^0-9]/', '', $_POST['ym_counter_id'] ?? ''),
            'goal_open'  => trim($_POST['ym_goal_open'] ?? ''),
            'goal_lead'  => trim($_POST['ym_goal_lead'] ?? ''),
        ];
    }
    $json   = json_encode($cfg, JSON_UNESCAPED_UNICODE);
    $exists = R::getCell('SELECT COUNT(*) FROM site_integrations WHERE site_id=? AND type=?', [$siteId, $type]);
    if ($exists) {
        R::exec('UPDATE site_integrations SET config=?, enabled=? WHERE site_id=? AND type=?', [$json, $enabled, $siteId, $type]);
    } else {
        R::exec('INSERT INTO site_integrations (site_id, type, config, enabled) VALUES (?,?,?,?)', [$siteId, $type, $json, $enabled]);
    }
    echo json_encode(['ok' => true]);
    R::close(); exit;
}

/* ── Тест Telegram ── */
if ($postAction === 'test_telegram') {
    header('Content-Type: application/json');
    $token = trim($_POST['tg_token'] ?? ''); $chatId = trim($_POST['tg_chat_id'] ?? '');
    if (!$token || !$chatId) { echo json_encode(['ok' => false, 'error' => 'Токен и Chat ID обязательны']); R::close(); exit; }
    $ch = curl_init();
    curl_setopt_array($ch, [CURLOPT_URL => 'https://api.telegram.org/bot' . $token . '/sendMessage', CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_POSTFIELDS => ['chat_id' => $chatId, 'text' => '✅ Exit Intent: тестовое сообщение. Интеграция работает!']]);
    $res = json_decode(curl_exec($ch), true); curl_close($ch);
    echo json_encode(['ok' => (bool)($res['ok'] ?? false), 'error' => $res['description'] ?? null]);
    R::close(); exit;
}

/* ── Тест Bitrix24 ── */
if ($postAction === 'test_bitrix24') {
    header('Content-Type: application/json');
    $webhook = trim($_POST['b24_webhook'] ?? '');
    if (!$webhook) { echo json_encode(['ok' => false, 'error' => 'Webhook обязателен']); R::close(); exit; }
    /* Собираем кастомные поля из формы (с тест-значениями вместо макросов) */
    $testMacros = ['{{phone}}'=>'+70000000000','{{page_url}}'=>'https://test.example.com','{{ym_client_id}}'=>'test123','{{messenger}}'=>'tg','{{ip}}'=>'127.0.0.1','{{utm_source}}'=>'test','{{utm_medium}}'=>'','{{utm_campaign}}'=>'','{{utm_content}}'=>'','{{utm_term}}'=>''];
    $cfKeys = $_POST['cf_key'] ?? []; $cfVals = $_POST['cf_value'] ?? [];
    $customFields = [];
    foreach ($cfKeys as $i => $k) { $k = trim($k); $v = trim($cfVals[$i] ?? ''); if ($k !== '') $customFields[$k] = str_replace(array_keys($testMacros), array_values($testMacros), $v); }
    $fields = array_merge([
        'TITLE'     => 'Exit Intent TEST',
        'PHONE'     => [['VALUE' => '+70000000000', 'VALUE_TYPE' => 'WORK']],
        'SOURCE_ID' => 'WEBFORM',
        'COMMENTS'  => 'Тестовый лид из админки',
    ], $customFields);
    $ch = curl_init();
    curl_setopt_array($ch, [CURLOPT_URL => rtrim($webhook,'/').'/crm.lead.add.json', CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_POSTFIELDS => http_build_query(['fields' => $fields])]);
    $raw = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
    if ($err) { echo json_encode(['ok' => false, 'error' => $err]); R::close(); exit; }
    $res = json_decode($raw, true);
    $ok = isset($res['result']) && $res['result'];
    echo json_encode(['ok' => $ok, 'error' => $res['error_description'] ?? ($res['error'] ?? null), 'b24_response' => $res]);
    R::close(); exit;
}

/* ── Сброс статистики ── */
if ($postAction === 'clear_stats') {
    header('Content-Type: application/json');
    $siteId = (int)($_POST['site_id'] ?? 0);
    if ($siteId) {
        R::exec('DELETE FROM popup_opens WHERE site_id=?', [$siteId]);
        R::exec('DELETE FROM popup_leads WHERE site_id=?', [$siteId]);
    } else {
        R::exec('DELETE FROM popup_opens'); R::exec('DELETE FROM popup_leads');
    }
    echo json_encode(['ok' => true]);
    R::close(); exit;
}

/* ════════════════════════════════════════════════════
   РОУТИНГ — список сайтов или страница сайта
   ════════════════════════════════════════════════════ */

$siteId = (int)($_GET['site'] ?? 0);
$tab    = $_GET['tab'] ?? ($siteId ? 'stats' : '');
$today  = date('Y-m-d');

/* ── Страница сайта ── */
if ($siteId) {
    $site = R::getRow('SELECT * FROM sites WHERE id=?', [$siteId]);
    if (!$site) { R::close(); header('Location: ?'); exit; }

    $proto   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $baseDir = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/index.php')), '/\\');
    $baseUrl = $proto . '://' . $host . $baseDir;

    /* ── Stats ── */
    $totalOpens = $totalLeads = $totalLeadsAll = $totalLeadsNoYm = 0;
    $opensToday = $leadsToday = 0;
    $convRate   = 0;
    $variantStats = [];
    $chartDays = $chartOpens = $chartLeads = [];
    $leadRows = []; $leadTotal = 0; $leadPages = 1;
    $perPage = 20; $page = max(1, (int)($_GET['page'] ?? 1)); $offset = ($page - 1) * $perPage;

    if ($tab === 'stats') {
        $totalOpens    = (int)R::getCell('SELECT COUNT(*) FROM popup_opens WHERE site_id=?', [$siteId]);
        $totalLeads    = (int)R::getCell('SELECT COUNT(*) FROM popup_leads WHERE site_id=?', [$siteId]);
        $totalLeadsAll = $totalLeads;
        $totalLeadsNoYm = (int)R::getCell('SELECT COUNT(*) FROM popup_leads WHERE site_id=? AND has_ym=0', [$siteId]);
        $convRate      = $totalOpens > 0 ? round($totalLeads / $totalOpens * 100, 1) : 0;
        $opensToday    = (int)R::getCell("SELECT COUNT(*) FROM popup_opens WHERE site_id=? AND DATE(created_at)=?", [$siteId, $today]);
        $leadsToday    = (int)R::getCell("SELECT COUNT(*) FROM popup_leads WHERE site_id=? AND DATE(created_at)=?", [$siteId, $today]);
        foreach (['A','B','C'] as $v) {
            $opens = (int)R::getCell('SELECT COUNT(*) FROM popup_opens WHERE site_id=? AND variant=?', [$siteId, $v]);
            $leads = (int)R::getCell('SELECT COUNT(*) FROM popup_leads WHERE site_id=? AND variant=?', [$siteId, $v]);
            $lnym  = (int)R::getCell('SELECT COUNT(*) FROM popup_leads WHERE site_id=? AND variant=? AND has_ym=0', [$siteId, $v]);
            $variantStats[$v] = ['opens' => $opens, 'leads' => $leads, 'leads_noym' => $lnym,
                'conv' => $opens > 0 ? round($leads / $opens * 100, 1) : 0];
        }
        for ($i = 6; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $chartDays[]  = date('d.m', strtotime($d));
            $chartOpens[] = (int)R::getCell("SELECT COUNT(*) FROM popup_opens WHERE site_id=? AND DATE(created_at)=?", [$siteId, $d]);
            $chartLeads[] = (int)R::getCell("SELECT COUNT(*) FROM popup_leads WHERE site_id=? AND DATE(created_at)=?", [$siteId, $d]);
        }
        $leadTotal = (int)R::getCell('SELECT COUNT(*) FROM popup_leads WHERE site_id=?', [$siteId]);
        $leadPages = max(1, (int)ceil($leadTotal / $perPage));
        $leadRows  = R::getAll('SELECT * FROM popup_leads WHERE site_id=? ORDER BY created_at DESC LIMIT ? OFFSET ?', [$siteId, $perPage, $offset]);
    }

    /* ── Popups ── */
    $popupConfigs = [];
    if ($tab === 'popups') {
        require_once __DIR__ . '/generator.php';
        $domain = $site['domain'];
        foreach (['A','B','C'] as $pv) {
            $row = null;
            if ($domain) $row = R::getRow('SELECT config FROM popup_config WHERE domain=? AND variant=?', [$domain, $pv]);
            if (!$row)   $row = R::getRow("SELECT config FROM popup_config WHERE domain='' AND variant=?", [$pv]);
            $saved = ($row && $row['config']) ? json_decode($row['config'], true) : [];
            $popupConfigs[$pv] = array_merge(popupDefaults($pv), $saved ?: []);
        }
    }

    /* ── Integrations ── */
    $integData = ['telegram' => ['config' => [], 'enabled' => 0], 'bitrix24' => ['config' => [], 'enabled' => 0], 'yandex_metrika' => ['config' => [], 'enabled' => 0]];
    if ($tab === 'integrations') {
        $rows = R::getAll('SELECT type, config, enabled FROM site_integrations WHERE site_id=?', [$siteId]);
        foreach ($rows as $row) {
            $integData[$row['type']] = ['config' => json_decode($row['config'], true) ?: [], 'enabled' => (int)$row['enabled']];
        }
    }

    R::close();

/* ────────────────────────────────────────────────── helpers ── */
function esc($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function fmtPhone($p) {
    $d = preg_replace('/\D/', '', $p ?? '');
    if (strlen($d) === 11 && $d[0] === '7') return '+7 ('.substr($d,1,3).') '.substr($d,4,3).'-'.substr($d,7,2).'-'.substr($d,9,2);
    return $p ?: '—';
}
function messengerBadge($m) {
    $map = ['tg'=>['Telegram','#0088cc'],'wa'=>['WhatsApp','#25d366'],'mx'=>['Max','#6c3fff']];
    if (!$m || !isset($map[$m])) return '<span class="text-muted">—</span>';
    [$label,$color] = $map[$m];
    return "<span class='badge' style='background:{$color};font-size:11px'>{$label}</span>";
}
function variantBadge($v) {
    $c = ['A'=>'#e02020','B'=>'#1db954','C'=>'#2563eb'];
    return "<span class='badge' style='background:".($c[$v]??'#888').";font-size:11px'>Вариант {$v}</span>";
}

    $activeVar = strtoupper($_GET['variant'] ?? 'A');
    if (!in_array($activeVar, ['A','B','C'])) $activeVar = 'A';
    $savedOk  = isset($_GET['saved']);
    $savedErr = $_GET['err'] ?? '';

    $tabLabels = ['A'=>'Вариант A — Скидка','B'=>'Вариант B — Подарок','C'=>'Вариант C — Прогресс'];
    $tabColors = ['A'=>'#e02020','B'=>'#1db954','C'=>'#2563eb'];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= esc($site['name'] ?: $site['domain']) ?> · Exit Intent</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
<style>
  html,body{height:100%}
  body{background:#f3f7fb;color:#0f172a;font-size:13px;font-family:'Segoe UI',system-ui,sans-serif}
  .ct-header{background:#fff;border-bottom:1px solid #dbeafe;padding:0 24px;height:52px;
    display:flex;align-items:center;justify-content:space-between;
    position:sticky;top:0;z-index:100;box-shadow:0 1px 4px rgba(15,23,42,.05)}
  .ct-logo{display:flex;align-items:center;gap:10px;font-weight:700;font-size:14px;color:#1d4ed8;letter-spacing:-.3px}
  .ct-logo .dot{width:28px;height:28px;background:linear-gradient(135deg,#3b82f6,#1d4ed8);border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:14px}
  .ct-nav{background:#fff;border-bottom:1px solid #dbeafe;padding:0 24px}
  .ct-nav .nav-link{color:#64748b;font-size:13px;font-weight:500;padding:10px 14px;border-bottom:2px solid transparent;border-radius:0;display:flex;align-items:center;gap:6px;transition:color .15s,border-color .15s}
  .ct-nav .nav-link:hover{color:#1d4ed8}
  .ct-nav .nav-link.active{color:#1d4ed8;border-bottom-color:#1d4ed8;background:transparent}
  .stat-card{background:#fff;border:1px solid #dbeafe;border-radius:12px;padding:20px 22px;box-shadow:0 4px 12px rgba(15,23,42,.06);position:relative;overflow:hidden}
  .stat-card .stat-icon{position:absolute;top:16px;right:18px;width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px}
  .stat-card .stat-val{font-size:32px;font-weight:700;color:#0f172a;line-height:1;margin-bottom:4px}
  .stat-card .stat-label{font-size:12px;color:#64748b;font-weight:500}
  .stat-card .stat-sub{font-size:11px;color:#94a3b8;margin-top:4px}
  .chart-wrap{background:#fff;border:1px solid #dbeafe;border-radius:12px;padding:20px;box-shadow:0 4px 12px rgba(15,23,42,.06)}
  .chart-canvas{position:relative;height:120px;display:flex;align-items:flex-end;gap:4px}
  .chart-bar-group{flex:1;display:flex;gap:2px;align-items:flex-end;justify-content:center}
  .chart-bar{border-radius:3px 3px 0 0;min-height:4px}
  .chart-labels{display:flex;gap:4px;margin-top:6px}
  .chart-label{flex:1;text-align:center;font-size:10px;color:#94a3b8}
  .ct-table{border-radius:10px;overflow:hidden;box-shadow:0 2px 8px rgba(15,23,42,.05)}
  .ct-table thead th{background:#f8faff;color:#475569;font-weight:600;border-bottom:2px solid #dbeafe;font-size:12px;padding:10px 14px;white-space:nowrap}
  .ct-table tbody td{padding:9px 14px;vertical-align:middle;border-color:#f0f4f8}
  .ct-table tbody tr:hover td{background:#f8faff}
  .section-title{font-size:12px;font-weight:700;color:#94a3b8;letter-spacing:.08em;text-transform:uppercase;margin-bottom:12px}
  .install-code{background:#0f172a;color:#e2e8f0;border-radius:8px;padding:16px;font-size:12px;line-height:1.7;overflow-x:auto;white-space:pre;margin:0;font-family:'Cascadia Code','Fira Code','Courier New',monospace}
  .copy-btn.copied{background:#1db954;border-color:#1db954;color:#fff}
  .breadcrumb-link{color:#1d4ed8;text-decoration:none;font-size:13px;font-weight:500}
  .breadcrumb-link:hover{text-decoration:underline}
  .site-badge{background:#f1f5f9;border:1px solid #dbeafe;border-radius:6px;padding:4px 12px;font-size:12px;font-family:monospace;color:#334155}
  .key-tag{font-family:monospace;font-size:11px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:4px;padding:2px 8px;color:#475569;letter-spacing:.04em}
</style>
</head>
<body>

<header class="ct-header">
  <div class="ct-logo">
    <div class="dot"><i class="bi bi-cursor-fill" style="font-size:12px"></i></div>
    <div>
      <a href="?" class="breadcrumb-link" style="font-size:11px;color:#94a3b8;font-weight:400">← Все сайты</a>
      <div style="margin-top:1px"><?= esc($site['name'] ?: $site['domain'] ?: 'Без домена') ?></div>
    </div>
  </div>
  <div class="d-flex align-items-center gap-2">
    <?php if ($site['domain']): ?>
    <span class="site-badge"><i class="bi bi-globe2 me-1"></i><?= esc($site['domain']) ?></span>
    <?php endif ?>
    <span class="key-tag" title="API Key"><?= esc($site['api_key']) ?></span>
  </div>
</header>

<nav class="ct-nav">
  <ul class="nav">
    <li class="nav-item"><a class="nav-link <?= $tab==='stats'?'active':'' ?>" href="?site=<?= $siteId ?>&tab=stats"><i class="bi bi-grid-1x2"></i> Статистика</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab==='popups'?'active':'' ?>" href="?site=<?= $siteId ?>&tab=popups"><i class="bi bi-pencil-square"></i> Попапы</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab==='integrations'?'active':'' ?>" href="?site=<?= $siteId ?>&tab=integrations"><i class="bi bi-plug"></i> Интеграции</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab==='install'?'active':'' ?>" href="?site=<?= $siteId ?>&tab=install"><i class="bi bi-code-slash"></i> Установка</a></li>
  </ul>
</nav>

<div class="container-fluid px-3 px-md-4 py-4" style="max-width:1100px">

<?php if ($tab === 'stats'): ?>

  <div class="row g-3 mb-4">
    <div class="col-6 col-md-3"><div class="stat-card">
      <div class="stat-icon bg-primary bg-opacity-10 text-primary"><i class="bi bi-eye"></i></div>
      <div class="stat-val"><?= $totalOpens ?></div>
      <div class="stat-label">Открытий</div>
      <div class="stat-sub">Сегодня: <?= $opensToday ?></div>
    </div></div>
    <div class="col-6 col-md-3"><div class="stat-card">
      <div class="stat-icon bg-success bg-opacity-10 text-success"><i class="bi bi-person-check"></i></div>
      <div class="stat-val"><?= $totalLeads ?></div>
      <div class="stat-label">Заявок (всего)</div>
      <div class="stat-sub">Сегодня: <?= $leadsToday ?></div>
    </div></div>
    <div class="col-6 col-md-3"><div class="stat-card">
      <div class="stat-icon bg-warning bg-opacity-10 text-warning"><i class="bi bi-graph-up-arrow"></i></div>
      <div class="stat-val"><?= $convRate ?>%</div>
      <div class="stat-label">Конверсия</div>
      <div class="stat-sub">открытия → заявки</div>
    </div></div>
    <div class="col-6 col-md-3"><div class="stat-card">
      <div class="stat-icon" style="background:rgba(108,63,255,.1);color:#6c3fff"><i class="bi bi-reception-4"></i></div>
      <div class="stat-val"><?= $totalLeadsNoYm ?></div>
      <div class="stat-label">Без Метрики</div>
      <div class="stat-sub">все заявки: <?= $totalLeadsAll ?></div>
    </div></div>
  </div>

  <div class="row g-3 mb-4">
    <div class="col-12 col-md-7">
      <div class="chart-wrap h-100">
        <div class="section-title mb-3">Последние 7 дней</div>
        <?php $maxVal = max(array_merge($chartOpens, $chartLeads, [1])); ?>
        <div class="chart-canvas">
          <?php for ($i=0;$i<7;$i++): ?>
          <div class="chart-bar-group">
            <div class="chart-bar" style="width:45%;background:#3b82f6;height:<?= round($chartOpens[$i]/$maxVal*100) ?>%" title="Открытия: <?= $chartOpens[$i] ?>"></div>
            <div class="chart-bar" style="width:45%;background:#1db954;height:<?= round($chartLeads[$i]/$maxVal*100) ?>%" title="Заявки: <?= $chartLeads[$i] ?>"></div>
          </div>
          <?php endfor ?>
        </div>
        <div class="chart-labels"><?php foreach($chartDays as $cd): ?><div class="chart-label"><?= $cd ?></div><?php endforeach ?></div>
        <div class="d-flex gap-3 mt-3" style="font-size:11px;color:#94a3b8">
          <span><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:#3b82f6;margin-right:4px"></span>Открытия</span>
          <span><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:#1db954;margin-right:4px"></span>Заявки</span>
        </div>
      </div>
    </div>
    <div class="col-12 col-md-5">
      <div class="chart-wrap h-100">
        <div class="section-title mb-3">По вариантам</div>
        <?php $maxV = max(array_merge(array_column($variantStats,'opens'),[1])); ?>
        <?php foreach(['A','B','C'] as $v): $vs=$variantStats[$v]; $bw=$maxV>0?round($vs['opens']/$maxV*100):0; ?>
        <div class="mb-3">
          <div class="d-flex justify-content-between mb-1">
            <?= variantBadge($v) ?>
            <span style="font-size:11px;color:#64748b"><?= $vs['opens'] ?> откр · <?= $vs['leads'] ?> заявок · <?= $vs['conv'] ?>%</span>
          </div>
          <div style="height:6px;border-radius:3px;background:#e5e7eb;overflow:hidden">
            <div style="width:<?= $bw ?>%;height:100%;border-radius:3px;background:<?= ['A'=>'#e02020','B'=>'#1db954','C'=>'#2563eb'][$v] ?>"></div>
          </div>
        </div>
        <?php endforeach ?>
        <div class="mt-3 pt-3 border-top">
          <button id="btn-clear" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Очистить статистику сайта</button>
        </div>
      </div>
    </div>
  </div>

  <?php if (!empty($leadRows)): ?>
  <div class="section-title">Последние заявки</div>
  <div class="card border-0 ct-table mb-3">
    <table class="table table-hover mb-0 ct-table">
      <thead><tr><th>#</th><th>Дата</th><th>Вариант</th><th>Телефон</th><th>Мессенджер</th><th>Страница</th><th>YM ClientID</th><th>Метрика</th></tr></thead>
      <tbody>
      <?php foreach($leadRows as $r): ?>
      <tr>
        <td style="color:#94a3b8"><?= esc($r['id']) ?></td>
        <td style="white-space:nowrap"><?= esc($r['created_at']) ?></td>
        <td><?= variantBadge($r['variant']) ?></td>
        <td class="fw-semibold"><?= fmtPhone($r['phone']) ?></td>
        <td><?= messengerBadge($r['messenger']) ?></td>
        <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
          <?php if (!empty($r['url'])): $urlShort = preg_replace('#^https?://#', '', $r['url']); ?>
            <a href="<?= esc($r['url']) ?>" target="_blank" rel="noopener" title="<?= esc($r['url']) ?>" style="font-size:11px;color:#3b82f6"><?= esc($urlShort) ?></a>
          <?php else: ?><span class="text-muted">—</span><?php endif ?>
        </td>
        <td><?= $r['ym_client_id'] ? '<code style="font-size:11px">'.esc($r['ym_client_id']).'</code>' : '<span class="text-muted">—</span>' ?></td>
        <td><?= $r['has_ym'] ? '<span class="badge bg-success" style="font-size:10px">✓</span>' : '<span class="badge bg-secondary" style="font-size:10px">нет</span>' ?></td>
      </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </div>
  <?php if ($leadPages > 1): ?>
  <nav><ul class="pagination pagination-sm justify-content-center">
    <?php for($p=1;$p<=$leadPages;$p++): ?>
    <li class="page-item <?= $p===$page?'active':'' ?>"><a class="page-link" href="?site=<?= $siteId ?>&tab=stats&page=<?= $p ?>"><?= $p ?></a></li>
    <?php endfor ?>
  </ul></nav>
  <?php endif ?>
  <?php endif ?>

<?php elseif ($tab === 'popups'): ?>

  <?php if ($savedOk): ?>
  <div class="alert alert-success alert-dismissible fade show mb-3"><i class="bi bi-check-circle-fill me-2"></i>Конфиг сохранён.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
  <?php endif ?>
  <?php if ($savedErr): ?>
  <div class="alert alert-danger alert-dismissible fade show mb-3"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= esc($savedErr) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
  <?php endif ?>

  <?php if (!$site['domain']): ?>
  <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i>Укажите домен сайта, чтобы настраивать попапы. <a href="#" onclick="openEditModal()">Редактировать сайт</a></div>
  <?php else: ?>

  <div class="chart-wrap mb-3" style="padding:12px 20px">
    <div style="font-size:12px;color:#64748b"><i class="bi bi-info-circle me-1"></i>Конфиг для домена <strong><?= esc($site['domain']) ?></strong>. Если не задан — используется глобальный.</div>
  </div>

  <ul class="nav nav-tabs mb-0" style="border-bottom:none">
    <?php foreach(['A','B','C'] as $pv): $isEnabled = ($popupConfigs[$pv]['enabled']??1)==1; ?>
    <li class="nav-item">
      <button class="nav-link <?= $pv===$activeVar?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#ptab<?= $pv ?>" style="font-size:13px">
        <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?= $isEnabled?$tabColors[$pv]:'#cbd5e1' ?>;margin-right:6px"></span>
        <?= $tabLabels[$pv] ?>
        <?php if(!$isEnabled): ?><span class="badge bg-secondary ms-1" style="font-size:9px">выкл</span><?php endif ?>
      </button>
    </li>
    <?php endforeach ?>
  </ul>

  <div class="tab-content">
  <?php foreach(['A','B','C'] as $pv): $c=$popupConfigs[$pv]; $isAct=$pv===$activeVar; ?>
  <div class="tab-pane fade <?= $isAct?'show active':'' ?>" id="ptab<?= $pv ?>">
    <div style="background:#fff;border:1px solid #dbeafe;border-top:none;border-radius:0 0 12px 12px;padding:24px">
      <form method="post" action="">
        <input type="hidden" name="action" value="save_popup">
        <input type="hidden" name="site_id" value="<?= $siteId ?>">
        <input type="hidden" name="variant" value="<?= $pv ?>">
        <input type="hidden" name="editor_domain" value="<?= esc($site['domain']) ?>">

        <div class="row g-3">
          <!-- Цвет -->
          <div class="col-12 col-md-4">
            <label class="form-label" style="font-size:12px;font-weight:600">Основной цвет</label>
            <div class="d-flex gap-2 align-items-center">
              <input type="color" id="cp<?= $pv ?>" value="<?= esc($c['color']) ?>" style="width:40px;height:32px;padding:2px;border:1px solid #ddd;border-radius:6px;cursor:pointer">
              <input type="text" name="color" id="ct<?= $pv ?>" class="form-control form-control-sm" value="<?= esc($c['color']) ?>" style="font-family:monospace;max-width:100px">
            </div>
          </div>

          <?php if($pv==='A'): ?>
          <div class="col-12 col-md-4">
            <label class="form-label" style="font-size:12px;font-weight:600">Бейдж</label>
            <input type="text" name="badge" class="form-control form-control-sm" value="<?= esc($c['badge']) ?>">
          </div>
          <div class="col-12 col-md-4">
            <label class="form-label" style="font-size:12px;font-weight:600">Таймер (сек)</label>
            <input type="number" name="timer" class="form-control form-control-sm" min="30" max="600" value="<?= esc($c['timer']) ?>">
          </div>
          <?php elseif($pv==='C'): ?>
          <div class="col-12 col-md-8">
            <label class="form-label" style="font-size:12px;font-weight:600">Метка вверху</label>
            <input type="text" name="label" class="form-control form-control-sm" value="<?= esc($c['label']) ?>">
          </div>
          <?php endif ?>

          <!-- Заголовок -->
          <div class="col-12">
            <label class="form-label" style="font-size:12px;font-weight:600">Заголовок <small class="text-muted fw-normal">(допустим &lt;br&gt;)</small></label>
            <input type="text" name="headline" class="form-control form-control-sm" value="<?= esc($c['headline']) ?>">
          </div>

          <!-- Подзаголовок -->
          <div class="col-12">
            <label class="form-label" style="font-size:12px;font-weight:600">Подтекст</label>
            <input type="text" name="subtext" class="form-control form-control-sm" value="<?= esc($c['subtext']) ?>">
          </div>

          <?php if($pv==='B'): ?>
          <div class="col-12 col-md-6">
            <label class="form-label" style="font-size:12px;font-weight:600">Название подарка</label>
            <input type="text" name="gift_name" class="form-control form-control-sm" value="<?= esc($c['gift_name']) ?>">
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label" style="font-size:12px;font-weight:600">Описание подарка</label>
            <input type="text" name="gift_desc" class="form-control form-control-sm" value="<?= esc($c['gift_desc']) ?>">
          </div>
          <?php endif ?>

          <?php if($pv==='C'): ?>
          <?php for($ci=1;$ci<=5;$ci++): ?>
          <div class="col-12 col-md-6">
            <label class="form-label" style="font-size:12px;font-weight:600">Пункт <?= $ci ?> <?= $ci===5?'<span class="badge bg-warning text-dark ms-1" style="font-size:9px">незавершён</span>':'' ?></label>
            <input type="text" name="check<?= $ci ?>" class="form-control form-control-sm" value="<?= esc($c['check'.$ci]) ?>">
          </div>
          <?php endfor ?>
          <?php endif ?>

          <!-- Кнопка -->
          <div class="col-12 col-md-6">
            <label class="form-label" style="font-size:12px;font-weight:600">Текст кнопки</label>
            <input type="text" name="btn" class="form-control form-control-sm" value="<?= esc($c['btn']) ?>">
          </div>

          <!-- Успех -->
          <div class="col-12 col-md-3">
            <label class="form-label" style="font-size:12px;font-weight:600">Заголовок успеха</label>
            <input type="text" name="ok_title" class="form-control form-control-sm" value="<?= esc($c['ok_title']) ?>">
          </div>
          <div class="col-12 col-md-3">
            <label class="form-label" style="font-size:12px;font-weight:600">Текст успеха</label>
            <input type="text" name="ok_text" class="form-control form-control-sm" value="<?= esc($c['ok_text']) ?>">
          </div>

        </div><!-- /row -->

        <div class="d-flex align-items-center gap-3 mt-4 pt-3 border-top">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="enabled" id="en<?= $pv ?>" <?= ($c['enabled']??1)?'checked':'' ?>>
            <label class="form-check-label" for="en<?= $pv ?>" style="font-size:13px;cursor:pointer">Участвует в ротации</label>
          </div>
          <button type="submit" class="btn btn-primary btn-sm ms-auto">
            <i class="bi bi-check2 me-1"></i> Сохранить вариант <?= $pv ?>
          </button>
        </div>

      </form>
    </div>
  </div>
  <?php endforeach ?>
  </div>
  <?php endif ?>

<?php elseif ($tab === 'integrations'): ?>

  <?php
    $tgCfg  = $integData['telegram']['config'];
    $tgOn   = $integData['telegram']['enabled'];
    $b24Cfg = $integData['bitrix24']['config'];
    $b24On  = $integData['bitrix24']['enabled'];
    $b24CF  = $b24Cfg['b24_custom_fields'] ?? [];
    $ymCfg  = $integData['yandex_metrika']['config'];
    $ymOn   = $integData['yandex_metrika']['enabled'];
  ?>

  <div id="int-alert" class="alert d-none mb-3" style="font-size:13px"></div>

  <div class="row g-3">
    <div class="col-12 col-lg-6">
      <div class="chart-wrap h-100" style="border-top:3px solid #0088cc">
        <div class="d-flex align-items-center gap-3 mb-3">
          <div style="font-size:20px;color:#0088cc"><i class="bi bi-telegram"></i></div>
          <div><div style="font-weight:700;font-size:14px">Telegram</div><div style="font-size:11px;color:#94a3b8">Уведомления при каждой заявке</div></div>
          <div class="ms-auto"><div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" id="tg-enabled" <?= $tgOn?'checked':'' ?>>
            <label class="form-check-label" for="tg-enabled" style="font-size:12px">Включено</label>
          </div></div>
        </div>
        <div class="mb-3">
          <label class="form-label" style="font-size:12px;font-weight:600">Bot Token</label>
          <input type="text" id="tg-token" class="form-control form-control-sm" placeholder="123456789:ABCdef..." value="<?= esc($tgCfg['tg_token']??'') ?>">
          <div class="form-text" style="font-size:11px">Получить у @BotFather в Telegram</div>
        </div>
        <div class="mb-3">
          <label class="form-label" style="font-size:12px;font-weight:600">Chat ID</label>
          <input type="text" id="tg-chat-id" class="form-control form-control-sm" placeholder="-100123456789" value="<?= esc($tgCfg['tg_chat_id']??'') ?>">
        </div>
        <div class="d-flex gap-2">
          <button class="btn btn-sm btn-outline-secondary" onclick="testTelegram(this)"><i class="bi bi-send me-1"></i>Тест</button>
          <button class="btn btn-sm btn-primary ms-auto" onclick="saveIntegration('telegram',this)"><i class="bi bi-check2 me-1"></i>Сохранить</button>
        </div>
      </div>
    </div>
    <div class="col-12 col-lg-6">
      <div class="chart-wrap h-100" style="border-top:3px solid #ff5c5c">
        <div class="d-flex align-items-center gap-3 mb-3">
          <div style="font-size:20px;color:#ff5c5c"><i class="bi bi-briefcase"></i></div>
          <div><div style="font-weight:700;font-size:14px">Bitrix24</div><div style="font-size:11px;color:#94a3b8">Создание лидов в CRM</div></div>
          <div class="ms-auto"><div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" id="b24-enabled" <?= $b24On?'checked':'' ?>>
            <label class="form-check-label" for="b24-enabled" style="font-size:12px">Включено</label>
          </div></div>
        </div>
        <div class="mb-3">
          <label class="form-label" style="font-size:12px;font-weight:600">Webhook URL</label>
          <input type="text" id="b24-webhook" class="form-control form-control-sm" placeholder="https://b24-xxx.bitrix24.ru/rest/1/TOKEN/" value="<?= esc($b24Cfg['b24_webhook']??'') ?>">
        </div>
        <div class="mb-3">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <label class="form-label mb-0" style="font-size:12px;font-weight:600">Кастомные поля</label>
            <button class="btn btn-outline-secondary" style="font-size:11px;padding:2px 8px" onclick="addCfRow()">+ поле</button>
          </div>
          <div id="cf-rows">
            <?php foreach($b24CF as $k=>$v): ?>
            <div class="d-flex gap-1 mb-1 cf-row">
              <input class="form-control form-control-sm" name="cf_key[]" placeholder="UF_CRM_..." value="<?= esc($k) ?>" style="font-size:12px">
              <input class="form-control form-control-sm" name="cf_value[]" placeholder="{{macro}}" value="<?= esc($v) ?>" style="font-size:12px">
              <button type="button" class="btn btn-sm btn-outline-danger" style="padding:2px 6px" onclick="this.closest('.cf-row').remove()">✕</button>
            </div>
            <?php endforeach ?>
          </div>
          <div style="font-size:11px;color:#94a3b8;margin-top:6px">
            Макросы: <code>{{phone}}</code> <code>{{page_url}}</code> <code>{{ym_client_id}}</code> <code>{{messenger}}</code> <code>{{ip}}</code> <code>{{utm_source}}</code> <code>{{utm_medium}}</code> <code>{{utm_campaign}}</code>
          </div>
        </div>
        <div class="d-flex gap-2">
          <button class="btn btn-sm btn-outline-secondary" onclick="testBitrix24(this)"><i class="bi bi-send me-1"></i>Тест</button>
          <button class="btn btn-sm btn-primary ms-auto" onclick="saveIntegration('bitrix24',this)"><i class="bi bi-check2 me-1"></i>Сохранить</button>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3 mt-1">
    <div class="col-12 col-lg-6">
      <div class="chart-wrap h-100" style="border-top:3px solid #f90">
        <div class="d-flex align-items-center gap-3 mb-3">
          <div style="font-size:20px;color:#f90"><i class="bi bi-bar-chart-line"></i></div>
          <div><div style="font-weight:700;font-size:14px">Яндекс.Метрика</div><div style="font-size:11px;color:#94a3b8">Отправка целей в браузере клиента</div></div>
          <div class="ms-auto"><div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" id="ym-enabled" <?= $ymOn?'checked':'' ?>>
            <label class="form-check-label" for="ym-enabled" style="font-size:12px">Включено</label>
          </div></div>
        </div>
        <div class="mb-3">
          <label class="form-label" style="font-size:12px;font-weight:600">ID счётчика Яндекс.Метрики</label>
          <input type="text" id="ym-counter-id" class="form-control form-control-sm" placeholder="12345678 (необязательно)" value="<?= esc($ymCfg['counter_id']??'') ?>">
          <div class="form-text" style="font-size:11px">Если задан — переопределяет ID из кода установки виджета</div>
        </div>
        <div class="mb-3">
          <label class="form-label" style="font-size:12px;font-weight:600">Цель при показе попапа</label>
          <input type="text" id="ym-goal-open" class="form-control form-control-sm" placeholder="exitintent_open" value="<?= esc($ymCfg['goal_open']??'') ?>">
          <div class="form-text" style="font-size:11px">Срабатывает когда попап открылся</div>
        </div>
        <div class="mb-3">
          <label class="form-label" style="font-size:12px;font-weight:600">Цель при отправке заявки</label>
          <input type="text" id="ym-goal-lead" class="form-control form-control-sm" placeholder="exitintent_lead" value="<?= esc($ymCfg['goal_lead']??'') ?>">
          <div class="form-text" style="font-size:11px">Срабатывает после успешной отправки формы</div>
        </div>
        <div class="form-text mb-3" style="font-size:11px">Цели вызываются через <code>ym(id, 'reachGoal', ...)</code> напрямую в браузере.</div>
        <div class="d-flex gap-2">
          <button class="btn btn-sm btn-primary ms-auto" onclick="saveIntegration('yandex_metrika',this)"><i class="bi bi-check2 me-1"></i>Сохранить</button>
        </div>
      </div>
    </div>
  </div>

<?php elseif ($tab === 'install'): ?>

  <?php
    $scriptUrl = $baseUrl . '/popup.js';
    $gateUrl   = $baseUrl . '/api/gate.php';
    $apiKey    = $site['api_key'];

    /* Вариант 1 — простой тег */
    $codeSimple = '<script src="' . $scriptUrl . '"' . "\n" .
      '        data-gate="' . $gateUrl . '"' . "\n" .
      '        data-key="' . $apiKey . '"' . "\n" .
      '        data-counter="XXXXXXXX"></script>';

    /* Вариант 2 — асинхронный */
    $codeAsync = '(function(w,d,s,u,g,k,c){' . "\n" .
      '  w._EI={gate:g,key:k,counter:c};' . "\n" .
      '  var el=d.createElement(s);el.async=1;el.src=u;' . "\n" .
      '  d.head.appendChild(el);' . "\n" .
      '})(window,document,\'script\',' . "\n" .
      '  \'' . $scriptUrl . '\',' . "\n" .
      '  \'' . $gateUrl . '\',' . "\n" .
      '  \'' . $apiKey . '\',' . "\n" .
      '  \'XXXXXXXX\');';

    /* Вариант 3 — с разбивкой домена (защита от парсеров) */
    $iHost     = parse_url($scriptUrl, PHP_URL_HOST);
    $iProto    = parse_url($scriptUrl, PHP_URL_SCHEME) . '://';
    $iBasePath = rtrim(dirname(parse_url($scriptUrl, PHP_URL_PATH)), '/');
    $iLen = strlen($iHost);
    $p1 = (int)round($iLen / 3); $p2 = (int)round($iLen * 2 / 3);
    $iH1 = substr($iHost, 0, $p1); $iH2 = substr($iHost, $p1, $p2 - $p1); $iH3 = substr($iHost, $p2);
    $iPLen = strlen($iBasePath);
    $pp1 = (int)round($iPLen / 3); $pp2 = (int)round($iPLen * 2 / 3);
    $iP1 = substr($iBasePath, 0, $pp1); $iP2 = substr($iBasePath, $pp1, $pp2 - $pp1); $iP3 = substr($iBasePath, $pp2);

    $codeObfuscated = '(function(){' . "\n" .
      'var _h=\'' . $iH1 . '\'+\'' . $iH2 . '\'+\'' . $iH3 . '\';' . "\n" .
      'var _p=\'' . $iP1 . '\'+\'' . $iP2 . '\'+\'' . $iP3 . '\';' . "\n" .
      'var _e=\'.p\'+\'hp\';' . "\n" .
      '(function(w,d,s,u,g,k,c){' . "\n" .
      '  w._EI={gate:g,key:k,counter:c};' . "\n" .
      '  var el=d.createElement(s);el.async=1;el.src=u;' . "\n" .
      '  d.head.appendChild(el);' . "\n" .
      '})(window,document,\'script\',' . "\n" .
      '  \'' . $iProto . '\'+_h+_p+\'/popup.js\',' . "\n" .
      '  \'' . $iProto . '\'+_h+_p+\'/api/gate\'+_e,' . "\n" .
      '  \'' . $apiKey . '\',' . "\n" .
      '  \'XXXXXXXX\');' . "\n" .
      '})();';
  ?>

  <div class="row g-3">
    <div class="col-12">
      <div class="chart-wrap" style="border-left:4px solid #3b82f6">
        <div class="d-flex gap-3">
          <div style="font-size:24px;color:#3b82f6"><i class="bi bi-info-circle-fill"></i></div>
          <div>
            <div style="font-weight:600;margin-bottom:4px">Как подключить</div>
            <div style="color:#64748b;font-size:12px;line-height:1.6">
              Вставьте код перед <code>&lt;/body&gt;</code>. Ключ <code><?= esc($apiKey) ?></code> уникален для этого сайта.
              Замените <code>ВАШ_СЧЁТЧИК_ЯМ</code> на номер счётчика Яндекс.Метрики (необязательно).
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="col-12 col-lg-6">
      <div class="chart-wrap h-100">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div><div class="section-title mb-0">Вариант 1 — простой тег</div></div>
          <button class="btn btn-sm btn-outline-primary copy-btn" data-target="code-simple"><i class="bi bi-clipboard"></i> Копировать</button>
        </div>
        <pre id="code-simple" class="install-code"><?= htmlspecialchars('<script>' . "\n" . $codeSimple . "\n" . '</script>', ENT_QUOTES, 'UTF-8') ?></pre>
      </div>
    </div>

    <div class="col-12 col-lg-6">
      <div class="chart-wrap h-100">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div><div class="section-title mb-0">Вариант 2 — асинхронный <span class="badge bg-success ms-1" style="font-size:10px;text-transform:none;letter-spacing:0">рекомендуется</span></div></div>
          <button class="btn btn-sm btn-outline-primary copy-btn" data-target="code-async"><i class="bi bi-clipboard"></i> Копировать</button>
        </div>
        <pre id="code-async" class="install-code"><?= htmlspecialchars('<script>' . "\n" . $codeAsync . "\n" . '</script>', ENT_QUOTES, 'UTF-8') ?></pre>
      </div>
    </div>

    <div class="col-12">
      <div class="chart-wrap">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div>
            <div class="section-title mb-0">Вариант 3 — с разбивкой домена</div>
            <div style="font-size:11px;color:#94a3b8">Домен и путь разбиты на части — защита от простых парсеров</div>
          </div>
          <button class="btn btn-sm btn-outline-primary copy-btn" data-target="code-obf"><i class="bi bi-clipboard"></i> Копировать</button>
        </div>
        <pre id="code-obf" class="install-code"><?= htmlspecialchars('<script>' . "\n" . $codeObfuscated . "\n" . '</script>', ENT_QUOTES, 'UTF-8') ?></pre>
      </div>
    </div>

    <div class="col-12">
      <div class="chart-wrap">
        <div class="section-title mb-3">Данные сайта</div>
        <div class="row g-2">
          <?php foreach(['API Key' => $apiKey, 'Script URL' => $scriptUrl, 'Gate URL' => $gateUrl] as $label => $val): ?>
          <div class="col-12">
            <div style="font-size:11px;color:#94a3b8;margin-bottom:3px"><?= $label ?></div>
            <div class="d-flex align-items-center gap-2">
              <code class="flex-grow-1 p-2" style="background:#f1f5f9;border-radius:6px;font-size:12px;display:block;word-break:break-all"><?= esc($val) ?></code>
              <button class="btn btn-sm btn-outline-secondary copy-btn flex-shrink-0" data-value="<?= esc($val) ?>"><i class="bi bi-clipboard"></i></button>
            </div>
          </div>
          <?php endforeach ?>
        </div>
      </div>
    </div>
  </div>

<?php endif ?>

</div>

<!-- Edit Site Modal -->
<div class="modal fade" id="editSiteModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title fs-6">Редактировать сайт</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div class="mb-3"><label class="form-label" style="font-size:12px;font-weight:600">Название</label><input type="text" id="edit-name" class="form-control form-control-sm" value="<?= esc($site['name']) ?>"></div>
      <div class="mb-3"><label class="form-label" style="font-size:12px;font-weight:600">Домен</label><input type="text" id="edit-domain" class="form-control form-control-sm" placeholder="example.com" value="<?= esc($site['domain']) ?>"><div class="form-text" style="font-size:11px">Без www., без http://</div></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Отмена</button>
      <button type="button" class="btn btn-primary btn-sm" onclick="saveSite()">Сохранить</button>
    </div>
  </div></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
var SITE_ID = <?= $siteId ?>;

/* Color picker sync */
<?php foreach(['A','B','C'] as $pv): ?>
(function(){
  var cp=document.getElementById('cp<?= $pv ?>'), ct=document.getElementById('ct<?= $pv ?>');
  if(!cp||!ct) return;
  cp.addEventListener('input',function(){ ct.value=cp.value; });
  ct.addEventListener('input',function(){ if(/^#[0-9a-fA-F]{6}$/.test(ct.value)) cp.value=ct.value; });
})();
<?php endforeach ?>

/* Копирование */
document.querySelectorAll('.copy-btn').forEach(function(btn){
  btn.addEventListener('click', function(){
    var text = btn.dataset.value || (btn.dataset.target ? document.getElementById(btn.dataset.target)?.textContent : '');
    if (!text) return;
    navigator.clipboard.writeText(text).then(function(){
      var orig = btn.innerHTML;
      btn.classList.add('copied'); btn.innerHTML = '<i class="bi bi-check2"></i> Скопировано';
      setTimeout(function(){ btn.classList.remove('copied'); btn.innerHTML = orig; }, 1800);
    });
  });
});

/* Очистить статистику */
var btnClear = document.getElementById('btn-clear');
if (btnClear) btnClear.addEventListener('click', function(){
  if (!confirm('Удалить всю статистику этого сайта?')) return;
  var fd = new FormData(); fd.append('action','clear_stats'); fd.append('site_id', SITE_ID);
  fetch(window.location.pathname, {method:'POST',body:fd}).then(r=>r.json()).then(d=>{ if(d.ok) location.reload(); });
});

/* Редактировать сайт */
function openEditModal() { new bootstrap.Modal(document.getElementById('editSiteModal')).show(); }
function saveSite() {
  var fd = new FormData();
  fd.append('action','update_site'); fd.append('id', SITE_ID);
  fd.append('name', document.getElementById('edit-name').value.trim());
  fd.append('domain', document.getElementById('edit-domain').value.trim());
  fetch(window.location.pathname, {method:'POST',body:fd}).then(r=>r.json()).then(d=>{ if(d.ok) location.reload(); });
}

/* Интеграции */
function intAlert(msg, ok) {
  var el = document.getElementById('int-alert');
  if (!el) return;
  el.className = 'alert mb-3 ' + (ok ? 'alert-success' : 'alert-danger');
  el.textContent = msg; el.classList.remove('d-none');
  setTimeout(function(){ el.classList.add('d-none'); }, 4000);
}
function saveIntegration(type, btn) {
  var orig = btn.innerHTML; btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
  var fd = new FormData();
  fd.append('action','save_integrations'); fd.append('site_id', SITE_ID); fd.append('type', type);
  if (type === 'telegram') {
    fd.append('enabled', document.getElementById('tg-enabled').checked ? '1' : '0');
    fd.append('tg_token', document.getElementById('tg-token').value.trim());
    fd.append('tg_chat_id', document.getElementById('tg-chat-id').value.trim());
  } else if (type === 'bitrix24') {
    fd.append('enabled', document.getElementById('b24-enabled').checked ? '1' : '0');
    fd.append('b24_webhook', document.getElementById('b24-webhook').value.trim());
    document.querySelectorAll('.cf-row').forEach(function(row){
      fd.append('cf_key[]', row.querySelector('[name="cf_key[]"]').value.trim());
      fd.append('cf_value[]', row.querySelector('[name="cf_value[]"]').value.trim());
    });
  } else if (type === 'yandex_metrika') {
    fd.append('enabled', document.getElementById('ym-enabled').checked ? '1' : '0');
    fd.append('ym_counter_id', document.getElementById('ym-counter-id').value.trim());
    fd.append('ym_goal_open', document.getElementById('ym-goal-open').value.trim());
    fd.append('ym_goal_lead', document.getElementById('ym-goal-lead').value.trim());
  }
  fetch(window.location.pathname, {method:'POST',body:fd}).then(r=>r.json()).then(d=>{
    btn.disabled=false; btn.innerHTML=orig;
    intAlert(d.ok ? 'Сохранено!' : ('Ошибка: '+(d.error||'?')), d.ok);
  }).catch(()=>{ btn.disabled=false; btn.innerHTML=orig; intAlert('Ошибка сети',false); });
}
function testTelegram(btn) {
  var orig=btn.innerHTML; btn.disabled=true; btn.innerHTML='<span class="spinner-border spinner-border-sm"></span>';
  var fd=new FormData(); fd.append('action','test_telegram');
  fd.append('tg_token', document.getElementById('tg-token').value.trim());
  fd.append('tg_chat_id', document.getElementById('tg-chat-id').value.trim());
  fetch(window.location.pathname,{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
    btn.disabled=false; btn.innerHTML=orig;
    intAlert(d.ok?'✅ Сообщение отправлено!':'❌ '+(d.error||'Ошибка'),d.ok);
  }).catch(()=>{ btn.disabled=false; btn.innerHTML=orig; intAlert('Ошибка сети',false); });
}
function testBitrix24(btn) {
  var orig=btn.innerHTML; btn.disabled=true; btn.innerHTML='<span class="spinner-border spinner-border-sm"></span>';
  var fd=new FormData(); fd.append('action','test_bitrix24');
  fd.append('b24_webhook', document.getElementById('b24-webhook').value.trim());
  document.querySelectorAll('.cf-row').forEach(function(row){
    fd.append('cf_key[]', row.querySelector('[name="cf_key[]"]').value.trim());
    fd.append('cf_value[]', row.querySelector('[name="cf_value[]"]').value.trim());
  });
  fetch(window.location.pathname,{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
    btn.disabled=false; btn.innerHTML=orig;
    if (d.ok) {
      intAlert('✅ Тестовый лид создан в Bitrix24!', true);
    } else {
      var msg = '❌ ' + (d.error || 'Ошибка');
      if (d.b24_response) msg += ' · ' + JSON.stringify(d.b24_response);
      intAlert(msg, false);
    }
  }).catch(()=>{ btn.disabled=false; btn.innerHTML=orig; intAlert('Ошибка сети',false); });
}
function addCfRow(key,val) {
  var row=document.createElement('div'); row.className='d-flex gap-1 mb-1 cf-row';
  row.innerHTML='<input class="form-control form-control-sm" name="cf_key[]" placeholder="UF_CRM_..." value="'+(key||'')+'" style="font-size:12px">'
    +'<input class="form-control form-control-sm" name="cf_value[]" placeholder="{{macro}}" value="'+(val||'')+'" style="font-size:12px">'
    +'<button type="button" class="btn btn-sm btn-outline-danger" style="padding:2px 6px" onclick="this.closest(\'.cf-row\').remove()">✕</button>';
  document.getElementById('cf-rows').appendChild(row);
}
</script>
</body>
</html>
<?php

/* ════════════════════════════════════════════════════
   СПИСОК САЙТОВ (главная страница)
   ════════════════════════════════════════════════════ */
} else {

    $sites = R::getAll("
        SELECT s.*,
               COALESCE(po.cnt,0) AS opens_count,
               COALESCE(pl.cnt,0) AS leads_count
        FROM sites s
        LEFT JOIN (SELECT site_id, COUNT(*) AS cnt FROM popup_opens GROUP BY site_id) po ON po.site_id=s.id
        LEFT JOIN (SELECT site_id, COUNT(*) AS cnt FROM popup_leads GROUP BY site_id) pl ON pl.site_id=s.id
        ORDER BY s.created_at DESC
    ");

    R::close();

    function esc($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Exit Intent · Сайты</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
<style>
  html,body{height:100%}
  body{background:#f3f7fb;color:#0f172a;font-size:13px;font-family:'Segoe UI',system-ui,sans-serif}
  .ct-header{background:#fff;border-bottom:1px solid #dbeafe;padding:0 24px;height:52px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;box-shadow:0 1px 4px rgba(15,23,42,.05)}
  .ct-logo{display:flex;align-items:center;gap:10px;font-weight:700;font-size:15px;color:#1d4ed8;letter-spacing:-.3px}
  .ct-logo .dot{width:28px;height:28px;background:linear-gradient(135deg,#3b82f6,#1d4ed8);border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:14px}
  .ct-table{border-radius:10px;overflow:hidden;box-shadow:0 2px 8px rgba(15,23,42,.05)}
  .ct-table thead th{background:#f8faff;color:#475569;font-weight:600;border-bottom:2px solid #dbeafe;font-size:12px;padding:10px 14px;white-space:nowrap}
  .ct-table tbody td{padding:11px 14px;vertical-align:middle;border-color:#f0f4f8}
  .ct-table tbody tr:hover td{background:#f8faff}
  .site-row td:first-child{cursor:pointer}
  .key-tag{font-family:monospace;font-size:11px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:4px;padding:2px 8px;color:#475569;letter-spacing:.04em;max-width:180px;overflow:hidden;text-overflow:ellipsis;display:inline-block;vertical-align:middle}
  .empty-state{text-align:center;padding:64px 16px;color:#94a3b8}
  .empty-state i{font-size:48px;display:block;margin-bottom:16px}
  .badge-on{background:#dcfce7;color:#16a34a;font-size:11px;padding:3px 10px;border-radius:20px;font-weight:600}
  .badge-off{background:#f1f5f9;color:#94a3b8;font-size:11px;padding:3px 10px;border-radius:20px;font-weight:600}
</style>
</head>
<body>

<header class="ct-header">
  <div class="ct-logo">
    <div class="dot"><i class="bi bi-cursor-fill" style="font-size:12px"></i></div>
    Exit Intent
  </div>
  <div style="color:#94a3b8;font-size:11px"><?= date('d.m.Y H:i') ?></div>
</header>

<div class="container-fluid px-3 px-md-4 py-4" style="max-width:960px">

  <div class="d-flex align-items-center justify-content-between mb-4">
    <div>
      <div style="font-size:18px;font-weight:700">Сайты</div>
      <div style="font-size:12px;color:#94a3b8">Каждый сайт — отдельные попапы, интеграции и код установки</div>
    </div>
    <button class="btn btn-primary btn-sm" onclick="openAddModal()">
      <i class="bi bi-plus-lg me-1"></i> Добавить сайт
    </button>
  </div>

  <?php if (empty($sites)): ?>
  <div class="empty-state">
    <i class="bi bi-globe2"></i>
    <p style="font-size:14px;font-weight:600;margin-bottom:8px">Сайтов пока нет</p>
    <p style="font-size:13px">Добавьте первый сайт, чтобы получить код установки и настроить попапы.</p>
    <button class="btn btn-primary mt-3" onclick="openAddModal()"><i class="bi bi-plus-lg me-1"></i>Добавить сайт</button>
  </div>
  <?php else: ?>

  <div class="card border-0 ct-table">
    <table class="table table-hover mb-0 ct-table">
      <thead>
        <tr>
          <th>Домен / Название</th>
          <th>API Key</th>
          <th>Открытий</th>
          <th>Заявок</th>
          <th>Статус</th>
          <th class="text-end">Действия</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($sites as $s): ?>
      <tr class="site-row">
        <td onclick="location.href='?site=<?= $s['id'] ?>'">
          <div style="font-weight:600;color:#1e3a8a;font-size:13px">
            <?= $s['domain'] ? esc($s['domain']) : '<span style="color:#94a3b8;font-style:italic">без домена</span>' ?>
          </div>
          <div style="font-size:11px;color:#94a3b8"><?= esc($s['name']) ?></div>
        </td>
        <td><span class="key-tag" title="<?= esc($s['api_key']) ?>"><?= esc($s['api_key']) ?></span></td>
        <td style="font-weight:600"><?= (int)$s['opens_count'] ?></td>
        <td style="font-weight:600;color:#1db954"><?= (int)$s['leads_count'] ?></td>
        <td>
          <button class="btn p-0 border-0 bg-transparent" onclick="toggleSite(<?= $s['id'] ?>,this)">
            <span class="<?= $s['is_active']?'badge-on':'badge-off' ?>" id="badge-<?= $s['id'] ?>">
              <?= $s['is_active']?'активен':'выключен' ?>
            </span>
          </button>
        </td>
        <td class="text-end" style="white-space:nowrap">
          <a href="?site=<?= $s['id'] ?>&tab=stats" class="btn btn-sm border-0 bg-transparent text-primary" title="Статистика"><i class="bi bi-bar-chart"></i></a>
          <a href="?site=<?= $s['id'] ?>&tab=install" class="btn btn-sm border-0 bg-transparent text-secondary" title="Код установки"><i class="bi bi-code-slash"></i></a>
          <button class="btn btn-sm border-0 bg-transparent text-danger" onclick="deleteSite(<?= $s['id'] ?>)" title="Удалить"><i class="bi bi-trash"></i></button>
        </td>
      </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </div>
  <?php endif ?>

</div>

<!-- Add Site Modal -->
<div class="modal fade" id="addSiteModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title fs-6"><i class="bi bi-plus-lg me-2"></i>Добавить сайт</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div id="add-error" class="alert alert-danger d-none" style="font-size:12px"></div>
      <div class="mb-3">
        <label class="form-label" style="font-size:12px;font-weight:600">Домен <span class="text-danger">*</span></label>
        <input type="text" id="new-domain" class="form-control form-control-sm" placeholder="example.com">
        <div class="form-text" style="font-size:11px">Без www. и без https://</div>
      </div>
      <div class="mb-3">
        <label class="form-label" style="font-size:12px;font-weight:600">Название</label>
        <input type="text" id="new-name" class="form-control form-control-sm" placeholder="Мой интернет-магазин">
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Отмена</button>
      <button type="button" class="btn btn-primary btn-sm" id="btn-create">Создать</button>
    </div>
  </div></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
var _addModal;
function openAddModal() {
  if (!_addModal) _addModal = new bootstrap.Modal(document.getElementById('addSiteModal'));
  document.getElementById('new-domain').value = '';
  document.getElementById('new-name').value = '';
  document.getElementById('add-error').classList.add('d-none');
  _addModal.show();
  setTimeout(function(){ document.getElementById('new-domain').focus(); }, 300);
}

document.getElementById('btn-create').addEventListener('click', function(){
  var domain = document.getElementById('new-domain').value.trim();
  var name   = document.getElementById('new-name').value.trim() || domain;
  if (!domain) { showAddError('Укажите домен'); return; }
  var fd = new FormData();
  fd.append('action','create_site'); fd.append('domain',domain); fd.append('name',name);
  this.disabled = true;
  fetch(window.location.pathname, {method:'POST',body:fd}).then(r=>r.json()).then(d=>{
    this.disabled = false;
    if (d.ok) { window.location.href = '?site=' + d.id + '&tab=install'; }
    else showAddError(d.error || 'Ошибка');
  }).catch(()=>{ this.disabled=false; showAddError('Ошибка сети'); });
});

function showAddError(msg) {
  var el = document.getElementById('add-error');
  el.textContent = msg; el.classList.remove('d-none');
}

function toggleSite(id, btn) {
  var fd = new FormData(); fd.append('action','toggle_site'); fd.append('id',id);
  fetch(window.location.pathname, {method:'POST',body:fd}).then(r=>r.json()).then(d=>{
    if (!d.ok) return;
    var badge = document.getElementById('badge-'+id);
    if (d.active) { badge.className='badge-on'; badge.textContent='активен'; }
    else { badge.className='badge-off'; badge.textContent='выключен'; }
  });
}

function deleteSite(id) {
  if (!confirm('Удалить сайт? Интеграции будут удалены. Статистика останется (site_id станет NULL).')) return;
  var fd = new FormData(); fd.append('action','delete_site'); fd.append('id',id);
  fetch(window.location.pathname, {method:'POST',body:fd}).then(r=>r.json()).then(d=>{ if(d.ok) location.reload(); });
}

/* Enter в поле домена */
document.getElementById('new-domain').addEventListener('keydown', function(e){
  if (e.key==='Enter') { e.preventDefault(); document.getElementById('btn-create').click(); }
});
</script>
</body>
</html>
<?php } ?>
