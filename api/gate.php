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

/* Извлечь UTM-параметр из URL */
function extractUtm(string $url, string $param): string {
    if (!$url) return '';
    parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $qs);
    return trim($qs[$param] ?? '');
}

/* Отправить уведомление в Telegram */
function send_telegram(string $token, string $chatId, string $phone, string $messenger, string $url, string $domain, string $ymId): void
{
    $msgrLabels = ['tg' => 'Telegram', 'wa' => 'WhatsApp', 'mx' => 'Max'];
    $msgrLabel  = $messenger ? ($msgrLabels[$messenger] ?? $messenger) : null;

    $utmSource   = extractUtm($url, 'utm_source');
    $utmMedium   = extractUtm($url, 'utm_medium');
    $utmCampaign = extractUtm($url, 'utm_campaign');

    $lines = ["🚨 <b>Новая заявка!</b>", ""];
    $lines[] = "📱 <b>Телефон:</b> " . htmlspecialchars($phone);
    if ($msgrLabel) $lines[] = "💬 <b>Мессенджер:</b> " . $msgrLabel;
    $lines[] = "🌐 <b>Сайт:</b> " . htmlspecialchars($domain);
    if ($url) $lines[] = "📄 <b>Страница:</b> " . htmlspecialchars($url);
    if ($utmSource) $lines[] = "🔗 <b>Источник:</b> " . htmlspecialchars($utmSource) . ($utmMedium ? ' / ' . htmlspecialchars($utmMedium) : '');
    if ($utmCampaign) $lines[] = "📊 <b>Кампания:</b> " . htmlspecialchars($utmCampaign);
    if ($ymId) $lines[] = "🔑 <b>YM ClientID:</b> " . htmlspecialchars($ymId);
    $lines[] = "🕐 <b>Время:</b> " . date('d.m.Y H:i:s');

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'https://api.telegram.org/bot' . $token . '/sendMessage',
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_POSTFIELDS     => [
            'chat_id'    => $chatId,
            'text'       => implode("\n", $lines),
            'parse_mode' => 'HTML',
        ],
    ]);
    curl_exec($ch);
    curl_close($ch);
}

/* Создать лид в Bitrix24 */
function send_bitrix24(string $webhook, string $phone, string $messenger, string $url, string $domain, string $ymId, string $ip, array $customFields = []): void
{
    $utmSource   = extractUtm($url, 'utm_source');
    $utmMedium   = extractUtm($url, 'utm_medium');
    $utmCampaign = extractUtm($url, 'utm_campaign');
    $utmContent  = extractUtm($url, 'utm_content');
    $utmTerm     = extractUtm($url, 'utm_term');

    $macros = [
        '{{phone}}'        => $phone,
        '{{page_url}}'     => $url,
        '{{ym_client_id}}' => $ymId,
        '{{messenger}}'    => $messenger,
        '{{ip}}'           => $ip,
        '{{utm_source}}'   => $utmSource,
        '{{utm_medium}}'   => $utmMedium,
        '{{utm_campaign}}' => $utmCampaign,
        '{{utm_content}}'  => $utmContent,
        '{{utm_term}}'     => $utmTerm,
    ];
    foreach ($customFields as $k => $v) {
        $customFields[$k] = str_replace(array_keys($macros), array_values($macros), $v);
    }

    $comments = array_filter([
        $url         ? 'Страница: '   . $url         : '',
        $utmSource   ? 'UTM source: ' . $utmSource   : '',
        $utmMedium   ? 'UTM medium: ' . $utmMedium   : '',
        $utmCampaign ? 'Кампания: '   . $utmCampaign : '',
        $ip          ? 'IP: '         . $ip          : '',
        $messenger   ? 'Мессенджер: ' . $messenger   : '',
    ]);

    $fields = array_merge([
        'TITLE'     => 'Exit Intent | ' . $domain,
        'PHONE'     => [['VALUE' => $phone, 'VALUE_TYPE' => 'WORK']],
        'SOURCE_ID' => 'WEBFORM',
        'COMMENTS'  => implode("\n", $comments),
    ], $customFields);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => rtrim($webhook, '/') . '/crm.lead.add.json',
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_POSTFIELDS     => http_build_query(['fields' => $fields]),
    ]);
    curl_exec($ch);
    curl_close($ch);
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
    /* Регистрируем домен в sites при первом показе */
    if ($domain) getSiteId($domain);

    /* Дедупликация: не пишем если в эту минуту уже есть открытие с тем же ym_client_id */
    if ($ymId) {
        $exists = R::getCell(
            "SELECT COUNT(*) FROM popup_opens
             WHERE ym_client_id = ? AND created_at >= datetime('now','localtime','-1 minute')",
            [$ymId]
        );
        if ($exists) { echo json_encode(['ok' => true, 'dup' => true]); R::close(); exit; }
    }

    $siteId = $domain ? R::getCell('SELECT id FROM sites WHERE domain=?', [$domain]) : null;

    R::exec(
        "INSERT INTO popup_opens (variant, domain, site_id, ym_client_id, has_ym, url, referrer, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now','localtime'))",
        [$variant, $domain, $siteId, $ymId, $hasYm, $url, $referrer]
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

    $cleanPhone = preg_replace('/\D/', '', $phone);
    $siteId     = $domain ? getSiteId($domain) : null;

    R::exec(
        "INSERT INTO popup_leads (variant, domain, site_id, phone, messenger, ym_client_id, has_ym, url, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, datetime('now','localtime'))",
        [$variant, $domain, $siteId, $cleanPhone, $messenger, $ymId, $hasYm, $url]
    );
    $id = R::getCell('SELECT last_insert_rowid()');

    /* Диспатч интеграций */
    if ($siteId) {
        $integrations = R::getAll(
            "SELECT type, config FROM site_integrations WHERE site_id=? AND enabled=1",
            [$siteId]
        );
        foreach ($integrations as $integ) {
            $cfg = json_decode($integ['config'] ?? '{}', true) ?: [];
            if ($integ['type'] === 'telegram') {
                $token  = trim($cfg['tg_token']   ?? '');
                $chatId = trim($cfg['tg_chat_id'] ?? '');
                if ($token && $chatId) {
                    send_telegram($token, $chatId, $cleanPhone, $messenger, $url, $domain, $ymId);
                }
            } elseif ($integ['type'] === 'bitrix24') {
                $webhook      = trim($cfg['b24_webhook']       ?? '');
                $customFields = $cfg['b24_custom_fields'] ?? [];
                if ($webhook) {
                    send_bitrix24($webhook, $cleanPhone, $messenger, $url, $domain, $ymId,
                                  $_SERVER['REMOTE_ADDR'] ?? '', $customFields);
                }
            }
        }
    }

    echo json_encode(['ok' => true, 'id' => (int)$id]);
    R::close(); exit;
}

echo json_encode(['ok' => false, 'error' => 'unknown action']);
R::close();
