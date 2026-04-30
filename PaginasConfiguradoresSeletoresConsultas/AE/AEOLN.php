<?php
header('Content-Type: text/plain; charset=UTF-8');
$baseDir = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
if ($baseDir === '') {
    $baseDir = dirname(__DIR__, 2);
}
require $baseDir . '/Seguranca/MetodosSegurancao.php';
require $baseDir . '/AcessosConsultas/CredenciaisBancoDados.php';


$pdo = new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
]);

$aeln = isset($_GET['AELN']) ? $_GET['AELN'] : '';
$aebr = isset($_GET['AEBR']) ? $_GET['AEBR'] : '';
$aetp = isset($_GET['AETP']) ? $_GET['AETP'] : '';
$aeel = isset($_GET['AEEL']) ? $_GET['AEEL'] : '';

$sql = "SELECT AEOD
        FROM _USR_CONF_AEOLN
        WHERE AELN = ? 
          AND AEBR = ? 
          AND AETP = ? 
          AND AEEL = ?";

$query = $pdo->prepare($sql);
$query->execute([$aeln, $aebr, $aetp, $aeel]);

if ($row = $query->fetch(PDO::FETCH_ASSOC)) {
    echo $row["AEOD"];
}

$pdo = null;
?>