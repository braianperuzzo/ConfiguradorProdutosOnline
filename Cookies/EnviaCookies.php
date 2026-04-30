<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody ?? '', true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Requisição inválida']);
    exit;
}

$baseDir = dirname(__DIR__);
require_once $baseDir . '/LogsErros/Logs.php';
require_once $baseDir . '/Seguranca/CSRF.php';
require_once $baseDir . '/TokensGeradores/TokensLimiteSolicitacoes.php';

$allowedSuffix = 'redutoresibr.com.br';
$allowedHosts = ['localhost', '127.0.0.1', 'configurador.redutoresibr.com.br', $allowedSuffix, 'www.redutoresibr.com.br'];

$extractHost = static function (string $value): string {
    $parsed = parse_url($value);
    if (is_array($parsed) && isset($parsed['host'])) {
        return strtolower(trim($parsed['host']));
    }
    return strtolower(trim($value));
};

$isAllowedHost = static function (string $host) use ($allowedHosts, $allowedSuffix): bool {
    if ($host === '') {
        return false;
    }
    if (in_array($host, $allowedHosts, true)) {
        return true;
    }
    return substr($host, -strlen('.' . $allowedSuffix)) === '.' . $allowedSuffix;
};

$originHeader = $_SERVER['HTTP_ORIGIN'] ?? '';
$refererHeader = $_SERVER['HTTP_REFERER'] ?? '';
$sourceHeader = $originHeader !== '' ? $originHeader : $refererHeader;
$sourceHost = $sourceHeader !== '' ? $extractHost($sourceHeader) : '';

if (!$isAllowedHost($sourceHost)) {
    log_event(json_encode([
        'componente' => 'cookies_envio',
        'nivel' => 'warning',
        'mensagem' => 'Origem não autorizada',
        'origem' => $sourceHeader,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? ''
    ], JSON_UNESCAPED_UNICODE));
    http_response_code(403);
    echo json_encode(['error' => 'Origem não autorizada']);
    exit;
}

if (!is_valid_csrf_token()) {
    log_event(json_encode([
        'componente' => 'cookies_envio',
        'nivel' => 'warning',
        'mensagem' => 'Token CSRF inválido',
        'origem' => $sourceHeader,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? ''
    ], JSON_UNESCAPED_UNICODE));
    http_response_code(403);
    echo json_encode(['error' => 'Token CSRF inválido']);
    exit;
}

if (!check_rate_limit('envia_cookies', 10, 60)) {
    log_event(json_encode([
        'componente' => 'cookies_envio',
        'nivel' => 'warning',
        'mensagem' => 'Rate limit excedido',
        'origem' => $sourceHeader,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? ''
    ], JSON_UNESCAPED_UNICODE));
    http_response_code(429);
    echo json_encode(['error' => 'Limite de requisições excedido']);
    exit;
}

$location = '';
if (isset($data['Location'])) {
    $location = filter_var((string)$data['Location'], FILTER_SANITIZE_URL) ?: '';
}

$schemaVersion = isset($data['SchemaVersion']) ? (int)$data['SchemaVersion'] : 2;
$allowedTypes = ['Cookie', 'LocalStorage', 'SessionStorage'];

$entries = $data['Identifiers'] ?? $data['Cookies'] ?? [];
if (!is_array($entries)) {
    $entries = [];
}

$normalized = [];
foreach ($entries as $entry) {
    if (!is_array($entry)) {
        continue;
    }

    $hash = $entry['Hash'] ?? ($entry['Name'] ?? '');
    if (!is_string($hash)) {
        continue;
    }
    $hash = strtolower(trim($hash));
    if (!preg_match('/^[a-f0-9]{64}$/', $hash)) {
        continue;
    }

    $type = $entry['Type'] ?? 'Cookie';
    if (!in_array($type, $allowedTypes, true)) {
        $type = 'Cookie';
    }

    $normalized[] = [
        'hash' => $hash,
        'type' => $type
    ];
}

if (empty($normalized)) {
    echo json_encode(['status' => 'ignored']);
    exit;
}

$record = [
    'location' => $location,
    'schemaVersion' => $schemaVersion,
    'identifiers' => $normalized,
    'capturedAt' => gmdate('c')
];


echo json_encode([
    'status' => 'ok',
    'count' => count($normalized)
]);
