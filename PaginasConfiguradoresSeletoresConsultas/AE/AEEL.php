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

$aeln = isset($_GET['AELN']) ? $_GET['AELN'] : '';
$aebr = isset($_GET['AEBR']) ? $_GET['AEBR'] : '';
$aetp = isset($_GET['AETP']) ? $_GET['AETP'] : '';

$sql = "SELECT DISTINCT COALESCE(
        (SELECT CONCAT(AEOC, ' - ', AEOD)
        FROM _USR_CONF_AEOLN
         WHERE AELN = ?
           AND AEBR = ?
           AND AETP = ?
           AND AEEL = OBSERVACOES.AEEL),
        OBSERVACOES.AEEL) AS DESCRICAO,
    OBSERVACOES.AEEL
FROM 
    (SELECT DISTINCT AEEL
    FROM _USR_CONF_AELN
     WHERE AELN = ?
       AND AEBR = ?
       AND AETP = ?
    ) AS OBSERVACOES
ORDER BY OBSERVACOES.AEEL;
";

$stmt = $pdo->prepare($sql);
$stmt->execute([$aeln, $aebr, $aetp, $aeln, $aebr, $aetp]);

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $valor = htmlspecialchars((string) ($row["AEEL"] ?? ''), ENT_QUOTES, 'UTF-8');
    $descricao = htmlspecialchars((string) ($row["DESCRICAO"] ?? ''), ENT_QUOTES, 'UTF-8');

    echo '<option value="' . $valor . '">' . $descricao . '</option>';
}

$pdo = null;
?>