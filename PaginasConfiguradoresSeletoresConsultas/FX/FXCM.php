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

$fxln = isset($_GET['FXLN']) ? $_GET['FXLN'] : '';
$fxbr = isset($_GET['FXBR']) ? $_GET['FXBR'] : '';
$fxrd = isset($_GET['FXRD']) ? $_GET['FXRD'] : '';
$fxet = isset($_GET['FXET']) ? $_GET['FXET'] : '';

$sql = "SELECT 
    CASE
        WHEN FXCM LIKE '%EE%' THEN CONCAT(REPLACE(FXCM, 'EE', 'EIXO DE ENTRADA DE Ø'), 'MM')
        ELSE FXCM
    END AS DESCRIÇÃO,
    FXCM
FROM (
  SELECT FXCM,
  CASE 
           WHEN CHARINDEX('-', FXCM) > 0 THEN 
             CASE 
               WHEN ISNUMERIC(SUBSTRING(FXCM, 1, CHARINDEX('-', FXCM) - 1)) = 1 THEN CAST(SUBSTRING(FXCM, 1, CHARINDEX('-', FXCM) - 1) AS INTEGER)
               ELSE 999999
             END
           ELSE 
             CASE 
               WHEN ISNUMERIC(FXCM) = 1 THEN CAST(FXCM AS INTEGER)
               ELSE 999999
             END
         END AS SortValue
FROM _USR_CONF_FXBR
WHERE FXLN = ?
AND FXBR = ?
AND FXRD = ?
AND FXET = ?) AS Subquery
GROUP BY FXCM
ORDER BY MAX(SortValue);";

$query = $pdo->prepare($sql);
$query->execute([$fxln, $fxbr, $fxrd, $fxet]);

while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
    $valor = htmlspecialchars((string) ($row["FXCM"] ?? ''), ENT_QUOTES, 'UTF-8');
    $descricao = htmlspecialchars((string) ($row["DESCRIÇÃO"] ?? ''), ENT_QUOTES, 'UTF-8');

    echo '<option value="' . $valor . '">' . $descricao . '</option>';
}

$pdo = null;
?>