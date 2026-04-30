<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ob_start();

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/NucleoApiChat.php';

function api_chat_produto_busca_campos_select(bool $incluirLinks): string
{
    $campos = [
        'PRODUTO.CD_PRODUTO AS codigo',
        'PRODUTO.DS_PRODUTO AS DS_DESCRICAO',
        'PRODUTO.DS_REFERENCIA AS DS_REFERENCIA',
        'PRODUTO.NR_ETIQUETA AS NR_ETIQUETA',
        'PRODUTO.CD_PRODCONFIG AS produtoPai',
        'PRODUTO.NR_ETIQUETA AS etiqueta',
        'PRODUTO.DS_OBSNOTA AS observacaoDatasheet',
        'LINKS.MANUAL_E_GARANTIA AS manualEGarantia',
        'LINKS.CATALOGO AS catalogo',
        'LINKS.MAIS_INFO AS maisInfo',
        'LINKS.CONHECA_TAMBEM AS conhecaTambem' 
    ];

    return implode(",\n                    ", $campos);
}

function api_chat_produto_busca_executar(PDO $pdo, string $termo, int $inicioPagina, int $fimPaginaComSentinela, int $limite, bool $incluirLinks = false): array
{
    $campos = api_chat_produto_busca_campos_select($incluirLinks);

    $termoNormalizado = busca_produto_normalizar_referencia($termo);

    $sql = "SELECT *
            FROM (
                SELECT
                    {$campos},
                    ROW_NUMBER() OVER (
                        ORDER BY
                            CASE
                                WHEN COALESCE(CAST(PRODUTO.CD_PRODUTO AS NVARCHAR(MAX)), '') = ? THEN 0
                                WHEN COALESCE(CAST(PRODUTO.NR_ETIQUETA AS NVARCHAR(MAX)), '') = ? THEN 1
                                WHEN PRODUTO.DS_REFERENCIA = ? THEN 2
                                WHEN COALESCE(CAST(PRODUTO.DS_OBSNOTA AS NVARCHAR(MAX)), '') = ? THEN 3
                                WHEN REPLACE(UPPER(COALESCE(PRODUTO.DS_REFERENCIA, '')), ' ', '') = ? THEN 4
                                WHEN REPLACE(UPPER(COALESCE(CAST(PRODUTO.CD_PRODUTO AS NVARCHAR(MAX)), '')), ' ', '') = ? THEN 5
                                WHEN REPLACE(UPPER(COALESCE(CAST(PRODUTO.NR_ETIQUETA AS NVARCHAR(MAX)), '')), ' ', '') = ? THEN 6
                                WHEN COALESCE(CAST(PRODUTO.DS_REFERENCIA AS NVARCHAR(MAX)), '') LIKE ? THEN 7
                                WHEN COALESCE(CAST(PRODUTO.CD_PRODUTO AS NVARCHAR(MAX)), '') LIKE ? THEN 8
                                WHEN COALESCE(CAST(PRODUTO.DS_OBSNOTA AS NVARCHAR(MAX)), '') LIKE ? THEN 9
                                WHEN COALESCE(CAST(PRODUTO.NR_ETIQUETA AS NVARCHAR(MAX)), '') LIKE ? THEN 10
                                WHEN COALESCE(CAST(PRODUTO.DS_PRODUTO AS NVARCHAR(MAX)), '') LIKE ? THEN 11
                                ELSE 12
                            END,
                            PRODUTO.CD_PRODUTO
                    ) AS numeroLinha
                FROM MMPR_PRODUTO AS PRODUTO
                INNER JOIN _USR_LINKS_PRODUTOS AS LINKS
                    ON PRODUTO.CD_FAMILIA = LINKS.CD_FAMILIA
                   AND PRODUTO.CD_GRUPO = LINKS.CD_GRUPO
                WHERE (
                        COALESCE(CAST(PRODUTO.CD_PRODUTO AS NVARCHAR(MAX)), '') = ?
                        OR COALESCE(CAST(PRODUTO.NR_ETIQUETA AS NVARCHAR(MAX)), '') = ?
                        OR PRODUTO.DS_REFERENCIA = ?
                        OR COALESCE(CAST(PRODUTO.DS_OBSNOTA AS NVARCHAR(MAX)), '') = ?
                        OR REPLACE(UPPER(COALESCE(PRODUTO.DS_REFERENCIA, '')), ' ', '') = ?
                        OR REPLACE(UPPER(COALESCE(CAST(PRODUTO.CD_PRODUTO AS NVARCHAR(MAX)), '')), ' ', '') = ?
                        OR REPLACE(UPPER(COALESCE(CAST(PRODUTO.NR_ETIQUETA AS NVARCHAR(MAX)), '')), ' ', '') = ?
                        OR COALESCE(CAST(PRODUTO.DS_REFERENCIA AS NVARCHAR(MAX)), '') LIKE ?
                        OR COALESCE(CAST(PRODUTO.CD_PRODUTO AS NVARCHAR(MAX)), '') LIKE ?
                        OR COALESCE(CAST(PRODUTO.DS_PRODUTO AS NVARCHAR(MAX)), '') LIKE ?
                        OR COALESCE(CAST(PRODUTO.DS_OBSNOTA AS NVARCHAR(MAX)), '') LIKE ?
                        OR COALESCE(CAST(PRODUTO.NR_ETIQUETA AS NVARCHAR(MAX)), '') LIKE ?
                    )
                  AND PRODUTO.CD_PRODCONFIG <> 'IA'
                  AND PRODUTO.CD_PRODCONFIG <> ''
                  AND PRODUTO.CD_PRODCONFIG IS NOT NULL
                  AND PRODUTO.ID_STATUS = 0
                  AND PRODUTO.DS_REFERENCIA NOT LIKE '%2.WS%'
                  AND PRODUTO.DS_REFERENCIA NOT LIKE 'MS.%'
            ) PAGINADO
            WHERE numeroLinha BETWEEN ? AND ?
            ORDER BY numeroLinha";

    $termoLike = '%' . $termo . '%';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $termo,
        $termo,
        $termo,
        $termo,
        $termoNormalizado,
        $termoNormalizado,
        $termoNormalizado,
        $termoLike,
        $termoLike,
        $termoLike,
        $termoLike,
        $termoLike,
        $termoLike,
        $termo,
        $termo,
        $termo,
        $termo,
        $termoNormalizado,
        $termoNormalizado,
        $termoNormalizado,
        $termoLike,
        $termoLike,
        $termoLike,
        $termoLike,
        $termoLike,
        $inicioPagina,
        $fimPaginaComSentinela,
    ]);

    $itens = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $temMais = count($itens) > $limite;
    if ($temMais) {
        array_pop($itens);
    }

    return [
        'dados' => is_array($itens) ? $itens : [],
        'temMais' => $temMais,
    ];
}

function responder_busca_produto_unificada(int $status, array $payload): void
{
    api_chat_middleware_responder($status, $payload, true);
}

function responder_busca_produto_parcial_sem_dados(string $pendencia, array $contexto = []): void
{
    responder_busca_produto_unificada(200, [
        'ok' => true,
        'status' => 'BLOQUEADO_SEM_DADOS',
        'erro' => 'Não foi possível concluir esta action com os dados recebidos.',
        'pendencia' => $pendencia,
        'contexto' => $contexto,
    ]);
}

function busca_produto_bool_query(string $nome, bool $padrao = false): bool
{
    $valor = filter_input(INPUT_GET, $nome, FILTER_UNSAFE_RAW);
    if (!is_string($valor)) {
        return $padrao;
    }

    $normalizado = strtolower(trim($valor));
    if ($normalizado === '') {
        return $padrao;
    }

    if (in_array($normalizado, ['1', 'true', 'sim', 'yes'], true)) {
        return true;
    }

    if (in_array($normalizado, ['0', 'false', 'nao', 'não', 'no'], true)) {
        return false;
    }

    return $padrao;
}

function busca_produto_int_query(string $nome, int $padrao, int $min, int $max): int
{
    $valor = filter_input(INPUT_GET, $nome, FILTER_VALIDATE_INT);
    if (!is_int($valor)) {
        return $padrao;
    }

    return max($min, min($max, $valor));
}

function busca_produto_string_query(string $nome): string
{
    $valor = filter_input(INPUT_GET, $nome, FILTER_UNSAFE_RAW);
    return is_string($valor) ? trim($valor) : '';
}

function busca_produto_payload_scalar(array $payload, array $candidatas): string
{
    if ($payload === [] || $candidatas === []) {
        return '';
    }

    $mapa = [];
    foreach ($payload as $chave => $valor) {
        if (!is_string($chave)) {
            continue;
        }

        $normalizada = busca_produto_normalizar_chave_entrada($chave);
        if ($normalizada === '' || array_key_exists($normalizada, $mapa)) {
            continue;
        }

        $mapa[$normalizada] = $valor;
    }

    foreach ($candidatas as $campo) {
        $normalizada = busca_produto_normalizar_chave_entrada($campo);
        if ($normalizada === '' || !array_key_exists($normalizada, $mapa)) {
            continue;
        }

        $valor = $mapa[$normalizada];
        if (!is_scalar($valor)) {
            continue;
        }

        $texto = trim((string) $valor);
        if ($texto !== '') {
            return $texto;
        }
    }

    return '';
}

function busca_produto_entrada_scalar(array $payload, array $candidatas): string
{
    $valor = busca_produto_payload_scalar($payload, $candidatas);
    if ($valor !== '') {
        return $valor;
    }

    foreach ($candidatas as $campo) {
        if (!is_string($campo) || trim($campo) === '') {
            continue;
        }

        $valorQuery = busca_produto_string_query($campo);
        if ($valorQuery !== '') {
            return $valorQuery;
        }
    }

    return '';
}

function busca_produto_normalizar_chave_entrada(string $chave): string
{
    $normalizada = trim($chave);
    if ($normalizada === '') {
        return '';
    }

    $semAcento = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalizada);
    if (is_string($semAcento) && $semAcento !== '') {
        $normalizada = $semAcento;
    }

    $normalizada = strtolower($normalizada);
    return preg_replace('/[^a-z0-9]/', '', $normalizada) ?? '';
}

function busca_produto_normalizar_item(array $item, bool $somenteDescricao, bool $modoCompacto, bool $incluirObservacoes): array
{
    $normalizado = [
        'codigo' => (string) ($item['codigo'] ?? ''),
        'DS_DESCRICAO' => (string) ($item['DS_DESCRICAO'] ?? ''),
        'DS_REFERENCIA' => (string) ($item['DS_REFERENCIA'] ?? ''),
        'NR_ETIQUETA' => (string) ($item['NR_ETIQUETA'] ?? ''),
    ];

    if (!$somenteDescricao) {
        $normalizado['produtoPai'] = $item['produtoPai'] ?? null;
        $normalizado['CD_PRODCONFIG'] = $item['produtoPai'] ?? null;
        $normalizado['manualEGarantia'] = $item['manualEGarantia'] ?? null;
        $normalizado['catalogo'] = $item['catalogo'] ?? null;
        $normalizado['maisInfo'] = $item['maisInfo'] ?? null;
        $normalizado['conhecaTambem'] = $item['conhecaTambem'] ?? null;
        if (!$modoCompacto || $incluirObservacoes) {
            $normalizado['observacaoDatasheet'] = $item['observacaoDatasheet'] ?? null;
        }
    }

    return $normalizado;
}

function busca_produto_normalizar_referencia(string $valor): string
{
    $referencia = strtoupper(trim($valor));
    return preg_replace('/\s+/', '', $referencia) ?? '';
}

function busca_produto_referencia_valida(string $valor): bool
{
    $referencia = busca_produto_normalizar_referencia($valor);
    if ($referencia === '') {
        return false;
    }

    return preg_match('/^(?=.{1,250}$)[0-9A-Z]+(\.[0-9A-Z]+){2,}$/', $referencia) === 1;
}

function busca_produto_resolver_acao(string $metodo, array $payloadPost): string
{
    $acao = busca_produto_string_query('acao');
    if ($acao !== '') {
        return $acao;
    }

    if (isset($payloadPost['acao']) && is_string($payloadPost['acao']) && trim($payloadPost['acao']) !== '') {
        return trim($payloadPost['acao']);
    }

    $q = busca_produto_string_query('q');
    $codigo = busca_produto_string_query('codigo');
    if ($q !== '') {
        return 'buscarProdutosPorTexto';
    }
    if ($codigo !== '') {
        return 'buscarProdutoPorCodigo';
    }

    return $metodo === 'POST' ? 'validarReferenciaEstruturadaPost' : 'consultarProdutosConfigurador';
}

function busca_produto_conectar_banco(string $baseDir): PDO
{
    require $baseDir . '/AcessosConsultas/CredenciaisBancoDados.php';

    return new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8,
    ]);
}

function executar_acao_buscar_produto_por_codigo(PDO $pdo, array $payloadPost = []): void
{
    $codigo = busca_produto_entrada_scalar($payloadPost, [
        'codigo',
        'codigoProduto',
        'codigoOuReferencia',
    ]);

    if ($codigo === '') {
        responder_busca_produto_parcial_sem_dados('Informe o parâmetro codigo.', [
            'acaoSugerida' => 'buscarProdutoPorCodigo',
            'parametrosMinimos' => ['codigo'],
        ]);
    }

    $somenteDescricao = busca_produto_bool_query('somenteDescricao');
    $modoCompacto = busca_produto_bool_query('modoCompacto');

    $sql = "SELECT TOP 1
                PRODUTO.CD_PRODUTO AS codigo,
                PRODUTO.DS_PRODUTO AS DS_DESCRICAO,
                PRODUTO.DS_REFERENCIA AS DS_REFERENCIA,
                COALESCE(CAST(PRODUTO.NR_ETIQUETA AS NVARCHAR(MAX)), '') AS NR_ETIQUETA,
                PRODUTO.CD_PRODCONFIG AS produtoPai,
                COALESCE(CAST(PRODUTO.NR_ETIQUETA AS NVARCHAR(MAX)), '') AS etiqueta,
                PRODUTO.DS_OBSNOTA AS observacaoDatasheet,
                LINKS.MANUAL_E_GARANTIA AS manualEGarantia,
                LINKS.CATALOGO AS catalogo,
                LINKS.MAIS_INFO AS maisInfo,
                LINKS.CONHECA_TAMBEM AS conhecaTambem
            FROM MMPR_PRODUTO AS PRODUTO
            INNER JOIN _USR_LINKS_PRODUTOS AS LINKS
                ON PRODUTO.CD_FAMILIA = LINKS.CD_FAMILIA
               AND PRODUTO.CD_GRUPO = LINKS.CD_GRUPO
            WHERE PRODUTO.CD_PRODUTO = ?
              AND PRODUTO.CD_PRODCONFIG <> 'IA'
              AND PRODUTO.CD_PRODCONFIG <> ''
              AND PRODUTO.CD_PRODCONFIG IS NOT NULL
              AND PRODUTO.ID_STATUS = 0
              AND PRODUTO.DS_REFERENCIA NOT LIKE '%2.WS%'
              AND PRODUTO.DS_REFERENCIA NOT LIKE 'MS.%'
            ORDER BY PRODUTO.CD_PRODUTO";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$codigo]);
    $registro = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($registro)) {
        responder_busca_produto_unificada(404, ['ok' => false, 'erro' => 'Produto não encontrado para o termo informado.', 'codigo' => $codigo]);
    }

    if ($somenteDescricao) {
        responder_busca_produto_unificada(200, [
            'ok' => true,
            'codigo' => (string) ($registro['codigo'] ?? $codigo),
            'DS_DESCRICAO' => (string) ($registro['DS_DESCRICAO'] ?? ''),
            'DS_REFERENCIA' => (string) ($registro['DS_REFERENCIA'] ?? ''),
            'produtoPai' => (string) ($registro['produtoPai'] ?? ''),
            'CD_PRODCONFIG' => (string) ($registro['produtoPai'] ?? ''),
        ]);
    }

    if ($modoCompacto) {
        unset($registro['etiqueta'], $registro['observacaoDatasheet']);
    }

    foreach (['manualEGarantia', 'catalogo', 'maisInfo', 'conhecaTambem'] as $campoLink) {
        if (!array_key_exists($campoLink, $registro)) {
            $registro[$campoLink] = null;
        }
    }

    responder_busca_produto_unificada(200, [
        'ok' => true,
        'filtros' => ['somenteDescricao' => $somenteDescricao, 'modoCompacto' => $modoCompacto],
        'dados' => $registro,
    ]);
}

function executar_acao_buscar_produtos_por_texto(PDO $pdo, array $payloadPost = []): void
{
    $termo = busca_produto_payload_scalar($payloadPost, [
        'q',
        'query',
        'termo',
        'entrada',
    ]);
    if ($termo === '') {
        $termo = busca_produto_string_query('q');
    }

    if ($termo === '') {
        responder_busca_produto_parcial_sem_dados('Informe o parâmetro q.', [
            'acaoSugerida' => 'buscarProdutosPorTexto',
            'parametrosMinimos' => ['q'],
        ]);
    }

    $somenteDescricao = busca_produto_bool_query('somenteDescricao');
    $modoCompacto = busca_produto_bool_query('modoCompacto');
    $incluirObservacoes = busca_produto_bool_query('incluirObservacoes');
    $limite = busca_produto_int_query('limite', 20, 1, 100);
    $pagina = busca_produto_int_query('pagina', 1, 1, 100000);

    $offset = ($pagina - 1) * $limite;
    $inicio = $offset + 1;
    $fim = $offset + $limite + 1;

    $resultado = api_chat_produto_busca_executar($pdo, $termo, $inicio, $fim, $limite, true);
    $itens = $resultado['dados'];
    $temMais = $resultado['temMais'];

    if (count($itens) === 0) {
        responder_busca_produto_unificada(404, [
            'ok' => false,
            'resolved' => false,
            'status' => 'ativo',
            'erro' => 'Nenhum produto encontrado para o termo informado.',
        ]);
    }

    $itensNormalizados = array_map(
        static fn (array $item): array => busca_produto_normalizar_item($item, $somenteDescricao, $modoCompacto, $incluirObservacoes),
        $itens
    );

    responder_busca_produto_unificada(200, [
        'ok' => true,
        'paginacao' => [
            'q' => $termo,
            'tipoBusca' => 'geral',
            'pagina' => $pagina,
            'limite' => $limite,
            'retornados' => count($itensNormalizados),
            'temMais' => $temMais,
            'proximaPagina' => $temMais ? $pagina + 1 : null,
        ],
        'capabilities' => [
            'buscaAcentoCaseInsensitive' => true,
            'fallbackEscopoGeral' => false,
        ],
        'dados' => $itensNormalizados,
    ]);
}

function executar_acao_consultar_produtos_configurador(PDO $pdo, array $payloadPost = []): void
{
    $codigo = busca_produto_entrada_scalar($payloadPost, ['codigo', 'codigoProduto']);
    $termo = busca_produto_entrada_scalar($payloadPost, ['q', 'query', 'termo']);
    $produtoPai = busca_produto_entrada_scalar($payloadPost, ['produtoPai', 'CD_PRODCONFIG', 'cd_prodconfig', 'produto']);
    $somenteDescricao = busca_produto_bool_query('somenteDescricao');
    $modoCompacto = busca_produto_bool_query('modoCompacto');

    if ($produtoPai === '' && $codigo === '' && $termo !== '') {
        $produtoPai = strtoupper($termo);
    }

    if ($somenteDescricao && $codigo === '') {
        responder_busca_produto_parcial_sem_dados('Informe o parâmetro codigo ao usar somenteDescricao.', [
            'acaoSugerida' => 'consultarProdutosConfigurador',
            'parametrosMinimos' => ['codigo'],
            'restricao' => 'somenteDescricao=true',
        ]);
    }

    $whereBase = " FROM MMPR_PRODUTO
        WHERE CD_PRODCONFIG <> 'IA'
          AND CD_PRODCONFIG <> ''
          AND CD_PRODCONFIG IS NOT NULL
          AND ID_STATUS = 0
          AND DS_REFERENCIA NOT LIKE '%2.WS%'
          AND DS_REFERENCIA NOT LIKE 'MS.%'
          AND CD_PRODUTO LIKE '%.%'";

    $filtros = [];
    $params = [];
    if ($codigo !== '') {
        $filtros[] = 'CD_PRODUTO = ?';
        $params[] = $codigo;
    }
    if ($produtoPai !== '') {
        $filtros[] = 'CD_PRODCONFIG = ?';
        $params[] = $produtoPai;
    }
    if ($filtros !== []) {
        $whereBase .= ' AND ' . implode(' AND ', $filtros);
    }

    if ($somenteDescricao) {
        $stmt = $pdo->prepare("SELECT TOP 1 CD_PRODUTO AS CodigoProduto, DS_PRODUTO AS DS_DESCRICAO, DS_REFERENCIA AS DS_REFERENCIA, NR_ETIQUETA AS NR_ETIQUETA, CD_PRODCONFIG AS ProdutoPai" . $whereBase . ' ORDER BY CD_PRODUTO');
        $stmt->execute($params);
        $registro = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$registro) {
            responder_busca_produto_unificada(404, ['ok' => false, 'erro' => 'Produto não encontrado para o código informado.', 'codigo' => $codigo]);
        }
        responder_busca_produto_unificada(200, [
            'ok' => true,
            'codigo' => (string) ($registro['CodigoProduto'] ?? $codigo),
            'DS_DESCRICAO' => (string) ($registro['DS_DESCRICAO'] ?? ''),
            'DS_REFERENCIA' => (string) ($registro['DS_REFERENCIA'] ?? ''),
            'NR_ETIQUETA' => (string) ($registro['NR_ETIQUETA'] ?? ''),
            'produtoPai' => (string) ($registro['ProdutoPai'] ?? ''),
            'CD_PRODCONFIG' => (string) ($registro['ProdutoPai'] ?? ''),
        ]);
    }

    $camposExternos = $modoCompacto
        ? 'TOP 1 CodigoProduto, DS_REFERENCIA, ProdutoPai, ProdutoPai AS CD_PRODCONFIG'
        : 'TOP 1 CodigoProduto, DescricaoProduto, DS_DESCRICAO, DS_REFERENCIA, EtiquetaProduto, ProdutoPai, ProdutoPai AS CD_PRODCONFIG, ObservacaoDatasheet';

    $sql = "SELECT {$camposExternos}
            FROM (
                SELECT CodigoProduto,
                       MAX(DescricaoProduto) AS DescricaoProduto,
                       MAX(DS_DESCRICAO) AS DS_DESCRICAO,
                       MAX(DS_REFERENCIA) AS DS_REFERENCIA,
                       MAX(EtiquetaProduto) AS EtiquetaProduto,
                       MAX(ProdutoPai) AS ProdutoPai,
                       MAX(ObservacaoDatasheet) AS ObservacaoDatasheet
                FROM (
                    SELECT CD_PRODUTO AS CodigoProduto,
                           DS_PRODUTO AS DescricaoProduto,
                           DS_PRODUTO AS DS_DESCRICAO,
                           DS_REFERENCIA AS DS_REFERENCIA,
                           NR_ETIQUETA AS EtiquetaProduto,
                           CD_PRODCONFIG AS ProdutoPai,
                           DS_OBSNOTA AS ObservacaoDatasheet"
        . $whereBase .
        "
                ) BASE
                GROUP BY CodigoProduto
            ) RESULTADO
            ORDER BY CodigoProduto";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $registro = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$registro) {
        responder_busca_produto_unificada(404, [
            'ok' => false,
            'erro' => 'Nenhum produto encontrado para os filtros informados.',
        ]);
    }

    responder_busca_produto_unificada(200, [
        'ok' => true,
        'resolved' => true,
        'status' => 'ativo',
        'filtros' => [
            'codigo' => $codigo !== '' ? $codigo : null,
            'produtoPai' => $produtoPai !== '' ? $produtoPai : null,
            'q' => $termo !== '' ? $termo : null,
            'somenteDescricao' => $somenteDescricao,
            'modoCompacto' => $modoCompacto,
        ],
        'dados' => $registro,
    ]);
}


function busca_produto_consultar_referencia_estruturada(PDO $pdo, string $referenciaNormalizada): ?array
{
    if ($referenciaNormalizada === '') {
        return null;
    }

    $referenciaCompacta = preg_replace('/[^0-9A-Z]/', '', $referenciaNormalizada) ?? '';

    $sql = "SELECT TOP 1
                PRODUTO.CD_PRODUTO AS codigo,
                PRODUTO.DS_PRODUTO AS DS_DESCRICAO,
                PRODUTO.DS_REFERENCIA AS DS_REFERENCIA,
                PRODUTO.NR_ETIQUETA AS NR_ETIQUETA,
                PRODUTO.CD_PRODCONFIG AS produtoPai,
                PRODUTO.DS_OBSNOTA AS observacaoDatasheet
            FROM MMPR_PRODUTO AS PRODUTO
            WHERE PRODUTO.CD_PRODCONFIG <> 'IA'
              AND PRODUTO.CD_PRODCONFIG <> ''
              AND PRODUTO.CD_PRODCONFIG IS NOT NULL
              AND PRODUTO.ID_STATUS = 0
              AND PRODUTO.DS_REFERENCIA NOT LIKE '%2.WS%'
              AND PRODUTO.DS_REFERENCIA NOT LIKE 'MS.%'
              AND (
                    REPLACE(UPPER(COALESCE(PRODUTO.DS_REFERENCIA, '')), ' ', '') = ?
                    OR REPLACE(REPLACE(REPLACE(REPLACE(UPPER(COALESCE(PRODUTO.DS_REFERENCIA, '')), ' ', ''), '.', ''), '-', ''), '/', '') = ?
              )
            ORDER BY PRODUTO.CD_PRODUTO";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$referenciaNormalizada, $referenciaCompacta]);
    $registro = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($registro) ? $registro : null;
}

function executar_acao_validar_referencia(PDO $pdo, string $metodo, array $payloadPost): void
{
    $referencia = busca_produto_payload_scalar($payloadPost, [
        'referencia',
        'referência',
        'valor',
        'entrada',
        'referenciaEstruturada',
        'codigoOuReferencia',
    ]);

    if ($referencia === '') {
        $referencia = busca_produto_payload_scalar($_GET, [
            'referencia',
            'referência',
            'valor',
            'entrada',
            'referenciaEstruturada',
            'codigoOuReferencia',
        ]);
    }

    $referenciaNormalizada = busca_produto_normalizar_referencia($referencia);
    $formatoValido = busca_produto_referencia_valida($referencia);
    $dadosOficiais = $formatoValido
        ? busca_produto_consultar_referencia_estruturada($pdo, $referenciaNormalizada)
        : null;

    responder_busca_produto_unificada(200, [
        'ok' => true,
        'acao' => $metodo === 'POST' ? 'validarReferenciaEstruturadaPost' : 'validarReferenciaEstruturadaGet',
        'entrada' => $referencia,
        'referenciaNormalizada' => $referenciaNormalizada,
        'valida' => $dadosOficiais !== null,
        'formatoValido' => $formatoValido,
        'dados' => $dadosOficiais,
        'erro' => $formatoValido && $dadosOficiais === null ? 'Referência não encontrada na base oficial.' : null,
    ]);
}

function busca_produto_select_extrair_sql(string $entrada): string
{
    $sql = trim($entrada);
    if ($sql === '') {
        return '';
    }

    if ((str_starts_with($sql, '```') && str_ends_with($sql, '```')) || (str_starts_with($sql, "'''") && str_ends_with($sql, "'''"))) {
        $sql = trim(substr($sql, 3, -3));
        $sql = preg_replace('/^\s*sql\s*/iu', '', $sql) ?? $sql;
    }

    return trim($sql);
}

function busca_produto_select_validar_sql(string $sql): ?string
{
    if ($sql === '') {
        return 'Informe um comando SQL SELECT para consulta.';
    }

    if (preg_match('/^\s*select\b/iu', $sql) !== 1 && preg_match('/^\s*with\b/iu', $sql) !== 1) {
        return 'Somente comandos iniciados por SELECT (ou WITH ... SELECT) são aceitos.';
    }

    if (substr_count($sql, ';') > 1 || (substr_count($sql, ';') === 1 && !preg_match('/;\s*$/u', $sql))) {
        return 'A consulta deve conter apenas um único comando SQL.';
    }

    if (preg_match('/(--|\/\*|\*\/)/u', $sql) === 1) {
        return 'Comentários SQL não são permitidos nesta API.';
    }

    if (preg_match('/\b(insert|update|delete|drop|alter|truncate|merge|exec(?:ute)?|create|grant|revoke|backup|restore)\b/iu', $sql) === 1) {
        return 'Somente consultas de leitura (SELECT) são permitidas.';
    }

    return null;
}

function busca_produto_select_aplicar_limite(string $sql, int $limite): string
{
    if (preg_match('/^\s*with\b/iu', $sql) === 1) {
        return 'SELECT TOP ' . $limite . ' * FROM (' . $sql . ') AS api_chat_limite_cte';
    }

    if (preg_match('/^\s*select\s+distinct\b/iu', $sql) === 1) {
        return preg_replace('/^\s*select\s+distinct\b/iu', 'SELECT DISTINCT TOP ' . $limite, $sql, 1) ?? $sql;
    }

    if (preg_match('/^\s*select\b/iu', $sql) === 1) {
        return preg_replace('/^\s*select\b/iu', 'SELECT TOP ' . $limite, $sql, 1) ?? $sql;
    }

    return $sql;
}

function executar_acao_consulta_select(string $baseDir, array $payloadPost, string $rawInput): void
{
    $input = $payloadPost;

    if (isset($_GET['sql']) && is_string($_GET['sql']) && !isset($input['sql'])) {
        $input['sql'] = $_GET['sql'];
    }

    if (isset($_GET['entrada']) && is_string($_GET['entrada']) && !isset($input['entrada'])) {
        $input['entrada'] = $_GET['entrada'];
    }

    if (isset($_GET['q']) && is_string($_GET['q']) && !isset($input['entrada']) && !isset($input['sql'])) {
        $input['entrada'] = $_GET['q'];
    }

    if (isset($_GET['query']) && is_string($_GET['query']) && !isset($input['entrada']) && !isset($input['sql'])) {
        $input['entrada'] = $_GET['query'];
    }

    if (isset($_GET['consulta']) && is_string($_GET['consulta']) && !isset($input['entrada']) && !isset($input['sql'])) {
        $input['entrada'] = $_GET['consulta'];
    }

    if (isset($_GET['limite']) && !isset($input['limite'])) {
        $input['limite'] = (int) $_GET['limite'];
    }

    $entrada = '';
    if (isset($input['sql']) && is_string($input['sql'])) {
        $entrada = $input['sql'];
    } elseif (isset($input['entrada']) && is_string($input['entrada'])) {
        $entrada = $input['entrada'];
    } elseif (isset($input['q']) && is_string($input['q'])) {
        $entrada = $input['q'];
    } elseif (isset($input['query']) && is_string($input['query'])) {
        $entrada = $input['query'];
    } elseif (isset($input['consulta']) && is_string($input['consulta'])) {
        $entrada = $input['consulta'];
    }

    $rawTrim = trim($rawInput);
    if ($rawTrim !== '' && $entrada === '') {
        if (preg_match('/^[\[{]/u', $rawTrim) === 1) {
            responder_busca_produto_unificada(400, [
                'ok' => false,
                'erro' => 'Body JSON inválido.',
            ]);
        }

        $entrada = $rawTrim;
    }

    $limite = isset($input['limite']) ? (int) $input['limite'] : 50;
    $limite = max(1, min(200, $limite));

    $sqlOriginal = busca_produto_select_extrair_sql($entrada);
    $erroValidacao = busca_produto_select_validar_sql($sqlOriginal);
    if ($erroValidacao !== null) {
        responder_busca_produto_unificada(400, [
            'ok' => false,
            'erro' => $erroValidacao,
        ]);
    }

    $sqlExecutado = busca_produto_select_aplicar_limite($sqlOriginal, $limite);

    $pdo = busca_produto_conectar_banco($baseDir);

    $stmt = $pdo->query($sqlExecutado);
    $linhas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    responder_busca_produto_unificada(200, [
        'ok' => true,
        'consulta' => [
            'sqlOriginal' => $sqlOriginal,
            'sqlExecutado' => $sqlExecutado,
            'limiteAplicado' => $limite,
            'retornados' => count($linhas),
        ],
        'dados' => $linhas,
    ]);
}

register_shutdown_function(static function (): void {
    $erroFatal = error_get_last();
    if (!is_array($erroFatal)) {
        return;
    }

    $tiposFatais = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array((int) ($erroFatal['type'] ?? 0), $tiposFatais, true)) {
        return;
    }

    responder_busca_produto_unificada(500, ['ok' => false, 'erro' => 'Erro interno ao executar ação de produtos.']);
});

$baseDir = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
if ($baseDir === '') {
    $baseDir = dirname(__DIR__);
}

$endpoint = busca_produto_string_query('_endpoint');
if ($endpoint === '') {
    $endpoint = '/APIChat/BuscaProduto.php';
}

api_chat_middleware_init($baseDir, $endpoint);

$metodo = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($metodo, ['GET', 'POST'], true)) {
    responder_busca_produto_unificada(405, ['ok' => false, 'erro' => 'Use GET ou POST.']);
}

auth_api_chat_validar_ou_responder($baseDir, 'responder_busca_produto_unificada');

$rawInput = '';
$payloadPost = [];
if ($metodo === 'POST') {
    $rawInput = (string) api_chat_middleware_raw_input();
    $payloadPost = json_decode($rawInput, true);
    if (!is_array($payloadPost)) {
        $payloadPost = [];
    }
}

$acao = busca_produto_resolver_acao($metodo, $payloadPost);
$acoesComBanco = ['buscarProdutoPorCodigo', 'buscarProdutosPorTexto', 'consultarProdutosConfigurador', 'consultaSelect'];

if ($acao === 'consultaSelect' && $metodo !== 'POST') {
    responder_busca_produto_unificada(405, ['ok' => false, 'erro' => 'Use POST para consultar SELECT no banco.']);
}

try {
    if (in_array($acao, ['validarReferenciaEstruturadaGet', 'validarReferenciaEstruturadaPost'], true)) {
        $pdo = busca_produto_conectar_banco($baseDir);
        executar_acao_validar_referencia($pdo, $metodo, $payloadPost);
        return;
    }

    if (!in_array($acao, $acoesComBanco, true)) {
        responder_busca_produto_unificada(400, ['ok' => false, 'erro' => 'Ação inválida.', 'acao' => $acao]);
    }

    if ($acao === 'consultaSelect') {
        executar_acao_consulta_select($baseDir, $payloadPost, $rawInput);
        return;
    }

    $pdo = busca_produto_conectar_banco($baseDir);

    if ($acao === 'buscarProdutoPorCodigo') {
        executar_acao_buscar_produto_por_codigo($pdo, $payloadPost);
        return;
    }

    if ($acao === 'buscarProdutosPorTexto') {
        executar_acao_buscar_produtos_por_texto($pdo, $payloadPost);
        return;
    }

    executar_acao_consultar_produtos_configurador($pdo, $payloadPost);
} catch (PDOException $e) {
    $codigoErro = trim((string) $e->getCode());
    $mensagemTecnica = trim((string) $e->getMessage());
    $detalhes = [
        [
            'tipo' => 'database_query_failure',
            'acao' => $acao,
            'sqlState' => $codigoErro,
            'retryable' => true,
        ],
    ];

    if ($mensagemTecnica !== '') {
        $detalhes[] = [
            'tipo' => 'database_exception_message',
            'mensagem' => $mensagemTecnica,
        ];
    }

    responder_busca_produto_unificada(200, [
        'ok' => false,
        'erro' => 'Falha ao consultar banco de dados.',
        'mensagemUsuario' => 'Falha ao consultar banco de dados.',
        'mensagemTecnica' => $mensagemTecnica !== ''
            ? $mensagemTecnica
            : 'A consulta oficial para retorno estendido de produto não pôde ser concluída neste momento.',
        'detalhes' => $detalhes,
        'codigoErro' => $codigoErro !== '' ? $codigoErro : 'FALHA_CONSULTA_BANCO',
        'retryable' => true,
        'contexto' => [
            'acaoSolicitada' => $acao,
            'observacao' => 'Outras rotas podem permanecer operacionais durante falha pontual de consulta oficial.',
        ],
        'telemetria' => [
            'sqlState' => $codigoErro,
        ],
    ]);
}
