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
$qurd = isset($_GET['QURD']) ? $_GET['QURD'] : '';

$sql = "
SELECT QUCM
FROM (
    SELECT QUCM,
           CASE 
             WHEN CHARINDEX('-', QUCM) > 0 THEN 
               CASE 
                 WHEN ISNUMERIC(SUBSTRING(QUCM, 1, CHARINDEX('-', QUCM) - 1)) = 1 
                   THEN CAST(SUBSTRING(QUCM, 1, CHARINDEX('-', QUCM) - 1) AS INTEGER)
                 ELSE 999999
               END
             ELSE 
               CASE 
                 WHEN ISNUMERIC(QUCM) = 1 
                   THEN CAST(QUCM AS INTEGER)
                 ELSE 999999
               END
           END AS SortValue
    FROM _USR_CONF_QUBR
    WHERE QULN = ?
      AND QUBR = ?
      AND ((QUVZ IS NULL OR QUVZ = '') OR QUVZ = ?)
      AND QURD = ?
) AS Subquery
GROUP BY QUCM
ORDER BY MAX(SortValue)";

$query = $pdo->prepare($sql);
$query->execute([$quln, $qubr, $quvz, $qurd]);

$temProduto = false;

echo '<option value="" disabled hidden selected></option>';

while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
    $valor = htmlspecialchars((string) ($row["QUCM"] ?? ''), ENT_QUOTES, 'UTF-8');

    if ($valor !== '') {
        echo '<option value="' . $valor . '">' . $valor . '</option>';
        $temProduto = true;
    }
}

if (!$temProduto) {
    echo '<option value="">Nenhuma Carcaça Encontrada</option>';
}

$pdo = null;
?>