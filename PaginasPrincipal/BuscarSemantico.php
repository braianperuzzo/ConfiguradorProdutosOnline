<?php
header('Content-Type: application/json; charset=UTF-8');

$inicio = microtime(true);
$documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
$baseDir = $documentRoot !== '' ? rtrim($documentRoot, DIRECTORY_SEPARATOR) : dirname(__DIR__);

require_once $baseDir . '/LogsErros/Logs.php';
require_once $baseDir . '/SEO/ListaConfiguradoresLinkAmigavel.php';
require_once $baseDir . '/ProcessamentoFilaJobs/CriaFilaProcessamento.php';
require_once $baseDir . '/PaginasConsultaProdutos/EmbeddingsCatalogo.php';

function normalizar_texto(string $valor): string {
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

function tokenizar_texto(string $valor): array {
    $normalizado = normalizar_texto($valor);
    if ($normalizado === '') {
        return [];
    }
    $tokens = preg_split('/\s+/', $normalizado, -1, PREG_SPLIT_NO_EMPTY);
    $tokens = array_values(array_unique($tokens));
    return $tokens;
}

function obter_mapa_sinonimos(): array {
    return [
        'redutor' => ['redutores', 'reducao', 'reducao de velocidade', 'motoredutor'],
        'motor' => ['motoredutor', 'motriz', 'motorizado'],
        'anticorrosivo' => ['anticorrosivos', 'anti corrosao', 'corrosao'],
        'inox' => ['aco inox', 'inoxidavel', 'aco inoxidavel'],
        'alto' => ['alto rendimento', 'rendimento'],
        'linha' => ['serie', 'familia', 'grupo'],
        'grupo' => ['familia', 'linha'],
    ];
}

function expandir_termos(array $tokens, array $sinonimos): array {
    $expandido = $tokens;
    $mapa = [];
    foreach ($tokens as $token) {
        if (!isset($sinonimos[$token])) {
            continue;
        }
        $mapa[$token] = $sinonimos[$token];
        foreach ($sinonimos[$token] as $variacao) {
            $variacaoNormalizada = normalizar_texto($variacao);
            if ($variacaoNormalizada !== '') {
                foreach (preg_split('/\s+/', $variacaoNormalizada) as $parte) {
                    if ($parte !== '') {
                        $expandido[] = $parte;
                    }
                }
            }
        }
    }
    $expandido = array_values(array_unique($expandido));
    return [$expandido, $mapa];
}

function carregar_dados_json(string $arquivo, array $padrao = []): array {
    if (!is_readable($arquivo)) {
        return $padrao;
    }
    $conteudo = file_get_contents($arquivo);
    if ($conteudo === false) {
        return $padrao;
    }
    $json = json_decode($conteudo, true);
    if (!is_array($json)) {
        return $padrao;
    }
    return $json;
}

function salvar_dados_json(string $arquivo, array $dados): void {
    $json = json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        return;
    }
    file_put_contents($arquivo, $json, LOCK_EX);
}

function carregar_links_configuradores(string $baseDir): array {
    $mapa = [];
    $arquivo = $baseDir . '/PaginasPrincipal/PaginaProdutos.html';
    $arquivoIndice = $baseDir . '/PaginasPrincipal/IndiceProdutos.json';
    $indice = carregar_dados_json($arquivoIndice);
    if (isset($indice['mapa']) && is_array($indice['mapa'])) {
        $arquivoExiste = is_readable($arquivo);
        $indiceAtualizado = $arquivoExiste
            ? (is_readable($arquivoIndice) && filemtime($arquivoIndice) >= filemtime($arquivo))
            : true;
        if ($indiceAtualizado) {
            return $indice['mapa'];
        }
        $mapa = $indice['mapa'];
    }
    if (!is_readable($arquivo)) {
        return $mapa;
    }
    $conteudo = file_get_contents($arquivo);
    if ($conteudo === false) {
        return $mapa;
    }
    if (!preg_match_all('/<a[^>]+class="[^"]*botao-home[^"]*"[^>]+href="([^"]+)"/i', $conteudo, $matches)) {
        return $mapa;
    }
    foreach ($matches[1] as $href) {
        $linha = '';
        $partes = parse_url($href);
        if (isset($partes['query'])) {
            parse_str($partes['query'], $params);
            foreach ($params as $chave => $valor) {
                if (preg_match('/LN$/i', $chave)) {
                    $linha = strtoupper((string) $valor);
                    break;
                }
            }
        }
        if ($linha !== '') {
            $mapa[$linha] = $href;
        }
    }
    if ($mapa !== []) {
        salvar_dados_json($arquivoIndice, [
            'geradoEm' => date('c'),
            'origem' => 'PaginaProdutos.html',
            'mapa' => $mapa,
        ]);
    }
    return $mapa;
}

function inicializar_interacoes(): array {
    return [
        'queries' => [],
        'items' => [],
        'updatedAt' => null,
    ];
}

function carregar_interacoes(string $arquivo): array {
    $interacoes = carregar_dados_json($arquivo, inicializar_interacoes());
    if (!isset($interacoes['queries']) || !is_array($interacoes['queries'])) {
        $interacoes['queries'] = [];
    }
    if (!isset($interacoes['items']) || !is_array($interacoes['items'])) {
        $interacoes['items'] = [];
    }
    return $interacoes;
}

function obter_variacao_ab_busca(string $cookieName = 'ibr_semantic_variant'): string {
    $variant = isset($_COOKIE[$cookieName]) ? trim((string) $_COOKIE[$cookieName]) : '';
    if ($variant !== 'embedding' && $variant !== 'lexical') {
        $seed = (string) ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $hash = hexdec(substr(md5($seed), 0, 8));
        $variant = ($hash % 100) < 50 ? 'embedding' : 'lexical';
        setcookie($cookieName, $variant, [
            'expires' => time() + 60 * 60 * 24 * 30,
            'path' => '/',
            'samesite' => 'Lax',
            'secure' => !empty($_SERVER['HTTPS']),
        ]);
    }
    return $variant;
}

function registrar_busca_interacoes(array $interacoes, string $query, array $resultados): array {
    if ($query === '') {
        return $interacoes;
    }
    if (!isset($interacoes['queries'][$query])) {
        $interacoes['queries'][$query] = [
            'count' => 0,
            'clicks' => 0,
            'conversions' => 0,
            'items' => [],
        ];
    }
    $interacoes['queries'][$query]['count'] = ($interacoes['queries'][$query]['count'] ?? 0) + 1;
    foreach ($resultados as $resultado) {
        if (!isset($resultado['id'])) {
            continue;
        }
        $itemId = (string) $resultado['id'];
        if (!isset($interacoes['items'][$itemId])) {
            $interacoes['items'][$itemId] = [
                'impressions' => 0,
                'clicks' => 0,
                'conversions' => 0,
            ];
        }
        $interacoes['items'][$itemId]['impressions'] = ($interacoes['items'][$itemId]['impressions'] ?? 0) + 1;
        if (!isset($interacoes['queries'][$query]['items'][$itemId])) {
            $interacoes['queries'][$query]['items'][$itemId] = [
                'impressions' => 0,
                'clicks' => 0,
                'conversions' => 0,
            ];
        }
        $interacoes['queries'][$query]['items'][$itemId]['impressions'] =
            ($interacoes['queries'][$query]['items'][$itemId]['impressions'] ?? 0) + 1;
    }
    $interacoes['updatedAt'] = date('c');
    return $interacoes;
}

function registrar_interacao_item(array $interacoes, string $query, string $itemId, string $tipo): array {
    if ($query === '' || $itemId === '') {
        return $interacoes;
    }
    if (!isset($interacoes['queries'][$query])) {
        $interacoes['queries'][$query] = [
            'count' => 0,
            'clicks' => 0,
            'conversions' => 0,
            'items' => [],
        ];
    }
    if (!isset($interacoes['queries'][$query]['items'][$itemId])) {
        $interacoes['queries'][$query]['items'][$itemId] = [
            'impressions' => 0,
            'clicks' => 0,
            'conversions' => 0,
        ];
    }
    if (!isset($interacoes['items'][$itemId])) {
        $interacoes['items'][$itemId] = [
            'impressions' => 0,
            'clicks' => 0,
            'conversions' => 0,
        ];
    }
    if ($tipo === 'conversao') {
        $interacoes['queries'][$query]['conversions'] = ($interacoes['queries'][$query]['conversions'] ?? 0) + 1;
        $interacoes['queries'][$query]['items'][$itemId]['conversions'] =
            ($interacoes['queries'][$query]['items'][$itemId]['conversions'] ?? 0) + 1;
        $interacoes['items'][$itemId]['conversions'] = ($interacoes['items'][$itemId]['conversions'] ?? 0) + 1;
    } else {
        $interacoes['queries'][$query]['clicks'] = ($interacoes['queries'][$query]['clicks'] ?? 0) + 1;
        $interacoes['queries'][$query]['items'][$itemId]['clicks'] =
            ($interacoes['queries'][$query]['items'][$itemId]['clicks'] ?? 0) + 1;
        $interacoes['items'][$itemId]['clicks'] = ($interacoes['items'][$itemId]['clicks'] ?? 0) + 1;
    }
    $interacoes['updatedAt'] = date('c');
    return $interacoes;
}

function calcular_boost_ranking(array $sinais): array {
    $impressions = max(1, (int) ($sinais['impressions'] ?? 0));
    $clicks = (int) ($sinais['clicks'] ?? 0);
    $conversions = (int) ($sinais['conversions'] ?? 0);
    $ctr = $clicks / $impressions;
    $conversionRate = $conversions / $impressions;
    $popularidade = log(1 + $clicks);
    $boost = min(30, $ctr * 120) + min(20, $conversionRate * 150) + min(10, $popularidade * 4);
    return [
        'boost' => $boost,
        'ctr' => $ctr,
        'conversionRate' => $conversionRate,
        'impressions' => $impressions,
        'clicks' => $clicks,
        'conversions' => $conversions,
    ];
}

function montar_url_configurador(string $linha, array $mapaUrls): string {
    $linha = strtoupper($linha);
    if (isset($mapaUrls[$linha])) {
        return $mapaUrls[$linha];
    }
    $paramMap = [
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
    $param = $paramMap[$linha] ?? '';
    $amigavel = codigo_amigavel($linha);
    if ($param !== '') {
        return "/Configurador{$amigavel}?{$param}=" . rawurlencode($linha);
    }
    return "/Configurador{$amigavel}";
}

$acao = trim((string) (filter_input(INPUT_POST, 'acao', FILTER_SANITIZE_FULL_SPECIAL_CHARS)
    ?? filter_input(INPUT_GET, 'acao', FILTER_SANITIZE_FULL_SPECIAL_CHARS)
    ?? filter_input(INPUT_POST, 'action', FILTER_SANITIZE_FULL_SPECIAL_CHARS)
    ?? filter_input(INPUT_GET, 'action', FILTER_SANITIZE_FULL_SPECIAL_CHARS)
    ?? ''));

$termo = trim((string) (filter_input(INPUT_GET, 'termo', FILTER_SANITIZE_FULL_SPECIAL_CHARS)
    ?? filter_input(INPUT_GET, 'q', FILTER_SANITIZE_FULL_SPECIAL_CHARS)
    ?? filter_input(INPUT_GET, 'query', FILTER_SANITIZE_FULL_SPECIAL_CHARS)
    ?? filter_input(INPUT_POST, 'termo', FILTER_SANITIZE_FULL_SPECIAL_CHARS)
    ?? filter_input(INPUT_POST, 'q', FILTER_SANITIZE_FULL_SPECIAL_CHARS)
    ?? filter_input(INPUT_POST, 'query', FILTER_SANITIZE_FULL_SPECIAL_CHARS)
    ?? ''));

$arquivoInteracoes = $baseDir . '/PaginasPrincipal/BuscaSemanticaInteracoes.json';

if ($acao !== '') {
    $tipoInteracao = strtolower(trim((string) (filter_input(INPUT_POST, 'tipo', FILTER_SANITIZE_FULL_SPECIAL_CHARS)
        ?? filter_input(INPUT_GET, 'tipo', FILTER_SANITIZE_FULL_SPECIAL_CHARS)
        ?? 'clique')));
    if ($tipoInteracao !== 'conversao') {
        $tipoInteracao = 'clique';
    }
    $item = trim((string) (filter_input(INPUT_POST, 'item', FILTER_SANITIZE_FULL_SPECIAL_CHARS)
        ?? filter_input(INPUT_GET, 'item', FILTER_SANITIZE_FULL_SPECIAL_CHARS)
        ?? ''));
    if ($termo === '' || $item === '') {
        echo json_encode(['erro' => 'Interação inválida.']);
        exit;
    }
    $interacoes = carregar_interacoes($arquivoInteracoes);
    $interacoes = registrar_interacao_item(
        $interacoes,
        normalizar_texto($termo),
        strtoupper($item),
        $tipoInteracao
    );
    salvar_dados_json($arquivoInteracoes, $interacoes);
    echo json_encode([
        'status' => 'ok',
        'query' => $termo,
        'item' => $item,
        'tipo' => $tipoInteracao,
    ]);
    exit;
}

if ($termo === '') {
    echo json_encode(['erro' => 'Consulta vazia.']);
    exit;
}

try {
    $arquivoMetadata = $baseDir . '/PaginasConfiguradores/InformacoesProdutos.json';
    $metadata = [];
    if (is_readable($arquivoMetadata)) {
        $metadataJson = file_get_contents($arquivoMetadata);
        $metadata = $metadataJson ? json_decode($metadataJson, true) : [];
    }
    if (!is_array($metadata)) {
        $metadata = [];
    }

    $mapaUrls = carregar_links_configuradores($baseDir);

    $normalizado = normalizar_texto($termo);
    $tokens = tokenizar_texto($termo);
    $sinonimos = obter_mapa_sinonimos();
    [$expansoes, $mapaSinonimos] = expandir_termos($tokens, $sinonimos);

    $interacoes = carregar_interacoes($arquivoInteracoes);
    $indice = carregar_indice_embeddings_catalogo($baseDir);
    $indiceCompleto = indice_embeddings_completo($indice);
    $variant = obter_variacao_ab_busca();
    $modo = ($indiceCompleto && $variant === 'embedding') ? 'embedding' : 'lexical';
    $fallbackSemIndice = !$indiceCompleto;

    $resultados = [];

    if ($modo === 'embedding') {
        $resultadosEmbedding = buscar_resultados_embeddings_catalogo($indice, $termo, 20);
        foreach ($resultadosEmbedding as $resultado) {
            $item = $resultado['item'] ?? [];
            $linhaStr = strtoupper((string) ($item['id'] ?? ''));
            if ($linhaStr === '') {
                continue;
            }
            $titulo = (string) ($item['titulo'] ?? '');
            $scoreBase = ((float) ($resultado['score'] ?? 0)) * 100;
            if ($normalizado !== '' && normalizar_texto($linhaStr) === $normalizado) {
                $scoreBase += 20;
            }
            if ($normalizado !== '' && normalizar_texto($titulo) === $normalizado) {
                $scoreBase += 10;
            }
            $sinaisItem = $interacoes['items'][$linhaStr] ?? [];
            $sinais = calcular_boost_ranking($sinaisItem);
            $scoreFinal = $scoreBase + $sinais['boost'];
            $resultados[] = [
                'id' => $linhaStr,
                'titulo' => $titulo,
                'url' => montar_url_configurador($linhaStr, $mapaUrls),
                'score' => round($scoreFinal, 2),
                'scoreBase' => round($scoreBase, 2),
                'boost' => round($sinais['boost'], 2),
                'ctr' => round($sinais['ctr'], 4),
                'matches' => [],
            ];
        }
    } else {
        foreach ($metadata as $linha => $info) {
            $linhaStr = strtoupper((string) $linha);
            $titulo = isset($info['title']) ? (string) $info['title'] : '';
            $descricao = isset($info['description']) ? (string) $info['description'] : '';
            $categorias = isset($info['categories']) && is_array($info['categories']) ? $info['categories'] : [];
            $grupos = isset($info['groups']) && is_array($info['groups']) ? $info['groups'] : [];

            $textoBusca = normalizar_texto(implode(' ', [
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
            $matches = [];

            $linhaNormalizada = normalizar_texto($linhaStr);
            $tituloNormalizado = normalizar_texto($titulo);

            if ($normalizado !== '' && $linhaNormalizada === $normalizado) {
                $score += 120;
                $matches[] = $linhaStr;
            }
            if ($normalizado !== '' && $tituloNormalizado === $normalizado) {
                $score += 100;
                $matches[] = $titulo;
            }
            if ($normalizado !== '' && strpos($textoBusca, $normalizado) !== false) {
                $score += 60;
            }

            foreach ($tokens as $token) {
                if ($token === '') {
                    continue;
                }
                if (strpos($textoBusca, $token) !== false) {
                    $score += 12;
                    $matches[] = $token;
                }
            }

            foreach ($expansoes as $token) {
                if ($token === '' || in_array($token, $tokens, true)) {
                    continue;
                }
                if (strpos($textoBusca, $token) !== false) {
                    $score += 6;
                    $matches[] = $token;
                }
            }

            if ($score <= 0) {
                continue;
            }

            $sinaisItem = $interacoes['items'][$linhaStr] ?? [];
            $sinais = calcular_boost_ranking($sinaisItem);
            $scoreFinal = $score + $sinais['boost'];

            $resultados[] = [
                'id' => $linhaStr,
                'titulo' => $titulo,
                'url' => montar_url_configurador($linhaStr, $mapaUrls),
                'score' => round($scoreFinal, 2),
                'scoreBase' => round($score, 2),
                'boost' => round($sinais['boost'], 2),
                'ctr' => round($sinais['ctr'], 4),
                'matches' => array_values(array_unique($matches)),
            ];
        }
    }

    usort($resultados, function ($a, $b) {
        $scoreA = $a['score'] ?? 0;
        $scoreB = $b['score'] ?? 0;
        if ($scoreA === $scoreB) {
            return strcmp((string) ($a['titulo'] ?? ''), (string) ($b['titulo'] ?? ''));
        }
        return $scoreA < $scoreB ? 1 : -1;
    });

    $resultados = array_slice($resultados, 0, 20);

    $interacoes = registrar_busca_interacoes($interacoes, $normalizado, $resultados);
    salvar_dados_json($arquivoInteracoes, $interacoes);

    $duracaoMs = round((microtime(true) - $inicio) * 1000, 2);
    
    echo json_encode([
        'query' => $termo,
        'normalizado' => $normalizado,
        'termos' => $tokens,
        'expansoes' => $expansoes,
        'sinonimos' => $mapaSinonimos,
        'modo' => $modo,
        'ab' => $variant,
        'indiceCompleto' => $indiceCompleto,
        'capabilities' => [
            'fallbackSemIndiceAtivo' => $fallbackSemIndice,
            'versaoBaseConhecimento' => api_chat_versao_base_conhecimento($baseDir),
        ],
        'resultados' => $resultados,
        'duracaoMs' => $duracaoMs,
    ]);
} catch (Throwable $e) {
    log_event(json_encode([
        'componente' => 'buscar_semantico',
        'nivel' => 'erro',
        'mensagem' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE));
    echo json_encode(['erro' => 'Falha ao processar busca semântica.']);
}
?>
