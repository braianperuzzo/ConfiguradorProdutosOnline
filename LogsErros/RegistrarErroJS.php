<?php
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../Seguranca/CSRF.php';
require_valid_csrf_token();
require_once __DIR__ . '/Logs.php';

$jsLoggingEnabled = getenv('LOG_JS_CAPTURE_ENABLED');
if ($jsLoggingEnabled !== false && in_array(strtolower(trim((string) $jsLoggingEnabled)), ['0', 'false', 'off', 'no'], true)) {
    echo json_encode(['sucesso' => true]);
    return;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?: [];

$msg = isset($data['mensagem']) ? substr(trim((string) $data['mensagem']), 0, 300) : '';
if ($msg === '') {
    $msg = isset($data['msg']) ? substr(trim((string) $data['msg']), 0, 300) : 'N/A';
}
$url = isset($data['url']) ? substr(trim((string) $data['url']), 0, 300) : '';
$linha = isset($data['linha']) ? intval($data['linha']) : 0;
$coluna = isset($data['coluna']) ? intval($data['coluna']) : 0;
$stack = isset($data['stack']) ? substr(trim((string) $data['stack']), 0, 1000) : '';
$tipo = isset($data['tipo']) ? substr(trim((string) $data['tipo']), 0, 100) : 'js_error';
$userAgent = isset($data['userAgent']) ? substr(trim((string) $data['userAgent']), 0, 300) : '';
$timestamp = isset($data['timestamp']) ? substr(trim((string) $data['timestamp']), 0, 40) : gmdate('c');
$contexto = isset($data['contexto']) && is_array($data['contexto']) ? $data['contexto'] : [];
$versao = isset($data['versao']) ? substr(trim((string) $data['versao']), 0, 60) : '';
$ambiente = isset($data['ambiente']) ? substr(trim((string) $data['ambiente']), 0, 40) : '';

$ignoredFragments = [
    "Cannot set properties of null (setting 'innerHTML')",
    'Object Not Found Matching Id',
    'Script error'
];

foreach ($ignoredFragments as $fragment) {
    if ($fragment !== '' && stripos($msg, $fragment) !== false) {
        echo json_encode(['sucesso' => true]);
        return;
    }
}

$payload = [
    'origem' => 'frontend',
    'tipo' => $tipo,
    'nivel' => 'error',
    'mensagem' => $msg,
    'url' => $url,
    'linha' => $linha,
    'coluna' => $coluna,
    'stack' => $stack,
    'userAgent' => $userAgent,
    'timestamp' => $timestamp,
    'versao' => $versao,
    'ambiente' => $ambiente,
    'contexto' => $contexto,
];

log_event($payload);

echo json_encode(['sucesso' => true]);
