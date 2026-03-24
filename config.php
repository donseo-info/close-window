<?php
/**
 * config.php — настройки exit-intent попапов
 */

/* Путь к RedBeanPHP (берём из соседнего call-tracking) */
define('RB_PATH',  __DIR__ . '/rb.php');

/* Путь к SQLite базе данных */
define('DB_PATH',  __DIR__ . '/db/exit_intent.db');

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
}
