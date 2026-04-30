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

$aeel = isset($_GET['AEEL']) ? $_GET['AEEL'] : '';
$aebr = isset($_GET['AEBR']) ? $_GET['AEBR'] : '';
$aetp = isset($_GET['AETP']) ? $_GET['AETP'] : '';
$aeln = isset($_GET['AELN']) ? $_GET['AELN'] : '';
$aeee = isset($_GET['AEEE']) ? $_GET['AEEE'] : '';

$sql = "SELECT 
    CASE 
    WHEN AEEEP2 = 'SIM' THEN CONCAT(AEEE2, ' (PADRÃO)')
        ELSE AEEE2
    END AS DESCRIÇÃO,
    AEEE2
FROM (
    SELECT DISTINCT
        AEEE2,
        AEEEP2
        FROM _USR_CONF_AELN
    WHERE AELN = ?
    AND AEBR = ?
    AND AETP = ?
    AND AEEL = ?
    AND AEEE = ?
) AS Subquery;
";

$stmt = $pdo->prepare($sql);
$stmt->execute([$aeln, $aebr, $aetp, $aeel, $aeee]);

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $valor = htmlspecialchars((string) ($row["AEEE2"] ?? ''), ENT_QUOTES, 'UTF-8');
    $descricao = htmlspecialchars((string) ($row["DESCRIÇÃO"] ?? ''), ENT_QUOTES, 'UTF-8');

    echo '<option value="' . $valor . '">' . $descricao . '</option>';
}

$pdo = null;
?>