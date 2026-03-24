<?php
require_once dirname(__DIR__) . '/config.php';

db_ensure_init();

/* ── POST: сохранить конфиг попапа и перегенерировать файлы ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_popup') {
    require_once __DIR__ . '/generator.php';
    $variant = strtoupper($_POST['variant'] ?? '');
    $domain  = strtolower(preg_replace('/^www\./i', '', trim($_POST['editor_domain'] ?? '')));
    if (in_array($variant, ['A','B','C'], true)) {
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
        $exists = R::getCell(
            'SELECT COUNT(*) FROM popup_config WHERE domain=? AND variant=?',
            [$domain, $variant]
        );
        if ($exists) {
            R::exec('UPDATE popup_config SET config=?, updated_at=? WHERE domain=? AND variant=?',
                    [$json, $now, $domain, $variant]);
        } else {
            R::exec('INSERT INTO popup_config (domain, variant, config, updated_at) VALUES (?,?,?,?)',
                    [$domain, $variant, $json, $now]);
        }

        $err = null;
        /* Статические файлы перегенерируем только для глобального конфига */
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
        $qs = '?tab=popups&variant=' . $variant . '&editor_domain=' . urlencode($domain);
        if ($err) {
            header('Location: ' . $qs . '&err=' . urlencode($err));
        } else {
            header('Location: ' . $qs . '&saved=1');
        }
        exit;
    }
    R::close();
    header('Location: ?tab=popups');
    exit;
}

/* ── AJAX: сброс статистики ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_stats') {
    header('Content-Type: application/json');
    $df = trim($_POST['domain_filter'] ?? '');
    if ($df) {
        R::exec('DELETE FROM popup_opens WHERE domain=?', [$df]);
        R::exec('DELETE FROM popup_leads WHERE domain=?', [$df]);
    } else {
        R::exec('DELETE FROM popup_opens');
        R::exec('DELETE FROM popup_leads');
    }
    echo json_encode(['ok' => true]);
    R::close(); exit;
}

$tab          = $_GET['tab']          ?? 'dashboard';
$domainFilter = trim($_GET['domain_filter'] ?? '');
$editorDomain = strtolower(preg_replace('/^www\./i', '', trim($_GET['editor_domain'] ?? '')));

/* ── URL проекта (автоопределение) ── */
$proto    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host     = $_SERVER['HTTP_HOST'] ?? 'yourdomain.ru';
$adminDir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/index.php'), '/\\');
$baseDir  = rtrim(dirname($adminDir), '/\\');
$baseUrl  = $proto . '://' . $host . $baseDir;
$scriptUrl = $baseUrl . '/popup.js';
$gateUrl   = $baseUrl . '/api/gate.php';
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset  = ($page - 1) * $perPage;
$today   = date('Y-m-d');

/* ── Список известных доменов ── */
$knownDomains = array_column(R::getAll(
    "SELECT domain FROM (
        SELECT domain FROM popup_opens WHERE domain != ''
        UNION
        SELECT domain FROM popup_leads WHERE domain != ''
     ) AS t ORDER BY domain"
), 'domain');

/* ── Фильтр по домену ── */
$dfWhere  = $domainFilter ? " AND domain = ?" : "";
$dfParams = $domainFilter ? [$domainFilter] : [];

/* ── Общая статистика ── */
$totalOpens    = (int)R::getCell("SELECT COUNT(*) FROM popup_opens  WHERE has_ym=1" . $dfWhere, $dfParams);
$totalLeads    = (int)R::getCell("SELECT COUNT(*) FROM popup_leads  WHERE has_ym=1" . $dfWhere, $dfParams);
$totalLeadsAll = (int)R::getCell("SELECT COUNT(*) FROM popup_leads" . $dfWhere, $dfParams);
$convRate      = $totalOpens > 0 ? round($totalLeads / $totalOpens * 100, 1) : 0;

$opensToday = (int)R::getCell(
    "SELECT COUNT(*) FROM popup_opens WHERE has_ym=1 AND DATE(created_at)=?" . $dfWhere,
    array_merge([$today], $dfParams)
);
$leadsToday = (int)R::getCell(
    "SELECT COUNT(*) FROM popup_leads WHERE has_ym=1 AND DATE(created_at)=?" . $dfWhere,
    array_merge([$today], $dfParams)
);

/* ── По вариантам ── */
$variantStats = [];
foreach (['A', 'B', 'C'] as $v) {
    $vp = array_merge([$v], $dfParams);
    $opens     = (int)R::getCell("SELECT COUNT(*) FROM popup_opens WHERE variant=? AND has_ym=1"  . $dfWhere, $vp);
    $leads     = (int)R::getCell("SELECT COUNT(*) FROM popup_leads WHERE variant=? AND has_ym=1"  . $dfWhere, $vp);
    $leadsNoYm = (int)R::getCell("SELECT COUNT(*) FROM popup_leads WHERE variant=? AND has_ym=0"  . $dfWhere, $vp);
    $variantStats[$v] = [
        'opens'      => $opens,
        'leads'      => $leads,
        'leads_noym' => $leadsNoYm,
        'conv'       => $opens > 0 ? round($leads / $opens * 100, 1) : 0,
    ];
}

/* ── График: открытия за 7 дней ── */
$chartDays  = [];
$chartOpens = [];
$chartLeads = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $chartDays[]  = date('d.m', strtotime($d));
    $dp = array_merge([$d], $dfParams);
    $chartOpens[] = (int)R::getCell("SELECT COUNT(*) FROM popup_opens WHERE DATE(created_at)=? AND has_ym=1" . $dfWhere, $dp);
    $chartLeads[] = (int)R::getCell("SELECT COUNT(*) FROM popup_leads WHERE DATE(created_at)=? AND has_ym=1" . $dfWhere, $dp);
}

/* ── Статистика по доменам (вкладка Домены) ── */
$domainStats = [];
if ($tab === 'domains') {
    $domainStats = R::getAll("
        SELECT domain,
               SUM(CASE WHEN src='o' THEN cnt ELSE 0 END) as opens,
               SUM(CASE WHEN src='l' THEN cnt ELSE 0 END) as leads
        FROM (
            SELECT domain, COUNT(*) as cnt, 'o' as src FROM popup_opens WHERE domain != '' GROUP BY domain
            UNION ALL
            SELECT domain, COUNT(*) as cnt, 'l' as src FROM popup_leads WHERE domain != '' GROUP BY domain
        ) x
        GROUP BY domain
        ORDER BY opens DESC
    ");
    foreach ($domainStats as &$ds) {
        $ds['conv'] = (int)$ds['opens'] > 0 ? round((int)$ds['leads'] / (int)$ds['opens'] * 100, 1) : 0;
    }
    unset($ds);
}

/* ── Конфиги попапов по доменам (для вкладки Домены) ── */
$cfgByDomain = [];
if ($tab === 'domains') {
    $configRows = R::getAll("SELECT domain, variant, updated_at FROM popup_config ORDER BY domain, variant");
    foreach ($configRows as $cr) {
        $cfgByDomain[$cr['domain']][] = $cr['variant'];
    }
}

/* ── Конфиги попапов (для вкладки редактора) ── */
$popupConfigs = [];
if ($tab === 'popups') {
    require_once __DIR__ . '/generator.php';
    foreach (['A','B','C'] as $pv) {
        /* Сначала домен-специфичный конфиг, потом глобальный */
        $row = null;
        if ($editorDomain !== '') {
            $row = R::getRow('SELECT config FROM popup_config WHERE domain=? AND variant=?', [$editorDomain, $pv]);
        }
        if (!$row) {
            $row = R::getRow("SELECT config FROM popup_config WHERE domain='' AND variant=?", [$pv]);
        }
        $saved = ($row && $row['config']) ? json_decode($row['config'], true) : [];
        $popupConfigs[$pv] = array_merge(popupDefaults($pv), $saved ?: []);
    }
}

/* ── Таблица лидов ── */
$leadRows  = [];
$leadTotal = 0;
$leadPages = 1;
if ($tab === 'leads') {
    $search  = trim($_GET['search'] ?? '');
    $vFilter = strtoupper(trim($_GET['variant'] ?? ''));
    $where = []; $params = [];
    if ($domainFilter) {
        $where[]  = 'domain = ?';
        $params[] = $domainFilter;
    }
    if ($search) {
        $where[]  = '(phone LIKE ? OR ym_client_id LIKE ?)';
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
    }
    if (in_array($vFilter, ['A','B','C'], true)) {
        $where[]  = 'variant = ?';
        $params[] = $vFilter;
    }
    $wsql      = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $leadTotal = (int)R::getCell("SELECT COUNT(*) FROM popup_leads {$wsql}", $params);
    $leadPages = max(1, (int)ceil($leadTotal / $perPage));
    $leadRows  = R::getAll(
        "SELECT * FROM popup_leads {$wsql} ORDER BY created_at DESC LIMIT ? OFFSET ?",
        array_merge($params, [$perPage, $offset])
    );
}

R::close();

/* ── Хелперы ── */
function esc($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function fmtPhone($p) {
    $d = preg_replace('/\D/', '', $p ?? '');
    if (strlen($d) === 11 && $d[0] === '7')
        return '+7 ('.substr($d,1,3).') '.substr($d,4,3).'-'.substr($d,7,2).'-'.substr($d,9,2);
    return $p ?: '—';
}
function messengerBadge($m) {
    $map = ['tg'=>['Telegram','#0088cc'],'wa'=>['WhatsApp','#25d366'],'mx'=>['Max','#6c3fff']];
    if (!$m || !isset($map[$m])) return '<span class="text-muted">—</span>';
    [$label,$color] = $map[$m];
    return "<span class='badge' style='background:{$color};font-size:11px'>{$label}</span>";
}
function activeTab($n,$c) { return $n===$c?'active':''; }
function buildUrl($extra=[]) {
    $p = array_merge($_GET, $extra); unset($p['page']);
    return '?'.http_build_query(array_filter($p, fn($v)=>$v!==''));
}
function variantBadge($v) {
    $c = ['A'=>'#e02020','B'=>'#1db954','C'=>'#2563eb'];
    $col = $c[$v] ?? '#888';
    return "<span class='badge' style='background:{$col};font-size:11px'>Вариант {$v}</span>";
}
function domainBadge($d) {
    if (!$d) return '<span class="text-muted" style="font-size:11px">— глобальный —</span>';
    return "<span style='font-size:12px;font-family:monospace;background:#f1f5f9;padding:2px 6px;border-radius:4px'>" . esc($d) . "</span>";
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Exit Intent · Админка</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
<style>
  html,body{height:100%}
  body{background:#f3f7fb;color:#0f172a;font-size:13px;font-family:'Segoe UI',system-ui,sans-serif}

  /* ── Шапка ── */
  .ct-header{background:#fff;border-bottom:1px solid #dbeafe;padding:0 24px;height:52px;
    display:flex;align-items:center;justify-content:space-between;
    position:sticky;top:0;z-index:100;box-shadow:0 1px 4px rgba(15,23,42,.05)}
  .ct-logo{display:flex;align-items:center;gap:10px;font-weight:700;font-size:14px;
    color:#1d4ed8;letter-spacing:-.3px}
  .ct-logo .dot{width:28px;height:28px;background:linear-gradient(135deg,#3b82f6,#1d4ed8);
    border-radius:8px;display:flex;align-items:center;justify-content:center;
    color:#fff;font-size:14px}
  .ct-now{color:#94a3b8;font-size:11px}

  /* ── Фильтр домена ── */
  .domain-bar{background:#fff;border-bottom:1px solid #dbeafe;padding:8px 24px;
    display:flex;align-items:center;gap:10px;font-size:12px}
  .domain-bar label{color:#64748b;font-weight:600;white-space:nowrap}

  /* ── Навигация ── */
  .ct-nav{background:#fff;border-bottom:1px solid #dbeafe;padding:0 24px}
  .ct-nav .nav-link{color:#64748b;font-size:13px;font-weight:500;padding:10px 14px;
    border-bottom:2px solid transparent;border-radius:0;
    display:flex;align-items:center;gap:6px;transition:color .15s,border-color .15s}
  .ct-nav .nav-link:hover{color:#1d4ed8}
  .ct-nav .nav-link.active{color:#1d4ed8;border-bottom-color:#1d4ed8;background:transparent}

  /* ── Карточки дашборда ── */
  .stat-card{background:#fff;border:1px solid #dbeafe;border-radius:12px;padding:20px 22px;
    box-shadow:0 4px 12px rgba(15,23,42,.06);position:relative;overflow:hidden;transition:box-shadow .2s}
  .stat-card:hover{box-shadow:0 6px 20px rgba(15,23,42,.1)}
  .stat-card .stat-icon{position:absolute;top:16px;right:18px;width:36px;height:36px;
    border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px}
  .stat-card .stat-val{font-size:32px;font-weight:700;color:#0f172a;line-height:1;margin-bottom:4px}
  .stat-card .stat-label{font-size:12px;color:#64748b;font-weight:500}
  .stat-card .stat-sub{font-size:11px;color:#94a3b8;margin-top:4px}

  /* ── Таблицы ── */
  .ct-table{border-radius:10px;overflow:hidden;box-shadow:0 2px 8px rgba(15,23,42,.05)}
  .ct-table thead th{background:#f8faff;color:#475569;font-weight:600;
    border-bottom:2px solid #dbeafe;font-size:12px;padding:10px 14px;white-space:nowrap}
  .ct-table tbody td{padding:9px 14px;vertical-align:middle;border-color:#f0f4f8}
  .ct-table tbody tr:hover td{background:#f8faff}

  /* ── Variant-бар в таблице ── */
  .vbar{height:6px;border-radius:3px;background:#e5e7eb;overflow:hidden;min-width:60px}
  .vbar-fill{height:100%;border-radius:3px;transition:width .5s ease}

  /* ── Chart ── */
  .chart-wrap{background:#fff;border:1px solid #dbeafe;border-radius:12px;
    padding:20px;box-shadow:0 4px 12px rgba(15,23,42,.06)}
  .chart-canvas{position:relative;height:120px;display:flex;align-items:flex-end;gap:4px}
  .chart-bar-group{flex:1;display:flex;gap:2px;align-items:flex-end;justify-content:center}
  .chart-bar{border-radius:3px 3px 0 0;min-height:4px;transition:height .5s ease}
  .chart-labels{display:flex;gap:4px;margin-top:6px}
  .chart-label{flex:1;text-align:center;font-size:10px;color:#94a3b8}

  /* ── Раздел ── */
  .section-title{font-size:12px;font-weight:700;color:#94a3b8;
    letter-spacing:.08em;text-transform:uppercase;margin-bottom:12px}

  /* ── Пустой стейт ── */
  .empty-state{text-align:center;padding:48px 16px;color:#94a3b8}
  .empty-state i{font-size:40px;display:block;margin-bottom:12px}
  .empty-state p{font-size:13px}

  /* ── Блок кода установки ── */
  .install-code{background:#0f172a;color:#e2e8f0;border-radius:8px;padding:16px;
    font-size:12px;line-height:1.7;overflow-x:auto;white-space:pre;margin:0;
    font-family:'Cascadia Code','Fira Code','Courier New',monospace}
  .copy-btn.copied{background:#1db954;border-color:#1db954;color:#fff}

  /* ── Domain filter badge ── */
  .filter-badge{background:#dbeafe;color:#1d4ed8;padding:2px 10px;border-radius:20px;
    font-size:11px;font-weight:600;display:inline-flex;align-items:center;gap:4px}
</style>
</head>
<body>

<!-- ── Шапка ── -->
<header class="ct-header">
  <div class="ct-logo">
    <div class="dot"><i class="bi bi-cursor-fill" style="font-size:12px"></i></div>
    Exit Intent · Статистика попапов
  </div>
  <div class="ct-now"><?= date('d.m.Y H:i') ?></div>
</header>

<!-- ── Навигация ── -->
<nav class="ct-nav">
  <ul class="nav">
    <li class="nav-item">
      <a class="nav-link <?= activeTab('dashboard',$tab) ?>" href="?tab=dashboard">
        <i class="bi bi-grid-1x2"></i> Дашборд
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= activeTab('leads',$tab) ?>" href="?tab=leads<?= $domainFilter ? '&domain_filter='.urlencode($domainFilter) : '' ?>">
        <i class="bi bi-person-lines-fill"></i> Заявки
        <?php if ($totalLeadsAll > 0): ?>
          <span class="badge bg-primary ms-1" style="font-size:10px"><?= $totalLeadsAll ?></span>
        <?php endif ?>
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= activeTab('domains',$tab) ?>" href="?tab=domains">
        <i class="bi bi-globe2"></i> Домены
        <?php if (count($knownDomains) > 0): ?>
          <span class="badge bg-secondary ms-1" style="font-size:10px"><?= count($knownDomains) ?></span>
        <?php endif ?>
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= activeTab('install',$tab) ?>" href="?tab=install">
        <i class="bi bi-code-slash"></i> Установка
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= activeTab('popups',$tab) ?>" href="?tab=popups">
        <i class="bi bi-pencil-square"></i> Попапы
      </a>
    </li>
  </ul>
</nav>

<!-- ── Строка фильтра по домену (для дашборда и заявок) ── -->
<?php if (in_array($tab, ['dashboard','leads'], true)): ?>
<div class="domain-bar">
  <label><i class="bi bi-funnel me-1"></i>Домен:</label>
  <form method="get" class="d-flex gap-2 align-items-center">
    <input type="hidden" name="tab" value="<?= esc($tab) ?>">
    <select name="domain_filter" class="form-select form-select-sm" style="max-width:220px"
            onchange="this.form.submit()">
      <option value="">— Все домены —</option>
      <?php foreach ($knownDomains as $kd): ?>
        <option value="<?= esc($kd) ?>" <?= $domainFilter===$kd?'selected':'' ?>><?= esc($kd) ?></option>
      <?php endforeach ?>
    </select>
    <?php if ($domainFilter): ?>
      <span class="filter-badge">
        <i class="bi bi-globe2"></i> <?= esc($domainFilter) ?>
        <a href="?tab=<?= esc($tab) ?>" class="text-decoration-none ms-1" style="color:inherit">✕</a>
      </span>
    <?php endif ?>
  </form>
</div>
<?php endif ?>

<div class="container-fluid px-3 px-md-4 py-4" style="max-width:1200px">

<?php if ($tab === 'dashboard'): ?>

  <!-- ── Карточки ── -->
  <div class="row g-3 mb-4">

    <div class="col-6 col-md-3">
      <div class="stat-card">
        <div class="stat-icon bg-primary bg-opacity-10 text-primary">
          <i class="bi bi-eye"></i>
        </div>
        <div class="stat-val"><?= $totalOpens ?></div>
        <div class="stat-label">Всего открытий</div>
        <div class="stat-sub">Сегодня: <?= $opensToday ?></div>
      </div>
    </div>

    <div class="col-6 col-md-3">
      <div class="stat-card">
        <div class="stat-icon bg-success bg-opacity-10 text-success">
          <i class="bi bi-person-check"></i>
        </div>
        <div class="stat-val"><?= $totalLeads ?></div>
        <div class="stat-label">Заявок (с Метрикой)</div>
        <div class="stat-sub">Сегодня: <?= $leadsToday ?></div>
      </div>
    </div>

    <div class="col-6 col-md-3">
      <div class="stat-card">
        <div class="stat-icon bg-warning bg-opacity-10 text-warning">
          <i class="bi bi-graph-up-arrow"></i>
        </div>
        <div class="stat-val"><?= $convRate ?>%</div>
        <div class="stat-label">Конверсия</div>
        <div class="stat-sub">открытия → заявки</div>
      </div>
    </div>

    <div class="col-6 col-md-3">
      <div class="stat-card">
        <div class="stat-icon" style="background:rgba(108,63,255,.1);color:#6c3fff">
          <i class="bi bi-reception-4"></i>
        </div>
        <?php
          $totalLeadsNoYm = array_sum(array_column($variantStats, 'leads_noym'));
        ?>
        <div class="stat-val"><?= $totalLeadsNoYm ?></div>
        <div class="stat-label">Заявок без Метрики</div>
        <div class="stat-sub">не в общей статистике</div>
      </div>
    </div>

  </div>

  <div class="row g-3 mb-4">

    <!-- ── График за 7 дней ── -->
    <div class="col-12 col-md-8">
      <div class="chart-wrap h-100">
        <div class="section-title mb-3">Открытия и заявки — последние 7 дней</div>
        <?php
          $maxVal = max(array_merge($chartOpens, $chartLeads, [1]));
        ?>
        <div class="chart-canvas">
          <?php for ($i = 0; $i < 7; $i++): ?>
          <div class="chart-bar-group">
            <div class="chart-bar"
                 style="width:45%;background:#3b82f6;height:<?= round($chartOpens[$i]/$maxVal*100) ?>%"
                 title="Открытия: <?= $chartOpens[$i] ?>"></div>
            <div class="chart-bar"
                 style="width:45%;background:#1db954;height:<?= round($chartLeads[$i]/$maxVal*100) ?>%"
                 title="Заявки: <?= $chartLeads[$i] ?>"></div>
          </div>
          <?php endfor ?>
        </div>
        <div class="chart-labels">
          <?php foreach ($chartDays as $dl): ?>
          <div class="chart-label"><?= $dl ?></div>
          <?php endforeach ?>
        </div>
        <div class="d-flex gap-3 mt-3">
          <span style="font-size:11px;color:#64748b">
            <span style="display:inline-block;width:10px;height:10px;background:#3b82f6;border-radius:2px;margin-right:4px"></span>Открытия
          </span>
          <span style="font-size:11px;color:#64748b">
            <span style="display:inline-block;width:10px;height:10px;background:#1db954;border-radius:2px;margin-right:4px"></span>Заявки
          </span>
        </div>
      </div>
    </div>

    <!-- ── Сравнение вариантов ── -->
    <div class="col-12 col-md-4">
      <div class="chart-wrap h-100">
        <div class="section-title mb-3">A/B сравнение</div>
        <?php
          $maxOpens = max(array_column($variantStats, 'opens') ?: [1]);
        ?>
        <?php foreach ($variantStats as $v => $vs):
          $colors = ['A'=>'#e02020','B'=>'#1db954','C'=>'#2563eb'];
          $col = $colors[$v] ?? '#888';
          $barW = $maxOpens > 0 ? round($vs['opens']/$maxOpens*100) : 0;
        ?>
        <div class="mb-3">
          <div class="d-flex justify-content-between align-items-center mb-1">
            <span class="fw-600" style="font-size:12px">
              <?= variantBadge($v) ?>
            </span>
            <span style="font-size:11px;color:#475569">
              <?= $vs['opens'] ?> → <?= $vs['leads'] ?>
              <span style="color:<?= $col ?>;font-weight:700"> (<?= $vs['conv'] ?>%)</span>
            </span>
          </div>
          <div class="vbar">
            <div class="vbar-fill" style="width:<?= $barW ?>%;background:<?= $col ?>"></div>
          </div>
        </div>
        <?php endforeach ?>
      </div>
    </div>
  </div>

  <!-- ── Детальная таблица по вариантам ── -->
  <div class="section-title">Детальная статистика по вариантам</div>
  <div class="card border-0 ct-table mb-4">
    <table class="table table-hover mb-0 ct-table">
      <thead>
        <tr>
          <th>Вариант</th>
          <th>Открытий<br><small class="text-muted fw-normal">(с Метрикой)</small></th>
          <th>Заявок<br><small class="text-muted fw-normal">(с Метрикой)</small></th>
          <th>Заявок<br><small class="text-muted fw-normal">(без Метрики)</small></th>
          <th>Конверсия</th>
          <th>Вес в открытиях</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($variantStats as $v => $vs):
        $colors = ['A'=>'#e02020','B'=>'#1db954','C'=>'#2563eb'];
        $col = $colors[$v];
        $barW = $totalOpens > 0 ? round($vs['opens']/$totalOpens*100) : 0;
        $labels = ['A'=>'Скидка −10% + таймер','B'=>'Бесплатный гайд','C'=>'Прогресс + 1 шаг'];
      ?>
      <tr>
        <td>
          <?= variantBadge($v) ?>
          <div style="font-size:11px;color:#94a3b8;margin-top:2px"><?= esc($labels[$v]) ?></div>
        </td>
        <td class="fw-600"><?= $vs['opens'] ?></td>
        <td class="fw-600" style="color:#1db954"><?= $vs['leads'] ?></td>
        <td style="color:#94a3b8"><?= $vs['leads_noym'] ?></td>
        <td>
          <span style="font-weight:700;color:<?= $col ?>"><?= $vs['conv'] ?>%</span>
        </td>
        <td style="min-width:120px">
          <div class="d-flex align-items-center gap-2">
            <div class="vbar flex-grow-1">
              <div class="vbar-fill" style="width:<?= $barW ?>%;background:<?= $col ?>"></div>
            </div>
            <span style="font-size:11px;color:#94a3b8;width:30px"><?= $barW ?>%</span>
          </div>
        </td>
      </tr>
      <?php endforeach ?>
      </tbody>
      <tfoot>
        <tr style="background:#f8faff">
          <td class="fw-600">Итого</td>
          <td class="fw-600"><?= $totalOpens ?></td>
          <td class="fw-600" style="color:#1db954"><?= $totalLeads ?></td>
          <td style="color:#94a3b8"><?= $totalLeadsNoYm ?></td>
          <td><span style="font-weight:700;color:#1d4ed8"><?= $convRate ?>%</span></td>
          <td></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <!-- ── Сброс ── -->
  <div class="text-end">
    <button class="btn btn-sm btn-outline-danger" id="btn-clear">
      <i class="bi bi-trash3"></i>
      <?php if ($domainFilter): ?>
        Сбросить статистику для «<?= esc($domainFilter) ?>»
      <?php else: ?>
        Сбросить всю статистику
      <?php endif ?>
    </button>
  </div>

<?php elseif ($tab === 'leads'): ?>

  <!-- ── Вкладка: заявки ── -->
  <?php
    $search  = trim($_GET['search'] ?? '');
    $vFilter = strtoupper(trim($_GET['variant'] ?? ''));
  ?>
  <div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
    <form method="get" class="d-flex gap-2 flex-wrap" style="flex:1">
      <input type="hidden" name="tab" value="leads">
      <?php if ($domainFilter): ?>
        <input type="hidden" name="domain_filter" value="<?= esc($domainFilter) ?>">
      <?php endif ?>
      <input class="form-control form-control-sm" style="max-width:200px"
             name="search" value="<?= esc($search) ?>" placeholder="Телефон / ClientID…">
      <select class="form-select form-select-sm" style="max-width:160px" name="variant">
        <option value="">Все варианты</option>
        <option value="A" <?= $vFilter==='A'?'selected':'' ?>>Вариант A</option>
        <option value="B" <?= $vFilter==='B'?'selected':'' ?>>Вариант B</option>
        <option value="C" <?= $vFilter==='C'?'selected':'' ?>>Вариант C</option>
      </select>
      <button class="btn btn-primary btn-sm" type="submit">
        <i class="bi bi-search"></i> Найти
      </button>
      <?php if ($search || $vFilter): ?>
        <a href="?tab=leads<?= $domainFilter ? '&domain_filter='.urlencode($domainFilter) : '' ?>"
           class="btn btn-outline-secondary btn-sm">Сброс</a>
      <?php endif ?>
    </form>
    <div style="font-size:12px;color:#94a3b8">
      Найдено: <?= $leadTotal ?>
    </div>
  </div>

  <?php if (empty($leadRows)): ?>
    <div class="empty-state">
      <i class="bi bi-inbox"></i>
      <p>Заявок пока нет.</p>
    </div>
  <?php else: ?>
  <div class="card border-0 ct-table mb-3">
    <table class="table table-hover mb-0 ct-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Дата</th>
          <th>Домен</th>
          <th>Вариант</th>
          <th>Телефон</th>
          <th>Мессенджер</th>
          <th>Яндекс ClientID</th>
          <th>Метрика</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($leadRows as $r): ?>
      <tr>
        <td style="color:#94a3b8"><?= esc($r['id']) ?></td>
        <td style="white-space:nowrap"><?= esc($r['created_at']) ?></td>
        <td>
          <?php if ($r['domain']): ?>
            <a href="?tab=leads&domain_filter=<?= urlencode($r['domain']) ?>"
               style="font-size:11px;font-family:monospace;color:#2563eb;text-decoration:none">
              <?= esc($r['domain']) ?>
            </a>
          <?php else: ?>
            <span class="text-muted">—</span>
          <?php endif ?>
        </td>
        <td><?= variantBadge($r['variant']) ?></td>
        <td class="fw-600" style="white-space:nowrap"><?= fmtPhone($r['phone']) ?></td>
        <td><?= messengerBadge($r['messenger']) ?></td>
        <td>
          <?php if ($r['ym_client_id']): ?>
            <code style="font-size:11px"><?= esc($r['ym_client_id']) ?></code>
          <?php else: ?>
            <span class="text-muted">—</span>
          <?php endif ?>
        </td>
        <td>
          <?php if ($r['has_ym']): ?>
            <span class="badge bg-success" style="font-size:10px">✓ есть</span>
          <?php else: ?>
            <span class="badge bg-secondary" style="font-size:10px">нет</span>
          <?php endif ?>
        </td>
      </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </div>

  <!-- ── Пагинация ── -->
  <?php if ($leadPages > 1): ?>
  <nav>
    <ul class="pagination pagination-sm justify-content-center">
      <?php for ($p = 1; $p <= $leadPages; $p++): ?>
      <li class="page-item <?= $p===$page?'active':'' ?>">
        <a class="page-link" href="<?= buildUrl(['tab'=>'leads','page'=>$p]) ?>"><?= $p ?></a>
      </li>
      <?php endfor ?>
    </ul>
  </nav>
  <?php endif ?>

  <?php endif ?>

<?php elseif ($tab === 'domains'): ?>

  <!-- ── Вкладка: домены ── -->
  <div class="d-flex align-items-center justify-content-between mb-4">
    <div>
      <div style="font-size:16px;font-weight:700;color:#0f172a">Статистика по доменам</div>
      <div style="font-size:12px;color:#94a3b8">Сайты, на которых установлен попап</div>
    </div>
    <a href="?tab=popups" class="btn btn-primary btn-sm">
      <i class="bi bi-pencil-square me-1"></i> Настроить попапы
    </a>
  </div>

  <?php if (empty($domainStats)): ?>
    <div class="empty-state">
      <i class="bi bi-globe2"></i>
      <p>Данных пока нет. Установите попап на сайт и подождите первых показов.</p>
    </div>
  <?php else: ?>
  <?php
    $maxDomainOpens = max(array_column($domainStats, 'opens') ?: [1]);
  ?>
  <div class="card border-0 ct-table mb-4">
    <table class="table table-hover mb-0 ct-table">
      <thead>
        <tr>
          <th>Домен</th>
          <th>Открытий<br><small class="text-muted fw-normal">(с Метрикой)</small></th>
          <th>Заявок<br><small class="text-muted fw-normal">(с Метрикой)</small></th>
          <th>Конверсия</th>
          <th>Активность</th>
          <th>Действия</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($domainStats as $ds): ?>
      <?php
        $barW = $maxDomainOpens > 0 ? round($ds['opens'] / $maxDomainOpens * 100) : 0;
        $convColor = $ds['conv'] >= 5 ? '#1db954' : ($ds['conv'] >= 2 ? '#f59e0b' : '#94a3b8');
      ?>
      <tr>
        <td>
          <div style="font-family:monospace;font-size:13px;font-weight:600">
            <?= $ds['domain'] ? esc($ds['domain']) : '<span class="text-muted">—</span>' ?>
          </div>
        </td>
        <td class="fw-600"><?= $ds['opens'] ?></td>
        <td class="fw-600" style="color:#1db954"><?= $ds['leads'] ?></td>
        <td>
          <span style="font-weight:700;color:<?= $convColor ?>"><?= $ds['conv'] ?>%</span>
        </td>
        <td style="min-width:120px">
          <div class="d-flex align-items-center gap-2">
            <div class="vbar flex-grow-1">
              <div class="vbar-fill" style="width:<?= $barW ?>%;background:#3b82f6"></div>
            </div>
            <span style="font-size:11px;color:#94a3b8;width:30px"><?= $barW ?>%</span>
          </div>
        </td>
        <td>
          <div class="d-flex gap-1">
            <a href="?tab=dashboard&domain_filter=<?= urlencode($ds['domain']) ?>"
               class="btn btn-sm btn-outline-primary" title="Статистика">
              <i class="bi bi-bar-chart"></i>
            </a>
            <?php if ($ds['domain']): ?>
            <a href="?tab=popups&editor_domain=<?= urlencode($ds['domain']) ?>"
               class="btn btn-sm btn-outline-secondary" title="Настроить попапы">
              <i class="bi bi-pencil"></i>
            </a>
            <?php endif ?>
          </div>
        </td>
      </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </div>

  <!-- ── Сводка по конфигам ── -->
  <div class="chart-wrap">
    <div class="section-title mb-3">Конфигурации попапов по доменам</div>
    <div style="font-size:12px;color:#64748b;margin-bottom:16px">
      Домен-специфичный конфиг перекрывает глобальный. Если для домена конфига нет — используется глобальный.
    </div>
    <?php if (empty($cfgByDomain)): ?>
      <div class="text-muted" style="font-size:12px">Конфигурации ещё не настроены.</div>
    <?php else: ?>
    <div class="row g-2">
      <?php foreach ($cfgByDomain as $cfgDomain => $variants): ?>
      <div class="col-12 col-md-6 col-lg-4">
        <div style="border:1px solid #dbeafe;border-radius:8px;padding:12px">
          <div class="d-flex align-items-center gap-2 mb-2">
            <?= domainBadge($cfgDomain) ?>
          </div>
          <div class="d-flex gap-1 flex-wrap">
            <?php foreach ($variants as $cv): ?>
              <?= variantBadge($cv) ?>
            <?php endforeach ?>
          </div>
          <div class="mt-2">
            <a href="?tab=popups&editor_domain=<?= urlencode($cfgDomain) ?>"
               style="font-size:11px;color:#2563eb">
              <i class="bi bi-pencil"></i> Редактировать
            </a>
          </div>
        </div>
      </div>
      <?php endforeach ?>
    </div>
    <?php endif ?>
  </div>
  <?php endif ?>

<?php elseif ($tab === 'install'): ?>

  <!-- ── Вкладка: установка ── -->
  <?php
    $codeAsync = '(function(w,d,s,u,g,c){' . "\n" .
      '  w._EI={gate:g,counter:c};' . "\n" .
      '  var el=d.createElement(s);el.async=1;el.src=u;' . "\n" .
      '  d.head.appendChild(el);' . "\n" .
      '})(window,document,\'script\',' . "\n" .
      '  \'' . $scriptUrl . '\',' . "\n" .
      '  \'' . $gateUrl . '\',' . "\n" .
      '  \'XXXXXXXX\');';

    $codeGtm = '<script>' . "\n" .
      '(function(w,d,s,u,g,c){' . "\n" .
      '  w._EI={gate:g,counter:c};' . "\n" .
      '  var el=d.createElement(s);el.async=1;el.src=u;' . "\n" .
      '  d.head.appendChild(el);' . "\n" .
      '})(window,document,\'script\',' . "\n" .
      '  \'' . $scriptUrl . '\',' . "\n" .
      '  \'' . $gateUrl . '\',' . "\n" .
      '  \'XXXXXXXX\');' . "\n" .
      '</script>';
  ?>

  <div class="row g-3">

    <div class="col-12">
      <div class="chart-wrap" style="border-left:4px solid #3b82f6">
        <div class="d-flex align-items-start gap-3">
          <div style="font-size:24px;color:#3b82f6;line-height:1"><i class="bi bi-info-circle-fill"></i></div>
          <div>
            <div style="font-weight:600;margin-bottom:4px">Как подключить попап на сайт</div>
            <div style="color:#64748b;font-size:12px;line-height:1.6">
              Вставьте один из вариантов кода перед закрывающим тегом <code>&lt;/body&gt;</code>.
              Замените <code>XXXXXXXX</code> на номер вашего счётчика Яндекс.Метрики.
              Попап автоматически определяет домен сайта и подгружает нужный конфиг.
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Вариант 1: простой тег -->
    <div class="col-12 col-lg-6">
      <div class="chart-wrap h-100">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div>
            <div class="section-title mb-0">Вариант 1 — простой</div>
            <div style="font-size:11px;color:#94a3b8">Обычный тег &lt;script&gt;</div>
          </div>
          <button class="btn btn-sm btn-outline-primary copy-btn" data-target="code-simple">
            <i class="bi bi-clipboard"></i> Копировать
          </button>
        </div>
        <pre id="code-simple" class="install-code"><?= htmlspecialchars('<script src="' . $scriptUrl . '"' . "\n" . '        data-gate="' . $gateUrl . '"' . "\n" . '        data-counter="XXXXXXXX"></script>', ENT_QUOTES, 'UTF-8') ?></pre>
      </div>
    </div>

    <!-- Вариант 2: асинхронный (рекомендуется) -->
    <div class="col-12 col-lg-6">
      <div class="chart-wrap h-100">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div>
            <div class="section-title mb-0">Вариант 2 — асинхронный <span class="badge bg-success ms-1" style="font-size:10px;font-weight:600;text-transform:none;letter-spacing:0">рекомендуется</span></div>
            <div style="font-size:11px;color:#94a3b8">Не блокирует загрузку страницы</div>
          </div>
          <button class="btn btn-sm btn-outline-primary copy-btn" data-target="code-async">
            <i class="bi bi-clipboard"></i> Копировать
          </button>
        </div>
        <pre id="code-async" class="install-code"><?= htmlspecialchars('<script>' . "\n" . $codeAsync . "\n" . '</script>', ENT_QUOTES, 'UTF-8') ?></pre>
      </div>
    </div>

    <!-- Вариант 3: для GTM -->
    <div class="col-12">
      <div class="chart-wrap">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div>
            <div class="section-title mb-0">Вариант 3 — через Google Tag Manager</div>
            <div style="font-size:11px;color:#94a3b8">Custom HTML тег в GTM → All Pages триггер</div>
          </div>
          <button class="btn btn-sm btn-outline-primary copy-btn" data-target="code-gtm">
            <i class="bi bi-clipboard"></i> Копировать
          </button>
        </div>
        <pre id="code-gtm" class="install-code"><?= htmlspecialchars($codeGtm, ENT_QUOTES, 'UTF-8') ?></pre>
        <div class="mt-3 p-3" style="background:#f8faff;border-radius:8px;font-size:12px;color:#475569">
          <strong>Шаги в GTM:</strong>
          <ol class="mb-0 mt-1" style="padding-left:18px;line-height:1.8">
            <li>Теги → Создать → Custom HTML</li>
            <li>Вставить код выше, заменить <code>XXXXXXXX</code></li>
            <li>Триггер: All Pages</li>
            <li>Сохранить и опубликовать</li>
          </ol>
        </div>
      </div>
    </div>

    <!-- Текущие URL -->
    <div class="col-12">
      <div class="chart-wrap">
        <div class="section-title mb-3">Адреса файлов (текущий сервер)</div>
        <div class="row g-2">
          <div class="col-12 col-md-6">
            <div style="font-size:11px;color:#94a3b8;margin-bottom:4px">Скрипт попапа</div>
            <div class="d-flex align-items-center gap-2">
              <code class="flex-grow-1 p-2" style="background:#f1f5f9;border-radius:6px;font-size:12px;display:block;word-break:break-all"><?= esc($scriptUrl) ?></code>
              <button class="btn btn-sm btn-outline-secondary copy-btn flex-shrink-0" data-value="<?= esc($scriptUrl) ?>">
                <i class="bi bi-clipboard"></i>
              </button>
            </div>
          </div>
          <div class="col-12 col-md-6">
            <div style="font-size:11px;color:#94a3b8;margin-bottom:4px">API-эндпоинт (gate)</div>
            <div class="d-flex align-items-center gap-2">
              <code class="flex-grow-1 p-2" style="background:#f1f5f9;border-radius:6px;font-size:12px;display:block;word-break:break-all"><?= esc($gateUrl) ?></code>
              <button class="btn btn-sm btn-outline-secondary copy-btn flex-shrink-0" data-value="<?= esc($gateUrl) ?>">
                <i class="bi bi-clipboard"></i>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

  </div><!-- /row -->

<?php elseif ($tab === 'popups'): ?>

  <?php
    $activeVar = strtoupper($_GET['variant'] ?? 'A');
    if (!in_array($activeVar, ['A','B','C'])) $activeVar = 'A';
    $savedOk  = isset($_GET['saved']);
    $savedErr = $_GET['err'] ?? '';
  ?>

  <?php if ($savedOk): ?>
  <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
    <i class="bi bi-check-circle-fill me-2"></i>
    Конфиг сохранён<?= $editorDomain ? " для домена «{$editorDomain}»" : ' (глобальный)' ?>.
    <?= $editorDomain === '' ? ' Файлы попапа перегенерированы.' : '' ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php endif ?>
  <?php if ($savedErr): ?>
  <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>
    <?= esc($savedErr) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php endif ?>

  <!-- ── Выбор домена для редактирования ── -->
  <div class="chart-wrap mb-4" style="padding:16px 20px">
    <div class="d-flex flex-wrap align-items-center gap-3">
      <div style="font-weight:600;font-size:13px;color:#0f172a;white-space:nowrap">
        <i class="bi bi-globe2 me-1 text-primary"></i> Редактируем конфиг для:
      </div>
      <form method="get" class="d-flex gap-2 align-items-center flex-wrap">
        <input type="hidden" name="tab"     value="popups">
        <input type="hidden" name="variant" value="<?= esc($activeVar) ?>">
        <select name="editor_domain" class="form-select form-select-sm" style="max-width:240px"
                onchange="this.form.submit()">
          <option value="">🌐 Глобальный (для всех доменов)</option>
          <?php foreach ($knownDomains as $kd): ?>
            <option value="<?= esc($kd) ?>" <?= $editorDomain===$kd?'selected':'' ?>><?= esc($kd) ?></option>
          <?php endforeach ?>
        </select>
        <div style="font-size:11px;color:#94a3b8">
          или введите домен вручную:
        </div>
        <div class="input-group input-group-sm" style="max-width:200px">
          <span class="input-group-text" style="font-size:11px">domain.ru</span>
          <input type="text" name="editor_domain_manual" class="form-control"
                 id="manual-domain" placeholder="example.com" style="font-size:12px">
          <button class="btn btn-outline-secondary" type="button" onclick="applyManualDomain()">→</button>
        </div>
      </form>
      <?php if ($editorDomain): ?>
        <span class="filter-badge">
          <i class="bi bi-globe2"></i> <?= esc($editorDomain) ?>
          <a href="?tab=popups&variant=<?= esc($activeVar) ?>"
             class="text-decoration-none ms-1" style="color:inherit">✕</a>
        </span>
        <div style="font-size:11px;color:#f59e0b">
          <i class="bi bi-exclamation-triangle-fill me-1"></i>
          Этот конфиг перекрывает глобальный только для данного домена.
        </div>
      <?php else: ?>
        <div style="font-size:11px;color:#64748b">
          Глобальный конфиг применяется ко всем доменам без индивидуальной настройки.
        </div>
      <?php endif ?>
    </div>
  </div>

  <!-- Sub-tabs A / B / C -->
  <ul class="nav nav-tabs mb-0" id="popupTabs" style="border-bottom:none">
    <?php
      $tabLabels = ['A'=>'Вариант A — Скидка','B'=>'Вариант B — Подарок','C'=>'Вариант C — Прогресс'];
      $tabColors = ['A'=>'#e02020','B'=>'#1db954','C'=>'#2563eb'];
    ?>
    <?php foreach (['A','B','C'] as $pv):
      $isEnabled = ($popupConfigs[$pv]['enabled'] ?? 1) == 1;
    ?>
    <li class="nav-item">
      <button class="nav-link <?= $pv === $activeVar ? 'active' : '' ?>"
              data-bs-toggle="tab" data-bs-target="#ptab<?= $pv ?>"
              style="font-size:13px">
        <span style="display:inline-block;width:8px;height:8px;border-radius:50%;
                     background:<?= $isEnabled ? $tabColors[$pv] : '#cbd5e1' ?>;margin-right:6px"></span>
        <?= $tabLabels[$pv] ?>
        <?php if (!$isEnabled): ?>
          <span class="badge bg-secondary ms-1" style="font-size:10px;font-weight:500">выкл</span>
        <?php endif ?>
      </button>
    </li>
    <?php endforeach ?>
  </ul>

  <div class="tab-content" style="background:#fff;border:1px solid #dbeafe;border-radius:0 8px 8px 8px;padding:24px">
    <?php foreach (['A','B','C'] as $pv):
      $cfg = $popupConfigs[$pv];
    ?>
    <div class="tab-pane fade <?= $pv === $activeVar ? 'show active' : '' ?>" id="ptab<?= $pv ?>">
      <form method="post">
        <input type="hidden" name="action"        value="save_popup">
        <input type="hidden" name="variant"       value="<?= $pv ?>">
        <input type="hidden" name="editor_domain" value="<?= esc($editorDomain) ?>">

        <!-- Блок 1: Оформление -->
        <div class="mb-4">
          <div class="section-title mb-3">Оформление</div>
          <div class="row g-3 align-items-end">

            <div class="col-auto">
              <label class="form-label fw-600" style="font-size:12px">Акцентный цвет</label>
              <div class="d-flex align-items-center gap-2">
                <input type="color" name="color" id="cp<?= $pv ?>"
                       value="<?= esc($cfg['color']) ?>"
                       style="width:44px;height:36px;padding:2px;border-radius:6px;border:1px solid #ddd;cursor:pointer">
                <input type="text" id="ct<?= $pv ?>" value="<?= esc($cfg['color']) ?>"
                       maxlength="7" style="width:88px;font-family:monospace;font-size:13px"
                       class="form-control form-control-sm">
              </div>
            </div>

            <?php if ($pv === 'A'): ?>
            <div class="col-12 col-md-4">
              <label class="form-label fw-600" style="font-size:12px">Бейдж (над заголовком)</label>
              <input type="text" class="form-control form-control-sm" name="badge"
                     value="<?= esc($cfg['badge']) ?>">
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label fw-600" style="font-size:12px">Таймер (сек)</label>
              <input type="number" class="form-control form-control-sm" name="timer"
                     value="<?= (int)$cfg['timer'] ?>" min="30" max="600" step="30">
            </div>
            <?php elseif ($pv === 'C'): ?>
            <div class="col-12 col-md-6">
              <label class="form-label fw-600" style="font-size:12px">Подпись над заголовком</label>
              <input type="text" class="form-control form-control-sm" name="label"
                     value="<?= esc($cfg['label']) ?>">
            </div>
            <?php endif ?>

          </div>
        </div>

        <!-- Блок 2: Тексты попапа -->
        <div class="mb-4">
          <div class="section-title mb-3">Тексты попапа</div>
          <div class="row g-3">

            <div class="col-12">
              <label class="form-label fw-600" style="font-size:12px">
                Заголовок
                <span class="text-muted fw-normal">(допустим &lt;br&gt; для переноса строки)</span>
              </label>
              <textarea class="form-control form-control-sm" name="headline"
                        rows="2"><?= esc($cfg['headline']) ?></textarea>
            </div>

            <?php if (isset($cfg['subtext'])): ?>
            <div class="col-12">
              <label class="form-label fw-600" style="font-size:12px">Подзаголовок / описание</label>
              <input type="text" class="form-control form-control-sm" name="subtext"
                     value="<?= esc($cfg['subtext']) ?>">
            </div>
            <?php endif ?>

            <?php if ($pv === 'B'): ?>
            <div class="col-12 col-md-6">
              <label class="form-label fw-600" style="font-size:12px">Название подарка</label>
              <input type="text" class="form-control form-control-sm" name="gift_name"
                     value="<?= esc($cfg['gift_name']) ?>">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label fw-600" style="font-size:12px">Описание подарка</label>
              <input type="text" class="form-control form-control-sm" name="gift_desc"
                     value="<?= esc($cfg['gift_desc']) ?>">
            </div>
            <?php endif ?>

            <?php if ($pv === 'C'): ?>
            <div class="col-12">
              <label class="form-label fw-600 d-block" style="font-size:12px">
                Чеклист
                <span class="text-muted fw-normal">(✓ = выполнено · ○ = в процессе)</span>
              </label>
              <div class="row g-2">
                <?php for ($ci = 1; $ci <= 5; $ci++): ?>
                <div class="col-12 col-md-6">
                  <div class="input-group input-group-sm">
                    <span class="input-group-text"
                          style="width:36px;justify-content:center;font-size:13px">
                      <?= $ci <= 4 ? '✓' : '○' ?>
                    </span>
                    <input type="text" class="form-control" name="check<?= $ci ?>"
                           value="<?= esc($cfg['check'.$ci]) ?>">
                  </div>
                </div>
                <?php endfor ?>
              </div>
            </div>
            <?php endif ?>

            <div class="col-12 col-md-6">
              <label class="form-label fw-600" style="font-size:12px">Текст кнопки</label>
              <input type="text" class="form-control form-control-sm" name="btn"
                     value="<?= esc($cfg['btn']) ?>">
            </div>

          </div>
        </div>

        <!-- Блок 3: Экран успеха -->
        <div class="mb-4">
          <div class="section-title mb-3">Экран после отправки</div>
          <div class="row g-3">
            <div class="col-12 col-md-6">
              <label class="form-label fw-600" style="font-size:12px">Заголовок</label>
              <input type="text" class="form-control form-control-sm" name="ok_title"
                     value="<?= esc($cfg['ok_title']) ?>">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label fw-600" style="font-size:12px">Текст</label>
              <input type="text" class="form-control form-control-sm" name="ok_text"
                     value="<?= esc($cfg['ok_text']) ?>">
            </div>
          </div>
        </div>

        <!-- Кнопка + toggle -->
        <div class="d-flex align-items-center gap-3 pt-2" style="border-top:1px solid #f0f4f8">
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-lightning-fill me-1"></i>
            Сохранить<?= $editorDomain ? ' для «' . esc($editorDomain) . '»' : ' глобально' ?>
          </button>
          <div class="form-check form-switch mb-0 ms-2">
            <input class="form-check-input" type="checkbox" role="switch"
                   name="enabled" id="en<?= $pv ?>" value="1"
                   <?= ($cfg['enabled'] ?? 1) ? 'checked' : '' ?>>
            <label class="form-check-label" for="en<?= $pv ?>" style="font-size:13px;cursor:pointer">
              Участвует в ротации
            </label>
          </div>
          <?php if ($editorDomain === ''): ?>
          <div style="font-size:11px;color:#94a3b8;margin-left:auto">
            Перезапишет: <code>popup-<?= strtolower($pv) ?>.js</code>,
            <code>popup-<?= strtolower($pv) ?>.min.js</code>,
            <code>popup.min.js</code>
          </div>
          <?php else: ?>
          <div style="font-size:11px;color:#64748b;margin-left:auto">
            <i class="bi bi-info-circle me-1"></i>
            Домен-специфичный конфиг сохраняется только в БД. Попап отдаётся динамически.
          </div>
          <?php endif ?>
        </div>

      </form>
    </div>
    <?php endforeach ?>
  </div>

<?php endif ?>

</div><!-- /container -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* Обновлять время в шапке */
setInterval(function(){
  var el = document.querySelector('.ct-now');
  if (!el) return;
  var d = new Date();
  el.textContent = d.toLocaleDateString('ru-RU') + ' ' +
    String(d.getHours()).padStart(2,'0') + ':' +
    String(d.getMinutes()).padStart(2,'0');
}, 30000);

/* Синхронизация color picker ↔ текстовое поле */
<?php foreach (['A','B','C'] as $pv): ?>
(function(){
  var cp = document.getElementById('cp<?= $pv ?>');
  var ct = document.getElementById('ct<?= $pv ?>');
  if (!cp || !ct) return;
  cp.addEventListener('input', function(){ ct.value = cp.value; });
  ct.addEventListener('input', function(){
    if (/^#[0-9a-fA-F]{6}$/.test(ct.value)) cp.value = ct.value;
  });
})();
<?php endforeach ?>

/* Ввод домена вручную */
function applyManualDomain() {
  var inp = document.getElementById('manual-domain');
  if (!inp || !inp.value.trim()) return;
  var d = inp.value.trim().replace(/^www\./i, '').toLowerCase();
  var url = new URL(window.location.href);
  url.searchParams.set('editor_domain', d);
  window.location.href = url.toString();
}
document.getElementById('manual-domain')?.addEventListener('keydown', function(e){
  if (e.key === 'Enter') { e.preventDefault(); applyManualDomain(); }
});

/* Копирование кода */
document.querySelectorAll('.copy-btn').forEach(function(btn){
  btn.addEventListener('click', function(){
    var target = btn.dataset.target;
    var text   = btn.dataset.value;
    if (target) {
      var el = document.getElementById(target);
      if (el) text = el.textContent;
    }
    if (!text) return;
    navigator.clipboard.writeText(text).then(function(){
      var orig = btn.innerHTML;
      btn.classList.add('copied');
      btn.innerHTML = '<i class="bi bi-check2"></i> Скопировано';
      setTimeout(function(){ btn.classList.remove('copied'); btn.innerHTML = orig; }, 1800);
    });
  });
});

/* Сброс статистики */
var btnClear = document.getElementById('btn-clear');
if (btnClear) {
  btnClear.addEventListener('click', function(){
    if (!confirm('Удалить статистику? Это действие необратимо.')) return;
    var fd = new FormData();
    fd.append('action', 'clear_stats');
    <?php if ($domainFilter): ?>
    fd.append('domain_filter', '<?= esc($domainFilter) ?>');
    <?php endif ?>
    fetch(window.location.pathname, { method:'POST', body: fd })
      .then(function(r){ return r.json(); })
      .then(function(d){ if (d.ok) location.reload(); });
  });
}
</script>
</body>
</html>
