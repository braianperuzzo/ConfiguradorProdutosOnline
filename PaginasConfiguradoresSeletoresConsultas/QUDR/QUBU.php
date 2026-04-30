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

$quln = isset($_GET['QULN']) ? $_GET['QULN'] : '';
$qubr = isset($_GET['QUBR']) ? $_GET['QUBR'] : '';
$quvz = isset($_GET['QUVZ']) ? $_GET['QUVZ'] : '';
$qurd = isset($_GET['QURD']) ? $_GET['QURD'] : '';
$qurd1 = isset($_GET['QURD1']) ? $_GET['QURD1'] : '';
$qucm = isset($_GET['QUCM']) ? $_GET['QUCM'] : '';
$qucp = isset($_GET['QUCP']) ? $_GET['QUCP'] : '';

$sql = "SELECT 
    CASE
        WHEN QUBU = 'N' THEN 'NÃO'
        WHEN QUBU = 'B1' THEN 'BUCHA SIMPLES'
        WHEN QUBU = 'B2' THEN 'BUCHA DUPLA'
        WHEN QUBU = 'B3' THEN 'BUCHA SIMPLES'
        WHEN QUBU = 'B4' THEN 'BUCHA DUPLA'
        WHEN ? LIKE '%EE%' THEN NULL
        WHEN ? LIKE '%EE%' THEN NULL
        ELSE QUBU
    END AS DESCRICAO,
    QUBU
FROM ( 
    -- Consulta para QULN igual a '1.QDR'
    SELECT DISTINCT QUBU
    FROM _USR_CONF_QUBR
    WHERE 
        (? = '1.QDR') AND
        QULN = '1.Q' AND 
        QUBR IN (
            SELECT DISTINCT QUBR1
            FROM _USR_CONF_QUDRBR
            WHERE QULN = ?
              AND QUBR = ?
              AND ((QUVZ IS NULL OR QUVZ = '') OR QUVZ = ?)
              AND QURD = ?
              AND QUBU NOT IN ('B1', 'B2')
        )
        AND ((QUVZ IS NULL OR QUVZ = '') OR QUVZ = ?)
        AND QURD = ?
        AND QUCM = ?
        AND ? NOT LIKE '%EE%'

    UNION

    -- Consulta para QULN igual a '1.QP'
    SELECT DISTINCT HYBU
    FROM _USR_CONF_HYKE
    WHERE 
        (? = '1.QP') AND
        HYLN = '1.M' AND
        HYBR IN (
            SELECT DISTINCT QUBR1
            FROM _USR_CONF_QUDRBR
            WHERE QULN = ?
              AND QUBR = ?
              AND ((QUVZ IS NULL OR QUVZ = '') OR QUVZ = ?)
        )
        AND HYBU NOT IN ('B1', 'B2')
        AND HYCM = ?
        AND ? NOT LIKE '%EE%'
) AS Subquery;";

$query = $pdo->prepare($sql);
$query->execute([
    $qucm, $qucp,
    $quln, $quln, $qubr, $quvz, $qurd, $quvz, $qurd1, $qucm, $qucp,
    $quln, $quln, $qubr, $quvz, $qucm, $qucm
]);

echo '<option value="" disabled hidden selected></option>';
$temProduto = false;

while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
    $valor = htmlspecialchars((string) ($row["QUBU"] ?? ''), ENT_QUOTES, 'UTF-8');
    $descricao = htmlspecialchars((string) ($row["DESCRICAO"] ?? ''), ENT_QUOTES, 'UTF-8');
    if ($valor !== '' && $descricao !== '') {
        echo '<option value="' . $valor . '">' . $descricao . '</option>';
        $temProduto = true;
    }
}

$pdo = null;
?>
