<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

// ---- Recurrence helpers ----

function isNthWeekdayOfMonth(DateTime $d, int $nth, int $weekday): bool {
    if ((int)$d->format('w') !== $weekday) return false;
    return (int)ceil((int)$d->format('j') / 7) === $nth;
}

function isLastWeekdayOfMonth(DateTime $d, int $weekday): bool {
    if ((int)$d->format('w') !== $weekday) return false;
    return ((int)$d->format('j') + 7) > (int)$d->format('t');
}

function occursOnDate(array $rule, string $date): bool {
    $freq     = $rule['frequency'] ?? 'daily';
    $interval = max(1, (int)($rule['interval'] ?? 1));
    $startDate = $rule['startDate'] ?? '';
    if (!$startDate) return false;

    $start = new DateTime($startDate);
    $check = new DateTime($date);
    if ($check < $start) return false;

    $endType = $rule['endType'] ?? 'never';
    if ($endType === 'until_date' && !empty($rule['endDate'])) {
        if ($check > new DateTime($rule['endDate'])) return false;
    }

    if ($freq === 'daily') {
        return ($check->diff($start)->days % $interval) === 0;
    }

    if ($freq === 'weekly') {
        $daysOfWeek = $rule['daysOfWeek'] ?? [];
        if (empty($daysOfWeek)) return false;
        $checkDow = (int)$check->format('w');
        if (!in_array($checkDow, $daysOfWeek)) return false;
        $startDow = (int)$start->format('w');
        $startWeekSun = (clone $start)->modify("-{$startDow} days");
        $checkDow2    = (int)$check->format('w');
        $checkWeekSun = (clone $check)->modify("-{$checkDow2} days");
        $weekDiff = (int)round($startWeekSun->diff($checkWeekSun)->days / 7);
        return ($weekDiff % $interval) === 0;
    }

    if ($freq === 'monthly') {
        $checkY = (int)$check->format('Y');
        $checkM = (int)$check->format('n');
        $startY = (int)$start->format('Y');
        $startM = (int)$start->format('n');
        $monthDiff = ($checkY - $startY) * 12 + ($checkM - $startM);
        if ($monthDiff < 0 || ($monthDiff % $interval) !== 0) return false;

        $monthlyType = $rule['monthlyType'] ?? 'day_of_month';
        if ($monthlyType === 'day_of_month') {
            return (int)$check->format('j') === (int)($rule['monthlyDay'] ?? 1);
        }
        if ($monthlyType === 'nth_weekday') {
            return isNthWeekdayOfMonth($check, (int)($rule['monthlyNth'] ?? 1), (int)($rule['monthlyWeekday'] ?? 0));
        }
        if ($monthlyType === 'last_weekday') {
            return isLastWeekdayOfMonth($check, (int)($rule['monthlyWeekday'] ?? 0));
        }
        return false;
    }

    if ($freq === 'yearly') {
        $yearDiff = (int)$check->format('Y') - (int)$start->format('Y');
        if ($yearDiff < 0 || ($yearDiff % $interval) !== 0) return false;
        if ((int)$check->format('n') !== (int)($rule['yearlyMonth'] ?? 1)) return false;
        if (!empty($rule['yearlyNth'])) {
            return isNthWeekdayOfMonth($check, (int)$rule['yearlyNth'], (int)($rule['yearlyWeekday'] ?? 0));
        }
        return (int)$check->format('j') === (int)($rule['yearlyDay'] ?? 1);
    }

    if ($freq === 'custom') {
        $unit = $rule['unit'] ?? 'day';
        if ($unit === 'day') {
            return ($check->diff($start)->days % $interval) === 0;
        }
        if ($unit === 'week') {
            $daysOfWeek = $rule['daysOfWeek'] ?? [];
            if (empty($daysOfWeek)) return false;
            $checkDow = (int)$check->format('w');
            if (!in_array($checkDow, $daysOfWeek)) return false;
            $startDow = (int)$start->format('w');
            $startWeekSun = (clone $start)->modify("-{$startDow} days");
            $checkDow2    = (int)$check->format('w');
            $checkWeekSun = (clone $check)->modify("-{$checkDow2} days");
            $weekDiff = (int)round($startWeekSun->diff($checkWeekSun)->days / 7);
            return ($weekDiff % $interval) === 0;
        }
        if ($unit === 'month') {
            $checkY = (int)$check->format('Y');
            $checkM = (int)$check->format('n');
            $startY = (int)$start->format('Y');
            $startM = (int)$start->format('n');
            $monthDiff = ($checkY - $startY) * 12 + ($checkM - $startM);
            if ($monthDiff < 0 || ($monthDiff % $interval) !== 0) return false;
            return (int)$check->format('j') === (int)$start->format('j');
        }
        if ($unit === 'year') {
            $yearDiff = (int)$check->format('Y') - (int)$start->format('Y');
            if ($yearDiff < 0 || ($yearDiff % $interval) !== 0) return false;
            return $check->format('m-d') === $start->format('m-d');
        }
    }

    return false;
}

function generateTemplateInstances(PDO $pdo, int $userId, string $today): void {
    $windowEnd = (new DateTime($today))->modify('+90 days')->format('Y-m-d');

    $stmt = $pdo->prepare('SELECT * FROM repeat_templates WHERE user_id = ?');
    $stmt->execute([$userId]);
    $templates = $stmt->fetchAll();
    if (empty($templates)) return;

    // Pre-load existing (template_id, date) pairs for idempotency
    $stmt = $pdo->prepare(
        'SELECT template_id, date FROM tasks WHERE user_id=? AND template_id IS NOT NULL AND date>=? AND date<=?'
    );
    $stmt->execute([$userId, $today, $windowEnd]);
    $existing = [];
    foreach ($stmt->fetchAll() as $row) {
        $existing[$row['template_id'] . '|' . $row['date']] = true;
    }

    $insertStmt = $pdo->prepare(
        'INSERT INTO tasks
         (id, user_id, date, title, memo, url, done, color, estimate, actual, task_order,
          start_time, repeat_days, template_id, scheduled_time)
         VALUES (?,?,?,?,?,?,0,?,?,0,?,0,\'[]\',?,?)'
    );

    foreach ($templates as $tpl) {
        $rule        = json_decode($tpl['rule'], true) ?? [];
        $skipped     = json_decode($tpl['skipped_dates'], true) ?? [];
        $genCount    = (int)$tpl['generated_count'];
        $endType     = $rule['endType'] ?? 'never';
        $endCount    = (int)($rule['endCount'] ?? 0);
        $templateId  = (int)$tpl['id'];
        $ruleStart   = $rule['startDate'] ?? $today;
        $winStart    = $ruleStart > $today ? $ruleStart : $today;

        $cursor = new DateTime($winStart);
        $end    = new DateTime($windowEnd);
        $newlyGenerated = 0;

        while ($cursor <= $end) {
            $dateStr = $cursor->format('Y-m-d');

            if ($endType === 'count' && ($genCount + $newlyGenerated) >= $endCount) break;
            if (in_array($dateStr, $skipped)) { $cursor->modify('+1 day'); continue; }

            $key = $templateId . '|' . $dateStr;
            if (isset($existing[$key])) { $cursor->modify('+1 day'); continue; }

            if (!occursOnDate($rule, $dateStr)) { $cursor->modify('+1 day'); continue; }

            $orderStmt = $pdo->prepare(
                'SELECT COALESCE(MAX(task_order), -1) + 1 FROM tasks WHERE user_id=? AND date=?'
            );
            $orderStmt->execute([$userId, $dateStr]);
            $newOrder = (int)$orderStmt->fetchColumn();

            $newId = 'tpl' . $templateId . '_' . str_replace('-', '', $dateStr);
            $insertStmt->execute([
                $newId, $userId, $dateStr,
                $tpl['title'], $tpl['memo'], $tpl['url'],
                $tpl['color'], (int)$tpl['estimate'],
                $newOrder, $templateId, $tpl['fixed_start_time'],
            ]);

            $existing[$key] = true;
            $newlyGenerated++;
            $cursor->modify('+1 day');
        }

        if ($newlyGenerated > 0 && $endType === 'count') {
            $pdo->prepare('UPDATE repeat_templates SET generated_count=generated_count+? WHERE id=?')
                ->execute([$newlyGenerated, $templateId]);
        }
    }
}

// ---- Main handler ----

header('Content-Type: application/json');

$user = requireAuth();
$userId = $user['id'];

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $data['action'] ?? '';

$pdo = getDB();

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
    $today = (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
    generateTemplateInstances($pdo, $userId, $today);
    $stmt = $pdo->prepare('SELECT * FROM tasks WHERE user_id = ? ORDER BY date, task_order');
    $stmt->execute([$userId]);
    echo json_encode(array_map('taskRowToJs', $stmt->fetchAll()));
    exit;
}

if ($action === 'list_templates') {
    $stmt = $pdo->prepare('SELECT * FROM repeat_templates WHERE user_id = ? ORDER BY id ASC');
    $stmt->execute([$userId]);
    echo json_encode(array_map('templateRowToJs', $stmt->fetchAll()));
    exit;
}

if ($action === 'save_template') {
    $t = $data['template'] ?? [];
    $title          = trim($t['title'] ?? '');
    $estimate       = max(1, (int)($t['estimate'] ?? 15));
    $color          = $t['color'] ?? '#B2DFDB';
    $memo           = $t['memo'] ?? '';
    $url            = $t['url'] ?? '';
    $fixedStartTime = $t['fixedStartTime'] ?? '';
    $rule           = $t['rule'] ?? [];
    $ruleJson       = json_encode($rule);

    if (empty($rule['frequency']) || empty($rule['startDate'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'frequencyとstartDateは必須です']);
        exit;
    }

    $templateId = isset($t['id']) ? (int)$t['id'] : null;

    if ($templateId) {
        $stmt = $pdo->prepare('SELECT id, rule FROM repeat_templates WHERE id=? AND user_id=?');
        $stmt->execute([$templateId, $userId]);
        $existing = $stmt->fetch();
        if (!$existing) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'テンプレートが見つかりません']);
            exit;
        }
        $ruleChanged = ($existing['rule'] !== $ruleJson);
        if ($ruleChanged) {
            $pdo->prepare(
                'UPDATE repeat_templates SET title=?,memo=?,url=?,color=?,estimate=?,fixed_start_time=?,
                 rule=?,skipped_dates=\'[]\',generated_count=0 WHERE id=? AND user_id=?'
            )->execute([$title, $memo, $url, $color, $estimate, $fixedStartTime, $ruleJson, $templateId, $userId]);
        } else {
            $pdo->prepare(
                'UPDATE repeat_templates SET title=?,memo=?,url=?,color=?,estimate=?,fixed_start_time=?,rule=?
                 WHERE id=? AND user_id=?'
            )->execute([$title, $memo, $url, $color, $estimate, $fixedStartTime, $ruleJson, $templateId, $userId]);
        }
    } else {
        $pdo->prepare(
            'INSERT INTO repeat_templates (user_id,title,memo,url,color,estimate,fixed_start_time,rule)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([$userId, $title, $memo, $url, $color, $estimate, $fixedStartTime, $ruleJson]);
        $templateId = (int)$pdo->lastInsertId();
    }

    $stmt = $pdo->prepare('SELECT * FROM repeat_templates WHERE id=?');
    $stmt->execute([$templateId]);
    echo json_encode(['ok' => true, 'template' => templateRowToJs($stmt->fetch())]);
    exit;
}

if ($action === 'delete_template') {
    $templateId = (int)($data['id'] ?? 0);
    if (!$templateId) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'IDが必要です']);
        exit;
    }
    $stmt = $pdo->prepare('SELECT id FROM repeat_templates WHERE id=? AND user_id=?');
    $stmt->execute([$templateId, $userId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'テンプレートが見つかりません']);
        exit;
    }
    $today = (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM tasks WHERE template_id=? AND user_id=? AND date>?')
            ->execute([$templateId, $userId, $today]);
        $pdo->prepare('DELETE FROM repeat_templates WHERE id=? AND user_id=?')
            ->execute([$templateId, $userId]);
        $pdo->commit();
        echo json_encode(['ok' => true]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
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
    $pdo->prepare($sql)->execute($params);
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

    // If this is a template-generated task, record its date as skipped
    $stmt = $pdo->prepare('SELECT template_id, date FROM tasks WHERE id=? AND user_id=?');
    $stmt->execute([$id, $userId]);
    $task = $stmt->fetch();
    if ($task && $task['template_id']) {
        $tplStmt = $pdo->prepare('SELECT skipped_dates FROM repeat_templates WHERE id=? AND user_id=?');
        $tplStmt->execute([$task['template_id'], $userId]);
        $tpl = $tplStmt->fetch();
        if ($tpl) {
            $skipped = json_decode($tpl['skipped_dates'], true) ?? [];
            if (!in_array($task['date'], $skipped)) {
                $skipped[] = $task['date'];
                $pdo->prepare('UPDATE repeat_templates SET skipped_dates=? WHERE id=? AND user_id=?')
                    ->execute([json_encode($skipped), $task['template_id'], $userId]);
            }
        }
    }

    $pdo->prepare('DELETE FROM tasks WHERE id=? AND user_id=?')->execute([$id, $userId]);
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

        $pdo->prepare('UPDATE tasks SET done=1, actual=?, estimate=?, title=? WHERE id=? AND user_id=?')
            ->execute([$actual, $newEstimate, $interruptedTitle, $id, $userId]);

        $stmt = $pdo->prepare(
            'SELECT task_order FROM tasks WHERE user_id=? AND date=? AND done=0 AND start_time=0 AND task_order>? ORDER BY task_order ASC LIMIT 1'
        );
        $stmt->execute([$userId, $task['date'], (int)$task['task_order']]);
        $next = $stmt->fetch();
        $cloneOrder = $next ? $next['task_order'] - 0.5 : (int)$task['task_order'] + 1;

        $cloneId = (string)$nowMs;
        $pdo->prepare('INSERT INTO tasks
            (id, user_id, date, title, memo, url, done, color, estimate, actual, task_order, start_time, repeat_days)
            VALUES (?,?,?,?,?,?,0,?,?,0,?,0,?)')->execute([
            $cloneId, $userId, $task['date'], $task['title'],
            $task['memo'], $task['url'], $task['color'],
            $remaining, $cloneOrder, $task['repeat_days'],
        ]);

        $stmt = $pdo->prepare('SELECT id FROM tasks WHERE user_id=? AND date=? ORDER BY task_order ASC');
        $stmt->execute([$userId, $task['date']]);
        $allIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $upd = $pdo->prepare('UPDATE tasks SET task_order=? WHERE id=? AND user_id=?');
        foreach ($allIds as $i => $tid) {
            $upd->execute([$i, $tid, $userId]);
        }

        $pdo->commit();

        $stmt = $pdo->prepare('SELECT * FROM tasks WHERE user_id=? AND date=? ORDER BY task_order');
        $stmt->execute([$userId, $task['date']]);
        echo json_encode(['ok' => true, 'tasks' => array_map('taskRowToJs', $stmt->fetchAll())]);
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

    // 開始済み・未完了の過去タスクを 23:59 で強制終了
    $tz = new DateTimeZone('Asia/Tokyo');
    $overdueStmt = $pdo->prepare(
        'SELECT id, date, start_time FROM tasks WHERE user_id=? AND date<? AND done=0 AND start_time>0'
    );
    $overdueStmt->execute([$userId, $today]);
    $overdues = $overdueStmt->fetchAll();
    $endStmt = $pdo->prepare('UPDATE tasks SET done=1, actual=? WHERE id=?');
    foreach ($overdues as $task) {
        $endOfDay = new DateTime($task['date'] . ' 23:59:00', $tz);
        $endMs    = $endOfDay->getTimestamp() * 1000;
        $actual   = max(1, (int)round(($endMs - (int)$task['start_time']) / 60000));
        $endStmt->execute([$actual, $task['id']]);
    }

    $stmt = $pdo->prepare(
        'UPDATE tasks SET date=?
         WHERE user_id=? AND date<? AND done=0 AND start_time=0
         AND (
             template_id IS NULL
             OR NOT EXISTS (
                 SELECT 1 FROM tasks t2
                 WHERE t2.user_id = tasks.user_id
                   AND t2.template_id = tasks.template_id
                   AND t2.date = ?
             )
         )'
    );
    $stmt->execute([$today, $userId, $today, $today]);
    echo json_encode(['ok' => true, 'updated' => $stmt->rowCount(), 'forcedEnded' => count($overdues)]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => '不正なアクション']);
