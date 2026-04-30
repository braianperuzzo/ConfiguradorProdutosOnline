<?php
header('Content-Type: application/json; charset=UTF-8');
$baseDir = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
if ($baseDir === '') {
    $baseDir = dirname(__DIR__);
}
require $baseDir . '/Seguranca/MetodosSegurancao.php';
require $baseDir . '/AcessosConsultas/CredenciaisBancoDados.php';

$cdPessoaRaw = trim($_GET['cd_pessoa'] ?? '');
$cdPessoa = preg_replace('/\D/', '', $cdPessoaRaw);
$meses = isset($_GET['meses']) ? (int) $_GET['meses'] : 12;
if ($meses <= 0 || $meses > 24) {
    $meses = 12;
}
$incluirAbertos = ($_GET['incluir_abertos'] ?? '0') === '1';

$dataInicio = (new DateTime())->modify("-{$meses} months")->format('Y-m-d');
$logDir = __DIR__ . '/Logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}
$logArquivo = $logDir . '/recomendacoes-pedidos-' . date('Y-m-d') . '.jsonl';

function registrarLogPedidos($arquivo, array $dados): void
{
    $payload = array_merge([
        'timestamp' => date('c')
    ], $dados);
    file_put_contents($arquivo, json_encode($payload, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND);
}

$pdo = new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
]);

$consultaBase = <<<'SQL'
SELECT
    PEDIDO.CD_EMPRESA,
    PEDIDO.CD_DOCUMENTO,
    PEDIDO.NR_COMPL,
    PEDIDO.DT_EMISSAO,
    PEDIDO.CD_PESSOA,
    PEDIDO.NM_PESSOA,
    PEDIDO.CD_NATUREZA,
    PEDIDO.CD_TIPO,
    PEDIDO.ID_STATUS,
    PEDIDO.CD_ETAPA,
    PEDIDO.DS_ATRIBUTO8 AS CD_VENDEDOR,
    VENDEDOR.NM_PESSOA AS NM_VENDEDOR,
    ITEM.CD_ITEM,
    ITEM.DS_ITEM,
    ITEM.CD_PRODUTO,
    ITEM.CD_OPERACAO,
    ITEM.VL_QUANTIDADE,
    PEDIDO.DT_PREVISAO,
    PRODUTO.DS_PRODUTO,
    PRODUTO.DS_REFERENCIA,
    PRODUTO.CD_PRODCONFIG,
    PRODUTO.CD_MARCA,
    PRODUTO.CD_FAMILIA,
    PRODUTO.CD_GRUPO,
    PRODUTO.CD_SUBGRUPO,
    PRODUTO.CD_CLASSFISCAL,
    PRODUTO.CD_CATEGORIA,
    PRODUTO.CD_MATERIAL,
    PRODUTO.CD_NCM,
    PRODUTO.VL_PESOLIQUIDO,
    PRODUTO.CD_FABRICANTE
FROM MVPE_PEDIDO AS PEDIDO
INNER JOIN MVPE_PEDIDOITEM AS ITEM
    ON PEDIDO.CD_EMPRESA = ITEM.CD_EMPRESA
    AND PEDIDO.NR_COMPL = ITEM.NR_COMPL
    AND PEDIDO.CD_DOCUMENTO = ITEM.CD_DOCUMENTO
INNER JOIN MMPR_PRODUTO AS PRODUTO
    ON PEDIDO.CD_EMPRESA = PRODUTO.CD_EMPRESA
    AND ITEM.CD_PRODUTO = PRODUTO.CD_PRODUTO
LEFT JOIN MBAD_PESSOA AS VENDEDOR
    ON VENDEDOR.CD_PESSOA = PEDIDO.DS_ATRIBUTO8
WHERE PEDIDO.CD_PESSOA = :cd_pessoa
  AND PEDIDO.DT_EMISSAO >= :data_inicio
  AND PEDIDO.CD_TIPO IN (1, 2, 8, 10, 11)
  AND PRODUTO.CD_PRODCONFIG <> 'IA'
      AND PRODUTO.DS_REFERENCIA NOT LIKE '%2.WS%'
      AND PRODUTO.DS_REFERENCIA NOT LIKE 'MS.%'
      AND PRODUTO.CD_PRODCONFIG IS NOT NULL
      AND PRODUTO.ID_STATUS = 0
SQL;

try {
    $efetivados = [];
    if ($cdPessoa !== '') {
        $sqlEfetivados = $consultaBase . "\n  AND PEDIDO.ID_STATUS IN (1, 2)\n  AND PEDIDO.CD_ETAPA IN (2, 7, 8, 9, 10)\nORDER BY PEDIDO.DT_EMISSAO DESC";
        $stmt = $pdo->prepare($sqlEfetivados);
        $stmt->bindValue(':cd_pessoa', $cdPessoa, PDO::PARAM_STR);
        $stmt->bindValue(':data_inicio', $dataInicio, PDO::PARAM_STR);
        $stmt->execute();
        $efetivados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        registrarLogPedidos($logArquivo, [
            'tipo' => 'efetivados',
            'sql' => $sqlEfetivados,
            'params' => [
                'cd_pessoa' => $cdPessoa,
                'data_inicio' => $dataInicio,
                'meses' => $meses
            ],
            'total' => count($efetivados),
            'amostra' => array_slice(array_map(static function ($pedido) {
                return [
                    'CD_DOCUMENTO' => $pedido['CD_DOCUMENTO'] ?? null,
                    'NR_COMPL' => $pedido['NR_COMPL'] ?? null,
                    'CD_PRODUTO' => $pedido['CD_PRODUTO'] ?? null,
                    'DS_PRODUTO' => $pedido['DS_PRODUTO'] ?? null,
                    'DT_EMISSAO' => $pedido['DT_EMISSAO'] ?? null
                ];
            }, $efetivados), 0, 5)
        ]);
    }

    $abertos = [];
    if ($incluirAbertos) {
        $sqlAbertos = $consultaBase . "\n  AND PEDIDO.ID_STATUS IN (0, 1, 5)\n  AND PEDIDO.CD_ETAPA IN (1, 16, 3, 4, 5, 11, 12, 13, 15, 17, 18, 19, 2, 8)\nORDER BY PEDIDO.DT_EMISSAO DESC";
        if ($cdPessoa !== '') {
            $stmtAbertos = $pdo->prepare($sqlAbertos);
            $stmtAbertos->bindValue(':cd_pessoa', $cdPessoa, PDO::PARAM_STR);
            $stmtAbertos->bindValue(':data_inicio', $dataInicio, PDO::PARAM_STR);
            $stmtAbertos->execute();
            $abertos = $stmtAbertos->fetchAll(PDO::FETCH_ASSOC);
            registrarLogPedidos($logArquivo, [
                'tipo' => 'abertos',
                'sql' => $sqlAbertos,
                'params' => [
                    'cd_pessoa' => $cdPessoa,
                    'data_inicio' => $dataInicio,
                    'meses' => $meses
                ],
                'total' => count($abertos),
                'amostra' => array_slice(array_map(static function ($pedido) {
                    return [
                        'CD_DOCUMENTO' => $pedido['CD_DOCUMENTO'] ?? null,
                        'NR_COMPL' => $pedido['NR_COMPL'] ?? null,
                        'CD_PRODUTO' => $pedido['CD_PRODUTO'] ?? null,
                        'DS_PRODUTO' => $pedido['DS_PRODUTO'] ?? null,
                        'DT_EMISSAO' => $pedido['DT_EMISSAO'] ?? null
                    ];
                }, $abertos), 0, 5)
            ]);
        }
    }

    $sugestoes = $efetivados;
    $sorteados = [];
    if (!$sugestoes || count($sugestoes) === 0) {
        $sqlSorteio = <<<'SQL'
WITH SorteioPorConfig AS (
    SELECT CD_PRODUTO AS CODIGO,
           DS_PRODUTO AS DESCRICAO,
           DS_REFERENCIA AS REFERENCIA,
           CD_PRODCONFIG,
           ROW_NUMBER() OVER (PARTITION BY CD_PRODCONFIG ORDER BY NEWID()) AS SORTEIAFAMILIACONFIGURADOR
    FROM MMPR_PRODUTO
    WHERE CD_PRODCONFIG IS NOT NULL
      AND CD_PRODCONFIG <> 'IA'
      AND DS_REFERENCIA NOT LIKE '%2.WS%'
      AND DS_REFERENCIA NOT LIKE 'MS.%'
      AND CD_PRODUTO LIKE '%.%'
      AND ID_STATUS = 0
)
SELECT TOP (5)
    CODIGO,
    DESCRICAO,
    REFERENCIA,
    CD_PRODCONFIG
FROM SorteioPorConfig
WHERE SORTEIAFAMILIACONFIGURADOR = 1
ORDER BY NEWID();
SQL;
        $stmtSorteio = $pdo->prepare($sqlSorteio);
        $stmtSorteio->execute();
        $sorteados = $stmtSorteio->fetchAll(PDO::FETCH_ASSOC);
        $sugestoes = array_map(static function ($item) {
            return [
                'CD_PRODUTO' => $item['CODIGO'] ?? null,
                'DS_PRODUTO' => $item['DESCRICAO'] ?? null,
                'DS_REFERENCIA' => $item['REFERENCIA'] ?? null,
                'CD_PRODCONFIG' => $item['CD_PRODCONFIG'] ?? null,
                'DT_PREVISAO' => null
            ];
        }, $sorteados);
        registrarLogPedidos($logArquivo, [
            'tipo' => 'sorteados',
            'sql' => $sqlSorteio,
            'total' => count($sorteados),
            'amostra' => array_slice(array_map(static function ($produto) {
                return [
                    'CD_PRODUTO' => $produto['CODIGO'] ?? null,
                    'DS_PRODUTO' => $produto['DESCRICAO'] ?? null
                ];
            }, $sorteados), 0, 5)
        ]);
    }

    echo json_encode([
        'cd_pessoa' => $cdPessoa,
        'data_inicio' => $dataInicio,
        'efetivados' => $efetivados,
        'sugestoes' => $sugestoes,
        'abertos' => $abertos
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    log_event('Erro ao buscar pedidos do Plenus.', [
        'erro' => $e->getMessage(),
        'cd_pessoa' => $cdPessoa,
        'data_inicio' => $dataInicio,
    ]);
    registrarLogPedidos($logArquivo, [
        'tipo' => 'erro',
        'mensagem' => $e->getMessage(),
        'cd_pessoa' => $cdPessoa,
        'data_inicio' => $dataInicio
    ]);
    echo json_encode(['erro' => 'Erro ao buscar pedidos do Plenus']);
}
?>
