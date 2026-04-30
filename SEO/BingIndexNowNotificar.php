<?php
require_once __DIR__ . '/BingIndexNowUtils.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    bingIndexNowResponderJson(405, [
        'status' => 'erro',
        'mensagem' => 'Método não permitido. Use POST.',
    ]);
}

$body = file_get_contents('php://input');
$data = json_decode((string) $body, true);

if (!is_array($data) || !isset($data['urlList']) || !is_array($data['urlList'])) {
    bingIndexNowResponderJson(400, [
        'status' => 'erro',
        'mensagem' => 'Body inválido. Envie um JSON com o campo "urlList".',
    ]);
}

$config = bingIndexNowCarregarConfig();
$urls = bingIndexNowNormalizarUrls($data['urlList'], $config['host']);

if ($urls === []) {
    bingIndexNowResponderJson(400, [
        'status' => 'erro',
        'mensagem' => 'Nenhuma URL válida para envio no mesmo host.',
        'hostEsperado' => $config['host'],
    ]);
}

$result = bingIndexNowEnviar($config, $urls);

if ($result['curlError'] !== '') {
    bingIndexNowResponderJson(502, [
        'status' => 'erro',
        'mensagem' => 'Falha ao conectar no endpoint do IndexNow.',
        'erro' => $result['curlError'],
    ]);
}

$statusCode = ($result['httpCode'] >= 200 && $result['httpCode'] < 300) ? 200 : 502;

bingIndexNowResponderJson($statusCode, [
    'status' => $statusCode === 200 ? 'ok' : 'erro',
    'enviadas' => count($urls),
    'httpCodeIndexNow' => $result['httpCode'],
    'respostaIndexNow' => $result['responseBody'],
    'keyLocation' => $config['keyLocation'],
]);
