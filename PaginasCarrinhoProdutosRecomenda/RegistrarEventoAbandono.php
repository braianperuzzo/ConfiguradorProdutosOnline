<?php
header('Content-Type: application/json; charset=UTF-8');
$baseDir = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
if ($baseDir === '') {
    $baseDir = dirname(__DIR__);
}
require $baseDir . '/Seguranca/MetodosSegurancao.php';
require_once $baseDir . '/ProcessamentoFilaJobs/CriaFilaProcessamento.php';

const TREINO_ABANDONO_INTERVALO_SEGUNDOS = 21600;
const TREINO_ABANDONO_LOG_RETENCAO_DIAS = 30;
const METRICAS_ABANDONO_INTERVALO_SEGUNDOS = 21600;
const ABANDONO_PSEUDO_SALT_ROTACAO_DIAS = 7;
const ABANDONO_USER_AGENT_TAMANHO_MAX = 256;
const ABANDONO_ORIGEM_TAMANHO_MAX = 512;
const QUALIDADE_ABANDONO_MAX_INVALIDOS = 1;
const ABANDONO_INFERENCIA_BUSCA_DIAS = 14;
const ABANDONO_INFERENCIA_EVENTO_FINAL_TIPOS = ['conversao', 'abandono'];

/**
 * Política de retenção e dados coletados:
 * - Retenção: logs de eventos de abandono são mantidos por até TREINO_ABANDONO_LOG_RETENCAO_DIAS dias.
 * - Dados coletados: evento, timestamp, métricas agregadas (tempo/valor/quantidade), origem normalizada,
 *   userAgent truncado, dados adicionais fornecidos pelo front e identificadores pseudonimizados.
 * - Identificadores: cdPessoa e sessaoId são pseudonimizados via hash com salt rotativo.
 * - Consentimento: se o front enviar opt-out/consentimento falso, o evento não é registrado.
 */

function normalizar_origem(?string $origem): ?string
{
    if ($origem === null || trim($origem) === '') {
        return null;
    }

    $origem = trim($origem);
    $partes = parse_url($origem);
    if (!is_array($partes) || empty($partes['host'])) {
        $origem = preg_replace('/\s+/', ' ', $origem);
        return mb_substr($origem, 0, ABANDONO_ORIGEM_TAMANHO_MAX);
    }

    $scheme = isset($partes['scheme']) ? strtolower($partes['scheme']) : 'https';
    $host = strtolower($partes['host']);
    $path = $partes['path'] ?? '/';
    $normalizado = $scheme . '://' . $host . $path;
    return mb_substr($normalizado, 0, ABANDONO_ORIGEM_TAMANHO_MAX);
}

function normalizar_user_agent(?string $userAgent): ?string
{
    if ($userAgent === null || trim($userAgent) === '') {
        return null;
    }

    $userAgent = preg_replace('/\s+/', ' ', trim($userAgent));
    return mb_substr($userAgent, 0, ABANDONO_USER_AGENT_TAMANHO_MAX);
}

function gerar_salt_rotativo(): string
{
    $segredo = getenv('JWT_SECRET') ?: 'abandono-fallback-secret';
    $periodo = (int) floor(time() / (ABANDONO_PSEUDO_SALT_ROTACAO_DIAS * 86400));
    return hash('sha256', $segredo . '|' . $periodo);
}

function pseudonimizar_identificador(?string $valor): ?string
{
    if ($valor === null || $valor === '') {
        return null;
    }

    $salt = gerar_salt_rotativo();
    return hash_hmac('sha256', $valor, $salt);
}

function obter_consentimento($valor): bool
{
    if (is_bool($valor)) {
        return $valor;
    }
    if (is_int($valor)) {
        return $valor === 1;
    }
    if (is_string($valor)) {
        $valor = strtolower(trim($valor));
        return in_array($valor, ['1', 'true', 'sim', 'yes'], true);
    }
    return false;
}

function obter_cookie_treinamento_ia_consentido(): bool
{
    $raw = $_COOKIE['lgpd-cookies-consent'] ?? '';
    if (!is_string($raw) || trim($raw) === '') {
        return false;
    }

    $consent = json_decode(urldecode($raw), true);
    if (!is_array($consent)) {
        return false;
    }

    return !empty($consent['AITraining']);
}

function obter_status_treino_abandono(string $baseDir): array
{
    $arquivo = $baseDir . '/PaginasCarrinhoProdutosRecomenda/IATreinoModeloAbandono.json';
    if (!is_file($arquivo)) {
        return ['__arquivo' => $arquivo];
    }
    $json = json_decode((string) file_get_contents($arquivo), true);
    if (!is_array($json)) {
        return ['__arquivo' => $arquivo];
    }
    $json['__arquivo'] = $arquivo;
    return $json;
}

function salvar_status_treino_abandono(array $status): void
{
    $arquivo = $status['__arquivo'] ?? '';
    if ($arquivo === '') {
        return;
    }
    unset($status['__arquivo']);
    file_put_contents(
        $arquivo,
        json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        LOCK_EX
    );
}

function obter_status_metricas_abandono(string $baseDir): array
{
    $arquivo = $baseDir . '/PaginasCarrinhoProdutosRecomenda/IAMetricasAbandonoStatus.json';
    if (!is_file($arquivo)) {
        return ['__arquivo' => $arquivo];
    }
    $json = json_decode((string) file_get_contents($arquivo), true);
    if (!is_array($json)) {
        return ['__arquivo' => $arquivo];
    }
    $json['__arquivo'] = $arquivo;
    return $json;
}

function carregar_schema_abandono(string $baseDir): ?array
{
    $schemaPath = $baseDir . '/PaginasCarrinhoProdutosRecomenda/IASchemaAbandono.json';
    if (!is_file($schemaPath)) {
        return null;
    }
    $schema = json_decode((string) file_get_contents($schemaPath), true);
    if (!is_array($schema)) {
        return null;
    }
    if (!isset($schema['limites'], $schema['mapeamentos']) || !is_array($schema['limites'])) {
        return null;
    }
    return $schema;
}

function obter_limites_padrao(): array
{
    return [
        'tempoParadoMs' => ['min' => 0.0, 'max' => 14400000.0],
        'valorCarrinho' => ['min' => 0.0, 'max' => 1000000.0],
        'quantidadeItens' => ['min' => 0.0, 'max' => 500.0],
        'tempoDesdeInicioMs' => ['min' => 0.0, 'max' => 28800000.0],
        'totalEventosSessao' => ['min' => 0.0, 'max' => 10000.0],
        'eventosAbandonoSessao' => ['min' => 0.0, 'max' => 200.0],
        'tempoAtivoTotalMs' => ['min' => 0.0, 'max' => 28800000.0],
        'profundidadeScroll' => ['min' => 0.0, 'max' => 100.0],
        'totalModaisAbertos' => ['min' => 0.0, 'max' => 200.0],
        'interacoesRecomendacoes' => ['min' => 0.0, 'max' => 500.0],
    ];
}

function obter_limites_campos(string $baseDir): array
{
    $tipos = [
        'tempoParadoMs' => 'int',
        'valorCarrinho' => 'float',
        'quantidadeItens' => 'int',
        'tempoDesdeInicioMs' => 'int',
        'totalEventosSessao' => 'int',
        'eventosAbandonoSessao' => 'int',
        'tempoAtivoTotalMs' => 'int',
        'profundidadeScroll' => 'float',
        'totalModaisAbertos' => 'int',
        'interacoesRecomendacoes' => 'int',
    ];
    $schema = carregar_schema_abandono($baseDir);
    if (!$schema) {
        $padrao = obter_limites_padrao();
        foreach ($padrao as $campo => $limite) {
            $padrao[$campo]['tipo'] = $tipos[$campo] ?? 'float';
        }
        return $padrao;
    }

    $limites = [];
    foreach ($schema['mapeamentos'] as $feature => $mapa) {
        if (!isset($mapa['campo'], $schema['limites'][$feature])) {
            continue;
        }
        $campo = $mapa['campo'];
        $limites[$campo] = [
            'min' => (float) $schema['limites'][$feature]['min'],
            'max' => (float) $schema['limites'][$feature]['max'],
            'tipo' => $tipos[$campo] ?? 'float',
        ];
    }
    return $limites ?: obter_limites_padrao();
}

function normalizar_valor_abandono(
    string $campo,
    $valor,
    float $minimo,
    float $maximo,
    array &$contadores,
    string $tipo
) {
    if ($valor === null || (is_string($valor) && trim($valor) === '')) {
        if (!isset($contadores['ausentes'][$campo])) {
            $contadores['ausentes'][$campo] = 0;
        }
        $contadores['ausentes'][$campo] += 1;
        $valor = $minimo;
    } elseif (!is_numeric($valor)) {
        if (!isset($contadores['invalidos'][$campo])) {
            $contadores['invalidos'][$campo] = 0;
        }
        $contadores['invalidos'][$campo] += 1;
        $valor = $minimo;
    } else {
        $valor = (float) $valor;
    }

    if ($valor < $minimo) {
        if (!isset($contadores['ajustados'][$campo])) {
            $contadores['ajustados'][$campo] = 0;
        }
        $contadores['ajustados'][$campo] += 1;
        $valor = $minimo;
    } elseif ($valor > $maximo) {
        if (!isset($contadores['ajustados'][$campo])) {
            $contadores['ajustados'][$campo] = 0;
        }
        $contadores['ajustados'][$campo] += 1;
        $valor = $maximo;
    }

    return $tipo === 'int' ? (int) round($valor) : (float) $valor;
}

function contar_campos_criticos_invalidos(array $campos, array $contadores): int
{
    $invalidos = 0;
    foreach ($campos as $campo) {
        if (!empty($contadores['ausentes'][$campo]) || !empty($contadores['invalidos'][$campo])) {
            $invalidos += 1;
        }
    }
    return $invalidos;
}

function registrar_log_qualidade(string $baseDir, array $registro): void
{
    $logDir = $baseDir . '/PaginasCarrinhoProdutosRecomenda/Logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0775, true);
    }

    $arquivo = $logDir . '/qualidade-abandono-' . date('Y-m-d') . '.jsonl';
    $linha = json_encode($registro, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    file_put_contents($arquivo, $linha, FILE_APPEND | LOCK_EX);
}

function registrar_log_inferencia_final(string $baseDir, array $registro): void
{
    $logDir = $baseDir . '/PaginasCarrinhoProdutosRecomenda/Logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0775, true);
    }

    $arquivo = $logDir . '/inferencias-abandono-final-' . date('Y-m-d') . '.jsonl';
    $linha = json_encode($registro, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    file_put_contents($arquivo, $linha, FILE_APPEND | LOCK_EX);
}

function carregar_inferencia_recente(string $baseDir, ?string $sessaoHash, ?string $cdPessoaHash): ?array
{
    $logDir = $baseDir . '/PaginasCarrinhoProdutosRecomenda/Logs';
    if (!is_dir($logDir)) {
        return null;
    }

    $arquivos = glob($logDir . '/inferencias-abandono-*.jsonl');
    if (!$arquivos) {
        return null;
    }

    rsort($arquivos);
    $limite = strtotime('-' . ABANDONO_INFERENCIA_BUSCA_DIAS . ' days');

    foreach ($arquivos as $arquivo) {
        $nome = basename($arquivo, '.jsonl');
        $partes = explode('inferencias-abandono-', $nome);
        if (count($partes) !== 2) {
            continue;
        }
        $dataLog = strtotime($partes[1]);
        if ($dataLog !== false && $dataLog < $limite) {
            continue;
        }

        $linhas = @file($arquivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($linhas)) {
            continue;
        }

        for ($i = count($linhas) - 1; $i >= 0; $i--) {
            $linha = trim($linhas[$i]);
            if ($linha === '') {
                continue;
            }
            $registro = json_decode($linha, true);
            if (!is_array($registro)) {
                continue;
            }
            $sessaoRegistro = isset($registro['sessaoId']) ? (string) $registro['sessaoId'] : null;
            $clienteRegistro = isset($registro['cdPessoa']) ? (string) $registro['cdPessoa'] : null;

            $bateSessao = $sessaoHash && $sessaoRegistro && hash_equals($sessaoHash, $sessaoRegistro);
            $bateCliente = $cdPessoaHash && $clienteRegistro && hash_equals($cdPessoaHash, $clienteRegistro);
            if ($bateSessao || $bateCliente) {
                return $registro;
            }
        }
    }

    return null;
}

function normalizar_evento_final(?string $evento): ?string
{
    if ($evento === null) {
        return null;
    }
    $evento = strtolower(trim($evento));
    if ($evento === '') {
        return null;
    }
    if (strpos($evento, 'conversao') === 0) {
        return 'conversao';
    }
    if (strpos($evento, 'abandono') === 0) {
        return 'abandono';
    }
    return null;
}

function salvar_status_metricas_abandono(array $status): void
{
    $arquivo = $status['__arquivo'] ?? '';
    if ($arquivo === '') {
        return;
    }
    unset($status['__arquivo']);
    file_put_contents(
        $arquivo,
        json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        LOCK_EX
    );
}

function treino_abandono_pendente(array $status, string $baseDir): bool
{
    $jobId = trim((string) ($status['ultimoJobId'] ?? ''));
    if ($jobId === '') {
        return false;
    }

    $arquivoJob = obter_diretorio_fila_jobs($baseDir) . DIRECTORY_SEPARATOR . $jobId . '.json';
    $job = ler_job_fila($arquivoJob);
    if (!$job) {
        return false;
    }

    $statusJob = $job['status'] ?? 'pendente';
    return !in_array($statusJob, ['processado', 'dead_letter'], true);
}

function metricas_abandono_pendente(array $status, string $baseDir): bool
{
    $jobId = trim((string) ($status['ultimoJobId'] ?? ''));
    if ($jobId === '') {
        return false;
    }

    $arquivoJob = obter_diretorio_fila_jobs($baseDir) . DIRECTORY_SEPARATOR . $jobId . '.json';
    $job = ler_job_fila($arquivoJob);
    if (!$job) {
        return false;
    }

    $statusJob = $job['status'] ?? 'pendente';
    return !in_array($statusJob, ['processado', 'dead_letter'], true);
}

function agendar_treino_modelo_abandono(string $baseDir): void
{
    $status = obter_status_treino_abandono($baseDir);
    $ultimoTreino = (int) ($status['ultimoTreinoEm'] ?? 0);
    if ($ultimoTreino > 0 && (time() - $ultimoTreino) < TREINO_ABANDONO_INTERVALO_SEGUNDOS) {
        return;
    }

    if (treino_abandono_pendente($status, $baseDir)) {
        return;
    }

    $dadosJob = [
        'logsDir' => $baseDir . '/PaginasCarrinhoProdutosRecomenda/Logs',
        'saidaModelo' => $baseDir . '/PaginasCarrinhoProdutosRecomenda/IAModeloAbandono.json',
        'limparLogsDias' => TREINO_ABANDONO_LOG_RETENCAO_DIAS,
    ];
    $jobId = registrar_job_fila('treinar_modelo_abandono', $dadosJob, $baseDir);

    $status['ultimoAgendamentoEm'] = time();
    $status['ultimoJobId'] = $jobId;
    salvar_status_treino_abandono($status);
}

function agendar_metricas_abandono(string $baseDir): void
{
    $status = obter_status_metricas_abandono($baseDir);
    $ultimaExecucao = (int) ($status['ultimaExecucaoEm'] ?? 0);
    if ($ultimaExecucao > 0 && (time() - $ultimaExecucao) < METRICAS_ABANDONO_INTERVALO_SEGUNDOS) {
        return;
    }

    if (metricas_abandono_pendente($status, $baseDir)) {
        return;
    }

    $dadosJob = [
        'logsDir' => $baseDir . '/PaginasCarrinhoProdutosRecomenda/Logs',
        'saidaMetricas' => $baseDir . '/PaginasCarrinhoProdutosRecomenda/IAMetricasAbandono.json',
        'limparInferenciasDias' => TREINO_ABANDONO_LOG_RETENCAO_DIAS,
    ];
    $jobId = registrar_job_fila('metricas_abandono', $dadosJob, $baseDir);

    $status['ultimoAgendamentoEm'] = time();
    $status['ultimoJobId'] = $jobId;
    salvar_status_metricas_abandono($status);
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['erro' => 'Payload inválido.']);
    exit;
}

$consentimentoPayload = isset($payload['consentimento'])
    ? obter_consentimento($payload['consentimento'])
    : true;
$consentimentoCookie = obter_cookie_treinamento_ia_consentido();

if (!$consentimentoPayload || !$consentimentoCookie) {
    echo json_encode([
        'sucesso' => true,
        'registrado' => false,
        'motivo' => !$consentimentoCookie ? 'cookie_treinamento_ia_obrigatorio' : 'opt_out'
    ]);
    exit;
}

$camposCriticos = ['evento', 'tempoParadoMs', 'valorCarrinho'];
$limitesCampos = obter_limites_campos($baseDir);
$contadoresQualidade = [
    'ausentes' => [],
    'invalidos' => [],
    'ajustados' => [],
];
$valoresNormalizados = [];
foreach ($limitesCampos as $campo => $limite) {
    $valoresNormalizados[$campo] = normalizar_valor_abandono(
        $campo,
        $payload[$campo] ?? null,
        (float) $limite['min'],
        (float) $limite['max'],
        $contadoresQualidade,
        $limite['tipo']
    );
}

$evento = $payload['evento'] ?? null;
$eventoNormalizado = null;
if (!is_scalar($evento) || trim((string) $evento) === '') {
    if (!isset($contadoresQualidade['ausentes']['evento'])) {
        $contadoresQualidade['ausentes']['evento'] = 0;
    }
    $contadoresQualidade['ausentes']['evento'] += 1;
} else {
    $eventoNormalizado = strtolower(trim((string) $evento));
}

$invalidosCriticos = contar_campos_criticos_invalidos($camposCriticos, $contadoresQualidade);
$totaisQualidade = [
    'ausentes' => array_sum($contadoresQualidade['ausentes']),
    'invalidos' => array_sum($contadoresQualidade['invalidos']),
    'ajustados' => array_sum($contadoresQualidade['ajustados']),
];

$motivoRejeicao = null;
if ($eventoNormalizado === null) {
    $motivoRejeicao = 'evento_ausente';
} elseif ($invalidosCriticos > QUALIDADE_ABANDONO_MAX_INVALIDOS) {
    $motivoRejeicao = 'qualidade_baixa';
}

registrar_log_qualidade($baseDir, [
    'timestamp' => date('c'),
    'evento' => $eventoNormalizado,
    'campos_criticos' => $camposCriticos,
    'invalidos_criticos' => $invalidosCriticos,
    'contadores' => $contadoresQualidade,
    'totais' => $totaisQualidade,
    'rejeitado' => $motivoRejeicao !== null,
    'motivo_rejeicao' => $motivoRejeicao,
]);

if ($motivoRejeicao === 'evento_ausente') {
    http_response_code(400);
    echo json_encode(['erro' => 'Evento não informado.', 'motivo' => 'evento_ausente']);
    exit;
}

if ($motivoRejeicao === 'qualidade_baixa') {
    http_response_code(400);
    echo json_encode(['erro' => 'Evento rejeitado por baixa qualidade.', 'motivo' => 'qualidade_baixa']);
    exit;
}

$cdPessoa = isset($payload['cdPessoa']) ? preg_replace('/\D/', '', (string) $payload['cdPessoa']) : null;
$sessaoId = isset($payload['sessaoId']) ? preg_replace('/[^a-zA-Z0-9\-_]/', '', (string) $payload['sessaoId']) : null;
$sessaoHash = pseudonimizar_identificador($sessaoId);
$cdPessoaHash = pseudonimizar_identificador($cdPessoa);

$registro = [
    'evento' => $eventoNormalizado,
    'timestamp' => date('c'),
    'tempoParadoMs' => $valoresNormalizados['tempoParadoMs'] ?? null,
    'valorCarrinho' => $valoresNormalizados['valorCarrinho'] ?? null,
    'quantidadeItens' => $valoresNormalizados['quantidadeItens'] ?? null,
    'cdPessoa' => $cdPessoaHash,
    'sessaoId' => $sessaoHash,
    'tempoDesdeInicioMs' => $valoresNormalizados['tempoDesdeInicioMs'] ?? null,
    'totalEventosSessao' => $valoresNormalizados['totalEventosSessao'] ?? null,
    'eventosAbandonoSessao' => $valoresNormalizados['eventosAbandonoSessao'] ?? null,
    'tempoAtivoTotalMs' => $valoresNormalizados['tempoAtivoTotalMs'] ?? null,
    'profundidadeScroll' => $valoresNormalizados['profundidadeScroll'] ?? null,
    'totalModaisAbertos' => $valoresNormalizados['totalModaisAbertos'] ?? null,
    'interacoesRecomendacoes' => $valoresNormalizados['interacoesRecomendacoes'] ?? null,
    'origem' => normalizar_origem($_SERVER['HTTP_REFERER'] ?? null),
    'userAgent' => normalizar_user_agent($_SERVER['HTTP_USER_AGENT'] ?? null),
    'dados' => $payload['dados'] ?? null
];

$logDir = $baseDir . '/PaginasCarrinhoProdutosRecomenda/Logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0775, true);
}

$arquivo = $logDir . '/eventos-abandono-' . date('Y-m-d') . '.jsonl';
$linha = json_encode($registro, JSON_UNESCAPED_UNICODE) . PHP_EOL;

if (file_put_contents($arquivo, $linha, FILE_APPEND | LOCK_EX) === false) {
    http_response_code(500);
    echo json_encode(['erro' => 'Não foi possível registrar o evento.']);
    exit;
}

$eventoFinal = normalizar_evento_final($eventoNormalizado);
if ($eventoFinal && in_array($eventoFinal, ABANDONO_INFERENCIA_EVENTO_FINAL_TIPOS, true)) {
    $inferencia = carregar_inferencia_recente($baseDir, $sessaoHash, $cdPessoaHash);
    registrar_log_inferencia_final($baseDir, [
        'timestamp' => date('c'),
        'evento_final' => $eventoFinal,
        'evento_origem' => $eventoNormalizado,
        'sessaoId' => $sessaoHash,
        'cdPessoa' => $cdPessoaHash,
        'score' => $inferencia['score'] ?? null,
        'acao' => $inferencia['acao'] ?? null,
        'limiar' => $inferencia['limiar'] ?? null,
        'inferencia_timestamp' => $inferencia['timestamp'] ?? null,
        'inferencia_encontrada' => $inferencia !== null,
    ]);
}

agendar_treino_modelo_abandono($baseDir);
agendar_metricas_abandono($baseDir);

echo json_encode(['sucesso' => true]);
?>
