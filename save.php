<?php
$data = json_decode(file_get_contents("php://input"), true);
if (!$data || !isset($data['id'], $data['tasks'])) {
    http_response_code(400);
    exit;
}
$id = preg_replace('/[^a-zA-Z0-9]/', '', $data['id']);
if ($id === '') {
    http_response_code(400);
    exit;
}
$tasks = $data['tasks'];
$filename = "data/$id.json";
file_put_contents($filename, json_encode($tasks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
