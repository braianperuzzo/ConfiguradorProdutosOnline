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
$tamanhoconcorrente = isset($_GET['COBR']) ? trim((string) $_GET['COBR']) : '';
$reducaoconcorrente = isset($_GET['CORD']) ? trim((string) $_GET['CORD']) : '';
$vazadoconcorrente = isset($_GET['COVZ']) ? trim((string) $_GET['COVZ']) : '';

$sql = "SELECT DISTINCT COTR
        FROM _USR_CONF_SITE_CONCORRENTE_DADOS
        WHERE COMA = ?
          AND COLN = ?
          AND COBR = ?
          AND CORD = ?";
$params = [
    $marcaconcorrente,
    $linhaconcorrente,
    $tamanhoconcorrente,
    $reducaoconcorrente
];

if ($vazadoconcorrente !== '') {
    $sql .= "
          AND COVZ = ?";
    $params[] = $vazadoconcorrente;
}

$sql .= "
        GROUP BY COTR
        ORDER BY COTR";

$query = $pdo->prepare($sql);
$query->execute($params);

while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
    $valor = htmlspecialchars((string) ($row["COTR"] ?? ''), ENT_QUOTES, 'UTF-8');
    echo '<option value="' . $valor . '">' . $valor . '</option>';
}

$pdo = null;
?>
