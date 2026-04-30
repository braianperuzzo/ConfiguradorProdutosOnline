<?php
header('Content-Type: application/json; charset=UTF-8');
$baseDir = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
if ($baseDir === '') {
    $baseDir = dirname(__DIR__);
}
require $baseDir . '/Seguranca/MetodosSegurancao.php';
require $baseDir . '/AcessosConsultas/CredenciaisBancoDados.php';

$termo = trim($_GET['q'] ?? '');
$digitos = preg_replace('/[^0-9A-Za-z]/', '', $termo);
$ehNumero = $digitos !== '' && ctype_digit($termo);
$offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
$limite = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
if ($limite <= 0 || $limite > 100) $limite = 20;
$sort = ($_GET['sort'] ?? 'codigo') === 'nome' ? 'nome' : 'codigo';
$dir = strtolower($_GET['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
$pdo = new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
]);
try {
    $colunaOrdenacao = $sort === 'nome' ? 'NM_PESSOA' : 'TRY_CAST(CD_PESSOA AS INT)';

    // consulta base
    $sql = 'SELECT CD_PESSOA, NM_PESSOA, NR_CPFCNPJ FROM MBAD_PESSOA';
    $params = [];

    // filtros de busca
    if ($termo !== '') {
        $condicoes = [];
        $condicoes[] = 'CD_PESSOA LIKE ?';
        $params[] = '%' . $termo . '%';
        $condicoes[] = 'NM_PESSOA LIKE ?';
        $params[] = '%' . $termo . '%';

        if ($digitos !== '') {
            $condicoes[] = "REPLACE(REPLACE(REPLACE(NR_CPFCNPJ, '.', ''), '-', ''), '/', '') LIKE ?";
            $params[] = '%' . $digitos . '%';
        }

        if ($ehNumero) {
            $condicoes[] = 'LTRIM(RTRIM(CD_PESSOA)) = ?';
            $params[] = $termo;
            $condicoes[] = 'TRY_CAST(CD_PESSOA AS INT) = TRY_CAST(? AS INT)';
            $params[] = $termo;
        }

        $sql .= ' WHERE ' . implode(' OR ', $condicoes);
    }

    // ordenação e paginação
    $prioridadeOrdenacao = '';
    if ($ehNumero) {
        $prioridadeOrdenacao = 'CASE WHEN LTRIM(RTRIM(CD_PESSOA)) = ? THEN 0 WHEN TRY_CAST(CD_PESSOA AS INT) = TRY_CAST(? AS INT) THEN 1 ELSE 2 END, ';
        $params[] = $termo;
        $params[] = $termo;
    }

    $sql .= " ORDER BY {$prioridadeOrdenacao}$colunaOrdenacao $dir OFFSET ? ROWS FETCH NEXT ? ROWS ONLY";
    $params[] = $offset;
    $params[] = $limite;

    $stmt = $pdo->prepare($sql);
    foreach ($params as $i => $param) {
        $type = is_int($param) ? PDO::PARAM_INT : PDO::PARAM_STR;
        $stmt->bindValue($i + 1, $param, $type);
    }
    $stmt->execute();
    $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['pessoas' => $dados]);
} catch (PDOException $e) {
    http_response_code(500);
    log_event('Erro ao buscar pessoas.', [
        'erro' => $e->getMessage(),
        'termo' => $termo,
        'offset' => $offset,
        'limite' => $limite,
    ]);
    echo json_encode(['erro' => 'Erro ao buscar pessoas']);
}
?>
