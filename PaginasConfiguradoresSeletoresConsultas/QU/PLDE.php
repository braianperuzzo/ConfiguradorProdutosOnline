<?php 
header('Content-Type: text/html; charset=UTF-8');
$baseDir = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
if ($baseDir === '') {
    $baseDir = dirname(__DIR__, 2);
}
require $baseDir . '/Seguranca/MetodosSegurancao.php';
require $baseDir . '/AcessosConsultas/CredenciaisBancoDados.php';


$pdo = new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
]);

$quln = isset($_GET['QULN']) ? $_GET['QULN'] : '';
$qubr = isset($_GET['QUBR']) ? $_GET['QUBR'] : '';
$qucm = isset($_GET['QUCM']) ? $_GET['QUCM'] : '';

$sql = "SELECT DISTINCT PLBU
FROM (
    SELECT B.PLBU
    FROM _USR_CONF_PLAS A
    JOIN _USR_CONF_PLBU B ON A.PLDE = B.PLDE
    WHERE A.PLLN = ? AND A.PLBR = ? AND A.PLCM = ?

    UNION

    SELECT PLDE AS PLBU
    FROM _USR_CONF_PLAS
    WHERE PLLN = ? AND PLBR = ? AND PLCM = ?
) AS TEMPORARIA";

$query = $pdo->prepare($sql);
$query->execute([$quln, $qubr, $qucm, $quln, $qubr, $qucm]);


$query = $pdo->prepare($sql);
$query->execute([$quln, $qubr, $qucm, $quln, $qubr, $qucm]);

echo '<option value="" selected hidden></option>';

$temProduto = false;
while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
    $valor = htmlspecialchars((string) ($row["PLBU"] ?? ''), ENT_QUOTES, 'UTF-8');
    $descricao = htmlspecialchars((string) ($row["PLBU"] ?? ''), ENT_QUOTES, 'UTF-8');

    echo '<option value="' . $valor . '">' . $descricao . '</option>';
    $temProduto = true;
}

$pdo = null;
?>