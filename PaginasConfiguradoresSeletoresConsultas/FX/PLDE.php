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
$fxcm = isset($_GET['FXCM']) ? $_GET['FXCM'] : '';

$sql = "SELECT PLBU
FROM (
    SELECT B.PLBU, 1 AS ORDEM
    FROM _USR_CONF_PLAS A
    JOIN _USR_CONF_PLBU B ON A.PLDE = B.PLDE
    WHERE A.PLLN = ?
      AND A.PLBR = ?
      AND A.PLCM = ?

    UNION ALL

    SELECT PLDE AS PLBU, 2 AS ORDEM
    FROM _USR_CONF_PLAS
    WHERE PLLN = ?
      AND PLBR = ?
      AND PLCM = ?
) AS TEMPORARIA
ORDER BY ORDEM, TRY_CAST(PLBU AS DECIMAL(10, 2));";

$query = $pdo->prepare($sql);
$query->execute([$fxln, $fxbr, $fxcm, $fxln, $fxbr, $fxcm]);

while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
    $valor = htmlspecialchars((string) ($row["PLBU"] ?? ''), ENT_QUOTES, 'UTF-8');
    echo '<option value="' . $valor . '">' . $valor . '</option>';
}

$pdo = null;
?>