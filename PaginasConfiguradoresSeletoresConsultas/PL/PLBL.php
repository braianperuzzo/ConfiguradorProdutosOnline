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

$plln = isset($_GET['PLLN']) ? $_GET['PLLN'] : '';
$plbr = isset($_GET['PLBR']) ? $_GET['PLBR'] : '';
$plet = isset($_GET['PLET']) ? $_GET['PLET'] : '';
$pltp = isset($_GET['PLTP']) ? $_GET['PLTP'] : '';

if ($pltp != 'A' && $pltp != 'T') {
    $pltp = 'PADRÃO';
}
$plrd = isset($_GET['PLRD']) ? $_GET['PLRD'] : '';
$plde = isset($_GET['PLDE']) ? $_GET['PLDE'] : '';
$plbu = isset($_GET['PLBU']) ? $_GET['PLBU'] : '';
$plbud = isset($_GET['PLBUD']) ? $_GET['PLBUD'] : '';

$sql = "SELECT PLBL
FROM _USR_CONF_PLBR
WHERE PLLN = ?
  AND PLBR = ?
  AND PLET = ?
  AND PLTP = ?
  AND PLRD = ?
  AND (
      (? = 'N' AND PLDE = ?)
      OR
      (? <> 'N' AND ? = 'N' AND PLDE = ?)
      OR
      (? <> 'N' AND ? <> 'N' AND PLDE = ?)
  )
ORDER BY
    CASE
        WHEN ISNUMERIC(PLBL) = 1 THEN CONVERT(decimal(10, 2), PLBL)
        ELSE NULL
    END;";

$params = [
    $plln, $plbr, $plet, $pltp, $plrd,
    $plbu, $plde,
    $plbu, $plbud, $plbu,
    $plbu, $plbud, $plbud
];

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $valor = htmlspecialchars((string) ($row["PLBL"] ?? ''), ENT_QUOTES, 'UTF-8');
    echo "<option value=\"{$valor}\">{$valor}</option>";
}

$pdo = null;
?>
