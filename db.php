<?php
function getDB(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $path = __DIR__ . '/data/tasks.db';
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA foreign_keys=ON');

    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        username   TEXT    NOT NULL UNIQUE,
        password   TEXT    NOT NULL,
        created_at TEXT    NOT NULL DEFAULT (datetime('now'))
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS tasks (
        id          TEXT    NOT NULL,
        user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        date        TEXT    NOT NULL,
        title       TEXT    NOT NULL DEFAULT '',
        memo        TEXT    NOT NULL DEFAULT '',
        url         TEXT    NOT NULL DEFAULT '',
        done        INTEGER NOT NULL DEFAULT 0,
        color       TEXT    NOT NULL DEFAULT '#B2DFDB',
        estimate    INTEGER NOT NULL DEFAULT 15,
        actual      INTEGER NOT NULL DEFAULT 0,
        task_order  INTEGER NOT NULL DEFAULT 0,
        start_time  INTEGER NOT NULL DEFAULT 0,
        repeat_days TEXT    NOT NULL DEFAULT '[]',
        PRIMARY KEY (id, user_id)
    )");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tasks_user_date ON tasks(user_id, date)");

    return $pdo;
}

function taskRowToJs(array $row): array {
    return [
        'id'         => $row['id'],
        'date'       => $row['date'],
        'title'      => $row['title'],
        'memo'       => $row['memo'],
        'url'        => $row['url'],
        'done'       => (bool)$row['done'],
        'color'      => $row['color'],
        'estimate'   => (int)$row['estimate'],
        'actual'     => (int)$row['actual'],
        'order'      => (int)$row['task_order'],
        'startTime'  => (int)$row['start_time'],
        'repeatDays' => json_decode($row['repeat_days'], true) ?? [],
    ];
}
