<?php
header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors', 0);
error_reporting(0);

$inicio = microtime(true);
$documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
$baseDir = $documentRoot !== '' ? rtrim($documentRoot, DIRECTORY_SEPARATOR) : dirname(__DIR__);

require_once $baseDir . '/LogsErros/Logs.php';
require_once $baseDir . '/ProcessamentoFilaJobs/CriaFilaProcessamento.php';

const RECOMENDACOES_COOCORRENCIA_CACHE_KEY = 'recomendacoes_coocorrencia_modelo_cache';
const RECOMENDACOES_COOCORRENCIA_CACHE_MTIME_KEY = 'recomendacoes_coocorrencia_modelo_cache_mtime';
const RECOMENDACOES_COOCORRENCIA_TTL_DIAS = 7;

function normalizar_codigo_recomendacao(string $codigo): string
{
    $codigo = strtoupper(trim($codigo));
    return preg_match('/^[A-Z0-9.]+$/', $codigo) ? $codigo : '';
}

function extrair_codigo_produto($item): string
{
    if (!is_array($item)) {
        return '';
    }
    $chaves = ['codigo', 'codigoProduto', 'cd_produto', 'cdProduto', 'CD_PRODUTO', 'id', 'produto'];
    foreach ($chaves as $chave) {
        if (!array_key_exists($chave, $item)) {
            continue;
        }
        $valor = normalizar_codigo_recomendacao((string) $item[$chave]);
        if ($valor !== '') {
            return $valor;
        }
    }
    return '';
}

function extrair_codigos_base(array $lista): array
{
    $codigos = [];
    foreach ($lista as $item) {
        $codigo = extrair_codigo_produto($item);
        if ($codigo !== '') {
            $codigos[$codigo] = true;
        }
    }
    return array_keys($codigos);
}

function carregar_modelo_recomendacoes(string $baseDir): array
{
    $modeloPath = $baseDir . '/PaginasCarrinhoProdutosRecomenda/IARecomendacoesCoocorrencia.json';
    if (!file_exists($modeloPath)) {
        return [];
    }

    if (function_exists('apcu_fetch')) {
        $cacheMtime = apcu_fetch(RECOMENDACOES_COOCORRENCIA_CACHE_MTIME_KEY);
        if (is_int($cacheMtime) && filemtime($modeloPath) === $cacheMtime) {
            $cacheModelo = apcu_fetch(RECOMENDACOES_COOCORRENCIA_CACHE_KEY);
            if (is_array($cacheModelo)) {
                return $cacheModelo;
            }
        }
    }

    $json = json_decode((string) file_get_contents($modeloPath), true);
    if (!is_array($json)) {
        return [];
    }

    if (function_exists('apcu_store')) {
        apcu_store(RECOMENDACOES_COOCORRENCIA_CACHE_KEY, $json);
        apcu_store(RECOMENDACOES_COOCORRENCIA_CACHE_MTIME_KEY, filemtime($modeloPath));
    }

    return $json;
}

function carregar_metadata_catalogo(string $baseDir): array
{
    $arquivo = $baseDir . '/PaginasConfiguradores/InformacoesProdutos.json';
    if (!is_readable($arquivo)) {
        return [];
    }
    $json = file_get_contents($arquivo);
    if ($json === false) {
        return [];
    }
    $dados = json_decode($json, true);
    return is_array($dados) ? $dados : [];
}

function montar_sugestao_produto(string $codigo, array $metadata = [], float $score = 0.0, string $origem = ''): array
{
    $info = $metadata[$codigo] ?? [];
    return [
        'CD_PRODUTO' => $codigo,
        'DS_PRODUTO' => (string) ($info['title'] ?? $info['titulo'] ?? ''),
        'DS_REFERENCIA' => (string) ($info['description'] ?? $info['descricao'] ?? ''),
        'score' => $score,
        'origem' => $origem,
    ];
}

function gerar_recomendacoes_genericas(array $excluir, int $limite, string $baseDir): array
{
    $metadata = carregar_metadata_catalogo($baseDir);
    if (!$metadata) {
        return [];
    }
    $sugestoes = [];
    foreach ($metadata as $codigo => $info) {
        $codigoNormalizado = normalizar_codigo_recomendacao((string) $codigo);
        if ($codigoNormalizado === '' || isset($excluir[$codigoNormalizado])) {
            continue;
        }
        $sugestoes[] = montar_sugestao_produto($codigoNormalizado, $metadata, 0.0, 'generico');
        if (count($sugestoes) >= $limite) {
            break;
        }
    }
    return $sugestoes;
}

function agendar_treino_recomendacoes(string $baseDir): void
{
    $modeloPath = $baseDir . '/PaginasCarrinhoProdutosRecomenda/IARecomendacoesCoocorrencia.json';
    $precisaTreinar = !file_exists($modeloPath);
    if (!$precisaTreinar) {
        $limite = time() - (RECOMENDACOES_COOCORRENCIA_TTL_DIAS * 86400);
        $precisaTreinar = filemtime($modeloPath) < $limite;
    }
    if (!$precisaTreinar) {
        return;
    }

    $historicoDir = $baseDir . '/Tokens/HistoricoCarrinhos';
    if (!is_dir($historicoDir)) {
        @mkdir($historicoDir, 0775, true);
    }
    if (!is_dir($historicoDir)) {
        return;
    }
    $dadosJob = [
        'historicoDir' => $historicoDir,
        'saidaModelo' => $modeloPath,
        'limparHistoricoDias' => 90,
    ];

    try {
        registrar_job_fila('treinar_recomendacoes_coocorrencia', $dadosJob, $baseDir);
    } catch (Throwable $e) {
        log_event(json_encode([
            'componente' => 'inferir_recomendacoes',
            'nivel' => 'erro',
            'mensagem' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE));
    }
}

$payload = json_decode((string) file_get_contents('php://input'), true);
$payload = is_array($payload) ? $payload : [];
$carrinho = $payload['carrinho'] ?? [];
$historico = $payload['historico'] ?? [];
$limite = (int) ($payload['limite'] ?? 12);
$limite = $limite > 0 ? min($limite, 40) : 12;

$codigosBase = array_merge(
    extrair_codigos_base(is_array($carrinho) ? $carrinho : []),
    extrair_codigos_base(is_array($historico) ? $historico : [])
);
$codigosBase = array_values(array_unique($codigosBase));
$codigosBaseSet = array_fill_keys($codigosBase, true);

agendar_treino_recomendacoes($baseDir);

$modelo = carregar_modelo_recomendacoes($baseDir);
$mapaProdutos = is_array($modelo['produtos'] ?? null) ? $modelo['produtos'] : [];
$listaGlobal = is_array($modelo['global'] ?? null) ? $modelo['global'] : [];

$acumulado = [];
foreach ($codigosBase as $codigo) {
    $recs = $mapaProdutos[$codigo] ?? [];
    if (!is_array($recs)) {
        continue;
    }
    foreach ($recs as $rec) {
        $codigoRec = normalizar_codigo_recomendacao((string) ($rec['codigo'] ?? $rec['CD_PRODUTO'] ?? ''));
        if ($codigoRec === '' || isset($codigosBaseSet[$codigoRec])) {
            continue;
        }
        $score = (float) ($rec['score'] ?? 0.0);
        $acumulado[$codigoRec] = ($acumulado[$codigoRec] ?? 0.0) + $score;
    }
}

arsort($acumulado);
$metadata = carregar_metadata_catalogo($baseDir);
$sugestoes = [];
foreach ($acumulado as $codigo => $score) {
    $sugestoes[] = montar_sugestao_produto($codigo, $metadata, $score, 'coocorrencia');
    if (count($sugestoes) >= $limite) {
        break;
    }
}

$fallback = false;
if (count($sugestoes) < $limite) {
    $fallback = true;
    $sugestoesFallback = [];
    $usados = $codigosBaseSet;
    foreach ($sugestoes as $sugestao) {
        $codigo = normalizar_codigo_recomendacao((string) ($sugestao['CD_PRODUTO'] ?? ''));
        if ($codigo !== '') {
            $usados[$codigo] = true;
        }
    }

    foreach ($listaGlobal as $rec) {
        $codigoRec = normalizar_codigo_recomendacao((string) ($rec['codigo'] ?? $rec['CD_PRODUTO'] ?? ''));
        if ($codigoRec === '' || isset($usados[$codigoRec])) {
            continue;
        }
        $sugestoesFallback[] = montar_sugestao_produto(
            $codigoRec,
            $metadata,
            (float) ($rec['score'] ?? 0.0),
            'global'
        );
        $usados[$codigoRec] = true;
        if (count($sugestoesFallback) + count($sugestoes) >= $limite) {
            break;
        }
    }

    if (count($sugestoesFallback) + count($sugestoes) < $limite) {
        $extras = gerar_recomendacoes_genericas($usados, $limite - count($sugestoes) - count($sugestoesFallback), $baseDir);
        $sugestoesFallback = array_merge($sugestoesFallback, $extras);
    }

    $sugestoes = array_merge($sugestoes, $sugestoesFallback);
}

$duracaoMs = round((microtime(true) - $inicio) * 1000, 2);

echo json_encode([
    'base' => $codigosBase,
    'sugestoes' => $sugestoes,
    'fallback' => $fallback,
    'versao_modelo' => (string) ($modelo['versao_modelo'] ?? ''),
    'duracaoMs' => $duracaoMs,
]);
?>
