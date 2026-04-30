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

$sql = "
SELECT PLGU
FROM (
    SELECT DISTINCT
        PLGU, 
        1 AS ORDEM,
        CASE 
            WHEN ISNUMERIC(LEFT(PLGU, PATINDEX('%[^0-9.]%', PLGU + 'a') - 1)) = 1
            THEN CAST(LEFT(PLGU, PATINDEX('%[^0-9.]%', PLGU + 'a') - 1) AS DECIMAL(10, 2))
            ELSE NULL 
        END AS NUMERIC_PART
    FROM _USR_CONF_PLCM
    WHERE PLTM = (
        SELECT PLTM
        FROM _USR_CONF_PLTM
        WHERE PLLN = ?
          AND PLBR = ?
          AND PLET = ?
          AND PLTP = ?
    )
) AS TEMPORARIA
ORDER BY ORDEM, NUMERIC_PART;
";

$params = [$plln, $plbr, $plet, $pltp];

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $valor = htmlspecialchars((string) ($row["PLGU"] ?? ''), ENT_QUOTES, 'UTF-8');
    echo "<option value=\"{$valor}\">{$valor}</option>";
}

$pdo = null;
?>
