<?php
/**
 * init.php — инициализация БД
 * Запустить один раз: php init.php
 */

require_once __DIR__ . '/config.php';
require_once RB_PATH;

R::setup('sqlite:' . DB_PATH);
R::freeze(false);

/* ── popup_opens: показы попапов (только с ym_client_id) ── */
R::exec("CREATE TABLE IF NOT EXISTS popup_opens (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    variant        TEXT    NOT NULL,          -- A, B или C
    ym_client_id   TEXT    NOT NULL DEFAULT '',
    has_ym         INTEGER NOT NULL DEFAULT 0, -- 1 = ym был доступен
    url            TEXT,
    referrer       TEXT,
    created_at     TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
)");

/* ── popup_leads: заявки из форм (все, включая без ym) ── */
R::exec("CREATE TABLE IF NOT EXISTS popup_leads (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    variant        TEXT    NOT NULL,          -- A, B или C
    phone          TEXT    NOT NULL,
    messenger      TEXT    DEFAULT '',        -- tg, wa, mx или пусто
    ym_client_id   TEXT    NOT NULL DEFAULT '',
    has_ym         INTEGER NOT NULL DEFAULT 0,
    url            TEXT,
    created_at     TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
)");

R::close();

echo "БД инициализирована: " . DB_PATH . PHP_EOL;
echo "Таблицы: popup_opens, popup_leads" . PHP_EOL;
