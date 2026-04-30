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

$sql = "SELECT DISTINCT
    CASE 
    WHEN HYLN = '1.C' THEN 'REDUTOR IBR C - COAXIAL'
    WHEN HYLN = '1.H' THEN 'REDUTOR IBR H - HELICOIDAL'
    WHEN HYLN = '1.M' THEN 'REDUTOR IBR M - MONOESTÁGIO'
    WHEN HYLN = '1.P' THEN 'REDUTOR IBR P - PARALELO'
    WHEN HYLN = '1.R' THEN 'REDUTOR IBR R - REDONDO'
    WHEN HYLN = '1.X' THEN 'REDUTOR IBR X - ORTOGONAL'
        ELSE HYLN
    END AS DESCRIÇÃO,
    HYLN
    FROM _USR_CONF_HYBR
ORDER BY HYLN;";

$query = $pdo->prepare($sql);
$query->execute();

while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
    echo '<option value="' . htmlspecialchars((string) ($row["HYLN"] ?? ''), ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string) ($row["DESCRIÇÃO"] ?? ''), ENT_QUOTES, 'UTF-8') . '</option>';
}

$pdo = null;
?>