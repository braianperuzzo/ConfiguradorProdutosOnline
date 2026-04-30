#!/usr/bin/env php
<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);

$baseDir = null;
$configPath = __DIR__ . '/../Configuracoes/FilaJobsWorker.ini';
$limiteAtraso = getenv('FILA_JOBS_HEARTBEAT_MAX_ATRASO');

if (!is_numeric($limiteAtraso)) {
    $limiteAtraso = 120;
} else {
    $limiteAtraso = max(1, (int) $limiteAtraso);
}

if (is_file($configPath)) {
    $config = parse_ini_file($configPath, false, INI_SCANNER_TYPED) ?: [];
    $baseDir = isset($config['DOCUMENT_ROOT']) ? trim((string) $config['DOCUMENT_ROOT']) : null;
}

if ($baseDir === null || $baseDir === '') {
    $baseDir = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
}

if ($baseDir === '') {
    $baseDir = dirname(__DIR__);
}

try {
    require_once $baseDir . '/ProcessamentoFilaJobs/CriaFilaProcessamento.php';
} catch (Throwable $t) {
    fwrite(STDERR, sprintf("[%s] Falha ao carregar dependencias: %s\n", date(DATE_ATOM), $t->getMessage()));
    exit(1);
}

$agora = time();
$monitoramento = ler_monitoramento_fila_jobs($baseDir);
$heartbeatTimestamp = (int) ($monitoramento['heartbeatTimestamp'] ?? 0);
$heartbeatPid = $monitoramento['heartbeatPid'] ?? null;
$atraso = $heartbeatTimestamp > 0 ? $agora - $heartbeatTimestamp : null;

if ($atraso !== null && $atraso <= $limiteAtraso) {
    fwrite(STDOUT, sprintf("[%s] Heartbeat OK (atraso=%ds, pid=%s)\n", date(DATE_ATOM), $atraso, (string) $heartbeatPid));
    exit(0);
}

$detalhes = [
    'heartbeatPid'        => $heartbeatPid,
    'heartbeatTimestamp'  => $heartbeatTimestamp,
    'atrasoSegundos'      => $atraso,
    'limiteSegundos'      => $limiteAtraso,
    'monitoramentoCru'    => $monitoramento,
    'momentoVerificacao'  => $agora,
];

registrar_alerta_job(
    'FilaJobs - worker sem heartbeat recente',
    array_merge($detalhes, [
        'mensagem' => 'O arquivo de monitoramento da fila não é atualizado dentro do limite esperado.',
    ])
);

disparar_processamento_fila_jobs($baseDir);

echo sprintf("[%s] Heartbeat atrasado; tentativa de reinicio disparada.\n", date(DATE_ATOM));