<?php
header('Content-Type: application/json; charset=UTF-8');
$baseDir = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
if ($baseDir === '') {
    $baseDir = dirname(__DIR__);
}
require $baseDir . '/Seguranca/MetodosSegurancao.php';
require $baseDir . '/AcessosConsultas/CredenciaisBancoDados.php';

$cdProduto = trim($_GET['cd_produto'] ?? '');

if ($cdProduto === '') {
    http_response_code(400);
    echo json_encode(['erro' => 'Informe cd_produto.']);
    exit;
}

try {
    $pdo = new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    log_event('Erro ao conectar no banco.', [
        'erro' => $e->getMessage(),
        'origem' => 'BuscarLinkProdutoRecomendado',
    ]);
    echo json_encode(['erro' => 'Erro ao conectar no banco.']);
    exit;
}

try {
    $stmt = $pdo->prepare('SELECT DISTINCT CD_PRODCONFIG, DS_ATRIBUTO2, DS_PRODUTO, DS_REFERENCIA, ID_STATUS FROM MMPR_PRODUTO WHERE CD_PRODUTO = ? AND CD_PRODCONFIG IS NOT NULL AND CD_PRODCONFIG <> \'IA\' AND DS_REFERENCIA NOT LIKE \'%2.WS%\' AND DS_REFERENCIA NOT LIKE \'MS.%\'');
    $stmt->execute([$cdProduto]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $cdProdconfig = trim((string) ($row['CD_PRODCONFIG'] ?? ''));
    $link = trim((string) ($row['DS_ATRIBUTO2'] ?? ''));
    $descricao = trim((string) ($row['DS_PRODUTO'] ?? ''));
    $referencia = trim((string) ($row['DS_REFERENCIA'] ?? ''));
    $status = isset($row['ID_STATUS']) ? (int) $row['ID_STATUS'] : null;
    echo json_encode([
        'cd_produto' => $cdProduto,
        'cd_prodconfig' => $cdProdconfig,
        'link' => $link,
        'descricao' => $descricao,
        'referencia' => $referencia,
        'id_status' => $status
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    log_event('Erro ao buscar DS_ATRIBUTO2.', [
        'erro' => $e->getMessage(),
        'cd_produto' => $cdProduto,
    ]);
    echo json_encode(['erro' => 'Erro ao buscar link do produto.']);
}

$pdo = null;
gc_collect_cycles();
?>
