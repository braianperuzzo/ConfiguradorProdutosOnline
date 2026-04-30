<?php
header('Content-Type: application/json; charset=UTF-8');
$baseDir = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
if ($baseDir === '') {
    $baseDir = dirname(__DIR__);
}
require_once $baseDir . '/Seguranca/CSRF.php';
require_valid_csrf_token();
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

    $codigo = trim(filter_input(INPUT_POST, 'codigo', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '');
    $referencia = trim(filter_input(INPUT_POST, 'referencia', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '');
    $codigo = strtoupper($codigo);
    $referencia = strtoupper($referencia);

    if (!$codigo && !$referencia) {
        echo json_encode([]);
        exit;
    }

    $cdProduto = $codigo;
    if (!$cdProduto && $referencia) {
        $sqlRef = "SELECT TOP 1 CD_PRODUTO
            FROM MMPR_PRODUTO
            WHERE DS_REFERENCIA = ?
              AND ID_STATUS = 0
              AND CD_PRODCONFIG IS NOT NULL";
        $queryRef = $pdo->prepare($sqlRef);
        $queryRef->execute([$referencia]);
        $rowRef = $queryRef->fetch(PDO::FETCH_ASSOC);
        $cdProduto = $rowRef['CD_PRODUTO'] ?? '';
    }

    if (!$cdProduto) {
        log_event('CompararProdutosDatasheet: referência não encontrada.', [
            'referencia' => $referencia ?: $codigo,
        ]);
        echo json_encode([]);
        exit;
    }

    $sqlObs = "SELECT DISTINCT DS_OBSNOTA FROM MMPR_PRODUTO WHERE CD_PRODUTO = ?";
    $queryObs = $pdo->prepare($sqlObs);
    $queryObs->execute([$cdProduto]);
    $obsRow = $queryObs->fetch(PDO::FETCH_ASSOC);

    $datasheet = '';
    if ($obsRow && array_key_exists('DS_OBSNOTA', $obsRow)) {
        $datasheet = $obsRow['DS_OBSNOTA'];
    }

    $temDatasheet = isset($datasheet) && trim((string) $datasheet) !== '';
  
    echo json_encode([
        'CD_PRODUTO' => $cdProduto,
        'DS_OBSNOTA' => $datasheet
    ]);

    $queryObs = null;
    $queryRef = null;
} catch (PDOException $e) {
    echo json_encode(['erro' => $e->getMessage()]);
}

$pdo = null;
gc_collect_cycles();
?>
