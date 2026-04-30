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
$hycp = isset($_GET['HYCP']) ? $_GET['HYCP'] : '';
$hyaf = isset($_GET['HYAF']) ? $_GET['HYAF'] : '';

$sql = "
SELECT 
    CASE
        WHEN HYAS = 'N' THEN 'NÃO'
        WHEN HYAS LIKE 'S%' THEN CONCAT('BASE DE FIXAÇÃO TIPO ', HYAS)
        WHEN HYAS = 'ED' THEN 'EIXO DE SAÍDA DUPLO'
        WHEN HYAS = 'ES' THEN 'EIXO DE SAÍDA SIMPLES'
        ELSE HYAS      
    END AS DESCRICAO,
    HYAS
FROM ( 
    SELECT DISTINCT HYAS
    FROM _USR_CONF_HYAS
    WHERE HYLN = ?
    AND HYBR = ?
    AND NOT (HYAS = 'S1' AND ? = '1.C' AND ? = '202A' AND ? = '71' AND ? = 'B5')
    AND NOT (HYAS = 'S1' AND ? = '1.C' AND ? = '302A' AND ? IN ('F120', 'F160', 'F200'))
    AND (
        NOT (HYAS = 'S4' AND ? = '1.C' AND ? IN ('452A', '453A') AND ? <> 'B14')
        OR
        (HYAS = 'S4' AND ? = '1.C' AND ? IN ('452A', '453A') AND ? IN ('71', '90') AND ? = 'B5')
        OR 
        (HYAS = 'S4' AND ? = '1.C' AND ? IN ('452A', '453A') AND ? LIKE '%EE%')
    )

    UNION

    SELECT 'N' AS HYAS
    WHERE NOT ? IN ('862C', '863C', '1002', '1003', '1102', '1103')
) Subquery";

$query = $pdo->prepare($sql);
$query->execute([
    $hyln, $hybr,
    $hyln, $hybr, $hycm, $hycp,
    $hyln, $hybr, $hyaf,
    $hyln, $hybr, $hycp,
    $hyln, $hybr, $hycm, $hycp,
    $hyln, $hybr, $hycm,
    $hybr
]);

while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
    $valor = htmlspecialchars((string) ($row["HYAS"] ?? ''), ENT_QUOTES, 'UTF-8');
    $descricao = htmlspecialchars((string) ($row["DESCRICAO"] ?? ''), ENT_QUOTES, 'UTF-8');
    echo '<option value="' . $valor . '">' . $descricao . '</option>';
}

$pdo = null;
?>
