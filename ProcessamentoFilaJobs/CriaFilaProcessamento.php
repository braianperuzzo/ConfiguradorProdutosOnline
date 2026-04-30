<?php
ini_set('display_errors', 0);
error_reporting(0);

/**
 * Responsável por gerenciar filas de jobs em disco.
 * A fila utiliza arquivos JSON armazenados em Tokens/FilaJOBs para permitir
 * que integrações externas sejam processadas em segundo plano. Cada job pode
 * ser reprocessado algumas vezes em caso de falha, usando um backoff
 * progressivo (1min, 5min, 15min, 60min) para evitar insistências em curto
 * espaço de tempo. Após atingir FILA_JOBS_MAX_TENTATIVAS o status é marcado
 * como dead_letter e o job deixa de ser executado até análise manual.
 */

const FILA_JOBS_MAX_TENTATIVAS = 5;
const FILA_JOBS_BACKOFF_INTERVALOS = [60, 300, 900, 3600];
const FILA_JOBS_ALERTA_FALHAS_CONSECUTIVAS = 3;
const FILA_JOBS_HISTORICO_REPROCESSAMENTO_LIMITE = 200;

if (!function_exists('log_event')) {
    require_once __DIR__ . '/../LogsErros/Logs.php';
}

function obter_diretorio_fila_jobs(string $baseDir): string
{
    return rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'Tokens' . DIRECTORY_SEPARATOR . 'FilaJOBs';
}

function obter_diretorio_dead_letter_jobs(string $baseDir): string
{
    return obter_diretorio_fila_jobs($baseDir) . DIRECTORY_SEPARATOR . 'dead-letter';
}

function obter_arquivo_metricas_fila_jobs(string $baseDir): string
{
    return obter_diretorio_fila_jobs($baseDir) . DIRECTORY_SEPARATOR . 'metricas.json';
}

function obter_arquivo_historico_reprocessamento(string $baseDir): string
{
    return obter_diretorio_fila_jobs($baseDir) . DIRECTORY_SEPARATOR . 'historico_reprocessamento.json';
}

function obter_arquivo_monitoramento_fila_jobs(string $baseDir): string
{
    return obter_diretorio_fila_jobs($baseDir) . DIRECTORY_SEPARATOR . 'monitoramento.json';
}

function obter_arquivo_catalogo_erros_fila_jobs(string $baseDir): string
{
    return obter_diretorio_fila_jobs($baseDir) . DIRECTORY_SEPARATOR . 'catalogo_erros.json';
}

function inicializar_fila_jobs(string $baseDir): void
{
    $dir = obter_diretorio_fila_jobs($baseDir);

    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }

    $webConfig = $dir . DIRECTORY_SEPARATOR . 'web.config';
    if (!file_exists($webConfig)) {
        file_put_contents(
            $webConfig,
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>" .
            "<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>"
        );
    }
}

function ler_monitoramento_fila_jobs(string $baseDir): array
{
    $arquivo = obter_arquivo_monitoramento_fila_jobs($baseDir);

    if (is_file($arquivo)) {
        $json = json_decode((string) file_get_contents($arquivo), true);
        if (is_array($json)) {
            return $json;
        }
    }

    return [];
}

function salvar_monitoramento_fila_jobs(string $baseDir, array $dados): void
{
    inicializar_fila_jobs($baseDir);
    $arquivo = obter_arquivo_monitoramento_fila_jobs($baseDir);

    file_put_contents(
        $arquivo,
        json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        LOCK_EX
    );

    chmod($arquivo, 0600);
}

function registrar_heartbeat_worker_fila_jobs(string $baseDir, int $pid): void
{
    $monitoramento = ler_monitoramento_fila_jobs($baseDir);

    $monitoramento['heartbeatTimestamp'] = time();
    $monitoramento['heartbeatPid'] = $pid;

    salvar_monitoramento_fila_jobs($baseDir, $monitoramento);
}

function obter_atraso_heartbeat_fila_jobs(string $baseDir, ?int $agora = null): ?int
{
    $monitoramento = ler_monitoramento_fila_jobs($baseDir);
    $timestamp = (int) ($monitoramento['heartbeatTimestamp'] ?? 0);

    if ($timestamp <= 0) {
        return null;
    }

    $agora = $agora ?? time();

    return max(0, $agora - $timestamp);
}

function registrar_log_job(array $dados): void
{
    $payload = array_merge([
        'timestamp' => date(DATE_ATOM),
        'componente' => 'fila_jobs',
        'nivel' => 'info',
    ], $dados);

    $payload = normalizar_utf8($payload);
    $json = json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if ($json === false) {
        log_event('FilaJobs - falha ao serializar log estruturado: ' . json_last_error_msg());
        return;
    }

    log_event($json);
}

function ler_metricas_fila_jobs(string $baseDir): array
{
    $arquivo = obter_arquivo_metricas_fila_jobs($baseDir);
    if (is_file($arquivo)) {
        $json = json_decode((string) file_get_contents($arquivo), true);
        if (is_array($json)) {
            return $json;
        }
    }

    return [
        'processados'          => 0,
        'falhas'               => 0,
        'duracaoTotalSegundos' => 0.0,
        'emProcessamento'      => 0,
        'spawnFalhou'          => 0,
        'ultimaAtualizacao'    => time(),
    ];
}

function salvar_metricas_fila_jobs(string $baseDir, array $metricas): void
{
    inicializar_fila_jobs($baseDir);
    file_put_contents(
        obter_arquivo_metricas_fila_jobs($baseDir),
        json_encode($metricas, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        LOCK_EX
    );
}

function ajustar_metricas_em_processamento(string $baseDir, int $delta): void
{
    $metricas = ler_metricas_fila_jobs($baseDir);
    $metricas['emProcessamento'] = max(0, (int) ($metricas['emProcessamento'] ?? 0) + $delta);
    $metricas['ultimaAtualizacao'] = time();
    salvar_metricas_fila_jobs($baseDir, $metricas);
}

function registrar_metricas_processamento(string $baseDir, float $duracaoSegundos, bool $sucesso): void
{
    $metricas = ler_metricas_fila_jobs($baseDir);

    $metricas['duracaoTotalSegundos'] = ($metricas['duracaoTotalSegundos'] ?? 0) + $duracaoSegundos;
    if ($sucesso) {
        $metricas['processados'] = ($metricas['processados'] ?? 0) + 1;
    } else {
        $metricas['falhas'] = ($metricas['falhas'] ?? 0) + 1;
    }

    $metricas['ultimaAtualizacao'] = time();
    salvar_metricas_fila_jobs($baseDir, $metricas);
}

function registrar_historico_reprocessamento(string $baseDir, array $dados): void
{
    $arquivo = obter_arquivo_historico_reprocessamento($baseDir);
    $historico = [];

    if (is_file($arquivo)) {
        $json = json_decode((string) file_get_contents($arquivo), true);
        if (is_array($json)) {
            $historico = $json;
        }
    }

    $historico[] = $dados;
    if (count($historico) > FILA_JOBS_HISTORICO_REPROCESSAMENTO_LIMITE) {
        $historico = array_slice($historico, -1 * FILA_JOBS_HISTORICO_REPROCESSAMENTO_LIMITE);
    }

    file_put_contents(
        $arquivo,
        json_encode($historico, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        LOCK_EX
    );
}

function registrar_catalogo_erro(string $baseDir, string $categoria, string $descricao): void
{
    $arquivo = obter_arquivo_catalogo_erros_fila_jobs($baseDir);
    $catalogo = [];

    if (is_file($arquivo)) {
        $json = json_decode((string) file_get_contents($arquivo), true);
        if (is_array($json)) {
            $catalogo = $json;
        }
    }

    $categoria = $categoria === 'definitivo' ? 'definitivo' : 'temporario';
    $descricaoNormalizada = preg_replace('/\s+/', ' ', mb_strtolower(trim($descricao), 'UTF-8'));
    $descricaoNormalizada = mb_substr($descricaoNormalizada, 0, 180, 'UTF-8');

    if (!isset($catalogo[$categoria][$descricaoNormalizada])) {
        $catalogo[$categoria][$descricaoNormalizada] = [
            'descricao'        => $descricao,
            'ocorrencias'      => 0,
            'primeiraOcorrencia' => time(),
            'ultimaOcorrencia' => time(),
        ];
    }

    $catalogo[$categoria][$descricaoNormalizada]['ocorrencias']++;
    $catalogo[$categoria][$descricaoNormalizada]['ultimaOcorrencia'] = time();

    file_put_contents(
        $arquivo,
        json_encode($catalogo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        LOCK_EX
    );
}

function registrar_alerta_job(string $titulo, array $dados): void
{
    $dados['alerta'] = true;
    $dados['nivel'] = 'alert';
    registrar_log_job($dados);

    $emails = getenv('FILA_JOBS_ALERT_EMAILS');
    if (is_string($emails) && trim($emails) !== '') {
        $lista = array_filter(array_map('trim', explode(',', $emails)));
        if ($lista) {
            $mensagem = $titulo . "\n\n" . json_encode($dados, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            foreach ($lista as $email) {
                @mail($email, '[Alerta] Fila de jobs', $mensagem);
            }
        }
    }

    $webhook = getenv('FILA_JOBS_SLACK_WEBHOOK');
    if (is_string($webhook) && trim($webhook) !== '') {
        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\n",
                'content' => json_encode(['text' => $titulo . "\n" . json_encode($dados, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]),
                'timeout' => 5,
            ],
        ]);

        @file_get_contents($webhook, false, $context);
    }
}

function registrar_job_fila(string $tipo, array $dados, string $baseDir): string
{
    inicializar_fila_jobs($baseDir);

    $jobId = date('YmdHis') . '_' . bin2hex(random_bytes(8));
    $arquivo = obter_diretorio_fila_jobs($baseDir) . DIRECTORY_SEPARATOR . $jobId . '.json';

    $job = [
        'id'         => $jobId,
        'tipo'       => $tipo,
        'dados'      => $dados,
        'status'     => 'pendente',
        'tentativas' => 0,
        'criadoEm'   => time(),
    ];

    $job = normalizar_utf8($job);
    $conteudo = json_encode(
        $job,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE
    );

    file_put_contents($arquivo, $conteudo, LOCK_EX);
    chmod($arquivo, 0600);

    disparar_processamento_fila_jobs($baseDir);
    registrar_verificacao_periodica_fila_jobs($baseDir);

    return $jobId;
}

function montar_urls_sincronizacao_cliente_pontual(): array
{
    $urls = [
        'https://www.redutoresibr.com.br/AreaRestrita/sincronizacliente/',
        'https://www.redutoresibr.com.br/AreaRestrita/sincronizacliente',
    ];

    $hostAtual = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($hostAtual !== '') {
        $hostAtual = preg_replace('/[^a-z0-9.\-:]/i', '', $hostAtual);
        if ($hostAtual !== '') {
            $urls[] = 'https://' . $hostAtual . '/AreaRestrita/sincronizacliente/';
            $urls[] = 'https://' . $hostAtual . '/AreaRestrita/sincronizacliente';
        }
    }

    return array_values(array_unique($urls));
}

function disparar_sincronizacao_cliente_background(string $authToken = ''): void
{
    $urls = montar_urls_sincronizacao_cliente_pontual();
    $tokenNormalizado = trim($authToken);
    $cabecalhoCookie = $tokenNormalizado !== ''
        ? 'auth_token=' . rawurlencode($tokenNormalizado)
        : '';

    if (function_exists('curl_init')) {
        foreach ($urls as $url) {
            $ch = curl_init($url);
            if ($ch === false) {
                continue;
            }

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT_MS => 800,
                CURLOPT_TIMEOUT_MS => 1400,
                CURLOPT_NOSIGNAL => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            if ($cabecalhoCookie !== '') {
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Cookie: ' . $cabecalhoCookie]);
            }

            @curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 400) {
                return;
            }
        }
        return;
    }

    $headers = [];
    if ($cabecalhoCookie !== '') {
        $headers[] = 'Cookie: ' . $cabecalhoCookie;
    }

    foreach ($urls as $url) {
        $contexto = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 1,
                'ignore_errors' => true,
                'header' => implode("\r\n", $headers),
            ],
        ]);
        @file_get_contents($url, false, $contexto);
    }
}

function resumir_dados_job(array $dados, int $limite = 800): string
{
    $dados = normalizar_utf8($dados);
    $dadosJson = json_encode(
        $dados,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if ($dadosJson === false) {
        return 'dados_invalidos';
    }

    if (strlen($dadosJson) <= $limite) {
        return $dadosJson;
    }

    return substr($dadosJson, 0, $limite) . '... [truncado]';
}

function ler_job_fila(string $arquivo): ?array
{
    if (!is_file($arquivo)) {
        return null;
    }

    $conteudo = file_get_contents($arquivo);
    if ($conteudo === false) {
        return null;
    }

    $json = json_decode($conteudo, true);
    if (!is_array($json)) {
        return null;
    }

    if (empty($json['tipo'])) {
        return null;
    }

    if (empty($json['status'])) {
        $json['status'] = 'pendente';
    }

    $json['__arquivo'] = $arquivo;

    return $json;
}

function job_esta_apto_para_processar(array $job, int $agora): bool
{
    $status = $job['status'] ?? 'pendente';
    $tentativas = (int) ($job['tentativas'] ?? 0);

    if ($tentativas >= FILA_JOBS_MAX_TENTATIVAS) {
        return false;
    }

    if ($status === 'pendente') {
        return true;
    }

    if ($status === 'erro' || $status === 'reprocessamento') {
        $proximaTentativa = (int) ($job['proximaTentativa'] ?? 0);
        return $proximaTentativa <= $agora;
    }

    if ($status === 'falha_definitiva' || $status === 'dead_letter') {
        return false;
    }

    return false;
}

function salvar_job_fila(array $job): void
{
    if (empty($job['__arquivo'])) {
        return;
    }

    $arquivo = $job['__arquivo'];
    unset($job['__arquivo']);

    $job = normalizar_utf8($job);
    $conteudo = json_encode(
        $job,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE
    );
    file_put_contents($arquivo, $conteudo, LOCK_EX);
}

function normalizar_utf8($valor)
{
    if (is_array($valor)) {
        foreach ($valor as $chave => $item) {
            $valor[$chave] = normalizar_utf8($item);
        }
        return $valor;
    }

    if (is_string($valor) && !mb_check_encoding($valor, 'UTF-8')) {
        $corrigido = @iconv('UTF-8', 'UTF-8//IGNORE', $valor);
        if ($corrigido !== false) {
            return $corrigido;
        }
    }

    return $valor;
}

function atualizar_job_fila(array $job, array $dadosAtualizados): void
{
    if (empty($job['__arquivo'])) {
        return;
    }

    $tentativasAtuais = $job['tentativas'] ?? 0;
    $jobAtualizado = array_merge($job, $dadosAtualizados);
    $jobAtualizado['tentativas'] = $tentativasAtuais + 1;
    $jobAtualizado['atualizadoEm'] = time();

    salvar_job_fila($jobAtualizado);
}

function calcular_proxima_tentativa(int $tentativasAtuais, int $agora): int
{
    $indice = min($tentativasAtuais, count(FILA_JOBS_BACKOFF_INTERVALOS) - 1);
    $intervalo = FILA_JOBS_BACKOFF_INTERVALOS[$indice] ?? 60;

    return $agora + $intervalo;
}

function mover_job_para_dead_letter(array $job, string $baseDir): ?string
{
    if (empty($job['__arquivo']) || !is_file($job['__arquivo'])) {
        return null;
    }

    $destinoDir = obter_diretorio_dead_letter_jobs($baseDir);
    if (!is_dir($destinoDir)) {
        mkdir($destinoDir, 0700, true);
    }

    $destino = $destinoDir . DIRECTORY_SEPARATOR . basename($job['__arquivo']);
    if (@rename($job['__arquivo'], $destino)) {
        return $destino;
    }

    return null;
}

function remover_job_fila(array $job): void
{
    if (empty($job['__arquivo'])) {
        return;
    }

    $arquivo = $job['__arquivo'];

    if (is_file($arquivo)) {
        @unlink($arquivo);
    }
}

function listar_arquivos_jobs_pendentes(string $baseDir): array
{
    $dir = obter_diretorio_fila_jobs($baseDir);
    if (!is_dir($dir)) {
        return [];
    }

    $arquivos = glob($dir . DIRECTORY_SEPARATOR . '*.json');
    if (!$arquivos) {
        return [];
    }

        $arquivoMonitoramento = basename(obter_arquivo_monitoramento_fila_jobs($baseDir));
    $arquivos = array_values(array_filter($arquivos, function (string $arquivo) use ($arquivoMonitoramento): bool {
        return basename($arquivo) !== $arquivoMonitoramento;
    }));

    sort($arquivos, SORT_STRING);

    return $arquivos;
}

function coletar_estatisticas_fila_jobs(string $baseDir): array
{
    $arquivos = listar_arquivos_jobs_pendentes($baseDir);
    $pendentes = 0;
    $reprocessamento = 0;

    foreach ($arquivos as $arquivo) {
        $job = ler_job_fila($arquivo);
        if ($job === null) {
            continue;
        }

        $status = $job['status'] ?? 'pendente';
        if ($status === 'pendente') {
            $pendentes++;
        }

        if ($status === 'reprocessamento' || $status === 'erro') {
            $reprocessamento++;
        }
    }

    $deadLetterDir = obter_diretorio_dead_letter_jobs($baseDir);
    $deadLetter = is_dir($deadLetterDir) ? glob($deadLetterDir . DIRECTORY_SEPARATOR . '*.json') : [];

    return [
        'pendentes'          => $pendentes,
        'reprocessamento'    => $reprocessamento,
        'deadLetterTamanho'  => $deadLetter ? count($deadLetter) : 0,
    ];
}

function montar_metricas_para_prometheus(string $baseDir): array
{
    $metricasPersistidas = ler_metricas_fila_jobs($baseDir);
    $estatisticasFila = coletar_estatisticas_fila_jobs($baseDir);

    $processados = (int) ($metricasPersistidas['processados'] ?? 0);
    $falhas = (int) ($metricasPersistidas['falhas'] ?? 0);
    $duracaoTotal = (float) ($metricasPersistidas['duracaoTotalSegundos'] ?? 0);
    $spawnFalhou = (int) ($metricasPersistidas['spawnFalhou'] ?? 0);

    $totalExecutados = $processados + $falhas;
    $tempoMedio = $totalExecutados > 0 ? $duracaoTotal / $totalExecutados : 0.0;
    $taxaFalhas = $totalExecutados > 0 ? $falhas / $totalExecutados : 0.0;

    $atrasoHeartbeat = obter_atraso_heartbeat_fila_jobs($baseDir);
    $atrasoHeartbeat = $atrasoHeartbeat === null ? -1 : $atrasoHeartbeat;

    return [
        'tempoMedio'           => $tempoMedio,
        'taxaFalhas'           => $taxaFalhas,
        'emProcessamento'      => (int) ($metricasPersistidas['emProcessamento'] ?? 0),
        'reprocessamento'      => $estatisticasFila['reprocessamento'],
        'deadLetterTamanho'    => $estatisticasFila['deadLetterTamanho'],
        'pendentes'            => $estatisticasFila['pendentes'],
        'processados'          => $processados,
        'falhas'               => $falhas,
        'duracaoTotalSegundos' => $duracaoTotal,
        'spawnFalhou'          => $spawnFalhou,
        'atrasoHeartbeat'      => $atrasoHeartbeat,
    ];
}

function verificar_fila_jobs_periodicamente(string $baseDir, int $intervaloSegundos = 15): void
{
    $monitoramento = ler_monitoramento_fila_jobs($baseDir);
    $agora = time();
    $ultimaExecucao = (int) ($monitoramento['ultimaExecucao'] ?? 0);

    if ($agora - $ultimaExecucao < $intervaloSegundos) {
        return;
    }

    $monitoramento['ultimaExecucao'] = $agora;
    $monitoramento['intervalo'] = $intervaloSegundos;

    salvar_monitoramento_fila_jobs($baseDir, $monitoramento);

    disparar_processamento_fila_jobs($baseDir);
}

function registrar_verificacao_periodica_fila_jobs(?string $baseDir = null, int $intervaloSegundos = 15): void
{
    static $registrado = false;
    if ($registrado) {
        return;
    }

    $registrado = true;
    $baseDir = $baseDir ?: (isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : dirname(__DIR__));

    register_shutdown_function(function () use ($baseDir, $intervaloSegundos) {
        try {
            verificar_fila_jobs_periodicamente($baseDir, $intervaloSegundos);
        } catch (Throwable $t) {
            log_event('FilaJobs - falha na verificação periódica: ' . $t->getMessage());
        }
    });
}

function disparar_processamento_fila_jobs(string $baseDir): void
{

    $script = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ProcessamentoFilaJobs' . DIRECTORY_SEPARATOR . 'ExecutaFilaProcessamento.php';
    if (!is_file($script)) {
        return;
    }

    $comando = escapeshellcmd(PHP_BINARY ?: 'php') . ' ' . escapeshellarg($script);

    $disabledFunctions = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    $processStarted = false;

        $comandoContexto = [
        'comando'            => $comando,
        'phpBinary'          => PHP_BINARY ?: 'php',
        'phpSapi'            => PHP_SAPI,
        'phpVersion'         => PHP_VERSION,
        'osFamily'           => PHP_OS_FAMILY,
        'disableFunctions'   => $disabledFunctions,
        'script'             => $script,
        'baseDir'            => $baseDir,
    ];

    if (stripos(PHP_OS_FAMILY, 'Windows') === 0) {
        if (function_exists('popen') && !in_array('popen', $disabledFunctions, true)) {
            $handle = @popen('start /B ' . $comando, 'r');
            if (is_resource($handle)) {
                @pclose($handle);
                $processStarted = true;
            }
        }
    } else {
        if (function_exists('exec') && !in_array('exec', $disabledFunctions, true)) {
            $output = [];
            $returnVar = null;
            @exec($comando . ' > /dev/null 2>&1 &', $output, $returnVar);

            // Quando exec está desabilitado ou a chamada retorna erro, $returnVar não será 0.
            $processStarted = ($returnVar === 0);
        }
    }

    if (!$processStarted) {
        $metricas = ler_metricas_fila_jobs($baseDir);
        $metricas['spawnFalhou'] = ($metricas['spawnFalhou'] ?? 0) + 1;
        $metricas['ultimaAtualizacao'] = time();
        salvar_metricas_fila_jobs($baseDir, $metricas);

        registrar_log_job([
            'evento'   => 'processamento_spawn_falhou',
            'mensagem' => 'Falha ao iniciar processamento da fila em background; executando de forma síncrona.',
            'dados'    => $comandoContexto,
        ]);

        registrar_alerta_job(
            'FilaJobs - falha ao iniciar worker em background',
            [
                'mensagem' => 'O worker da fila não pôde ser iniciado com exec/popen; fallback síncrono acionado.',
                'detalhes' => $comandoContexto,
            ]
        );

        // Ambiente sem acesso a comandos em segundo plano: processa a fila de forma síncrona.
        include $script;
    }
}
