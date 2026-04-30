<?php
header('Content-Type: application/json; charset=UTF-8');

$inicio = microtime(true);
$documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
$baseDir = $documentRoot !== '' ? rtrim($documentRoot, DIRECTORY_SEPARATOR) : dirname(__DIR__);

require_once $baseDir . '/LogsErros/Logs.php';
require_once $baseDir . '/ProcessamentoFilaJobs/CriaFilaProcessamento.php';

function rag_normalizar_texto(string $valor): string
{
    $texto = trim($valor);
    if ($texto === '') {
        return '';
    }
    $texto = mb_strtolower($texto, 'UTF-8');
    $translit = iconv('UTF-8', 'ASCII//TRANSLIT', $texto);
    if ($translit !== false) {
        $texto = $translit;
    }
    $texto = preg_replace('/[^a-z0-9\s.]+/', ' ', $texto);
    $texto = str_replace('.', ' ', $texto);
    $texto = preg_replace('/\s+/', ' ', $texto);
    return trim($texto);
}

function rag_tokenizar(string $valor): array
{
    $normalizado = rag_normalizar_texto($valor);
    if ($normalizado === '') {
        return [];
    }
    $tokens = preg_split('/\s+/', $normalizado, -1, PREG_SPLIT_NO_EMPTY);
    return array_values(array_unique($tokens));
}

function rag_pontuar_documento(array $doc, array $tokens): int
{
    $textoBusca = (string) ($doc['textoBusca'] ?? '');
    if ($textoBusca === '' || $tokens === []) {
        return 0;
    }
    $score = 0;
    foreach ($tokens as $token) {
        if ($token === '') {
            continue;
        }
        $score += substr_count($textoBusca, $token);
        if (!empty($doc['titulo']) && stripos((string) $doc['titulo'], $token) !== false) {
            $score += 2;
        }
    }
    return $score;
}

function rag_carregar_indice(string $baseDir): array
{
    $arquivo = $baseDir . '/Tokens/Conhecimento/configuradores_index.json';
    if (!is_file($arquivo)) {
        return [];
    }
    $conteudo = file_get_contents($arquivo);
    if ($conteudo === false) {
        return [];
    }
    $json = json_decode($conteudo, true);
    if (!is_array($json)) {
        return [];
    }
    return $json;
}

function rag_enviar_resposta(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

function rag_mapa_linhas_conversao(): array
{
    return [
        '1.C' => 'IBRC',
        '1.FFA' => 'IBRPFFA',
        '1.FKA' => 'IBRXFKA',
        '1.FR' => 'IBRCFR',
        '1.H' => 'IBRH',
        '1.M' => 'IBRM',
        '1.P' => 'IBRP',
        '1.Q' => 'IBRQ',
        '1.QDR' => 'IBRQDR',
        '1.QP' => 'IBRQP',
        '1.R' => 'IBRR',
        '1.V' => 'IBRV',
        '1.VFN' => 'IBRVFN',
        '1.X' => 'IBRX',
        '1.Z' => 'IBRZ',
        '2.I' => 'IBRMSML',
        '3.APM' => 'ANTICORROSIVOSAPM',
        '3.GR' => 'IBRGR',
        '3.GS' => 'IBRGS',
        '3.I' => 'IBRT3AT3C',
        '3.PB' => 'IBRPB',
        '3.PBL' => 'IBRPBL',
        '3.RIC' => 'IBRRIC',
        '3.SA' => 'IBRSA',
        '3.SB' => 'IBRSB',
        '3.SBL' => 'IBRSBL',
        '3.SD' => 'IBRSD',
        '3.SPM' => 'ANTICORROSIVOSSPM',
        '3.W' => 'WEGALTORENDIMENTO',
        '4.K' => 'IBRK',
    ];
}

function rag_parametro_linha_configurador(string $linha): string
{
    $mapa = [
        '1.Q' => 'QULN',
        '1.QDR' => 'QULN',
        '1.QP' => 'QULN',
        '1.C' => 'HYLN',
        '1.H' => 'HYLN',
        '1.M' => 'HYLN',
        '1.P' => 'HYLN',
        '1.R' => 'HYLN',
        '1.X' => 'HYLN',
        '1.FFA' => 'FXLN',
        '1.FKA' => 'FXLN',
        '1.FR' => 'FXLN',
        '2.I' => 'MOLN',
        '3.I' => 'MOLN',
        '3.W' => 'MOLN',
        '3.APM' => 'MOLN',
        '3.SPM' => 'MOLN',
        '3.PB' => 'PLLN',
        '3.PBL' => 'PLLN',
        '3.SA' => 'PLLN',
        '3.SB' => 'PLLN',
        '3.SBL' => 'PLLN',
        '3.SD' => 'PLLN',
        '1.V' => 'VALN',
        '1.Z' => 'ACLN',
        '1.VFN' => 'ACLN',
        '3.GR' => 'AELN',
        '3.GS' => 'AELN',
        '3.RIC' => 'AELN',
        '4.K' => 'INLN',
    ];
    return $mapa[$linha] ?? '';
}

function rag_identificar_linha(string $entrada, array $mapaConversao): array
{
    $normalizado = strtoupper(trim($entrada));
    $normalizado = preg_replace('/\s+/', '', $normalizado);
    if ($normalizado === '') {
        return ['', ''];
    }

    if (isset($mapaConversao[$normalizado])) {
        return [$normalizado, $mapaConversao[$normalizado]];
    }

    $invertido = array_flip($mapaConversao);
    if (isset($invertido[$normalizado])) {
        $linha = (string) $invertido[$normalizado];
        return [$linha, $normalizado];
    }

    return ['', ''];
}

function rag_acao_referencia(array $payload, string $requestId): void
{
    $mapaConversao = rag_mapa_linhas_conversao();
    $entrada = trim((string) ($payload['referencia'] ?? $payload['entrada'] ?? $_GET['referencia'] ?? $_GET['entrada'] ?? ''));
    $linhaEntrada = trim((string) ($payload['linha'] ?? $_GET['linha'] ?? ''));

    $referenciaUpper = strtoupper($entrada);
    $referenciaUpper = preg_replace('/\s+/', '', $referenciaUpper);
    $partes = array_values(array_filter(explode('.', $referenciaUpper), static fn($v) => $v !== ''));

    [$linhaCodigo, $linhaCatalogo] = rag_identificar_linha($linhaEntrada, $mapaConversao);
    if ($linhaCodigo === '' && count($partes) >= 1) {
        [$linhaCodigo, $linhaCatalogo] = rag_identificar_linha($partes[0], $mapaConversao);
    }
    if ($linhaCodigo === '' && count($partes) >= 2) {
        [$linhaCodigo, $linhaCatalogo] = rag_identificar_linha($partes[0] . '.' . $partes[1], $mapaConversao);
    }

    $sugestoes = [];
    if ($linhaCodigo === '') {
        $sugestoes[] = 'Informe a linha do produto (ex.: 1.Q) ou a linha convertida (ex.: IBRQ).';
    }
    if (count($partes) > 0 && count($partes) < 3) {
        $sugestoes[] = 'A referência parece incompleta: use pelo menos 3 blocos separados por ponto.';
    }
    if ($entrada === '') {
        $sugestoes[] = 'Envie a referência completa para validar e sugerir o link direto.';
    }

    $paramLinha = $linhaCodigo !== '' ? rag_parametro_linha_configurador($linhaCodigo) : '';
    $link = null;
    if ($linhaCodigo !== '' && $paramLinha !== '') {
        $link = '/Configurador?' . $paramLinha . '=' . rawurlencode($linhaCodigo);
    }

    rag_enviar_resposta([
        'ok' => true,
        'requestId' => $requestId,
        'acao' => 'referencia',
        'entrada' => $entrada,
        'referenciaNormalizada' => $referenciaUpper,
        'blocosReferencia' => $partes,
        'linha' => [
            'codigo' => $linhaCodigo,
            'catalogo' => $linhaCatalogo,
        ],
        'linkConfigurador' => $link,
        'sugestoes' => $sugestoes,
        'conversaoLinha' => $mapaConversao,
    ]);
}

$requestId = bin2hex(random_bytes(8));
$payload = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    if ($raw !== false && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }
}

$acao = trim((string) ($payload['acao'] ?? $_GET['acao'] ?? ''));

if ($acao === 'referencia') {
    rag_acao_referencia($payload, $requestId);
    return;
}

if ($acao === 'indexar') {
    try {
        $jobId = registrar_job_fila('indexar_rag_configuradores', [
            'origem' => 'endpoint_rag',
            'solicitadoEm' => time(),
            'requestId' => $requestId,
        ], $baseDir);

        log_event(json_encode([
            'timestamp' => date(DATE_ATOM),
            'componente' => 'rag_configuradores',
            'evento' => 'indexacao_solicitada',
            'requestId' => $requestId,
            'jobId' => $jobId,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        rag_enviar_resposta([
            'ok' => true,
            'requestId' => $requestId,
            'jobId' => $jobId,
        ]);
        return;
    } catch (Throwable $t) {
        log_event(json_encode([
            'timestamp' => date(DATE_ATOM),
            'componente' => 'rag_configuradores',
            'evento' => 'indexacao_falhou',
            'requestId' => $requestId,
            'erro' => $t->getMessage(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        rag_enviar_resposta([
            'ok' => false,
            'requestId' => $requestId,
            'erro' => 'Falha ao enfileirar a indexação.',
        ]);
        return;
    }
}

$query = trim((string) ($payload['query'] ?? $payload['q'] ?? $_GET['q'] ?? ''));
$limite = (int) ($payload['limite'] ?? $_GET['limite'] ?? 6);
$limite = max(1, min(10, $limite));

if ($query === '') {
    rag_enviar_resposta([
        'ok' => false,
        'requestId' => $requestId,
        'erro' => 'Consulta vazia. Informe um texto para pesquisa.',
    ]);
    return;
}

$indice = rag_carregar_indice($baseDir);
if (!$indice || empty($indice['documentos'])) {
    rag_enviar_resposta([
        'ok' => false,
        'requestId' => $requestId,
        'erro' => 'Índice de conhecimento indisponível.',
        'indexacaoPendente' => true,
    ]);
    return;
}

$tokens = rag_tokenizar($query);
$resultados = [];
foreach ($indice['documentos'] as $doc) {
    if (!is_array($doc)) {
        continue;
    }
    $score = rag_pontuar_documento($doc, $tokens);
    if ($score <= 0) {
        continue;
    }
    $resultados[] = [
        'id' => $doc['id'] ?? '',
        'tipo' => $doc['tipo'] ?? '',
        'titulo' => $doc['titulo'] ?? '',
        'resumo' => $doc['resumo'] ?? '',
        'url' => $doc['url'] ?? '',
        'fonte' => $doc['fonte'] ?? '',
        'score' => $score,
    ];
}

usort($resultados, static function ($a, $b) {
    return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
});

$resultados = array_slice($resultados, 0, $limite);

log_event(json_encode([
    'timestamp' => date(DATE_ATOM),
    'componente' => 'rag_configuradores',
    'evento' => 'consulta',
    'requestId' => $requestId,
    'query' => $query,
    'tokens' => $tokens,
    'resultados' => count($resultados),
    'duracaoMs' => (int) ((microtime(true) - $inicio) * 1000),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

rag_enviar_resposta([
    'ok' => true,
    'requestId' => $requestId,
    'resultados' => $resultados,
    'total' => count($resultados),
    'atualizadoEm' => $indice['geradoEm'] ?? null,
]);
