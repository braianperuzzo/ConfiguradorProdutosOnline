<?php
header('Content-Type: text/plain; charset=UTF-8');
$baseDir = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
if ($baseDir === '') {
    $baseDir = dirname(__DIR__);
}
require $baseDir . '/Seguranca/MetodosSegurancao.php';
require $baseDir . '/AcessosConsultas/CredenciaisBancoDados.php';
require_once $baseDir . '/ProcessamentoFilaJobs/CriaFilaProcessamento.php';
registrar_verificacao_periodica_fila_jobs($baseDir);

$pdo = new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
]);

$moln = filter_input(INPUT_POST, 'MOLN', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '';
$motp = filter_input(INPUT_POST, 'MOTP', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '';
$mott = filter_input(INPUT_POST, 'MOTT', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '';
$mofq = filter_input(INPUT_POST, 'MOFQ', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '';
$mopt = filter_input(INPUT_POST, 'MOPT', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '';
$mopl = filter_input(INPUT_POST, 'MOPL', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '';

$sql = "SELECT MOCM
FROM _USR_CONF_MOBR
WHERE MOLN = ?
AND MOTP = ?
AND MOTT = ?
AND MOFQ = ?
AND MOPT = ?
AND MOPL = ?";

$query = $pdo->prepare($sql);

$query->execute([$moln, $motp, $mott, $mofq, $mopt, $mopl]);

if ($row = $query->fetch(PDO::FETCH_ASSOC)) {
    echo htmlspecialchars($row["MOCM"]);
}

$pdo = null;
?>