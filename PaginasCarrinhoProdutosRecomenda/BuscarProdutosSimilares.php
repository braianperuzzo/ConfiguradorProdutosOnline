<?php
header('Content-Type: application/json; charset=UTF-8');

$inicio = microtime(true);
$documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
$baseDir = $documentRoot !== '' ? rtrim($documentRoot, DIRECTORY_SEPARATOR) : dirname(__DIR__);

require_once $baseDir . '/LogsErros/Logs.php';
require_once $baseDir . '/SEO/ListaConfiguradoresLinkAmigavel.php';
require_once $baseDir . '/ProcessamentoFilaJobs/CriaFilaProcessamento.php';
require_once $baseDir . '/PaginasConsultaProdutos/EmbeddingsCatalogo.php';

function carregar_metadata_catalogo(string $baseDir): array {
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

function buscar_similares_lexical(array $metadata, array $ids, int $limite = 12): array {
    $idsNormalizados = array_values(array_unique(array_map('strtoupper', $ids)));
    $tokensBase = [];
    foreach ($idsNormalizados as $id) {
        $tokensBase = array_merge($tokensBase, tokenizar_texto_embeddings($id));
    }
    $tokensBase = array_values(array_unique($tokensBase));
    $resultados = [];
    foreach ($metadata as $linha => $info) {
        $linhaStr = strtoupper((string) $linha);
        if (in_array($linhaStr, $idsNormalizados, true)) {
            continue;
        }
        $titulo = isset($info['title']) ? (string) $info['title'] : '';
        $descricao = isset($info['description']) ? (string) $info['description'] : '';
        $categorias = isset($info['categories']) && is_array($info['categories']) ? $info['categories'] : [];
        $grupos = isset($info['groups']) && is_array($info['groups']) ? $info['groups'] : [];
        $textoBusca = normalizar_texto_embeddings(implode(' ', [
            $linhaStr,
            $titulo,
            $descricao,
            implode(' ', $categorias),
            implode(' ', $grupos),
        ]));
        if ($textoBusca === '') {
            continue;
        }
        $score = 0.0;
        foreach ($tokensBase as $token) {
            if ($token !== '' && strpos($textoBusca, $token) !== false) {
                $score += 1.0;
            }
        }
        if ($score <= 0) {
            continue;
        }
        $resultados[] = [
            'id' => $linhaStr,
            'titulo' => $titulo,
            'descricao' => $descricao,
            'resumo' => gerar_resumo_embeddings($descricao),
            'url' => montar_url_configurador_embeddings($linhaStr),
            'score' => $score,
        ];
    }
    usort($resultados, function ($a, $b) {
        return ($a['score'] ?? 0) < ($b['score'] ?? 0) ? 1 : -1;
    });
    return array_slice($resultados, 0, $limite);
}

$idsBrutos = (string) (filter_input(INPUT_GET, 'ids', FILTER_SANITIZE_FULL_SPECIAL_CHARS)
    ?? filter_input(INPUT_GET, 'codigos', FILTER_SANITIZE_FULL_SPECIAL_CHARS)
    ?? filter_input(INPUT_GET, 'itens', FILTER_SANITIZE_FULL_SPECIAL_CHARS)
    ?? '');
$limite = (int) (filter_input(INPUT_GET, 'limite', FILTER_SANITIZE_NUMBER_INT) ?? 12);
$limite = $limite > 0 ? min($limite, 40) : 12;

$ids = array_values(array_filter(array_map(static function ($item) {
    $item = strtoupper(trim((string) $item));
    return preg_match('/^[A-Z0-9.]+$/', $item) ? $item : '';
}, preg_split('/[,\s]+/', $idsBrutos, -1, PREG_SPLIT_NO_EMPTY))));

if (!$ids) {
    echo json_encode(['erro' => 'Nenhum produto informado.']);
    exit;
}

try {
    $indice = carregar_indice_embeddings_catalogo($baseDir);
    $indiceCompleto = indice_embeddings_completo($indice);
    if (!$indiceCompleto) {
        solicitar_indexacao_embeddings_catalogo($baseDir, 'recomendacoes_similares', false);
    }

    $resultados = [];
    $modo = 'lexical';

    if ($indiceCompleto) {
        $modo = 'embedding';
        $resultadosEmb = buscar_recomendacoes_similares_catalogo($indice, $ids, $limite);
        foreach ($resultadosEmb as $resultado) {
            $item = $resultado['item'] ?? [];
            $resultados[] = [
                'id' => (string) ($item['id'] ?? ''),
                'titulo' => (string) ($item['titulo'] ?? ''),
                'descricao' => (string) ($item['descricao'] ?? ''),
                'resumo' => (string) ($item['resumo'] ?? ''),
                'url' => (string) ($item['url'] ?? ''),
                'score' => round(((float) ($resultado['score'] ?? 0)) * 100, 2),
            ];
        }
    } else {
        $metadata = carregar_metadata_catalogo($baseDir);
        $resultados = buscar_similares_lexical($metadata, $ids, $limite);
    }

    $duracaoMs = round((microtime(true) - $inicio) * 1000, 2);

    echo json_encode([
        'ids' => $ids,
        'modo' => $modo,
        'indiceCompleto' => $indiceCompleto,
        'resultados' => $resultados,
        'duracaoMs' => $duracaoMs,
    ]);
} catch (Throwable $e) {
    log_event(json_encode([
        'componente' => 'buscar_similares',
        'nivel' => 'erro',
        'mensagem' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE));
    echo json_encode(['erro' => 'Falha ao processar recomendações similares.']);
}
?>
