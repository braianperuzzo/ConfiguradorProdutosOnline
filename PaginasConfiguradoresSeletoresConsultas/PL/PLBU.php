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

$sql = "
SELECT 
    CASE 
        WHEN PLBU = 'N' THEN 'NÃO'
        ELSE PLBU 
    END AS DESCRICAO,
    PLBU
FROM (
    -- Opções com bucha dupla
    SELECT PLBU, 1 AS ORDEM
    FROM _USR_CONF_PLBU
    WHERE PLBU IN (
        SELECT DISTINCT PLDE
        FROM _USR_CONF_PLBU
        WHERE PLBU = ?
    )
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

    -- Opções com bucha simples
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

    -- Opção N se válida
    SELECT DISTINCT 
        CASE 
            WHEN EXISTS (
                SELECT PLDE
                FROM _USR_CONF_PLBR
                WHERE PLLN = ?
                  AND PLBR = ?
                  AND PLET = ?
                  AND PLTP = ?
                  AND PLDE = ?
                  AND PLRD = ?
            )
            THEN 'N'
        END AS PLBU,
        1 AS ORDEM
) AS subquery
WHERE PLBU IS NOT NULL
ORDER BY ORDEM, 
    CASE 
        WHEN ISNUMERIC(PLBU) = 1 THEN CONVERT(decimal(10,2), PLBU)
        ELSE NULL
    END;
";

$params = [
    $plde, $plln, $plbr, $plet, $pltp, $plrd, $plde, $plln, $plbr, $plet, $pltp, $plrd, $plln, $plbr, $plet, $pltp, $plde, $plrd
];

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $valor = htmlspecialchars((string) ($row["PLBU"] ?? ''), ENT_QUOTES, 'UTF-8');
    $descricao = htmlspecialchars((string) ($row["DESCRICAO"] ?? ''), ENT_QUOTES, 'UTF-8');
    echo "<option value=\"{$valor}\">{$descricao}</option>";
}

$pdo = null;
?>
