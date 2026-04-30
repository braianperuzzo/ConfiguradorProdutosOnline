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
$qurd1 = isset($_GET['QURD1']) ? $_GET['QURD1'] : '';

$sql = "SELECT DISTINCT B.QURD2 AS QURD2
FROM _USR_CONF_QUDRBR A
JOIN _USR_CONF_QUDRRD B ON A.QULN = B.QULN AND A.QURD = B.QURD
WHERE B.QULN = ?
AND B.QURD = ?
AND B.QURD1 = ?
AND (B.QURD2 IN (SELECT DISTINCT QURD
        FROM _USR_CONF_QUBR
        WHERE QULN = '1.Q'
        AND QUBR IN (
            SELECT DISTINCT QUBR2
            FROM _USR_CONF_QUDRBR
            WHERE QULN = ?
            AND QUBR = ?
            AND ((QUVZ IS NULL OR QUVZ = '') OR QUVZ = ?)
            AND QURD = ?)))";

$query = $pdo->prepare($sql);
$query->execute([$quln, $qurd, $qurd1, $quln, $qubr, $quvz, $qurd]);

$temProduto = false;

while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
    $valor = htmlspecialchars((string) ($row["QURD2"] ?? ''), ENT_QUOTES, 'UTF-8');
    if ($valor !== '') {
        echo '<option value="' . $valor . '">' . $valor . '</option>';
        $temProduto = true;
    }
}

$pdo = null;
?>