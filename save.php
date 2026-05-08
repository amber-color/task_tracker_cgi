<?php
$data = json_decode(file_get_contents("php://input"), true);
$id = preg_replace('/[^a-zA-Z0-9]/', '', $data['id']);
$tasks = $data['tasks'];
$filename = "data/$id.json";
file_put_contents($filename, json_encode($tasks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
