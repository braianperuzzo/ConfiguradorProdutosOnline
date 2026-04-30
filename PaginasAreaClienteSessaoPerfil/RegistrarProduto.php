<?php
header('Content-Type: application/json; charset=UTF-8');
$baseDir = $_SERVER['DOCUMENT_ROOT'] ?: dirname(__DIR__);
require_once $baseDir . '/Seguranca/CSRF.php';
require_valid_csrf_token();
require $baseDir . '/Seguranca/MetodosSegurancao.php';
require_once $baseDir . '/LogsErros/Logs.php';
require_once $baseDir . '/TokensGeradores/TokenPadraoJson.php';
require $baseDir . '/AcessosConsultas/CredenciaisBancoDados.php';

$token = $_COOKIE['auth_token'] ?? '';
if (!$token) {
    http_response_code(401);
    
    echo json_encode(['erro' => 'Usuário não autenticado.']);
    exit;
}

$segredo = getenv('JWT_SECRET');
if ($segredo === false) {
    $segredo = trim(file_get_contents($baseDir . '/Configuracoes/Segredo.jwt'));
}

$dados = JWTHelper::decode($token, $segredo);
if (!$dados) {
    http_response_code(403);
    
    echo json_encode(['erro' => 'Token inválido ou expirado.']);
    exit;
}

$documento = preg_replace('/[^0-9A-Za-z]/', '', (string)($dados['empresaDocumento'] ?? $_COOKIE['ibr_empresa_doc'] ?? ''));
if ($documento === '') {
    http_response_code(400);
    echo json_encode(['erro' => '⚠️ CNPJ ou CPF não encontrado.']);
    exit;
}

$produto = strtoupper(trim(filter_input(INPUT_POST, 'produto', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? ''));
$link = trim(filter_input(INPUT_POST, 'link', FILTER_SANITIZE_URL) ?? '');

if ($link && (!filter_var($link, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $link))) {
    echo json_encode(['erro' => 'Link invalido']);
    exit;
}

if (!$produto) {
    echo json_encode(['erro' => 'Codigo invalido']);
    exit;
}

try {
     $pdo = new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
    ]);

        $sqlUpdate = "UPDATE _USR_CONF_SITE_HISTORICO_PRODUTO
                     SET DS_LINK = ?
                   WHERE DS_EMAIL = ?
                     AND NR_CPFCNPJ = ?
                     AND DS_REFERENCIA = ?
                     AND (DS_LINK IS NULL OR CONVERT(VARCHAR(MAX), DS_LINK) = '')";
    $stmt = $pdo->prepare($sqlUpdate);
    $stmt->execute([
        $link,
        strtolower($dados['email']),
        $documento,
        $produto
    ]);

    $sql = "INSERT INTO _USR_CONF_SITE_HISTORICO_PRODUTO (DS_EMAIL, NR_CPFCNPJ, DS_REFERENCIA, DS_LINK, DT_DATA)
            SELECT ?, ?, ?, ?, CONVERT(VARCHAR(19), GETDATE(), 120)
             WHERE NOT EXISTS (
                 SELECT 1 FROM _USR_CONF_SITE_HISTORICO_PRODUTO
                  WHERE DS_EMAIL = ?
                    AND NR_CPFCNPJ = ?
                    AND DS_REFERENCIA = ?
                    AND CONVERT(VARCHAR(MAX), DS_LINK) = ?
                    AND DATEDIFF(MINUTE, DT_DATA, GETDATE()) = 0
             )";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        strtolower($dados['email']),
        $documento,
        $produto,
        $link,
        strtolower($dados['email']),
        $documento,
        $produto,
        $link
    ]);

    echo json_encode(['sucesso' => true]);
    $pdo = null;
} catch (PDOException $e) {
    log_event('RegistrarProduto: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['erro' => 'Erro ao registrar produto']);
}
?>