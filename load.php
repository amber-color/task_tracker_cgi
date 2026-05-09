<?php
$id = preg_replace('/[^a-zA-Z0-9]/', '', $_POST['id'] ?? '');
if ($id === '') {
    http_response_code(400);
    exit;
}
$filename = "data/$id.json";
header('Content-Type: application/json');
if (file_exists($filename)) {
    echo file_get_contents($filename);
} else {
    echo '[]';
}
