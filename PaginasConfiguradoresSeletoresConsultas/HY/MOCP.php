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

$moln = isset($_GET['MOLN']) ? $_GET['MOLN'] : '';
$motp = isset($_GET['MOTP']) ? $_GET['MOTP'] : '';
$mott = isset($_GET['MOTT']) ? $_GET['MOTT'] : '';
$mofq = isset($_GET['MOFQ']) ? $_GET['MOFQ'] : '';
$mopt = isset($_GET['MOPT']) ? $_GET['MOPT'] : '';
$mopl = isset($_GET['MOPL']) ? $_GET['MOPL'] : '';
$hycm = isset($_GET['HYCM']) ? $_GET['HYCM'] : '';
$hycp = isset($_GET['HYCP']) ? $_GET['HYCP'] : '';


$sql = "SELECT B.MOCP
FROM _USR_CONF_MOBR A
JOIN _USR_CONF_MOCP B ON (A.MOLN = B.MOLN AND A.MOCM = B.MOCM)
WHERE A.MOLN = ?
AND A.MOTP = ?
AND A.MOTT = ?
AND A.MOFQ = ?
AND A.MOPT = ?
AND A.MOPL = ?
AND ((? = '100-112' AND A.MOCM IN ('100', '112'))
    OR A.MOCM = ?)
    AND B.MOCP LIKE REPLACE(REPLACE(?, 'B5', '5'), 'B14', '4')";

$hycpModified = '%' . $hycp . '%';

$query = $pdo->prepare($sql);
$query->execute([$moln, $motp, $mott, $mofq, $mopt, $mopl, $hycm, $hycm, $hycpModified]);

while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
    $valor = htmlspecialchars((string) ($row["MOCP"] ?? ''), ENT_QUOTES, 'UTF-8');
    
    echo '<option value="' . $valor . '">' . $valor . '</option>';
}

$pdo = null;
?>
