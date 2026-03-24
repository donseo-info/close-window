<?php
/**
 * gate.php — приём событий от popup.js
 *
 * POST action=open  { variant, ym_client_id, has_ym, url, referrer }
 * POST action=lead  { variant, phone, messenger, ym_client_id, has_ym, url }
 */

require_once dirname(__DIR__) . '/config.php';

/* ── CORS: разрешаем запросы с любого домена ── */
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

db_ensure_init();

/* ── Принимаем POST или GET (для Image-beacon fallback) ── */
$raw = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;

function g(array $src, string $key, string $default = ''): string {
    return trim((string)($src[$key] ?? $default));
}

/* Извлечь домен из URL (без www.) */
function extractDomain(string $url): string {
    if (!$url) return '';
    $host = parse_url($url, PHP_URL_HOST) ?: '';
    return preg_replace('/^www\./i', '', strtolower($host));
}

$action   = g($raw, 'action');
$variant  = strtoupper(g($raw, 'variant'));
$ymId     = g($raw, 'ym_client_id');
$hasYm    = (int)(bool)($raw['has_ym'] ?? 0);
$url      = g($raw, 'url');
$referrer = g($raw, 'referrer');
$domain   = extractDomain($url);

if (!in_array($variant, ['A', 'B', 'C'], true)) {
    echo json_encode(['ok' => false, 'error' => 'bad variant']); exit;
}

/* ── action=open ── */
if ($action === 'open') {
    /* Дедупликация: не пишем если в эту минуту уже есть открытие с тем же ym_client_id */
    if ($ymId) {
        $exists = R::getCell(
            "SELECT COUNT(*) FROM popup_opens
             WHERE ym_client_id = ? AND created_at >= datetime('now','localtime','-1 minute')",
            [$ymId]
        );
        if ($exists) { echo json_encode(['ok' => true, 'dup' => true]); R::close(); exit; }
    }

    R::exec(
        "INSERT INTO popup_opens (variant, domain, ym_client_id, has_ym, url, referrer, created_at)
         VALUES (?, ?, ?, ?, ?, ?, datetime('now','localtime'))",
        [$variant, $domain, $ymId, $hasYm, $url, $referrer]
    );
    $id = R::getCell('SELECT last_insert_rowid()');

    echo json_encode(['ok' => true, 'id' => (int)$id]);
    R::close(); exit;
}

/* ── action=lead ── */
if ($action === 'lead') {
    $phone     = g($raw, 'phone');
    $messenger = g($raw, 'messenger');

    if (!$phone) {
        echo json_encode(['ok' => false, 'error' => 'phone required']); R::close(); exit;
    }

    R::exec(
        "INSERT INTO popup_leads (variant, domain, phone, messenger, ym_client_id, has_ym, url, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now','localtime'))",
        [$variant, $domain, preg_replace('/\D/', '', $phone), $messenger, $ymId, $hasYm, $url]
    );
    $id = R::getCell('SELECT last_insert_rowid()');

    echo json_encode(['ok' => true, 'id' => (int)$id]);
    R::close(); exit;
}

echo json_encode(['ok' => false, 'error' => 'unknown action']);
R::close();
