<?php
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
$baseDir = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
if ($baseDir === '') {
    $baseDir = dirname(__DIR__);
}
require $baseDir . '/Seguranca/MetodosSegurancao.php';
require $baseDir . '/AcessosConsultas/CredenciaisBancoDados.php';
require_once $baseDir . '/ProcessamentoFilaJobs/CriaFilaProcessamento.php';
registrar_verificacao_periodica_fila_jobs($baseDir);

try {
    $pdo = new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
    ]);

    $codigoProduto = isset($_REQUEST['codigo']) ? $_REQUEST['codigo'] : '';
    if (!$codigoProduto) {
        echo json_encode([]);
        exit;
    }
                
    $sql =  "SELECT 
        ESTRUTURA.CD_EMPRESA,
        ALMOX.CD_ALMOXARIFADO,
        ESTRUTURA.CD_COMPONENTE AS CD_PRODUTO,
        ISNULL(
            MIN(ESTOQUECOMPONENTE.NR_QTTOTAL - ISNULL(ESTOQUECOMPONENTE.NR_QTBLOQUEADA, 0)), 
            0) AS ESTOQUEDISPONIVEL
    FROM 
    (
        SELECT DISTINCT CD_EMPRESA 
        FROM MMES_ESTOQUE
    ) AS CODIGOEMPRESA
    
    CROSS APPLY fn_explodeestoque(CODIGOEMPRESA.CD_EMPRESA, ?) AS ESTRUTURA
    CROSS JOIN (SELECT '1'  AS CD_ALMOXARIFADO UNION ALL SELECT '16' AS CD_ALMOXARIFADO) AS ALMOX
    
    LEFT JOIN MMES_ESTOQUE AS ESTOQUECOMPONENTE ON ESTOQUECOMPONENTE.CD_EMPRESA = ESTRUTURA.CD_EMPRESA AND ESTOQUECOMPONENTE.CD_PRODUTO = ESTRUTURA.CD_COMPONENTE AND ESTOQUECOMPONENTE.CD_ALMOXARIFADO = ALMOX.CD_ALMOXARIFADO
    LEFT JOIN MMPR_PRODUTO AS PRODUTO ON PRODUTO.CD_EMPRESA = ESTRUTURA.CD_EMPRESA AND PRODUTO.CD_PRODUTO = ESTRUTURA.CD_COMPONENTE
    
    WHERE 
        PRODUTO.ID_CONTRESTOQUE = 0
            AND EXISTS (
        SELECT 1
        FROM MMPR_PRODUTOESTRUTURA AS PRODUTOESTRUTURA
        WHERE PRODUTOESTRUTURA.CD_EMPRESA = ESTRUTURA.CD_EMPRESA
          AND PRODUTOESTRUTURA.CD_PRODUTO = ?
          AND PRODUTOESTRUTURA.CD_COMPONENTE = ESTRUTURA.CD_COMPONENTE
    )
    GROUP BY 
        ESTRUTURA.CD_EMPRESA,
        ALMOX.CD_ALMOXARIFADO,
        ESTRUTURA.CD_COMPONENTE";  

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$codigoProduto, $codigoProduto]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($rows ?: []);

    $stmt = null;

} catch (PDOException $e) {
    echo json_encode(['erro' => $e->getMessage()]);
}

$pdo = null;
gc_collect_cycles();
?>
