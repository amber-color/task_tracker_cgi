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

    $pdo->exec("CREATE TABLE IF NOT EXISTS repeat_templates (
        id               INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id          INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        title            TEXT    NOT NULL DEFAULT '',
        memo             TEXT    NOT NULL DEFAULT '',
        url              TEXT    NOT NULL DEFAULT '',
        color            TEXT    NOT NULL DEFAULT '#B2DFDB',
        estimate         INTEGER NOT NULL DEFAULT 15,
        fixed_start_time TEXT    NOT NULL DEFAULT '',
        rule             TEXT    NOT NULL DEFAULT '{}',
        skipped_dates    TEXT    NOT NULL DEFAULT '[]',
        generated_count  INTEGER NOT NULL DEFAULT 0,
        created_at       TEXT    NOT NULL DEFAULT (datetime('now'))
    )");

    // Idempotent column migrations for tasks table
    $cols = $pdo->query("PRAGMA table_info(tasks)")->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_column($cols, 'name');
    if (!in_array('template_id', $colNames)) {
        $pdo->exec("ALTER TABLE tasks ADD COLUMN template_id INTEGER DEFAULT NULL");
    }
    if (!in_array('scheduled_time', $colNames)) {
        $pdo->exec("ALTER TABLE tasks ADD COLUMN scheduled_time TEXT NOT NULL DEFAULT ''");
    }

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tasks_template_id ON tasks(template_id)");

    // Idempotent column migrations for repeat_templates table
    $tplCols = $pdo->query("PRAGMA table_info(repeat_templates)")->fetchAll(PDO::FETCH_ASSOC);
    $tplColNames = array_column($tplCols, 'name');
    if (!in_array('fixed_start_time', $tplColNames)) {
        $pdo->exec("ALTER TABLE repeat_templates ADD COLUMN fixed_start_time TEXT NOT NULL DEFAULT ''");
    }
    if (!in_array('skipped_dates', $tplColNames)) {
        $pdo->exec("ALTER TABLE repeat_templates ADD COLUMN skipped_dates TEXT NOT NULL DEFAULT '[]'");
    }
    if (!in_array('generated_count', $tplColNames)) {
        $pdo->exec("ALTER TABLE repeat_templates ADD COLUMN generated_count INTEGER NOT NULL DEFAULT 0");
    }
    if (!in_array('memo', $tplColNames)) {
        $pdo->exec("ALTER TABLE repeat_templates ADD COLUMN memo TEXT NOT NULL DEFAULT ''");
    }
    if (!in_array('url', $tplColNames)) {
        $pdo->exec("ALTER TABLE repeat_templates ADD COLUMN url TEXT NOT NULL DEFAULT ''");
    }
    if (!in_array('color', $tplColNames)) {
        $pdo->exec("ALTER TABLE repeat_templates ADD COLUMN color TEXT NOT NULL DEFAULT '#B2DFDB'");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS user_settings (
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        key     TEXT    NOT NULL,
        value   TEXT    NOT NULL DEFAULT '',
        PRIMARY KEY (user_id, key)
    )");

    return $pdo;
}

function taskRowToJs(array $row): array {
    return [
        'id'            => $row['id'],
        'date'          => $row['date'],
        'title'         => $row['title'],
        'memo'          => $row['memo'],
        'url'           => $row['url'],
        'done'          => (bool)$row['done'],
        'color'         => $row['color'],
        'estimate'      => (int)$row['estimate'],
        'actual'        => (int)$row['actual'],
        'order'         => (int)$row['task_order'],
        'startTime'     => (int)$row['start_time'],
        'repeatDays'    => json_decode($row['repeat_days'], true) ?? [],
        'templateId'    => isset($row['template_id']) ? (int)$row['template_id'] : null,
        'scheduledTime' => $row['scheduled_time'] ?? '',
    ];
}

function templateRowToJs(array $row): array {
    return [
        'id'             => (int)$row['id'],
        'title'          => $row['title'],
        'memo'           => $row['memo'],
        'url'            => $row['url'],
        'color'          => $row['color'],
        'estimate'       => (int)$row['estimate'],
        'fixedStartTime' => $row['fixed_start_time'],
        'rule'           => json_decode($row['rule'], true) ?? [],
        'skippedDates'   => json_decode($row['skipped_dates'], true) ?? [],
        'generatedCount' => (int)$row['generated_count'],
    ];
}
