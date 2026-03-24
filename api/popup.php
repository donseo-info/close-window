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

$domain = strtolower(trim($_GET['domain'] ?? ''));
$domain = preg_replace('/^www\./i', '', $domain);

db_ensure_init();

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

$defaults = popupDefaults($variant);
$saved    = ($row && $row['config']) ? json_decode($row['config'], true) : [];
$cfg      = array_merge($defaults, $saved ?: []);

R::close();

/* Если вариант отключён — возвращаем пустой скрипт */
if (($cfg['enabled'] ?? 1) != 1) {
    echo '// disabled';
    exit;
}

$fn = 'generatePopup' . $variant;
echo $fn($cfg);
