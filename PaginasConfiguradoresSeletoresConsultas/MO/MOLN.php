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

$sql = "SELECT CASE 
        WHEN MOLN = '2.I' THEN 'MOTOR IBR MS/ML'
        WHEN MOLN = '3.I' THEN 'MOTOR IBR T3A/T3C'
        WHEN MOLN = '3.W' THEN 'MOTOR WEG ALTO RENDIMENTO'
        WHEN MOLN = '3.APM' THEN 'MOTOR IBR ANTICORROSIVO APM'
        WHEN MOLN = '3.SPM' THEN 'MOTOR IBR ANTICORROSIVO SPM'
        WHEN MOLN = '2.WS' THEN 'MOTOR WEG ESPECIAL'
        ELSE MOLN
        END AS DESCRIÇÃO,
    MOLN
FROM ( 
    SELECT DISTINCT MOLN
    FROM _USR_CONF_MOBR) AS Subquery;";

$query = $pdo->prepare($sql);
$query->execute();

while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
    echo '<option value="' . htmlspecialchars((string) ($row["MOLN"] ?? ''), ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string) ($row["DESCRIÇÃO"] ?? ''), ENT_QUOTES, 'UTF-8') . '</option>';
}

$pdo = null;
?>