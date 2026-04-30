#!/usr/bin/env php
<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);

$baseDir = null;
$sleepSeconds = 15;
$configPath = __DIR__ . '/../Configuracoes/FilaJobsWorker.ini';

if (is_file($configPath)) {
    $config = parse_ini_file($configPath, false, INI_SCANNER_TYPED) ?: [];
    $baseDir = isset($config['DOCUMENT_ROOT']) ? trim((string) $config['DOCUMENT_ROOT']) : null;
    $sleepConfig = $config['SLEEP_SECONDS'] ?? null;
    if (is_numeric($sleepConfig)) {
        $sleepSeconds = max(1, (int) $sleepConfig);
    }
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

fwrite(STDOUT, sprintf(
    "[%s] Worker iniciado (baseDir=%s, sleep=%ds)\n",
    date(DATE_ATOM),
    $baseDir,
    $sleepSeconds
));

$pid = function_exists('getmypid') ? (int) getmypid() : 0;

registrar_heartbeat_worker_fila_jobs($baseDir, $pid);

while (true) {
    try {
        verificar_fila_jobs_periodicamente($baseDir, $sleepSeconds);
        disparar_processamento_fila_jobs($baseDir);
    } catch (Throwable $t) {
        fwrite(STDERR, sprintf("[%s] Erro no worker: %s\n", date(DATE_ATOM), $t->getMessage()));
    }

    registrar_heartbeat_worker_fila_jobs($baseDir, $pid);

    sleep($sleepSeconds);
}