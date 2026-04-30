<?php
ini_set('display_errors', 0);
error_reporting(0);

$baseDir = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : dirname(__DIR__);
$timestamp = gmdate('c');

$diagnosticos = [
    'versao' => [
        'ok' => false,
        'path' => $baseDir . '/Versionamento/Versao.json',
    ],
    'filaJobs' => [
        'ok' => false,
        'path' => $baseDir . '/Tokens/FilaJOBs',
        'readable' => false,
        'writable' => false,
        'canWrite' => false,
    ],
];

$versaoPath = $diagnosticos['versao']['path'];
if (is_readable($versaoPath)) {
    $conteudo = file_get_contents($versaoPath);
    if ($conteudo !== false) {
        $json = json_decode($conteudo, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $diagnosticos['versao']['ok'] = true;
            $diagnosticos['versao']['dados'] = $json;
        } else {
            $diagnosticos['versao']['erro'] = 'JSON invalido: ' . json_last_error_msg();
        }
    } else {
        $diagnosticos['versao']['erro'] = 'Falha ao ler o arquivo de versao.';
    }
} else {
    $diagnosticos['versao']['erro'] = 'Arquivo de versao nao encontrado ou sem permissao de leitura.';
}

$filaDir = $diagnosticos['filaJobs']['path'];
if (is_dir($filaDir)) {
    $diagnosticos['filaJobs']['readable'] = is_readable($filaDir);
    $diagnosticos['filaJobs']['writable'] = is_writable($filaDir);

    if ($diagnosticos['filaJobs']['writable']) {
        $tempFile = $filaDir . '/healthcheck_' . bin2hex(random_bytes(8)) . '.tmp';
        $writeOk = file_put_contents($tempFile, 'ok');
        if ($writeOk !== false) {
            $diagnosticos['filaJobs']['canWrite'] = true;
            @unlink($tempFile);
        }
    }

    $diagnosticos['filaJobs']['ok'] = $diagnosticos['filaJobs']['readable'] && $diagnosticos['filaJobs']['canWrite'];

    if (!$diagnosticos['filaJobs']['ok']) {
        $diagnosticos['filaJobs']['erro'] = 'Falha no acesso de leitura ou escrita na fila.';
    }
} else {
    $diagnosticos['filaJobs']['erro'] = 'Diretorio da fila nao encontrado.';
}

$statusOk = $diagnosticos['versao']['ok'] && $diagnosticos['filaJobs']['ok'];

header('Content-Type: application/json; charset=utf-8');
header($statusOk ? 'HTTP/1.1 200 OK' : 'HTTP/1.1 500 Internal Server Error');

echo json_encode([
    'status' => $statusOk ? 'ok' : 'erro',
    'timestamp' => $timestamp,
    'diagnosticos' => $diagnosticos,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
