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
        ym_client_id TEXT    NOT NULL DEFAULT '',
        has_ym       INTEGER NOT NULL DEFAULT 0,
        url          TEXT,
        referrer     TEXT,
        created_at   TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
    )");
    R::exec("CREATE TABLE IF NOT EXISTS popup_leads (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        variant      TEXT    NOT NULL,
        phone        TEXT    NOT NULL,
        messenger    TEXT    DEFAULT '',
        ym_client_id TEXT    NOT NULL DEFAULT '',
        has_ym       INTEGER NOT NULL DEFAULT 0,
        url          TEXT,
        created_at   TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
    )");
    R::freeze(true);
}
