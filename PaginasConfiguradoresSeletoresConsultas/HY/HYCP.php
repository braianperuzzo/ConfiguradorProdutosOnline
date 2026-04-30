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

$hyln = isset($_GET['HYLN']) ? $_GET['HYLN'] : '';
$hybr = isset($_GET['HYBR']) ? $_GET['HYBR'] : '';
$hycm = isset($_GET['HYCM']) ? $_GET['HYCM'] : '';
$hyas = isset($_GET['HYAS']) ? $_GET['HYAS'] : '';
$hyaf = isset($_GET['HYAF']) ? $_GET['HYAF'] : '';

$sql = "SELECT DISTINCT HYCP
FROM _USR_CONF_HYCP
WHERE HYLN = ?
AND HYBR = ?
AND HYCM = ?
AND NOT (HYCP = 'B5' AND ? = '1.C' AND ? = '202A' AND ? = '71' AND ? = 'S1')
AND NOT (HYCP = 'B5' AND ? = '1.C' AND ? = '302A' AND ? IN ('F120', 'F160', 'F200') AND ? = 'S1')
AND (
  NOT (? = 'S4' AND ? = '1.C' AND ? IN ('452A', '453A') AND HYCP <> 'B14')
  OR
  (? = 'S4' AND ? = '1.C' AND ? IN ('452A', '453A') AND ? IN ('71', '90') AND HYCP = 'B5')
  OR
  (? = 'S4' AND ? = '1.C' AND ? IN ('452A', '453A') AND ? = '%EE%')
)
ORDER BY HYCP";

$query = $pdo->prepare($sql);
$query->execute([
    $hyln, $hybr, $hycm,
    $hyln, $hybr, $hycm, $hyas,
    $hyln, $hybr, $hyaf, $hyas,
    $hyas, $hyln, $hybr,
    $hyas, $hyln, $hybr, $hycm,
    $hyas, $hyln, $hybr, $hycm
]);

while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
    $valor = htmlspecialchars((string) ($row["HYCP"] ?? ''), ENT_QUOTES, 'UTF-8');
    echo '<option value="' . $valor . '">' . $valor . '</option>';
}

$pdo = null;
?>