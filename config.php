<?php
/**
 * config.php — настройки exit-intent попапов
 */

/* Путь к RedBeanPHP (берём из соседнего call-tracking) */
define('RB_PATH',  __DIR__ . '/rb.php');

/* Путь к SQLite базе данных */
define('DB_PATH',  __DIR__ . '/db/exit_intent.db');

/* CSRF: секрет для HMAC-токенов (сменить на продакшене) */
define('CSRF_SECRET', 'change-me-on-production-' . md5(__FILE__));

/* Авто-инициализация: создаём директорию и таблицы при первом запуске */
function db_ensure_init() {
    $dir = dirname(DB_PATH);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    require_once RB_PATH;
    R::setup('sqlite:' . DB_PATH);
    R::freeze(false);
    R::exec("CREATE TABLE IF NOT EXISTS popup_opens (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        variant      TEXT    NOT NULL,
        domain       TEXT    NOT NULL DEFAULT '',
        ym_client_id TEXT    NOT NULL DEFAULT '',
        has_ym       INTEGER NOT NULL DEFAULT 0,
        url          TEXT,
        referrer     TEXT,
        created_at   TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
    )");
    R::exec("CREATE TABLE IF NOT EXISTS popup_leads (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        variant      TEXT    NOT NULL,
        domain       TEXT    NOT NULL DEFAULT '',
        phone        TEXT    NOT NULL,
        messenger    TEXT    DEFAULT '',
        ym_client_id TEXT    NOT NULL DEFAULT '',
        has_ym       INTEGER NOT NULL DEFAULT 0,
        url          TEXT,
        created_at   TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
    )");
    R::exec("CREATE TABLE IF NOT EXISTS popup_config (
        domain     TEXT    NOT NULL DEFAULT '',
        variant    TEXT    NOT NULL,
        config     TEXT    NOT NULL DEFAULT '{}',
        updated_at TEXT,
        PRIMARY KEY (domain, variant)
    )");
    R::exec("CREATE TABLE IF NOT EXISTS sites (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        domain     TEXT NOT NULL UNIQUE,
        name       TEXT NOT NULL DEFAULT '',
        api_key    TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    )");
    R::exec("CREATE TABLE IF NOT EXISTS site_integrations (
        id      INTEGER PRIMARY KEY AUTOINCREMENT,
        site_id INTEGER NOT NULL,
        type    TEXT NOT NULL,
        config  TEXT NOT NULL DEFAULT '{}',
        enabled INTEGER NOT NULL DEFAULT 1,
        UNIQUE(site_id, type)
    )");
    db_migrate();
    R::freeze(true);
}

/* Миграция схемы для существующих БД */
function db_migrate() {
    /* Добавляем колонку domain в popup_opens */
    $cols = R::getAll("PRAGMA table_info(popup_opens)");
    if (!array_filter($cols, fn($c) => $c['name'] === 'domain')) {
        R::exec("ALTER TABLE popup_opens ADD COLUMN domain TEXT NOT NULL DEFAULT ''");
    }

    /* Добавляем колонку domain в popup_leads */
    $cols = R::getAll("PRAGMA table_info(popup_leads)");
    if (!array_filter($cols, fn($c) => $c['name'] === 'domain')) {
        R::exec("ALTER TABLE popup_leads ADD COLUMN domain TEXT NOT NULL DEFAULT ''");
    }

    /* Мигрируем popup_config: добавляем колонку domain и меняем PK */
    $cols = R::getAll("PRAGMA table_info(popup_config)");
    if (!array_filter($cols, fn($c) => $c['name'] === 'domain')) {
        R::exec("CREATE TABLE popup_config_v2 (
            domain     TEXT    NOT NULL DEFAULT '',
            variant    TEXT    NOT NULL,
            config     TEXT    NOT NULL DEFAULT '{}',
            updated_at TEXT,
            PRIMARY KEY (domain, variant)
        )");
        R::exec("INSERT INTO popup_config_v2 (domain, variant, config, updated_at)
                 SELECT '', variant, config, updated_at FROM popup_config");
        R::exec("DROP TABLE popup_config");
        R::exec("ALTER TABLE popup_config_v2 RENAME TO popup_config");
    }

    /* Добавляем site_id в popup_leads, popup_opens, popup_config */
    foreach (['popup_leads', 'popup_opens', 'popup_config'] as $tbl) {
        $cols = R::getAll("PRAGMA table_info({$tbl})");
        if (!array_filter($cols, fn($c) => $c['name'] === 'site_id')) {
            R::exec("ALTER TABLE {$tbl} ADD COLUMN site_id INTEGER");
        }
    }

    /* Добавляем is_active, updated_at в sites */
    $cols = R::getAll("PRAGMA table_info(sites)");
    $siteColNames = array_column($cols, 'name');
    if (!in_array('is_active', $siteColNames)) {
        R::exec("ALTER TABLE sites ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1");
    }
    if (!in_array('updated_at', $siteColNames)) {
        R::exec("ALTER TABLE sites ADD COLUMN updated_at TEXT");
    }
}

/* Получить или создать site_id по домену */
function getSiteId(string $domain): ?int {
    if (!$domain) return null;
    $id = R::getCell('SELECT id FROM sites WHERE domain=?', [$domain]);
    if ($id) return (int)$id;
    R::exec('INSERT OR IGNORE INTO sites (domain, api_key) VALUES (?, ?)',
            [$domain, bin2hex(random_bytes(8))]);
    $id = R::getCell('SELECT id FROM sites WHERE domain=?', [$domain]);
    return $id ? (int)$id : null;
}
