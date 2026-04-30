<?php
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../Seguranca/CSRF.php';
require_valid_csrf_token();
require_once __DIR__ . '/Logs.php';

$loggingEnabled = getenv('LOG_WEB_VITALS_ENABLED');
if ($loggingEnabled !== false && in_array(strtolower(trim((string) $loggingEnabled)), ['0', 'false', 'off', 'no'], true)) {
    echo json_encode(['sucesso' => true]);
    return;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?: [];

$metricNameRaw = $data['metric_name'] ?? $data['metrica'] ?? $data['mensagem'] ?? '';
$metricValueRaw = $data['metric_value'] ?? $data['valor'] ?? 0.0;
$metricDeltaRaw = $data['metric_delta'] ?? $data['delta'] ?? 0.0;
$metricRatingRaw = $data['metric_rating'] ?? $data['rating'] ?? '';

$metricName = $metricNameRaw !== '' ? substr(trim((string) $metricNameRaw), 0, 40) : '';
$metricValue = (float) $metricValueRaw;
$metricDelta = (float) $metricDeltaRaw;
$metricRating = $metricRatingRaw !== '' ? substr(trim((string) $metricRatingRaw), 0, 20) : '';
$metricId = isset($data['metric_id']) ? substr(trim((string) $data['metric_id']), 0, 80) : '';
$navigationType = isset($data['navigation_type']) ? substr(trim((string) $data['navigation_type']), 0, 30) : '';
$url = isset($data['url']) ? substr(trim((string) $data['url']), 0, 300) : '';
$userAgentRaw = $data['userAgent'] ?? $data['user_agent'] ?? '';
$userAgent = $userAgentRaw !== '' ? substr(trim((string) $userAgentRaw), 0, 300) : '';
$timestamp = isset($data['timestamp']) ? substr(trim((string) $data['timestamp']), 0, 40) : gmdate('c');
$contexto = isset($data['contexto']) && is_array($data['contexto']) ? $data['contexto'] : [];
$versao = isset($data['versao']) ? substr(trim((string) $data['versao']), 0, 60) : '';
$ambiente = isset($data['ambiente']) ? substr(trim((string) $data['ambiente']), 0, 40) : '';

if ($metricName === '') {
    echo json_encode(['sucesso' => true]);
    return;
}

$payload = [
    'origem' => 'frontend',
    'tipo' => 'web_vitals',
    'nivel' => 'info',
    'mensagem' => $metricName,
    'metrica' => $metricName,
    'valor' => $metricValue,
    'delta' => $metricDelta,
    'rating' => $metricRating,
    'metric_id' => $metricId,
    'navigation_type' => $navigationType,
    'url' => $url,
    'userAgent' => $userAgent,
    'timestamp' => $timestamp,
    'versao' => $versao,
    'ambiente' => $ambiente,
    'contexto' => $contexto,
];

log_event($payload);

echo json_encode(['sucesso' => true]);
