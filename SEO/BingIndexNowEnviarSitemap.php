<?php
require_once __DIR__ . '/BingIndexNowUtils.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    bingIndexNowResponderJson(405, [
        'status' => 'erro',
        'mensagem' => 'Método não permitido. Use GET.',
    ]);
}

$config = bingIndexNowCarregarConfig();
$baseDir = $config['baseDir'];

$sitemapParam = trim((string) ($_GET['sitemap'] ?? 'sitemap.xml'));
if ($sitemapParam === '') {
    $sitemapParam = 'sitemap.xml';
}

$sitemapParam = ltrim($sitemapParam, '/');
$sitemapPath = realpath($baseDir . '/' . $sitemapParam);
$baseRealPath = realpath($baseDir);

if ($sitemapPath === false || $baseRealPath === false || strpos($sitemapPath, $baseRealPath) !== 0 || !is_readable($sitemapPath)) {
    bingIndexNowResponderJson(400, [
        'status' => 'erro',
        'mensagem' => 'Sitemap inválido ou não encontrado.',
        'sitemap' => $sitemapParam,
    ]);
}

$xml = @simplexml_load_file($sitemapPath);
if ($xml === false) {
    bingIndexNowResponderJson(422, [
        'status' => 'erro',
        'mensagem' => 'Falha ao processar o XML do sitemap.',
        'sitemap' => $sitemapParam,
    ]);
}

$urls = [];
if (isset($xml->url)) {
    foreach ($xml->url as $urlNode) {
        $loc = trim((string) ($urlNode->loc ?? ''));
        if ($loc !== '') {
            $urls[] = $loc;
        }
    }
}

$urls = bingIndexNowNormalizarUrls($urls, $config['host']);
$dryRun = strtolower(trim((string) ($_GET['dryRun'] ?? 'false'))) === 'true';

if ($urls === []) {
    bingIndexNowResponderJson(400, [
        'status' => 'erro',
        'mensagem' => 'Nenhuma URL válida encontrada no sitemap para o host configurado.',
        'hostEsperado' => $config['host'],
        'sitemap' => $sitemapParam,
    ]);
}

if ($dryRun) {
    bingIndexNowResponderJson(200, [
        'status' => 'ok',
        'modo' => 'dryRun',
        'urlsEncontradas' => count($urls),
        'primeirasUrls' => array_slice($urls, 0, 20),
        'keyLocation' => $config['keyLocation'],
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
    'sitemap' => $sitemapParam,
]);
