<?php
require_once __DIR__ . '/../APIChat/_shared/SemanticIndexStorage.php';
if (!function_exists('normalizar_texto_embeddings')) {
    function normalizar_texto_embeddings(string $valor): string
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
}

if (!function_exists('tokenizar_texto_embeddings')) {
    function tokenizar_texto_embeddings(string $valor): array
    {
        $normalizado = normalizar_texto_embeddings($valor);
        if ($normalizado === '') {
            return [];
        }
        $tokens = preg_split('/\s+/', $normalizado, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($tokens)) {
            return [];
        }
        $tokens = array_values(array_unique($tokens));
        return array_values(array_filter($tokens, function ($token) {
            return $token !== '' && mb_strlen($token, 'UTF-8') > 1;
        }));
    }
}

if (!function_exists('obter_mapa_sinonimos_embeddings')) {
    function obter_mapa_sinonimos_embeddings(): array
    {
        return [
            'redutor' => ['redutores', 'reducao', 'reducao de velocidade', 'motoredutor'],
            'motor' => ['motoredutor', 'motriz', 'motorizado'],
            'anticorrosivo' => ['anticorrosivos', 'anti corrosao', 'corrosao'],
            'inox' => ['aco inox', 'inoxidavel', 'aco inoxidavel'],
            'alto' => ['alto rendimento', 'rendimento'],
            'linha' => ['serie', 'familia', 'grupo'],
            'grupo' => ['familia', 'linha'],
            'acessorio' => ['acessorios', 'opcional'],
        ];
    }
}

if (!function_exists('expandir_termos_embeddings')) {
    function expandir_termos_embeddings(array $tokens, array $sinonimos): array
    {
        $expandido = $tokens;
        foreach ($tokens as $token) {
            if (!isset($sinonimos[$token])) {
                continue;
            }
            foreach ($sinonimos[$token] as $variacao) {
                $variacaoNormalizada = normalizar_texto_embeddings($variacao);
                if ($variacaoNormalizada === '') {
                    continue;
                }
                foreach (preg_split('/\s+/', $variacaoNormalizada) as $parte) {
                    if ($parte !== '') {
                        $expandido[] = $parte;
                    }
                }
            }
        }
        return array_values(array_unique($expandido));
    }
}

if (!function_exists('gerar_vetor_vazio_embeddings')) {
    function gerar_vetor_vazio_embeddings(int $dimensao): array
    {
        return array_fill(0, $dimensao, 0.0);
    }
}

if (!function_exists('adicionar_tokens_embedding')) {
    function adicionar_tokens_embedding(array &$vetor, array $tokens, float $peso, int $dimensao): void
    {
        foreach ($tokens as $token) {
            $hash = crc32($token);
            $indice = $hash % $dimensao;
            $sinal = ($hash & 1) === 1 ? 1.0 : -1.0;
            $vetor[$indice] += $peso * $sinal;
        }
    }
}

if (!function_exists('normalizar_vetor_embeddings')) {
    function normalizar_vetor_embeddings(array $vetor): array
    {
        $soma = 0.0;
        foreach ($vetor as $valor) {
            $soma += $valor * $valor;
        }
        if ($soma <= 0.0) {
            return $vetor;
        }
        $norma = sqrt($soma);
        if ($norma <= 0.0) {
            return $vetor;
        }
        foreach ($vetor as $idx => $valor) {
            $vetor[$idx] = $valor / $norma;
        }
        return $vetor;
    }
}

if (!function_exists('gerar_resumo_embeddings')) {
    function gerar_resumo_embeddings(string $texto, int $maxSentencas = 2, int $maxChars = 240): string
    {
        $limpo = trim(preg_replace('/\s+/', ' ', $texto));
        if ($limpo === '') {
            return '';
        }
        $sentencas = preg_split('/(?<=[.!?])\s+/', $limpo, -1, PREG_SPLIT_NO_EMPTY);
        $resumo = '';
        $contador = 0;
        if (is_array($sentencas)) {
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
        }
        if ($resumo === '') {
            $resumo = mb_substr($limpo, 0, $maxChars, 'UTF-8');
        }
        if (mb_strlen($resumo, 'UTF-8') > $maxChars) {
            $resumo = mb_substr($resumo, 0, $maxChars - 1, 'UTF-8') . '…';
        }
        return $resumo;
    }
}

if (!function_exists('gerar_embedding_texto')) {
    function gerar_embedding_texto(string $texto, int $dimensao = 96, float $peso = 1.0): array
    {
        $vetor = gerar_vetor_vazio_embeddings($dimensao);
        $tokens = tokenizar_texto_embeddings($texto);
        adicionar_tokens_embedding($vetor, $tokens, $peso, $dimensao);
        return $vetor;
    }
}

if (!function_exists('obter_caminho_indice_embeddings')) {
    function obter_caminho_indice_embeddings(string $baseDir): string
    {
        return rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'PaginasConsultaProdutos' . DIRECTORY_SEPARATOR . 'EmbeddingsCatalogo.json';
    }
}

if (!function_exists('obter_caminho_status_embeddings')) {
    function obter_caminho_status_embeddings(string $baseDir): string
    {
        return rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'PaginasConsultaProdutos' . DIRECTORY_SEPARATOR . 'EmbeddingsCatalogoStatus.json';
    }
}

if (!function_exists('carregar_status_embeddings')) {
    function carregar_status_embeddings(string $baseDir): array
    {
        $arquivo = obter_caminho_status_embeddings($baseDir);
        if (!is_file($arquivo)) {
            return [];
        }
        $json = json_decode((string) file_get_contents($arquivo), true);
        return is_array($json) ? $json : [];
    }
}

if (!function_exists('salvar_status_embeddings')) {
    function salvar_status_embeddings(string $baseDir, array $status): void
    {
        $arquivo = obter_caminho_status_embeddings($baseDir);
        file_put_contents(
            $arquivo,
            json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }
}

if (!function_exists('carregar_indice_embeddings_catalogo')) {
    function carregar_indice_embeddings_catalogo(string $baseDir): array
    {
        $versionado = api_chat_semantic_index_load($baseDir, 'produtos-embeddings');
        if (is_array($versionado) && isset($versionado['itens']) && is_array($versionado['itens'])) {
            return $versionado;
        }

        $arquivo = obter_caminho_indice_embeddings($baseDir);
        if (!is_file($arquivo)) {
            return [];
        }
        $json = json_decode((string) file_get_contents($arquivo), true);
        if (!is_array($json)) {
            return [];
        }
        if (!isset($json['itens']) || !is_array($json['itens'])) {
            $json['itens'] = [];
        }
        return $json;
    }
}

if (!function_exists('indice_embeddings_completo')) {
    function indice_embeddings_completo(array $indice): bool
    {
        $totalEsperado = (int) ($indice['totalEsperado'] ?? 0);
        $totalIndexado = (int) ($indice['totalIndexado'] ?? 0);
        return $totalEsperado > 0 && $totalIndexado >= $totalEsperado;
    }
}

if (!function_exists('solicitar_indexacao_embeddings_catalogo')) {
    function solicitar_indexacao_embeddings_catalogo(string $baseDir, string $origem = 'manual', bool $forcar = false): bool
    {
        $status = carregar_status_embeddings($baseDir);
        $agora = time();
        $ultimaSolicitacao = isset($status['ultimaSolicitacaoEm']) ? (int) $status['ultimaSolicitacaoEm'] : 0;
        $limiteRequisicao = 5 * 60;
        if (!$forcar && $ultimaSolicitacao > 0 && ($agora - $ultimaSolicitacao) < $limiteRequisicao) {
            return false;
        }
        if (!function_exists('registrar_job_fila')) {
            require_once $baseDir . '/ProcessamentoFilaJobs/CriaFilaProcessamento.php';
        }
        $jobId = registrar_job_fila('indexar_catalogo_embeddings', [
            'origem' => $origem,
            'forcar' => $forcar,
        ], $baseDir);
        $status['ultimaSolicitacaoEm'] = $agora;
        $status['ultimoJobId'] = $jobId;
        $status['status'] = 'pendente';
        salvar_status_embeddings($baseDir, $status);
        return true;
    }
}

if (!function_exists('montar_url_configurador_embeddings')) {
    function montar_url_configurador_embeddings(string $linha): string
    {
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
        $amigavel = function_exists('codigo_amigavel') ? codigo_amigavel($linha) : $linha;
        if ($param !== '') {
            return "/Configurador{$amigavel}?{$param}=" . rawurlencode($linha);
        }
        return "/Configurador{$amigavel}";
    }
}

if (!function_exists('gerar_index_embeddings_catalogo')) {
    function gerar_index_embeddings_catalogo(string $baseDir, array $opcoes = []): array
    {
        $dimensao = isset($opcoes['dimensao']) ? (int) $opcoes['dimensao'] : 96;
        $forcar = (bool) ($opcoes['forcar'] ?? false);
        $origem = (string) ($opcoes['origem'] ?? 'fila_jobs');
        $arquivoIndice = obter_caminho_indice_embeddings($baseDir);
        $lockArquivo = $arquivoIndice . '.lock';
        $lockHandle = fopen($lockArquivo, 'c+');
        if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
            return ['status' => 'ocupado'];
        }

        $arquivoMetadata = $baseDir . '/PaginasConfiguradores/InformacoesProdutos.json';
        if (!is_readable($arquivoMetadata)) {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
            return ['status' => 'erro', 'mensagem' => 'Metadata indisponível.'];
        }

        $metadataJson = file_get_contents($arquivoMetadata);
        $metadata = $metadataJson ? json_decode($metadataJson, true) : [];
        if (!is_array($metadata)) {
            $metadata = [];
        }

        $totalEsperado = count($metadata);
        $itens = [];

        foreach ($metadata as $linha => $info) {
            $linhaStr = strtoupper((string) $linha);
            $titulo = isset($info['title']) ? (string) $info['title'] : '';
            $descricao = isset($info['description']) ? (string) $info['description'] : '';
            $categorias = isset($info['categories']) && is_array($info['categories']) ? $info['categories'] : [];
            $grupos = isset($info['groups']) && is_array($info['groups']) ? $info['groups'] : [];
            $categoriasTexto = implode(' ', array_merge($categorias, $grupos));

            $vetor = gerar_vetor_vazio_embeddings($dimensao);
            adicionar_tokens_embedding($vetor, tokenizar_texto_embeddings($linhaStr), 1.4, $dimensao);
            adicionar_tokens_embedding($vetor, tokenizar_texto_embeddings($titulo), 2.2, $dimensao);
            adicionar_tokens_embedding($vetor, tokenizar_texto_embeddings($descricao), 1.0, $dimensao);
            adicionar_tokens_embedding($vetor, tokenizar_texto_embeddings($categoriasTexto), 1.6, $dimensao);
            $vetor = normalizar_vetor_embeddings($vetor);

            $itens[] = [
                'id' => $linhaStr,
                'titulo' => $titulo,
                'descricao' => $descricao,
                'resumo' => gerar_resumo_embeddings($descricao),
                'categorias' => array_values($categorias),
                'url' => montar_url_configurador_embeddings($linhaStr),
                'vetor' => array_map(static function ($valor) {
                    return round((float) $valor, 6);
                }, $vetor),
            ];
        }

        $indice = [
            'geradoEm' => date('c'),
            'origem' => $origem,
            'dimensao' => $dimensao,
            'totalEsperado' => $totalEsperado,
            'totalIndexado' => count($itens),
            'fonteMtime' => filemtime($arquivoMetadata) ?: null,
            'itens' => $itens,
        ];

        if ($forcar || !is_file($arquivoIndice) || $totalEsperado !== 0) {
            file_put_contents(
                $arquivoIndice,
                json_encode($indice, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
                LOCK_EX
            );
        }

        $status = carregar_status_embeddings($baseDir);
        $status['ultimaGeracaoEm'] = time();
        $status['status'] = 'concluido';
        $status['totalIndexado'] = $indice['totalIndexado'];
        $status['totalEsperado'] = $indice['totalEsperado'];
        salvar_status_embeddings($baseDir, $status);

        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);

        return ['status' => 'ok', 'totalIndexado' => count($itens), 'totalEsperado' => $totalEsperado];
    }
}

if (!function_exists('gerar_payload_index_embeddings_catalogo')) {
    function gerar_payload_index_embeddings_catalogo(string $baseDir, int $dimensao = 96, string $origem = 'script_offline'): array
    {
        $arquivoMetadata = $baseDir . '/PaginasConfiguradores/InformacoesProdutos.json';
        $metadataJson = is_readable($arquivoMetadata) ? file_get_contents($arquivoMetadata) : false;
        $metadata = $metadataJson ? json_decode($metadataJson, true) : [];
        if (!is_array($metadata)) {
            $metadata = [];
        }

        $itens = [];
        foreach ($metadata as $linha => $info) {
            $linhaStr = strtoupper((string) $linha);
            $titulo = isset($info['title']) ? (string) $info['title'] : '';
            $descricao = isset($info['description']) ? (string) $info['description'] : '';
            $categorias = isset($info['categories']) && is_array($info['categories']) ? $info['categories'] : [];
            $grupos = isset($info['groups']) && is_array($info['groups']) ? $info['groups'] : [];
            $categoriasTexto = implode(' ', array_merge($categorias, $grupos));

            $vetor = gerar_vetor_vazio_embeddings($dimensao);
            adicionar_tokens_embedding($vetor, tokenizar_texto_embeddings($linhaStr), 1.4, $dimensao);
            adicionar_tokens_embedding($vetor, tokenizar_texto_embeddings($titulo), 2.2, $dimensao);
            adicionar_tokens_embedding($vetor, tokenizar_texto_embeddings($descricao), 1.0, $dimensao);
            adicionar_tokens_embedding($vetor, tokenizar_texto_embeddings($categoriasTexto), 1.6, $dimensao);
            $vetor = normalizar_vetor_embeddings($vetor);

            $itens[] = [
                'id' => $linhaStr,
                'titulo' => $titulo,
                'descricao' => $descricao,
                'resumo' => gerar_resumo_embeddings($descricao),
                'categorias' => array_values($categorias),
                'url' => montar_url_configurador_embeddings($linhaStr),
                'vetor' => array_map(static function ($valor) {
                    return round((float) $valor, 6);
                }, $vetor),
            ];
        }

        return [
            'geradoEm' => date('c'),
            'origem' => $origem,
            'dimensao' => $dimensao,
            'totalEsperado' => count($metadata),
            'totalIndexado' => count($itens),
            'fonteMtime' => is_readable($arquivoMetadata) ? (filemtime($arquivoMetadata) ?: null) : null,
            'versaoBaseConhecimento' => api_chat_versao_base_conhecimento($baseDir),
            'itens' => $itens,
        ];
    }
}

if (!function_exists('buscar_resultados_embeddings_catalogo')) {
    function buscar_resultados_embeddings_catalogo(array $indice, string $consulta, int $limite = 20): array
    {
        $dimensao = (int) ($indice['dimensao'] ?? 96);
        if ($dimensao <= 0) {
            $dimensao = 96;
        }
        $tokens = tokenizar_texto_embeddings($consulta);
        $sinonimos = obter_mapa_sinonimos_embeddings();
        $tokens = expandir_termos_embeddings($tokens, $sinonimos);
        $vetorConsulta = gerar_vetor_vazio_embeddings($dimensao);
        adicionar_tokens_embedding($vetorConsulta, $tokens, 1.2, $dimensao);
        $vetorConsulta = normalizar_vetor_embeddings($vetorConsulta);
        if (!$vetorConsulta || !is_array($indice['itens'] ?? null)) {
            return [];
        }
        $resultados = [];
        foreach ($indice['itens'] as $item) {
            $vetorItem = $item['vetor'] ?? [];
            if (!is_array($vetorItem) || count($vetorItem) !== $dimensao) {
                continue;
            }
            $score = 0.0;
            foreach ($vetorItem as $idx => $valor) {
                $score += $valor * ($vetorConsulta[$idx] ?? 0.0);
            }
            $resultados[] = [
                'item' => $item,
                'score' => $score,
            ];
        }
        usort($resultados, function ($a, $b) {
            return ($a['score'] ?? 0) < ($b['score'] ?? 0) ? 1 : -1;
        });
        $resultados = array_slice($resultados, 0, $limite);
        return array_map(static function ($resultado) {
            return [
                'item' => $resultado['item'],
                'score' => $resultado['score'],
            ];
        }, $resultados);
    }
}

if (!function_exists('buscar_recomendacoes_similares_catalogo')) {
    function buscar_recomendacoes_similares_catalogo(array $indice, array $ids, int $limite = 12): array
    {
        $dimensao = (int) ($indice['dimensao'] ?? 96);
        $idsNormalizados = array_values(array_unique(array_map('strtoupper', $ids)));
        if (!is_array($indice['itens']) || !$indice['itens']) {
            return [];
        }
        $vetorBase = gerar_vetor_vazio_embeddings($dimensao);
        $contagem = 0;
        $mapaItens = [];
        foreach ($indice['itens'] as $item) {
            $idItem = strtoupper((string) ($item['id'] ?? ''));
            if ($idItem !== '') {
                $mapaItens[$idItem] = $item;
            }
        }
        foreach ($idsNormalizados as $id) {
            if (!isset($mapaItens[$id])) {
                continue;
            }
            $vetorItem = $mapaItens[$id]['vetor'] ?? null;
            if (!is_array($vetorItem) || count($vetorItem) !== $dimensao) {
                continue;
            }
            foreach ($vetorItem as $idx => $valor) {
                $vetorBase[$idx] += (float) $valor;
            }
            $contagem++;
        }
        if ($contagem <= 0) {
            return [];
        }
        foreach ($vetorBase as $idx => $valor) {
            $vetorBase[$idx] = $valor / $contagem;
        }
        $vetorBase = normalizar_vetor_embeddings($vetorBase);
        $resultados = [];
        foreach ($indice['itens'] as $item) {
            $idItem = strtoupper((string) ($item['id'] ?? ''));
            if ($idItem === '' || in_array($idItem, $idsNormalizados, true)) {
                continue;
            }
            $vetorItem = $item['vetor'] ?? [];
            if (!is_array($vetorItem) || count($vetorItem) !== $dimensao) {
                continue;
            }
            $score = 0.0;
            foreach ($vetorItem as $idx => $valor) {
                $score += $valor * ($vetorBase[$idx] ?? 0.0);
            }
            $resultados[] = [
                'item' => $item,
                'score' => $score,
            ];
        }
        usort($resultados, function ($a, $b) {
            return ($a['score'] ?? 0) < ($b['score'] ?? 0) ? 1 : -1;
        });
        $resultados = array_slice($resultados, 0, $limite);
        return array_map(static function ($resultado) {
            return [
                'item' => $resultado['item'],
                'score' => $resultado['score'],
            ];
        }, $resultados);
    }
}
?>
