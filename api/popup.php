<?php
/**
 * popup.php — динамическая отдача попапа с конфигом для домена
 *
 * GET ?variant=A&domain=example.com
 *
 * Логика:
 *  1. Ищем конфиг для (domain, variant)
 *  2. Если нет — берём глобальный конфиг (domain='')
 *  3. Генерируем JS попапа и отдаём
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/admin/generator.php';

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: public, max-age=300'); /* 5 минут */

$variant = strtoupper(trim($_GET['variant'] ?? ''));
if (!in_array($variant, ['A', 'B', 'C'], true)) {
    http_response_code(400);
    echo '// bad variant';
    exit;
}

$key    = trim($_GET['key'] ?? '');
$domain = strtolower(trim($_GET['domain'] ?? ''));
$domain = preg_replace('/^www\./i', '', $domain);

db_ensure_init();

/* Если передан key — определяем домен и site_id по сайту */
$siteId = null;
if ($key) {
    $site = R::getRow('SELECT id, domain FROM sites WHERE api_key=? AND is_active=1', [$key]);
    if ($site) { $siteId = (int)$site['id']; if (!$domain) $domain = $site['domain']; }
}

/* Ищем конфиг: сначала домен-специфичный, потом глобальный */
$row = null;
if ($domain !== '') {
    $row = R::getRow(
        'SELECT config FROM popup_config WHERE domain=? AND variant=?',
        [$domain, $variant]
    );
}
if (!$row) {
    $row = R::getRow(
        "SELECT config FROM popup_config WHERE domain='' AND variant=?",
        [$variant]
    );
}

/* Цели Яндекс.Метрики для этого сайта */
$ymGoals = ['goal_open' => '', 'goal_lead' => ''];
if ($siteId) {
    $ymRow = R::getRow(
        "SELECT config FROM site_integrations WHERE site_id=? AND type='yandex_metrika' AND enabled=1",
        [$siteId]
    );
    if ($ymRow) {
        $ymCfg = json_decode($ymRow['config'], true) ?: [];
        $ymGoals['goal_open'] = trim($ymCfg['goal_open'] ?? '');
        $ymGoals['goal_lead'] = trim($ymCfg['goal_lead'] ?? '');
    }
}

$defaults = popupDefaults($variant);
$saved    = ($row && $row['config']) ? json_decode($row['config'], true) : [];
$cfg      = array_merge($defaults, $saved ?: []);

R::close();

/* Если вариант отключён — возвращаем пустой скрипт */
if (($cfg['enabled'] ?? 1) != 1) {
    echo '// disabled';
    exit;
}

/* Вшиваем цели ЯМ — popup.js прочитает их из window._EI_ym */
if ($ymGoals['goal_open'] !== '' || $ymGoals['goal_lead'] !== '') {
    echo 'window._EI_ym=' . json_encode($ymGoals, JSON_UNESCAPED_UNICODE) . ';' . "\n";
}

$fn = 'generatePopup' . $variant;
echo $fn($cfg);
