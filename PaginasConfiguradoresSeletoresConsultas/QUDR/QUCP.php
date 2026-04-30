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

$quln   = isset($_GET['QULN'])   ? $_GET['QULN'] : '';
$qubr   = isset($_GET['QUBR'])   ? $_GET['QUBR'] : '';
$quvz   = isset($_GET['QUVZ'])   ? $_GET['QUVZ'] : '';
$qurd   = isset($_GET['QURD'])   ? $_GET['QURD'] : '';
$qurd1  = isset($_GET['QURD1'])  ? $_GET['QURD1'] : '';
$qucm   = isset($_GET['QUCM'])   ? $_GET['QUCM'] : '';

$sql = "SELECT 
    CASE
        WHEN QUCP LIKE '%EE%' THEN 
            CASE
                WHEN QUCP LIKE '%TS%' THEN CONCAT(REPLACE(REPLACE(QUCP, 'EE', 'EIXO DE ENTRADA DE Ø'), 'TS', ''), 'MM - COM SOLDA')
                ELSE CONCAT(REPLACE(QUCP, 'EE', 'EIXO DE ENTRADA DE Ø'), 'MM')
            END
        ELSE QUCP
    END AS DESCRICAO,
    QUCP
FROM (
    -- Consulta para QULN igual a '1.QDR'
    SELECT QUCP, 
        CASE 
            WHEN CHARINDEX('-', QUCP) > 0 THEN 
                CASE 
                    WHEN ISNUMERIC(SUBSTRING(QUCP, 1, CHARINDEX('-', QUCP) - 1)) = 1 THEN CAST(SUBSTRING(QUCP, 1, CHARINDEX('-', QUCP) - 1) AS INTEGER)
                    ELSE 999999
                END
            ELSE 
                CASE 
                    WHEN ISNUMERIC(QUCP) = 1 THEN CAST(QUCP AS INTEGER)
                    ELSE 999999
                END
        END AS SortValue
    FROM _USR_CONF_QUCP AS A
    LEFT JOIN _USR_CONF_QUBR AS B ON A.QULN = B.QULN AND A.QUBR = B.QUBR AND A.QUCM = B.QUCM 
    WHERE (? = '1.QDR')
        AND A.QULN = '1.Q'
        AND A.QUCM = ?
        AND A.QUBR IN (
            SELECT DISTINCT QUBR1
            FROM _USR_CONF_QUDRBR
            WHERE QULN = ?
              AND QUBR = ?
              AND ((QUVZ IS NULL OR QUVZ = '') OR QUVZ = ?)
              AND QURD = ?
        )
        AND B.QURD = ?
        AND ((B.QUVZ IS NULL OR B.QUVZ = '') OR B.QUVZ = ?)
        AND (B.QUBU IS NULL OR B.QUBU = 'N' OR A.QUCP NOT LIKE '%EE%')

    UNION

    -- Consulta para QULN igual a '1.QP'
    SELECT HYCP,
        CASE 
            WHEN CHARINDEX('-', HYCP) > 0 THEN 
                CASE 
                    WHEN ISNUMERIC(SUBSTRING(HYCP, 1, CHARINDEX('-', HYCP) - 1)) = 1 THEN CAST(SUBSTRING(HYCP, 1, CHARINDEX('-', HYCP) - 1) AS INTEGER)
                    ELSE 999999
                END
            ELSE 
                CASE 
                    WHEN ISNUMERIC(HYCP) = 1 THEN CAST(HYCP AS INTEGER)
                    ELSE 999999
                END
        END AS SortValue
    FROM _USR_CONF_HYCP
    WHERE (? = '1.QP')
        AND HYLN = '1.M'
        AND HYCM = ?
        AND HYBR IN (
            SELECT DISTINCT QUBR1
            FROM _USR_CONF_QUDRBR
            WHERE QULN = ?
              AND QUBR = ?
              AND ((QUVZ IS NULL OR QUVZ = '') OR QUVZ = ?)
              AND QURD = ?
        )
) AS Subquery
GROUP BY QUCP
ORDER BY MAX(SortValue);";

$query = $pdo->prepare($sql);
$query->execute([
    $quln, $qucm, $quln, $qubr, $quvz, $qurd, $qurd1, $quvz,
    $quln, $qucm, $quln, $qubr, $quvz, $qurd
]);

echo '<option value="" disabled hidden selected></option>';
$temProduto = false;

while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
    $valor = htmlspecialchars((string) ($row["QUCP"] ?? ''), ENT_QUOTES, 'UTF-8');
    $descricao = htmlspecialchars((string) ($row["DESCRICAO"] ?? ''), ENT_QUOTES, 'UTF-8');
    if ($valor !== '' && $descricao !== '') {
        echo '<option value="' . $valor . '">' . $descricao . '</option>';
        $temProduto = true;
    }
}

$pdo = null;
?>
