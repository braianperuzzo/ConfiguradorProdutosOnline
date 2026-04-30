<?php
header('Content-Type: text/html; charset=UTF-8');
$baseDir = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
if ($baseDir === '') {
    $baseDir = dirname(__DIR__, 2);
}
require $baseDir . '/Seguranca/MetodosSegurancao.php';
require $baseDir . '/AcessosConsultas/CredenciaisBancoDados.php';
require_once $baseDir . '/LogsErros/Logs.php';

try {
    $pdo = new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
    ]);
} catch (PDOException $e) {
    if (function_exists('log_event')) {
        log_event('Consulta ao banco de dados falhou: ' . $e->getMessage());
    }
    http_response_code(500);
    echo '<option value="" disabled>Erro ao conectar ao banco de dados</option>';
    exit;
}



$pdo = new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
]);

$quln = isset($_GET['QULN']) ? $_GET['QULN'] : '';
$qubr = isset($_GET['QUBR']) ? $_GET['QUBR'] : '';
$quvz = isset($_GET['QUVZ']) ? $_GET['QUVZ'] : '';
$qucp = isset($_GET['QUCP']) ? $_GET['QUCP'] : '';
$qucm = isset($_GET['QUCM']) ? $_GET['QUCM'] : '';
$qurd = isset($_GET['QURD']) ? $_GET['QURD'] : '';

$qucpModified = '%' . $qucp . '%';

$sql = "SELECT 
    CASE
        WHEN QUBU = 'N' THEN 'NÃO'
        WHEN QUBU = 'B1' THEN 'BUCHA SIMPLES'
        WHEN QUBU = 'B2' THEN 'BUCHA DUPLA'
        WHEN ? LIKE '%EE%' THEN NULL
        ELSE QUBU
    END AS DESCRICAO,
    QUBU
FROM ( 
    SELECT DISTINCT QUBU
    FROM _USR_CONF_QUBR
    WHERE QULN = ?
        AND QUBR = ?
        AND ((QUVZ IS NULL OR QUVZ = '') OR QUVZ = ?)
        AND QURD = ?
        AND QUCM = ?
        AND (? NOT LIKE '%EE%')
        AND QUBU NOT IN ('B3', 'B4')) AS Subquery;";

$query = $pdo->prepare($sql);
$query->execute([$qucpModified, $quln, $qubr, $quvz, $qurd, $qucm, $qucpModified]);

$temProduto = false;
while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
    $valor = htmlspecialchars((string) ($row["QUBU"] ?? ''), ENT_QUOTES, 'UTF-8');
    $descricao = htmlspecialchars((string) ($row["DESCRICAO"] ?? ''), ENT_QUOTES, 'UTF-8');

    if ($valor !== '' && $descricao !== '') {
        echo '<option value="' . $valor . '">' . $descricao . '</option>';
        $temProduto = true;
    }
}

$pdo = null;
?>