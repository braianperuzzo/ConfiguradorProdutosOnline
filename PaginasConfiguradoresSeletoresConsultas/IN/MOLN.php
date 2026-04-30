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

$inln = isset($_GET['INLN']) ? $_GET['INLN'] : '';
$intp = isset($_GET['INTP']) ? $_GET['INTP'] : '';
$intt = isset($_GET['INTT']) ? $_GET['INTT'] : '';
$infe = isset($_GET['INFE']) ? $_GET['INFE'] : '';
$inco = isset($_GET['INCO']) ? $_GET['INCO'] : '';
$inopcs = isset($_GET['INOPCS']) ? $_GET['INOPCS'] : '';

$sql = "SELECT DISTINCT CASE 
        WHEN MOLN = '2.I' THEN 'MOTOR IBR STANDARD'
        WHEN MOLN = '3.I' THEN 'MOTOR IBR ALTO RENDIMENTO'
        WHEN MOLN = '3.W' THEN 'MOTOR WEG ALTO RENDIMENTO'
        WHEN MOLN = '3.APM' THEN 'MOTOR IBR ANTICORROSIVO APM'
        WHEN MOLN = '3.SPM' THEN 'MOTOR IBR ANTICORROSIVO SPM'
        ELSE MOLN
        END AS DESCRIÇÃO,
    MOLN
    FROM _USR_CONF_INOPCS
    WHERE INLN = ?
AND INTP = ?
AND INTT = ?
AND INFE = ?
AND INCO = ?
AND INOPCS = ?";

$query = $pdo->prepare($sql);
$query->execute([$inln, $intp, $intt, $infe, $inco, $inopcs]);

while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
    $valor = htmlspecialchars((string) ($row["MOLN"] ?? ''), ENT_QUOTES, 'UTF-8');
    $descricao = htmlspecialchars((string) ($row["DESCRIÇÃO"] ?? ''), ENT_QUOTES, 'UTF-8');

    echo '<option value="' . $valor . '">' . $descricao . '</option>';
}

$pdo = null;
?>