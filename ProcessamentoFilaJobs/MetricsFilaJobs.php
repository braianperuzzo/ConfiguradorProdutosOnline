<?php
ini_set('display_errors', 0);
error_reporting(0);

$baseDir = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : dirname(__DIR__);

try {
    require_once $baseDir . '/ProcessamentoFilaJobs/CriaFilaProcessamento.php';
} catch (Throwable $t) {
    header('HTTP/1.1 500 Internal Server Error');
    echo "# Erro ao carregar dependencias: " . $t->getMessage();
    exit(1);
}

try {
    $metricas = montar_metricas_para_prometheus($baseDir);
} catch (Throwable $t) {
    header('HTTP/1.1 500 Internal Server Error');
    echo "# Erro ao calcular métricas: " . $t->getMessage();
    exit(1);
}

header('Content-Type: text/plain; charset=utf-8');

echo "# HELP fila_jobs_avg_processing_seconds Tempo médio de processamento por job.\n";
printf("fila_jobs_avg_processing_seconds %.4f\n", $metricas['tempoMedio']);

echo "# HELP fila_jobs_failure_rate Taxa de falhas acumulada da fila de jobs.\n";
printf("fila_jobs_failure_rate %.4f\n", $metricas['taxaFalhas']);

echo "# HELP fila_jobs_processing_count Quantidade de jobs atualmente em processamento.\n";
printf("fila_jobs_processing_count %d\n", $metricas['emProcessamento']);

echo "# HELP fila_jobs_reprocessing_count Quantidade de jobs aguardando reprocessamento.\n";
printf("fila_jobs_reprocessing_count %d\n", $metricas['reprocessamento']);

echo "# HELP fila_jobs_dead_letter_total Total de jobs na dead-letter.\n";
printf("fila_jobs_dead_letter_total %d\n", $metricas['deadLetterTamanho']);

echo "# HELP fila_jobs_pending_total Total de jobs pendentes.\n";
printf("fila_jobs_pending_total %d\n", $metricas['pendentes']);

echo "# HELP fila_jobs_processed_total Total de jobs processados com sucesso.\n";
printf("fila_jobs_processed_total %d\n", $metricas['processados']);

echo "# HELP fila_jobs_failures_total Total de falhas registradas.\n";
printf("fila_jobs_failures_total %d\n", $metricas['falhas']);

echo "# HELP fila_jobs_processing_duration_seconds_total Soma das durações de execução (segundos).\n";
printf("fila_jobs_processing_duration_seconds_total %.4f\n", $metricas['duracaoTotalSegundos']);

echo "# HELP fila_jobs_spawn_failures_total Total de tentativas de spawn do worker que falharam.\n";
printf("fila_jobs_spawn_failures_total %d\n", $metricas['spawnFalhou']);

echo "# HELP fila_jobs_heartbeat_delay_seconds Atraso do heartbeat do worker em relação ao relógio atual.\n";
printf("fila_jobs_heartbeat_delay_seconds %d\n", $metricas['atrasoHeartbeat']);