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

$hycm = isset($_GET['HYCM']) ? $_GET['HYCM'] : '';
$plbu = isset($_GET['PLBU']) ? $_GET['PLBU'] : '';
$plde = isset($_GET['PLDE']) ? $_GET['PLDE'] : '';

$sql = "SELECT PLGU
FROM (
SELECT DISTINCT PLGU, 1 as ORDEM
    FROM _USR_CONF_PLCM
    WHERE PLTM = (
        CASE
        WHEN (? = '63')
        OR (? = '71' AND ? = '19')
        OR (? = '71' AND ? = '19' AND ? = 'N')
        THEN '7MP62A'
        
        WHEN (? = '71' AND ? = '24')
        OR (? = '71' AND ? = '24' AND ? = 'N')
        OR (? = '80')
        OR (? = '90' AND ? = '24')
        OR (? = '90' AND ? = '24' AND ? = 'N')
        THEN '7MP90A'
        
        WHEN (? = '90' AND ? = '32')
        OR (? = '90' AND ? = '32' AND ? = 'N')
        OR (? = '100-112' AND ? = '32')
        OR (? = '100-112' AND ? = '32' AND ? = 'N')
        THEN '7MP120A'
        
        WHEN (? = '100-112' AND ? = '42')
        OR (? = '100-112' AND ? = '42' AND ? = 'N')
        THEN '7MP142A'
    END)
) AS TEMPORARIA
ORDER BY ORDEM, TRY_CAST(PLGU AS DECIMAL(10, 2));";

$stmt = $pdo->prepare($sql);
$stmt->execute([$hycm, $hycm, $plbu, $hycm, $plde, $plbu, $hycm, $plbu, $hycm, $plde, $plbu, $hycm, $hycm, $plbu, $hycm, $plde, $plbu, $hycm, $plbu, $hycm, $plde, $plbu, $hycm, $plbu, $hycm, $plde, $plbu, $hycm, $plbu, $hycm, $plde, $plbu]);

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $valor = htmlspecialchars((string) ($row["PLGU"] ?? ''), ENT_QUOTES, 'UTF-8');
    $descricao = htmlspecialchars((string) ($row["PLGU"] ?? ''), ENT_QUOTES, 'UTF-8');

    echo '<option value="' . $valor . '">' . $descricao . '</option>';
}

$pdo = null;
?>