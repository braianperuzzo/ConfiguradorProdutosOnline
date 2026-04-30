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

$sql = "SELECT 
    CASE 
        WHEN PLLN = '3.PB' THEN 'REDUTOR PLANETÁRIO IBR PB'
        WHEN PLLN = '3.PBL' THEN 'REDUTOR PLANETÁRIO IBR PBL'
        WHEN PLLN = '3.SA' THEN 'REDUTOR PLANETÁRIO IBR SA'
        WHEN PLLN = '3.SB' THEN 'REDUTOR PLANETÁRIO IBR SB'
        WHEN PLLN = '3.SBL' THEN 'REDUTOR PLANETÁRIO IBR SBL'
        WHEN PLLN = '3.SD' THEN 'REDUTOR PLANETÁRIO IBR SD'
        ELSE PLLN
    END AS DESCRICAO,
    PLLN
FROM 
    (SELECT DISTINCT PLLN
    FROM _USR_CONF_PLBR) AS subquery
ORDER BY PLLN;";

$stmt = $pdo->prepare($sql);
$stmt->execute([$sql]);

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $valor = htmlspecialchars((string) ($row["PLLN"] ?? ''), ENT_QUOTES, 'UTF-8');
    $descricao = htmlspecialchars((string) ($row["DESCRICAO"] ?? ''), ENT_QUOTES, 'UTF-8');

    echo '<option value="' . $valor . '">' . $descricao . '</option>';
}

$pdo = null;
?>