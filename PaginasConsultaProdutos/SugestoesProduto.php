<?php
header('Content-Type: application/json; charset=UTF-8');
$baseDir = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
if ($baseDir === '') {
    $baseDir = dirname(__DIR__);
}
require $baseDir . '/AcessosConsultas/CredenciaisBancoDados.php';

$termo = trim((string) (filter_input(INPUT_GET, 'termo', FILTER_SANITIZE_FULL_SPECIAL_CHARS)
    ?? filter_input(INPUT_POST, 'termo', FILTER_SANITIZE_FULL_SPECIAL_CHARS)
    ?? ''));

if ($termo === '' || mb_strlen($termo, 'UTF-8') < 2) {
    echo json_encode(['resultados' => []]);
    exit;
}

function escape_like(string $valor): string {
    $valor = str_replace('\\', '\\\\', $valor);
    $valor = str_replace('%', '\\%', $valor);
    $valor = str_replace('_', '\\_', $valor);
    $valor = str_replace('[', '\\[', $valor);
    return $valor;
}

try {
    $pdo = new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8,
    ]);

    $tokens = preg_split('/\s+/', $termo, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $conditions = [];
    $params = [];

    foreach ($tokens as $token) {
        $param = '%' . escape_like($token) . '%';
        $conditions[] = "(CD_PRODUTO LIKE ? ESCAPE '\\' OR DS_PRODUTO LIKE ? ESCAPE '\\' OR DS_REFERENCIA LIKE ? ESCAPE '\\' OR DS_OBSNOTA LIKE ? ESCAPE '\\')";
        $params[] = $param;
        $params[] = $param;
        $params[] = $param;
        $params[] = $param;
    }

    $limite = 10;
    $sql = "SELECT DISTINCT TOP {$limite} CD_PRODUTO AS CODIGO, DS_PRODUTO AS DESCRICAO"
        . " FROM MMPR_PRODUTO"
        . " WHERE ID_STATUS = 0"
        . " AND CD_PRODCONFIG IS NOT NULL"
        . " AND CD_PRODCONFIG <> 'IA'"
        . " AND DS_REFERENCIA NOT LIKE '%2.WS%'"
        . " AND DS_REFERENCIA NOT LIKE 'MS.%'";

    if (!empty($conditions)) {
        $sql .= " AND " . implode(' AND ', $conditions);
    }

    $sql .= " ORDER BY CD_PRODUTO";

    $query = $pdo->prepare($sql);
    $query->execute($params);

    $resultados = $query->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $saida = array_map(static function ($row) {
        return [
            'codigo' => isset($row['CODIGO']) ? trim((string) $row['CODIGO']) : '',
            'descricao' => isset($row['DESCRICAO']) ? trim((string) $row['DESCRICAO']) : '',
        ];
    }, $resultados);

    echo json_encode(['resultados' => $saida]);
} catch (Throwable $e) {
    echo json_encode(['resultados' => [], 'erro' => 'Falha ao carregar sugestões.']);
}

$pdo = null;
?>