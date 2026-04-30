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

$sql = "SELECT 
            CASE 
                WHEN QUAS = 'N' THEN 'NÃO'
                WHEN QUAS = 'ES' THEN 'EIXO DE SAÍDA SIMPLES'
                WHEN QUAS = 'ED' THEN 'EIXO DE SAÍDA DUPLO'
                WHEN QUAS = 'ES30' THEN 'EIXO DE SAÍDA SIMPLES - Ø30MM'
                ELSE QUAS
            END AS DESCRICAO,
            QUAS
        FROM ( 
            SELECT DISTINCT QUAS
            FROM _USR_CONF_QUAS
            WHERE QULN = ?
              AND QUBR = ?
              AND ((QUVZ IS NULL OR QUVZ = '') OR QUVZ = ?)
            UNION ALL
            SELECT 'N' AS QUAS
        ) AS Subquery
        GROUP BY QUAS
        ORDER BY DESCRICAO";

$stmt = $pdo->prepare($sql);
$stmt->execute([$quln, $qubr, $quvz]);

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $valor = htmlspecialchars((string) ($row["QUAS"] ?? ''), ENT_QUOTES, 'UTF-8');
    $descricao = htmlspecialchars((string) ($row["DESCRICAO"] ?? ''), ENT_QUOTES, 'UTF-8');

    echo '<option value="' . $valor . '">' . $descricao . '</option>';
}

$pdo = null;
?>