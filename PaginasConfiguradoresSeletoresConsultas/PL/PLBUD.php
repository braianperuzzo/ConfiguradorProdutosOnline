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

$plln = isset($_GET['PLLN']) ? $_GET['PLLN'] : '';
$plbr = isset($_GET['PLBR']) ? $_GET['PLBR'] : '';
$plet = isset($_GET['PLET']) ? $_GET['PLET'] : '';
$pltp = isset($_GET['PLTP']) ? $_GET['PLTP'] : '';

if ($pltp != 'A' && $pltp != 'T') {
    $pltp = 'PADRÃO';
}
$plrd = isset($_GET['PLRD']) ? $_GET['PLRD'] : '';
$plde = isset($_GET['PLDE']) ? $_GET['PLDE'] : '';
$plbu = isset($_GET['PLBU']) ? $_GET['PLBU'] : '';

$sql = "SELECT CASE 
            WHEN PLDE = 'N' THEN 'NÃO'
            ELSE PLDE
        END AS DESCRICAO,
        PLDE
    FROM (
        SELECT PLDE, 1 AS ORDEM
        FROM _USR_CONF_PLBU
        WHERE PLBU = ?
          AND PLDE IN (
            SELECT DISTINCT PLDE
            FROM _USR_CONF_PLBR
            WHERE PLLN = ?
              AND PLBR = ?
              AND PLET = ?
              AND PLTP = ?
              AND PLRD = ?
          )

        UNION

        SELECT DISTINCT 
            CASE 
                WHEN NOT EXISTS (
                    SELECT PLDE
                    FROM _USR_CONF_PLBU
                    WHERE PLBU = ?
                      AND PLDE IN (
                        SELECT DISTINCT PLDE
                        FROM _USR_CONF_PLBR
                        WHERE PLLN = ?
                          AND PLBR = ?
                          AND PLET = ?
                          AND PLTP = ?
                          AND PLRD = ?
                          AND PLDE = ?
                      )
                ) THEN 'N'
            END AS PLDE,
            1 AS ORDEM
    ) AS Subquery
    WHERE PLDE IS NOT NULL
    ORDER BY ORDEM, TRY_CAST(PLDE AS DECIMAL(10, 2))";

$params = [
    $plbu, $plln, $plbr, $plet, $pltp, $plrd,
    $plbu, $plln, $plbr, $plet, $pltp, $plrd, $plbu
];

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $valor = htmlspecialchars((string) ($row["PLDE"] ?? ''), ENT_QUOTES, 'UTF-8');
    $descricao = htmlspecialchars((string) ($row["DESCRICAO"] ?? ''), ENT_QUOTES, 'UTF-8');
    echo "<option value=\"{$valor}\">{$descricao}</option>";
}

$pdo = null;
?>
