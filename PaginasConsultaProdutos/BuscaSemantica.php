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
    return array_values(array_unique($tokens));
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
        'api' => ['endpoint', 'integracao', 'documentacao'],
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
    return [array_values(array_unique($expandido)), $mapa];
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

function gerar_resumo(string $texto, int $maxSentencas = 2, int $maxChars = 240): string {
    $limpo = trim(preg_replace('/\s+/', ' ', $texto));
    if ($limpo === '') {
        return '';
    }
    $sentencas = preg_split('/(?<=[.!?])\s+/', $limpo, -1, PREG_SPLIT_NO_EMPTY);
    $resumo = '';
    $contador = 0;
    foreach ($sentencas as $sentenca) {
        $sentenca = trim($sentenca);
        if ($sentenca === '') {
            continue;
        }
        $resumo .= ($resumo !== '' ? ' ' : '') . $sentenca;
        $contador++;
        if ($contador >= $maxSentencas) {
            break;
        }
    }
    if ($resumo === '') {
        $resumo = mb_substr($limpo, 0, $maxChars, 'UTF-8');
    }
    if (mb_strlen($resumo, 'UTF-8') > $maxChars) {
        $resumo = mb_substr($resumo, 0, $maxChars - 1, 'UTF-8') . '…';
    }
    return $resumo;
}

function montar_url_configurador(string $linha): string {
    $linha = strtoupper($linha);
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

function carregar_produtos(string $baseDir): array {
    $arquivo = $baseDir . '/PaginasConfiguradores/InformacoesProdutos.json';
    $metadata = carregar_dados_json($arquivo);
    if (!is_array($metadata)) {
        return [];
    }
    $produtos = [];
    foreach ($metadata as $linha => $info) {
        $linhaStr = strtoupper((string) $linha);
        $titulo = isset($info['title']) ? (string) $info['title'] : '';
        $descricao = isset($info['description']) ? (string) $info['description'] : '';
        $categorias = isset($info['categories']) && is_array($info['categories']) ? $info['categories'] : [];
        $grupos = isset($info['groups']) && is_array($info['groups']) ? $info['groups'] : [];
        $produtos[] = [
            'id' => $linhaStr,
            'tipo' => 'produto',
            'titulo' => $titulo,
            'descricao' => $descricao,
            'resumo' => gerar_resumo($descricao),
            'url' => montar_url_configurador($linhaStr),
            'extra' => implode(' ', array_merge($categorias, $grupos)),
        ];
    }
    return $produtos;
}

function obter_titulo_documento(string $conteudo, string $arquivo): string {
    $linhas = preg_split('/\R/', $conteudo);
    if (is_array($linhas)) {
        foreach ($linhas as $linha) {
            $linha = trim($linha);
            if ($linha !== '') {
                return $linha;
            }
        }
    }
    return pathinfo($arquivo, PATHINFO_FILENAME);
}

function carregar_documentos(string $baseDir): array {
    $dirDocs = $baseDir . '/DocumentacaoAPIs';
    if (!is_dir($dirDocs)) {
        return [];
    }
    $documentos = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dirDocs, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $arquivo) {
        if (!$arquivo->isFile()) {
            continue;
        }
        $caminho = $arquivo->getPathname();
        $conteudo = file_get_contents($caminho);
        if ($conteudo === false) {
            continue;
        }
        $titulo = obter_titulo_documento($conteudo, $arquivo->getFilename());
        $relativo = str_replace($baseDir, '', $caminho);
        if ($relativo === $caminho) {
            $relativo = str_replace($dirDocs, '/DocumentacaoAPIs', $caminho);
        }
        $documentos[] = [
            'id' => ltrim($relativo, '/'),
            'tipo' => 'documentacao',
            'titulo' => $titulo,
            'descricao' => $conteudo,
            'resumo' => gerar_resumo($conteudo),
            'url' => $relativo,
            'extra' => '',
        ];
    }
    return $documentos;
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

function calcular_resultados_lexicais(array $itens, string $normalizado, array $tokens, array $expansoes): array {
    $resultados = [];

    foreach ($itens as $item) {
        $id = (string) ($item['id'] ?? '');
        $titulo = (string) ($item['titulo'] ?? '');
        $descricao = (string) ($item['descricao'] ?? '');
        $extra = (string) ($item['extra'] ?? '');

        $textoBusca = normalizar_texto(implode(' ', [$id, $titulo, $descricao, $extra]));
        if ($textoBusca === '') {
            continue;
        }

        $score = 0.0;
        $matches = [];
        $idNormalizado = normalizar_texto($id);
        $tituloNormalizado = normalizar_texto($titulo);

        if ($normalizado !== '' && $idNormalizado === $normalizado) {
            $score += 120;
            $matches[] = $id;
        }
        if ($normalizado !== '' && $tituloNormalizado === $normalizado) {
            $score += 90;
            $matches[] = $titulo;
        }
        if ($normalizado !== '' && strpos($textoBusca, $normalizado) !== false) {
            $score += 50;
        }

        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            if (strpos($textoBusca, $token) !== false) {
                $score += 10;
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

        $resultados[] = [
            'id' => $id,
            'tipo' => $item['tipo'] ?? 'produto',
            'titulo' => $titulo,
            'resumo' => $item['resumo'] ?? '',
            'url' => $item['url'] ?? '',
            'score' => round($score, 2),
            'matches' => array_values(array_unique($matches)),
        ];
    }

    return $resultados;
}

$termo = trim((string) (filter_input(INPUT_GET, 'termo', FILTER_SANITIZE_FULL_SPECIAL_CHARS)
    ?? filter_input(INPUT_GET, 'q', FILTER_SANITIZE_FULL_SPECIAL_CHARS)
    ?? filter_input(INPUT_GET, 'query', FILTER_SANITIZE_FULL_SPECIAL_CHARS)
    ?? filter_input(INPUT_POST, 'termo', FILTER_SANITIZE_FULL_SPECIAL_CHARS)
    ?? filter_input(INPUT_POST, 'q', FILTER_SANITIZE_FULL_SPECIAL_CHARS)
    ?? filter_input(INPUT_POST, 'query', FILTER_SANITIZE_FULL_SPECIAL_CHARS)
    ?? ''));

if ($termo === '') {
    echo json_encode(['erro' => 'Consulta vazia.']);
    exit;
}

try {
    $normalizado = normalizar_texto($termo);
    $tokens = tokenizar_texto($termo);
    $sinonimos = obter_mapa_sinonimos();
    [$expansoes, $mapaSinonimos] = expandir_termos($tokens, $sinonimos);
    $variant = obter_variacao_ab_busca();
    $indice = carregar_indice_embeddings_catalogo($baseDir);
    $indiceCompleto = indice_embeddings_completo($indice);

    $modo = ($indiceCompleto && $variant === 'embedding') ? 'embedding' : 'lexical';
    $fallbackSemIndice = !$indiceCompleto;
    $resultados = [];

    if ($modo === 'embedding') {
        $resultadosEmbedding = buscar_resultados_embeddings_catalogo($indice, $termo, 20);
        foreach ($resultadosEmbedding as $resultado) {
            $item = $resultado['item'] ?? [];
            $score = (float) ($resultado['score'] ?? 0);
            $scoreFinal = $score * 100;
            $id = (string) ($item['id'] ?? '');
            $titulo = (string) ($item['titulo'] ?? '');
            if ($normalizado !== '' && normalizar_texto($id) === $normalizado) {
                $scoreFinal += 20;
            }
            if ($normalizado !== '' && normalizar_texto($titulo) === $normalizado) {
                $scoreFinal += 10;
            }
            $resultados[] = [
                'id' => $id,
                'tipo' => 'produto',
                'titulo' => $titulo,
                'resumo' => (string) ($item['resumo'] ?? ''),
                'url' => (string) ($item['url'] ?? ''),
                'score' => round($scoreFinal, 2),
                'matches' => [],
            ];
        }

        $documentos = carregar_documentos($baseDir);
        $resultadosDocs = calcular_resultados_lexicais($documentos, $normalizado, $tokens, $expansoes);
        $resultados = array_merge($resultados, $resultadosDocs);
    } else {
        $itens = array_merge(carregar_produtos($baseDir), carregar_documentos($baseDir));
        $resultados = calcular_resultados_lexicais($itens, $normalizado, $tokens, $expansoes);
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
        'componente' => 'buscar_semantico_global',
        'nivel' => 'erro',
        'mensagem' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE));
    echo json_encode(['erro' => 'Falha ao processar busca semântica.']);
}
?>
