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
    WHEN AELN = '3.GR' THEN 'ACOPLAMENTO ELÁSTICO IBR GR'
        WHEN AELN = '3.GS' THEN 'ACOPLAMENTO ELÁSTICO IBR GS'
        WHEN AELN = '3.RIC' THEN 'ACOPLAMENTO ELÁSTICO IBR RIC'
        ELSE AELN
    END AS DESCRICAO,
    AELN
FROM 
    (SELECT DISTINCT AELN
    FROM _USR_CONF_AELN) AS subquery
ORDER BY AELN";

$query = $pdo->prepare($sql);
$query->execute();

while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
    echo '<option value="' . htmlspecialchars((string) ($row["AELN"] ?? ''), ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string) ($row["DESCRICAO"] ?? ''), ENT_QUOTES, 'UTF-8') . '</option>';
}

$pdo = null;
?>