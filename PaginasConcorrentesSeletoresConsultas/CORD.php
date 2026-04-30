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

$marcaconcorrente   = isset($_GET['COMA']) ? trim((string) $_GET['COMA']) : '';
$linhaconcorrente   = isset($_GET['COLN']) ? trim((string) $_GET['COLN']) : '';
$tamanhoconcorrente = isset($_GET['COBR']) ? trim((string) $_GET['COBR']) : '';

$params = [$marcaconcorrente, $linhaconcorrente];

$whereExtra = '';
if ($tamanhoconcorrente !== '') {
    $whereExtra = ' AND COBR = ?';
    $params[] = $tamanhoconcorrente;
}

$sql = "
    SELECT DISTINCT
        X.CORD,
        X.CORD_NUM,
        CASE WHEN X.CORD_NUM IS NULL THEN 1 ELSE 0 END AS CORD_IS_NULL
    FROM (
        SELECT
            CORD,
            TRY_CONVERT(float, REPLACE(CORD, ',', '.')) AS CORD_NUM
        FROM _USR_CONF_SITE_CONCORRENTE_DADOS
        WHERE COMA = ?
          AND COLN = ?
          $whereExtra
    ) AS X
    ORDER BY
        CORD_IS_NULL,
        X.CORD_NUM,
        X.CORD
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $valor = htmlspecialchars((string)($row['CORD'] ?? ''), ENT_QUOTES, 'UTF-8');
    echo '<option value="' . $valor . '">' . $valor . '</option>';
}

$pdo = null;
?>