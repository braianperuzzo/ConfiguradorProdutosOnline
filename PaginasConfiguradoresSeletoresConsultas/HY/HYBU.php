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
$hyrd = isset($_GET['HYRD']) ? $_GET['HYRD'] : '';
$hycm = isset($_GET['HYCM']) ? $_GET['HYCM'] : '';

$sql = "SELECT 
    CASE
        WHEN HYBU = 'N' THEN 'NÃO'
        WHEN HYBU = 'B1' THEN 'BUCHA SIMPLES'
        WHEN HYBU = 'B2' THEN 'BUCHA DUPLA'
        WHEN ? LIKE '%EE%' THEN NULL
        WHEN ? = '1.R' THEN NULL
        ELSE HYBU
    END AS DESCRIÇÃO,
    HYBU
FROM ( 
    SELECT DISTINCT HYBU
    FROM _USR_CONF_HYKE
    WHERE HYLN = ?
      AND HYBR = ?
      AND HYCM = ?
      AND ? NOT LIKE '%EE%'
      AND HYBU NOT IN ('B3', 'B4')
) AS Subquery;";

$query = $pdo->prepare($sql);
$query->execute([$hycm, $hyln, $hyln, $hybr, $hycm, $hycm]);

$temProduto = false;
while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
    $valor = htmlspecialchars((string) ($row["HYBU"] ?? ''), ENT_QUOTES, 'UTF-8');
    $descricao = htmlspecialchars((string) ($row["DESCRIÇÃO"] ?? ''), ENT_QUOTES, 'UTF-8');

    if ($valor !== '' && $descricao !== '') {
        echo '<option value="' . $valor . '">' . $descricao . '</option>';
        $temProduto = true;
    }
}

$pdo = null;
?>