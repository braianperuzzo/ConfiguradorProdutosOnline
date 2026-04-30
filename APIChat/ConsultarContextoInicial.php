<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/NucleoApiChat.php';

const API_CHAT_CONTEXTO_INICIAL_SCHEMA_VERSION = 1;
const API_CHAT_CONTEXTO_INICIAL_CACHE_TTL = 120;
const API_CHAT_CONTEXTO_INICIAL_CARRINHO_DIAS_PADRAO = 7;
const API_CHAT_CONTEXTO_INICIAL_CARRINHO_LIMITE_EVENTOS_PADRAO = 5;
const API_CHAT_CONTEXTO_INICIAL_CARRINHO_LIMITE_EVENTOS_MAX = 30;
const API_CHAT_CONTEXTO_INICIAL_CARRINHO_DIAS_MAX = 30;

function responder_contexto_inicial(int $status, array $payload): void
{
    api_chat_middleware_responder($status, $payload);
}

function contexto_inicial_payload(): array
{
    return api_chat_payload_json();
}

function contexto_inicial_includes_normalizados($entrada): array
{
    $tokens = [];

    if (is_string($entrada)) {
        $tokens = preg_split('/[,;]+/', $entrada) ?: [];
    } elseif (is_array($entrada)) {
        foreach ($entrada as $item) {
            if (is_string($item)) {
                $tokens[] = $item;
            }
        }
    }

    $aliases = [
        'capabilities' => 'capabilities',
        'memories' => 'memories',
        'catalogo' => 'catalogoProduto',
        'catalogoproduto' => 'catalogoProduto',
        'catalogo_produto' => 'catalogoProduto',
        'produto' => 'catalogoProduto',
        'produtos' => 'catalogoProduto',
    ];

    $saida = [];
    foreach ($tokens as $token) {
        $normalizado = strtolower(trim($token));
        if ($normalizado === '' || !isset($aliases[$normalizado])) {
            continue;
        }

        $bloco = $aliases[$normalizado];
        if (!in_array($bloco, $saida, true)) {
            $saida[] = $bloco;
        }
    }

    if ($saida === []) {
        return ['capabilities', 'memories', 'catalogoProduto'];
    }

    return $saida;
}

function contexto_inicial_int_param(array $dados, string $chave, int $padrao, int $minimo, int $maximo): int
{
    $valor = $dados[$chave] ?? null;
    if (is_string($valor) && trim($valor) !== '' && preg_match('/^-?\d+$/', trim($valor)) === 1) {
        $valor = (int) trim($valor);
    }

    if (!is_int($valor)) {
        return $padrao;
    }

    if ($valor < $minimo) {
        return $minimo;
    }

    if ($valor > $maximo) {
        return $maximo;
    }

    return $valor;
}

function contexto_inicial_memories_resumo(array $state, string $baseDir, array $filtros): array
{
    $payload = api_chat_memory_owner_payload_normalizado($filtros);
    $payload['scope'] = (string) ($filtros['scope'] ?? 'session');
    $payload['scopes'] = isset($filtros['scopes']) && is_array($filtros['scopes']) ? $filtros['scopes'] : [];
    $payload['namespace'] = (string) ($filtros['namespace'] ?? '');
    $payload['query'] = (string) ($filtros['query'] ?? '');
    $payload['limit'] = contexto_inicial_int_param($filtros, 'memoriesLimit', 20, 1, 200);

    $resultado = api_chat_memory_retrieve($state, $baseDir, $payload);
    $itens = is_array($resultado['items'] ?? null) ? $resultado['items'] : [];

    $porTipo = [];
    $porNamespace = [];
    $ultimoEm = null;

    foreach ($itens as $item) {
        if (!is_array($item)) {
            continue;
        }

        $tipo = strtolower(trim((string) ($item['memoryType'] ?? 'contextual')));
        $namespace = trim((string) ($item['namespace'] ?? 'sem_namespace'));
        $criadoEm = (string) ($item['createdAt'] ?? '');

        $porTipo[$tipo] = (int) ($porTipo[$tipo] ?? 0) + 1;
        $porNamespace[$namespace] = (int) ($porNamespace[$namespace] ?? 0) + 1;

        if ($criadoEm !== '' && ($ultimoEm === null || strcmp($criadoEm, $ultimoEm) > 0)) {
            $ultimoEm = $criadoEm;
        }
    }

    arsort($porTipo);
    arsort($porNamespace);

    return [
        'scope' => (string) ($resultado['scope'] ?? 'session'),
        'scopes' => is_array($resultado['scopes'] ?? null) ? array_values($resultado['scopes']) : [],
        'ownerKey' => (string) ($resultado['ownerKey'] ?? ''),
        'total' => count($itens),
        'porTipo' => $porTipo,
        'porNamespace' => $porNamespace,
        'ultimoRegistroEm' => $ultimoEm,
        'idsRecentes' => array_values(array_map(
            static fn(array $item): string => (string) ($item['id'] ?? ''),
            array_slice($itens, -5)
        )),
    ];
}

function contexto_inicial_inteligencia_carrinho(string $baseDir, array $filtros): array
{
    $dias = contexto_inicial_int_param(
        $filtros,
        'carrinhoDias',
        API_CHAT_CONTEXTO_INICIAL_CARRINHO_DIAS_PADRAO,
        1,
        API_CHAT_CONTEXTO_INICIAL_CARRINHO_DIAS_MAX
    );
    $limiteEventos = contexto_inicial_int_param(
        $filtros,
        'carrinhoLimiteEventos',
        API_CHAT_CONTEXTO_INICIAL_CARRINHO_LIMITE_EVENTOS_PADRAO,
        1,
        API_CHAT_CONTEXTO_INICIAL_CARRINHO_LIMITE_EVENTOS_MAX
    );

    $logsDir = rtrim($baseDir, '/\\') . '/PaginasCarrinhoProdutosRecomenda/Logs';
    if (!is_dir($logsDir)) {
        return [
            'escopo' => ['dias' => $dias, 'limiteEventos' => $limiteEventos],
            'resumoLogs' => [
                'diasConsiderados' => $dias,
                'totalEventos' => 0,
                'eventosPorTipo' => [],
                'eventosPorOrigem' => [],
                'topTermosPesquisa' => [],
            ],
            'ultimosEventos' => [],
        ];
    }

    return [
        'escopo' => ['dias' => $dias, 'limiteEventos' => $limiteEventos],
        'resumoLogs' => contexto_inicial_resumo_eventos_abandono($logsDir, $dias),
        'ultimosEventos' => contexto_inicial_ultimos_eventos($logsDir, $limiteEventos),
    ];
}

function contexto_inicial_ler_jsonl(string $arquivo, int $maxLinhas = 10000): array
{
    if (!is_file($arquivo)) {
        return [];
    }

    $linhas = @file($arquivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($linhas) || $linhas === []) {
        return [];
    }

    if (count($linhas) > $maxLinhas) {
        $linhas = array_slice($linhas, -$maxLinhas);
    }

    $saida = [];
    foreach ($linhas as $linha) {
        $obj = json_decode((string) $linha, true);
        if (is_array($obj)) {
            $saida[] = $obj;
        }
    }

    return $saida;
}

function contexto_inicial_coletar_termos_busca($valor, array &$contador, string $contexto = ''): void
{
    if (is_array($valor)) {
        foreach ($valor as $chave => $item) {
            $chaveStr = strtolower((string) $chave);
            $novoContexto = $contexto !== '' ? ($contexto . '.' . $chaveStr) : $chaveStr;
            contexto_inicial_coletar_termos_busca($item, $contador, $novoContexto);
        }

        return;
    }

    if (!is_scalar($valor) || is_bool($valor)) {
        return;
    }

    if ($contexto !== '' && preg_match('/(busca|pesquisa|query|termo|palavra|search)/i', $contexto) !== 1) {
        return;
    }

    $texto = trim((string) $valor);
    if ($texto === '' || api_chat_strlen($texto) < 2 || api_chat_strlen($texto) > 120) {
        return;
    }

    $contador[$texto] = (int) ($contador[$texto] ?? 0) + 1;
}

function contexto_inicial_resumo_eventos_abandono(string $logsDir, int $dias): array
{
    $arquivos = glob($logsDir . '/eventos-abandono-*.jsonl') ?: [];
    sort($arquivos, SORT_STRING);

    $limiar = strtotime('-' . max(1, $dias) . ' days');
    $total = 0;
    $porEvento = [];
    $porOrigem = [];
    $termos = [];

    foreach ($arquivos as $arquivo) {
        foreach (contexto_inicial_ler_jsonl($arquivo) as $evento) {
            $timestamp = strtotime((string) ($evento['timestamp'] ?? ''));
            if ($timestamp === false || $timestamp < $limiar) {
                continue;
            }

            $total++;
            $nomeEvento = strtolower(trim((string) ($evento['evento'] ?? 'indefinido')));
            $origem = trim((string) ($evento['origem'] ?? 'desconhecida'));
            $porEvento[$nomeEvento] = (int) ($porEvento[$nomeEvento] ?? 0) + 1;
            $porOrigem[$origem] = (int) ($porOrigem[$origem] ?? 0) + 1;

            if (isset($evento['dados'])) {
                contexto_inicial_coletar_termos_busca($evento['dados'], $termos, 'dados');
            }
        }
    }

    arsort($porEvento);
    arsort($porOrigem);
    arsort($termos);

    $topTermos = [];
    foreach (array_slice($termos, 0, 15, true) as $termo => $qtd) {
        $topTermos[] = ['termo' => $termo, 'quantidade' => $qtd];
    }

    return [
        'diasConsiderados' => max(1, $dias),
        'totalEventos' => $total,
        'eventosPorTipo' => $porEvento,
        'eventosPorOrigem' => $porOrigem,
        'topTermosPesquisa' => $topTermos,
    ];
}

function contexto_inicial_ultimos_eventos(string $logsDir, int $limite): array
{
    $arquivos = glob($logsDir . '/eventos-abandono-*.jsonl') ?: [];
    sort($arquivos, SORT_STRING);

    $eventos = [];
    foreach ($arquivos as $arquivo) {
        foreach (contexto_inicial_ler_jsonl($arquivo) as $evento) {
            $eventos[] = [
                'timestamp' => (string) ($evento['timestamp'] ?? ''),
                'evento' => (string) ($evento['evento'] ?? ''),
                'origem' => (string) ($evento['origem'] ?? ''),
                'valorCarrinho' => $evento['valorCarrinho'] ?? null,
                'quantidadeItens' => $evento['quantidadeItens'] ?? null,
            ];
        }
    }

    usort($eventos, static function (array $a, array $b): int {
        return strcmp((string) ($a['timestamp'] ?? ''), (string) ($b['timestamp'] ?? ''));
    });

    return array_slice($eventos, -$limite);
}

function contexto_inicial_catalogo_produto(string $baseDir): array
{
    $versao = api_chat_versao_base_conhecimento($baseDir);
    $indice = api_chat_semantic_index_load($baseDir, 'produtos-embeddings');

    $itens = is_array($indice['itens'] ?? null) ? $indice['itens'] : [];
    $amostra = [];
    foreach (array_slice($itens, 0, 5) as $item) {
        if (!is_array($item)) {
            continue;
        }

        $amostra[] = [
            'id' => (string) ($item['id'] ?? ''),
            'titulo' => (string) ($item['titulo'] ?? ''),
            'url' => (string) ($item['url'] ?? ''),
        ];
    }

    return [
        'versaoBaseConhecimento' => $versao,
        'indiceDisponivel' => $indice !== [],
        'origem' => (string) ($indice['origem'] ?? ''),
        'geradoEm' => (string) ($indice['geradoEm'] ?? ''),
        'dimensaoEmbedding' => (int) ($indice['dimensao'] ?? 0),
        'totalEsperado' => (int) ($indice['totalEsperado'] ?? 0),
        'totalIndexado' => (int) ($indice['totalIndexado'] ?? count($itens)),
        'fonteMtime' => (string) ($indice['fonteMtime'] ?? ''),
        'amostraProdutos' => $amostra,
    ];
}

function contexto_inicial_cache_header(array $payload): void
{
    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        return;
    }

    $etag = 'W/"' . hash('sha256', $encoded) . '"';
    header('Cache-Control: public, max-age=' . API_CHAT_CONTEXTO_INICIAL_CACHE_TTL . ', stale-while-revalidate=30');
    header('ETag: ' . $etag);

    $ifNoneMatch = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
    if ($ifNoneMatch !== '' && $ifNoneMatch === $etag) {
        http_response_code(304);
        exit;
    }
}

function contexto_inicial_carregar_estavel_cacheado(string $baseDir, string $bloco): array
{
    $cacheKey = api_chat_storage_cache_key(
        '/APIChat/ConsultarContextoInicial.php',
        $bloco,
        ['baseConhecimento' => api_chat_versao_base_conhecimento($baseDir)],
        'contexto-inicial',
        API_CHAT_CONTEXTO_INICIAL_SCHEMA_VERSION
    );

    $cacheRaw = api_chat_storage_get($cacheKey);
    if (is_string($cacheRaw)) {
        $cacheJson = json_decode($cacheRaw, true);
        if (is_array($cacheJson)) {
            return $cacheJson;
        }
    }

    if ($bloco === 'capabilities') {
        $dados = [
            'configuradoresSuportados' => ['AC', 'AE', 'FX', 'HY', 'IN', 'MO', 'PL', 'QU', 'QUDR', 'VA'],
            'memoryModesChat' => ['off', 'read_only', 'read_write'],
            'recursosEssenciais' => ['buscaTextual', 'buscaPorCodigoExato', 'listagemProdutos', 'memorySubsystem'],
            'versaoBaseConhecimento' => api_chat_versao_base_conhecimento($baseDir),
        ];
    } else {
        $dados = contexto_inicial_catalogo_produto($baseDir);
    }

    api_chat_storage_set(
        $cacheKey,
        (string) json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        API_CHAT_CONTEXTO_INICIAL_CACHE_TTL
    );

    return $dados;
}

$baseDir = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
if ($baseDir === '') {
    $baseDir = dirname(__DIR__);
}

api_chat_middleware_init($baseDir, '/APIChat/ConsultarContextoInicial.php');
auth_api_chat_validar_ou_responder($baseDir, 'responder_contexto_inicial');

$metodo = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($metodo, ['GET', 'POST'], true)) {
    responder_contexto_inicial(405, [
        'ok' => false,
        'erro' => 'Use GET ou POST para consultar o contexto inicial.',
        'codigoErro' => 'METODO_NAO_PERMITIDO',
    ]);
}

$body = $metodo === 'POST' ? contexto_inicial_payload() : [];
$includesParam = $_GET['includes'] ?? null;
if (isset($_GET['includes']) && is_array($_GET['includes'])) {
    $includesParam = $_GET['includes'];
}
if (isset($body['includes'])) {
    $includesParam = $body['includes'];
}

$includes = contexto_inicial_includes_normalizados($includesParam);
$filtros = array_merge($_GET, $body);

$stateMemories = api_chat_memory_carregar($baseDir);
api_chat_memory_limpar_expiradas($stateMemories);

$resposta = [
    'ok' => true,
    'endpoint' => '/APIChat/ConsultarContextoInicial.php',
    'includes' => $includes,
    'geradoEm' => gmdate('c'),
    'contexto' => [],
];

foreach ($includes as $bloco) {
    if ($bloco === 'capabilities') {
        $resposta['contexto']['capabilities'] = contexto_inicial_carregar_estavel_cacheado($baseDir, 'capabilities');
        continue;
    }

    if ($bloco === 'memories') {
        $resposta['contexto']['memories'] = contexto_inicial_memories_resumo($stateMemories, $baseDir, $filtros);
        continue;
    }

    if ($bloco === 'catalogoProduto') {
        $resposta['contexto']['catalogoProduto'] = contexto_inicial_carregar_estavel_cacheado($baseDir, 'catalogoProduto');
    }
}

$somenteBlocosEstaveis = count(array_diff($includes, ['capabilities', 'catalogoProduto'])) === 0;
if ($somenteBlocosEstaveis) {
    contexto_inicial_cache_header($resposta);
} else {
    header('Cache-Control: private, no-store');
}

responder_contexto_inicial(200, $resposta);
