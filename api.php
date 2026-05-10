<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json');

$user = requireAuth();
$userId = $user['id'];

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $data['action'] ?? '';

$pdo = getDB();

// ホワイトリスト: JSフィールド名 → DBカラム名
const FIELD_MAP = [
    'date'        => 'date',
    'title'       => 'title',
    'memo'        => 'memo',
    'url'         => 'url',
    'done'        => 'done',
    'color'       => 'color',
    'estimate'    => 'estimate',
    'actual'      => 'actual',
    'order'       => 'task_order',
    'startTime'   => 'start_time',
    'repeatDays'  => 'repeat_days',
];

if ($action === 'load') {
    $stmt = $pdo->prepare('SELECT * FROM tasks WHERE user_id = ? ORDER BY date, task_order');
    $stmt->execute([$userId]);
    echo json_encode(array_map('taskRowToJs', $stmt->fetchAll()));
    exit;
}

if ($action === 'add') {
    $t = $data['task'] ?? [];
    $id = preg_replace('/[^a-zA-Z0-9]/', '', $t['id'] ?? '');
    if ($id === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'IDが不正です']);
        exit;
    }
    $stmt = $pdo->prepare('INSERT OR REPLACE INTO tasks
        (id, user_id, date, title, memo, url, done, color, estimate, actual, task_order, start_time, repeat_days)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([
        $id, $userId,
        $t['date']        ?? '',
        $t['title']       ?? '',
        $t['memo']        ?? '',
        $t['url']         ?? '',
        ($t['done']       ?? false) ? 1 : 0,
        $t['color']       ?? '#B2DFDB',
        (int)($t['estimate'] ?? 15),
        (int)($t['actual']   ?? 0),
        (int)($t['order']    ?? 0),
        (int)($t['startTime']?? 0),
        json_encode($t['repeatDays'] ?? []),
    ]);
    $stmt = $pdo->prepare('SELECT * FROM tasks WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $userId]);
    echo json_encode(['ok' => true, 'task' => taskRowToJs($stmt->fetch())]);
    exit;
}

if ($action === 'update') {
    $id = $data['id'] ?? '';
    $fields = $data['fields'] ?? [];
    if (!$id || empty($fields)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => '入力が不正です']);
        exit;
    }

    $setClauses = [];
    $params = [];
    foreach ($fields as $jsKey => $value) {
        if (!isset(FIELD_MAP[$jsKey])) continue;
        $col = FIELD_MAP[$jsKey];
        $setClauses[] = "$col = ?";
        if ($jsKey === 'done') {
            $params[] = $value ? 1 : 0;
        } elseif ($jsKey === 'repeatDays') {
            $params[] = json_encode($value);
        } elseif (in_array($jsKey, ['estimate','actual','order','startTime'])) {
            $params[] = (int)$value;
        } else {
            $params[] = $value;
        }
    }

    if (empty($setClauses)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => '更新フィールドがありません']);
        exit;
    }

    $params[] = $id;
    $params[] = $userId;
    $sql = 'UPDATE tasks SET ' . implode(', ', $setClauses) . ' WHERE id = ? AND user_id = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'delete') {
    $id = $data['id'] ?? '';
    if (!$id) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'IDが必要です']);
        exit;
    }
    $stmt = $pdo->prepare('DELETE FROM tasks WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $userId]);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'reorder') {
    $date = $data['date'] ?? '';
    $order = $data['order'] ?? [];
    if (!$date || !is_array($order)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => '入力が不正です']);
        exit;
    }
    $stmt = $pdo->prepare('UPDATE tasks SET task_order = ? WHERE id = ? AND user_id = ?');
    $pdo->beginTransaction();
    foreach ($order as $index => $id) {
        $stmt->execute([$index, $id, $userId]);
    }
    $pdo->commit();
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'interrupt') {
    $id = $data['id'] ?? '';
    $nowMs = (int)($data['now_ms'] ?? 0);
    if (!$id || !$nowMs) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => '入力が不正です']);
        exit;
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM tasks WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        $task = $stmt->fetch();

        if (!$task || !$task['start_time'] || $task['done']) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => '対象タスクが不正です']);
            exit;
        }

        $rawMin = ($nowMs - $task['start_time']) / 60000;
        $actual = $rawMin < 1 ? 1 : (int)floor($rawMin);
        $remaining = max(1, (int)$task['estimate'] - $actual);
        $interruptedTitle = $task['title'] . '（中断）';
        $newEstimate = min((int)$task['estimate'], $actual);

        // 元タスク更新
        $stmt = $pdo->prepare('UPDATE tasks SET done=1, actual=?, estimate=?, title=? WHERE id=? AND user_id=?');
        $stmt->execute([$actual, $newEstimate, $interruptedTitle, $id, $userId]);

        // 次の未完了・未開始タスクの order を取得
        $stmt = $pdo->prepare(
            'SELECT task_order FROM tasks WHERE user_id=? AND date=? AND done=0 AND start_time=0 AND task_order > ? ORDER BY task_order ASC LIMIT 1'
        );
        $stmt->execute([$userId, $task['date'], (int)$task['task_order']]);
        $next = $stmt->fetch();
        $cloneOrder = $next ? $next['task_order'] - 0.5 : (int)$task['task_order'] + 1;

        // クローンタスク作成
        $cloneId = (string)$nowMs;
        $stmt = $pdo->prepare('INSERT INTO tasks
            (id, user_id, date, title, memo, url, done, color, estimate, actual, task_order, start_time, repeat_days)
            VALUES (?,?,?,?,?,?,0,?,?,0,?,0,?)');
        $stmt->execute([
            $cloneId, $userId, $task['date'], $task['title'],
            $task['memo'], $task['url'], $task['color'],
            $remaining, $cloneOrder,
            $task['repeat_days'],
        ]);

        // その日の全タスクを再採番
        $stmt = $pdo->prepare('SELECT id FROM tasks WHERE user_id=? AND date=? ORDER BY task_order ASC');
        $stmt->execute([$userId, $task['date']]);
        $allIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $upd = $pdo->prepare('UPDATE tasks SET task_order=? WHERE id=? AND user_id=?');
        foreach ($allIds as $i => $tid) {
            $upd->execute([$i, $tid, $userId]);
        }

        $pdo->commit();

        // その日の全タスクを返す
        $stmt = $pdo->prepare('SELECT * FROM tasks WHERE user_id=? AND date=? ORDER BY task_order');
        $stmt->execute([$userId, $task['date']]);
        $dayTasks = array_map('taskRowToJs', $stmt->fetchAll());
        echo json_encode(['ok' => true, 'tasks' => $dayTasks]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'migrate_past') {
    $today = $data['today'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $today)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => '日付形式が不正です']);
        exit;
    }
    $stmt = $pdo->prepare(
        'UPDATE tasks SET date=? WHERE user_id=? AND date<? AND done=0 AND start_time=0'
    );
    $stmt->execute([$today, $userId, $today]);
    echo json_encode(['ok' => true, 'updated' => $stmt->rowCount()]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => '不正なアクション']);
