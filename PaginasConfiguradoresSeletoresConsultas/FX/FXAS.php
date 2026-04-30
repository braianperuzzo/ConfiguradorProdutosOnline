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

$sql = "SELECT 
    CASE
        WHEN FXAS = 'N' THEN 'NÃO'
        WHEN FXAS LIKE 'S%' THEN CONCAT('BASE DE FIXAÇÃO TIPO ', FXAS)
        WHEN FXAS = 'ED' THEN 'EIXO DE SAÍDA DUPLO'
        WHEN FXAS = 'ES' THEN 'EIXO DE SAÍDA SIMPLES'
        ELSE FXAS      
    END AS DESCRIÇÃO,
    FXAS
FROM ( 
    SELECT DISTINCT FXAS
    FROM _USR_CONF_FXAS
    WHERE FXLN = ?
    AND FXBR = ?
    UNION
    SELECT 'SX' AS FXAS
    WHERE ? = '1.FR'
    UNION
    SELECT 'N' AS FXAS
    WHERE ? <> '1.FR'
) Subquery";

$query = $pdo->prepare($sql);
$query->execute([$fxln, $fxbr, $fxln, $fxln]);

while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
    $valor = htmlspecialchars((string) ($row["FXAS"] ?? ''), ENT_QUOTES, 'UTF-8');
    $descricao = htmlspecialchars((string) ($row["DESCRIÇÃO"] ?? ''), ENT_QUOTES, 'UTF-8');

    echo '<option value="' . $valor . '">' . $descricao . '</option>';
}

$pdo = null;
?>