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

$sql = "SELECT QURD1
FROM (SELECT DISTINCT B.QURD1, 1 as ORDEM
FROM _USR_CONF_QUDRBR A
JOIN _USR_CONF_QUDRRD B ON A.QULN = B.QULN AND A.QURD = B.QURD
WHERE B.QULN = ?
AND B.QURD = ?
AND (

(? = '1.QDR' AND B.QURD1 IN (
    SELECT DISTINCT QURD
    FROM _USR_CONF_QUBR
    WHERE QULN = '1.Q'
    AND ((QUVZ IS NULL OR QUVZ = '') OR QUVZ = ?)
    AND QUBR IN (
        SELECT DISTINCT QUBR1
        FROM _USR_CONF_QUDRBR
        WHERE QULN = ?
        AND QUBR = ?
        AND ((QUVZ IS NULL OR QUVZ = '') OR QUVZ = ?))))

OR (? = '1.QP'
AND B.QURD1 IN (
    SELECT DISTINCT HYRD
    FROM _USR_CONF_HYBR
    WHERE HYLN = '1.M'
    AND HYBR IN (
        SELECT DISTINCT QUBR1
        FROM _USR_CONF_QUDRBR
        WHERE QULN = ?
        AND QUBR = ?
        AND ((QUVZ IS NULL OR QUVZ = '') OR QUVZ = ?))))
)  

AND B.QURD2 IN(
    SELECT DISTINCT QURD
    FROM _USR_CONF_QUBR
    WHERE QULN = '1.Q'
    AND ((QUVZ IS NULL OR QUVZ = '') OR QUVZ = ?)
    AND QUBR IN(
    SELECT DISTINCT QUBR2
    FROM _USR_CONF_QUDRBR
    WHERE QULN = ?
    AND QUBR = ?
    AND ((QUVZ IS NULL OR QUVZ = '') OR QUVZ = ?)))
) AS TEMPORARIA
  ORDER BY ORDEM, TRY_CAST(QURD1 AS DECIMAL(10, 2))";

$query = $pdo->prepare($sql);
$params = [
    $quln, $qurd, $quln, $quvz, $quln, $qubr, $quvz,
    $quln, $quln, $qubr, $quvz,
    $quvz, $quln, $qubr, $quvz
];
$query->execute($params);

$temProduto = false;

echo '<option value="" disabled hidden selected></option>';

while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
    $valor = htmlspecialchars((string) ($row["QURD1"] ?? ''), ENT_QUOTES, 'UTF-8');
    if ($valor !== '') {
        echo '<option value="' . $valor . '">' . $valor . '</option>';
        $temProduto = true;
    }
}

$pdo = null;
?>