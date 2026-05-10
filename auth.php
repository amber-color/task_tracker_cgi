<?php
require_once __DIR__ . '/db.php';

session_start();
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $data['action'] ?? '';

if ($action === 'check') {
    if (!empty($_SESSION['user_id'])) {
        echo json_encode(['ok' => true, 'username' => $_SESSION['username']]);
    } else {
        echo json_encode(['ok' => false]);
    }
    exit;
}

if ($action === 'logout') {
    session_destroy();
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'register') {
    $username = $data['username'] ?? '';
    $password = $data['password'] ?? '';

    if (!preg_match('/^[a-zA-Z0-9]{1,32}$/', $username)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'IDは半角英数字1〜32文字で入力してください']);
        exit;
    }
    if (mb_strlen($password) < 8) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'パスワードは8文字以上で入力してください']);
        exit;
    }

    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'そのIDは既に使われています']);
        exit;
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare('INSERT INTO users (username, password) VALUES (?, ?)');
    $stmt->execute([$username, $hash]);
    $userId = (int)$pdo->lastInsertId();

    $_SESSION['user_id'] = $userId;
    $_SESSION['username'] = $username;
    echo json_encode(['ok' => true, 'username' => $username]);
    exit;
}

if ($action === 'login') {
    $username = $data['username'] ?? '';
    $password = $data['password'] ?? '';

    if (!preg_match('/^[a-zA-Z0-9]{1,32}$/', $username) || $password === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => '入力が正しくありません']);
        exit;
    }

    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT id, password FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'IDまたはパスワードが違います']);
        exit;
    }

    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['username'] = $username;
    echo json_encode(['ok' => true, 'username' => $username]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => '不正なアクション']);

function requireAuth(): array {
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        exit;
    }
    return ['id' => $_SESSION['user_id'], 'username' => $_SESSION['username']];
}
