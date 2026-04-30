<?php
ini_set('display_errors', 0);
error_reporting(0);
require $_SERVER['DOCUMENT_ROOT'] . '/Seguranca/MetodosSegurancao.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$cnpj = strtoupper(preg_replace('/[^0-9A-Z]/', '', $_GET['cnpj'] ?? ''));
if (strlen($cnpj) !== 14) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => 'CNPJ inválido.']);
    exit;
}

// Preparar diretórios de cache e limite
$cacheDir = $baseDir . '/Tokens/CacheEmpresa';
$legacyCacheDir = $baseDir . '/Tokens/CacheEmpresaReceita';
$limiteDir = $baseDir . '/Tokens/LimitesSolicitacoes';
@mkdir($cacheDir, 0775, true);
@mkdir($limiteDir, 0775, true);

$cacheFile = $cacheDir . '/' . $cnpj . '.json';
$legacyCacheFile = $legacyCacheDir . '/' . $cnpj . '.json';
$cacheTTL = 86400; // 1 dia

// Migra cache legado para o diretório oficial
if (!is_file($cacheFile) && is_file($legacyCacheFile)) {
    @rename($legacyCacheFile, $cacheFile);
}

// Retorna do cache se disponível
if (is_file($cacheFile) && (time() - filemtime($cacheFile) < $cacheTTL)) {
    $dadosCache = json_decode(file_get_contents($cacheFile), true);
    $nomeCache = $dadosCache['nome'] ?? ($dadosCache['nome_fantasia'] ?? ($dadosCache['fantasia'] ?? ''));
    if ($nomeCache) {
        echo json_encode(['sucesso' => true, 'nome' => strtoupper($nomeCache)]);
        exit;
    }
}

// Respeitar intervalo mínimo entre requisições externas
$lastFile = $limiteDir . '/receitaws.txt';
$minInterval = 2; // segundos
$now = time();
$last = is_file($lastFile) ? (int)file_get_contents($lastFile) : 0;
if ($now - $last < $minInterval) {
    usleep(($minInterval - ($now - $last)) * 1000000);
}
file_put_contents($lastFile, $now);

$apiUrl = 'https://www.receitaws.com.br/v1/cnpj/' . $cnpj . '?t=' . time();
$maxAttempts = 3;
$wait = 1;
$resp = false;
$httpCode = 0;
$curlErr = '';
for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'ConfiguradorIBR'
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    if ($resp !== false && $httpCode !== 429 && $httpCode < 500) {
        break;
    }
    sleep($wait);
    $wait *= 2;
}

if ($resp === false) {
    http_response_code(500);
    log_event('Erro curl consulta CNPJ.', [
        'cnpj' => $cnpj,
        'erro' => $curlErr,
        'servico' => 'receitaws',
    ]);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Serviço temporariamente indisponível.']);
    exit;
}
if ($httpCode === 404) {
    http_response_code(404);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Não encontrado.']);
    exit;
}
if ($httpCode === 429) {
    http_response_code(429);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Consultas muito frequentes. Aguarde alguns segundos e tente novamente.']);
    exit;
}
if ($httpCode >= 500 || $httpCode < 200) {
    http_response_code(500);
    log_event('HTTP inválido ao consultar CNPJ.', [
        'cnpj' => $cnpj,
        'http_code' => $httpCode,
        'resposta' => $resp,
        'servico' => 'receitaws',
    ]);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Serviço temporariamente indisponível.']);
    exit;
}
if ($httpCode !== 200) {
    http_response_code($httpCode);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Erro na consulta.']);
    exit;
}

$dados = json_decode($resp, true);
if ($dados) {
    file_put_contents($cacheFile, $resp);
}
$nome = $dados['nome'] ?? ($dados['nome_fantasia'] ?? ($dados['fantasia'] ?? ''));
if ($nome) {
    echo json_encode(['sucesso' => true, 'nome' => strtoupper($nome)]);
} else {
    http_response_code(404);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Não encontrado.']);
}
?>
