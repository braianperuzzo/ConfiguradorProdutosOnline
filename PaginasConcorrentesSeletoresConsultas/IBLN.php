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

$marcaconcorrente = isset($_GET['COMA']) ? trim((string) $_GET['COMA']) : '';
$linhaconcorrente = isset($_GET['COLN']) ? trim((string) $_GET['COLN']) : '';
$paiibr = isset($_GET['IBCN']) ? trim((string) $_GET['IBCN']) : '';

$sql = "SELECT DISTINCT IBLN
        FROM _USR_CONF_SITE_CONCORRENTE_IBR
        WHERE COMA = ?
          AND COLN = ?";
$params = [
    $marcaconcorrente,
    $linhaconcorrente
];

if ($paiibr !== '') {
    $sql .= "
          AND IBCN = ?";
    $params[] = $paiibr;
}

$sql .= "
        GROUP BY IBLN
        ORDER BY IBLN";

$query = $pdo->prepare($sql);
$query->execute($params);

while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
    $valor = htmlspecialchars((string) ($row["IBLN"] ?? ''), ENT_QUOTES, 'UTF-8');
    echo '<option value="' . $valor . '">' . $valor . '</option>';
}

$pdo = null;
?>
