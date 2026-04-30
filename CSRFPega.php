<?php
require_once __DIR__ . '/Seguranca/CSRF.php';
header('Content-Type: application/json; charset=UTF-8');
$token = csrf_token();
echo json_encode(['token' => $token]);
?>