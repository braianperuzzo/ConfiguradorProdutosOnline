<?php
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../Seguranca/CSRF.php';
require_valid_csrf_token();
require_once __DIR__ . '/Logs.php';

$loggingEnabled = getenv('LOG_TAG_ERRORS_ENABLED');
if ($loggingEnabled !== false && in_array(strtolower(trim((string) $loggingEnabled)), ['0', 'false', 'off', 'no'], true)) {
    echo json_encode(['sucesso' => true]);
    return;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?: [];

$tag = isset($data['tag']) ? substr(trim((string) $data['tag']), 0, 60) : '';
$problema = isset($data['problema']) ? substr(trim((string) $data['problema']), 0, 160) : '';
$detalhe = isset($data['detalhe']) ? substr(trim((string) $data['detalhe']), 0, 400) : '';
$url = isset($data['url']) ? substr(trim((string) $data['url']), 0, 300) : '';
$userAgent = isset($data['userAgent']) ? substr(trim((string) $data['userAgent']), 0, 300) : '';
$timestamp = isset($data['timestamp']) ? substr(trim((string) $data['timestamp']), 0, 40) : gmdate('c');
$contexto = isset($data['contexto']) && is_array($data['contexto']) ? $data['contexto'] : [];
$versao = isset($data['versao']) ? substr(trim((string) $data['versao']), 0, 60) : '';
$ambiente = isset($data['ambiente']) ? substr(trim((string) $data['ambiente']), 0, 40) : '';

if ($tag === '' || $problema === '') {
    echo json_encode(['sucesso' => true]);
    return;
}

$payload = [
    'origem' => 'frontend',
    'tipo' => 'tag_error',
    'nivel' => 'error',
    'mensagem' => $problema,
    'tag' => $tag,
    'problema' => $problema,
    'detalhe' => $detalhe,
    'url' => $url,
    'userAgent' => $userAgent,
    'timestamp' => $timestamp,
    'versao' => $versao,
    'ambiente' => $ambiente,
    'contexto' => $contexto,
];

log_tag_error($payload);

echo json_encode(['sucesso' => true]);
