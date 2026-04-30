<?php
header('Content-Type: application/json; charset=UTF-8');
$baseDir = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
if ($baseDir === '') {
    $baseDir = dirname(__DIR__);
}
require $baseDir . '/Seguranca/MetodosSegurancao.php';

const ABANDONO_PSEUDO_SALT_ROTACAO_DIAS = 7;

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

function registrar_log_inferencia(string $baseDir, array $registro): void
{
    $logDir = $baseDir . '/PaginasCarrinhoProdutosRecomenda/Logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0775, true);
    }

    $arquivo = $logDir . '/inferencias-abandono-' . date('Y-m-d') . '.jsonl';
    $linha = json_encode($registro, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    file_put_contents($arquivo, $linha, FILE_APPEND | LOCK_EX);
}

function normalizar_feature(string $nome, $valor, float $minimo, float $maximo, array &$contadores): float
{
    if ($valor === null || (is_string($valor) && trim($valor) === '')) {
        if (!isset($contadores['ausentes'][$nome])) {
            $contadores['ausentes'][$nome] = 0;
        }
        $contadores['ausentes'][$nome] += 1;
        $valor = $minimo;
    } elseif (!is_numeric($valor)) {
        if (!isset($contadores['invalidos'][$nome])) {
            $contadores['invalidos'][$nome] = 0;
        }
        $contadores['invalidos'][$nome] += 1;
        $valor = $minimo;
    } else {
        $valor = (float) $valor;
    }

    if ($valor < $minimo) {
        if (!isset($contadores['ajustados'][$nome])) {
            $contadores['ajustados'][$nome] = 0;
        }
        $contadores['ajustados'][$nome] += 1;
        return $minimo;
    }
    if ($valor > $maximo) {
        if (!isset($contadores['ajustados'][$nome])) {
            $contadores['ajustados'][$nome] = 0;
        }
        $contadores['ajustados'][$nome] += 1;
        return $maximo;
    }
    return $valor;
}

function carregar_schema(string $schemaPath): array
{
    if (!file_exists($schemaPath)) {
        http_response_code(503);
        echo json_encode(['erro' => 'Schema indisponível.']);
        exit;
    }

    $schema = json_decode(file_get_contents($schemaPath), true);
    if (!is_array($schema)) {
        http_response_code(500);
        echo json_encode(['erro' => 'Schema inválido.']);
        exit;
    }

    foreach (['features', 'limites', 'mapeamentos'] as $chave) {
        if (!array_key_exists($chave, $schema)) {
            http_response_code(500);
            echo json_encode(['erro' => 'Schema incompleto.']);
            exit;
        }
    }

    if (!is_array($schema['features']) || empty($schema['features'])) {
        http_response_code(500);
        echo json_encode(['erro' => 'Schema inválido.']);
        exit;
    }

    if (!is_array($schema['limites']) || !is_array($schema['mapeamentos'])) {
        http_response_code(500);
        echo json_encode(['erro' => 'Schema inválido.']);
        exit;
    }

    foreach ($schema['features'] as $feature) {
        if (!isset($schema['limites'][$feature]['min'], $schema['limites'][$feature]['max'])) {
            http_response_code(500);
            echo json_encode(['erro' => 'Schema incompleto.']);
            exit;
        }
    }

    return $schema;
}

function carregar_thresholds(?string $thresholdsPath): ?array
{
    if ($thresholdsPath === null || !file_exists($thresholdsPath)) {
        return null;
    }

    $dados = json_decode(file_get_contents($thresholdsPath), true);
    if (!is_array($dados)) {
        return null;
    }

    return $dados;
}

function normalizar_segmento($segmento): array
{
    $dados = null;
    $chaves = [];
    if (is_string($segmento)) {
        $segmento = trim($segmento);
        if ($segmento !== '') {
            $dados = $segmento;
            $chaves[] = $segmento;
        }
    } elseif (is_array($segmento)) {
        $dados = $segmento;
        foreach ($segmento as $chave => $valor) {
            if (!is_scalar($valor)) {
                continue;
            }
            $valor = trim((string) $valor);
            if ($valor === '') {
                continue;
            }
            $chaves[] = $chave . ':' . strtolower($valor);
        }
    }

    $chaves = array_values(array_unique($chaves));
    $composto = $chaves ? implode('|', $chaves) : null;

    return [
        'dados' => $dados,
        'chaves' => $chaves,
        'chave_composta' => $composto,
    ];
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['erro' => 'Payload inválido.']);
    exit;
}

$modeloPath = $baseDir . '/PaginasCarrinhoProdutosRecomenda/IAModeloAbandono.json';
$modelo = null;
$modeloCacheKey = 'abandono_modelo_cache';
$modeloCacheMtimeKey = 'abandono_modelo_cache_mtime';

if (function_exists('apcu_fetch')) {
    $cacheMtime = apcu_fetch($modeloCacheMtimeKey);
    if (is_int($cacheMtime) && file_exists($modeloPath) && filemtime($modeloPath) === $cacheMtime) {
        $cacheModelo = apcu_fetch($modeloCacheKey);
        if (is_array($cacheModelo)) {
            $modelo = $cacheModelo;
        }
    }
}

if (!$modelo) {
    if (!file_exists($modeloPath)) {
        http_response_code(503);
        echo json_encode(['erro' => 'Modelo indisponível.']);
        exit;
    }
    $modelo = json_decode(file_get_contents($modeloPath), true);
    if (!is_array($modelo) || empty($modelo['pesos'])) {
        http_response_code(500);
        echo json_encode(['erro' => 'Modelo inválido.']);
        exit;
    }
    if (function_exists('apcu_store')) {
        apcu_store($modeloCacheKey, $modelo);
        apcu_store($modeloCacheMtimeKey, filemtime($modeloPath));
    }
}

$evento = strtolower((string) ($payload['evento'] ?? ''));
$consentimento = isset($payload['consentimento'])
    ? obter_consentimento($payload['consentimento'])
    : true;

$schemaPath = $baseDir . '/PaginasCarrinhoProdutosRecomenda/IASchemaAbandono.json';
$schema = carregar_schema($schemaPath);
$featureNames = $schema['features'];
$limitesFeatures = $schema['limites'];
$mapeamentos = $schema['mapeamentos'];

$segmentoInfo = normalizar_segmento($payload['segmento'] ?? null);

$contadores = [
    'ausentes' => [],
    'invalidos' => [],
    'ajustados' => [],
];

$valoresFeatures = [];
foreach ($featureNames as $feature) {
    $mapa = $mapeamentos[$feature] ?? [];
    $valor = null;

    if (isset($mapa['evento'])) {
        $valor = $evento === $mapa['evento'] ? 1.0 : 0.0;
    } elseif (isset($mapa['derivado']) && $mapa['derivado'] === 'valor_carrinho/quantidade_itens') {
        $valorCarrinho = $valoresFeatures['valor_carrinho'] ?? 0.0;
        $quantidadeItens = $valoresFeatures['quantidade_itens'] ?? 0.0;
        $valor = $valorCarrinho / max(1.0, $quantidadeItens);
    } elseif (isset($mapa['campo'])) {
        $valor = $payload[$mapa['campo']] ?? null;
    }

    $limite = $limitesFeatures[$feature];
    $valoresFeatures[$feature] = normalizar_feature(
        $feature,
        $valor,
        (float) $limite['min'],
        (float) $limite['max'],
        $contadores
    );
}

$features = [];
foreach ($featureNames as $feature) {
    $features[] = $valoresFeatures[$feature] ?? 0.0;
}

$medias = $modelo['medias'] ?? [];
$stds = $modelo['stds'] ?? [];
foreach ($features as $idx => $valor) {
    $media = isset($medias[$idx]) ? (float) $medias[$idx] : 0.0;
    $std = isset($stds[$idx]) ? (float) $stds[$idx] : 1.0;
    if ($std == 0.0) {
        $std = 1.0;
    }
    $features[$idx] = ($valor - $media) / $std;
}

$pesos = $modelo['pesos'];
$z = (float) ($pesos[0] ?? 0.0);
foreach ($features as $idx => $valor) {
    $peso = isset($pesos[$idx + 1]) ? (float) $pesos[$idx + 1] : 0.0;
    $z += $peso * $valor;
}

$score = 1.0 / (1.0 + exp(-$z));
$thresholdsPath = $baseDir . '/PaginasCarrinhoProdutosRecomenda/IALimitesRegistro.json';
$thresholds = carregar_thresholds($thresholdsPath);
$limiarGlobal = isset($modelo['threshold']) ? (float) $modelo['threshold'] : 0.55;
if (is_array($thresholds) && isset($thresholds['global']) && is_numeric($thresholds['global'])) {
    $limiarGlobal = (float) $thresholds['global'];
}
$limiar = $limiarGlobal;
if (is_array($thresholds) && isset($thresholds['segmentos']) && is_array($thresholds['segmentos'])) {
    $segmentosConfig = $thresholds['segmentos'];
    $candidatos = [];
    if ($segmentoInfo['chave_composta']) {
        $candidatos[] = $segmentoInfo['chave_composta'];
    }
    foreach ($segmentoInfo['chaves'] as $chave) {
        $candidatos[] = $chave;
    }
    foreach ($candidatos as $chave) {
        if (isset($segmentosConfig[$chave]) && is_numeric($segmentosConfig[$chave])) {
            $limiar = (float) $segmentosConfig[$chave];
            break;
        }
    }
}
$acao = $score >= $limiar ? 'mostrar_popup' : 'neutro';
$amostras = (int) ($modelo['amostras'] ?? 0);
$versaoModelo = isset($modelo['versao_modelo']) ? (string) $modelo['versao_modelo'] : null;
$metricas = null;
if (isset($modelo['metricas']) && is_array($modelo['metricas'])) {
    $metricas = $modelo['metricas'];
} elseif (isset($modelo['metricas_validacao']) && is_array($modelo['metricas_validacao'])) {
    $metricas = $modelo['metricas_validacao'];
}
$auc = null;
if (is_array($metricas) && isset($metricas['auc']) && is_numeric($metricas['auc'])) {
    $auc = (float) $metricas['auc'];
}
$amostrasMinimas = 200;
$aucMinimo = 0.70;
$motivo = null;
if ($amostras < $amostrasMinimas) {
    $acao = 'neutro';
    $motivo = 'amostras_insuficientes';
}
if ($acao !== 'neutro' && ($auc === null || $auc < $aucMinimo)) {
    $acao = 'neutro';
    $motivo = 'metricas_insuficientes';
}

if ($consentimento) {
    $cdPessoa = isset($payload['cdPessoa']) ? preg_replace('/\D/', '', (string) $payload['cdPessoa']) : null;
    $sessaoId = isset($payload['sessaoId']) ? preg_replace('/[^a-zA-Z0-9\-_]/', '', (string) $payload['sessaoId']) : null;
    registrar_log_inferencia($baseDir, [
        'timestamp' => date('c'),
        'evento' => $evento,
        'score' => round($score, 6),
        'acao' => $acao,
        'limiar' => $limiar,
        'limiar_aplicado' => $limiar,
        'segmento' => $segmentoInfo['dados'],
        'segmento_chave' => $segmentoInfo['chave_composta'],
        'sessaoId' => pseudonimizar_identificador($sessaoId),
        'cdPessoa' => pseudonimizar_identificador($cdPessoa),
        'motivo' => $motivo,
        'contadores_features' => $contadores,
    ]);
}

echo json_encode([
    'score' => round($score, 4),
    'limiar' => $limiar,
    'acao' => $acao,
    'amostras' => $amostras,
    'motivo' => $motivo,
    'versao_modelo' => $versaoModelo,
]);
?>
