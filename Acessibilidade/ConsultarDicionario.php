<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$palavraOriginal = isset($_GET['palavra']) ? (string) $_GET['palavra'] : '';
$palavraOriginal = trim($palavraOriginal);

if ($palavraOriginal === '' || mb_strlen($palavraOriginal, 'UTF-8') < 2) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'erro' => 'Palavra inválida para consulta.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!preg_match('/^[\p{L}\p{N}]{2,}$/u', $palavraOriginal)) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'erro' => 'A consulta aceita apenas uma palavra (sem frases ou parágrafos).'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$palavra = mb_strtolower($palavraOriginal, 'UTF-8');

$url = 'https://www.dicio.com.br/' . rawurlencode($palavra) . '/';
$html = '';

if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'ConfiguradorOnline/1.0',
        CURLOPT_HTTPHEADER => ['Accept-Language: pt-BR,pt;q=0.9'],
    ]);
    $response = curl_exec($ch);
    if (is_string($response) && $response !== '') {
        $html = $response;
    }
    curl_close($ch);
}

if ($html === '') {
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 8,
            'header' => "User-Agent: ConfiguradorOnline/1.0\r\nAccept-Language: pt-BR,pt;q=0.9\r\n"
        ]
    ]);

    $fallback = @file_get_contents($url, false, $context);
    if (is_string($fallback) && $fallback !== '') {
        $html = $fallback;
    }
}

if ($html === '' || trim($html) === '') {
    http_response_code(502);
    echo json_encode([
        'ok' => false,
        'erro' => 'Não foi possível consultar o Dicio no momento.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

libxml_use_internal_errors(true);
$dom = new DOMDocument();
$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
$xpath = new DOMXPath($dom);

$sinonimos = [];
foreach ($xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' adicional ') and contains(concat(' ', normalize-space(@class), ' '), ' sinonimos ')]//a") as $node) {
    $texto = trim((string) $node->textContent);
    if ($texto !== '') {
        $sinonimos[$texto] = true;
    }
}
$sinonimos = array_keys($sinonimos);

$significado = '';
$descricaoNo = $xpath->query("//meta[@property='og:description']/@content");
if ($descricaoNo && $descricaoNo->length > 0) {
    $descricao = trim((string) $descricaoNo->item(0)->nodeValue);
    if ($descricao !== '') {
        $descricao = preg_replace('/\s+/u', ' ', $descricao) ?? $descricao;
        $descricao = preg_replace('/^Significado\s+de\s+[^.]+\.\s*/iu', '', $descricao) ?? $descricao;
        $significado = trim($descricao);
    }
}

if ($significado === '') {
    $paragrafos = $xpath->query("//p[contains(@class,'significado') or contains(@class,'adicional')]//span");
    if ($paragrafos && $paragrafos->length > 0) {
        $significado = trim((string) $paragrafos->item(0)->textContent);
    }
}

$exemplos = [];
$exemploQuery = "//h3[contains(@class,'tit-exemplo')]/following-sibling::*[contains(@class,'frases')][1]//*[contains(@class,'frase')]"
    . " | //h3[contains(@class,'tit-frases')]/following-sibling::*[contains(@class,'frases')][1]//*[contains(@class,'frase')]";
foreach ($xpath->query($exemploQuery) as $node) {
    $texto = trim(preg_replace('/\s+/u', ' ', (string) $node->textContent) ?? '');
    if ($texto !== '') {
        $exemplos[$texto] = true;
    }
}

$resultado = [
    'ok' => true,
    'palavra' => $palavra,
    'sinonimos' => array_slice($sinonimos, 0, 15),
    'significado' => $significado,
    'exemplos' => array_slice(array_keys($exemplos), 0, 6),
    'fonte' => 'https://www.dicio.com.br/'
];

echo json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
