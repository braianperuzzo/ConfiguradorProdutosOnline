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
    echo json_encode(['erro' => '⚠️ Usuário não Autenticado.']);
    exit;
}

$segredo = getenv('JWT_SECRET');
if ($segredo === false) {
    $segredo = trim(file_get_contents($baseDir . '/Configuracoes/Segredo.jwt'));
}

$dados = JWTHelper::decode($token, $segredo);
if (!$dados) {
    http_response_code(403);
    echo json_encode(['erro' => '⚠️ Token Inválido ou Expirado.']);
    exit;
}

$documentoRequest = preg_replace('/[^0-9A-Za-z]/', '', (string) (filter_input(INPUT_POST, 'documento', FILTER_UNSAFE_RAW) ?? ''));
$documento = preg_replace(
    '/[^0-9A-Za-z]/',
    '',
    (string)($dados['empresaDocumento'] ?? $_COOKIE['ibr_empresa_doc'] ?? '')
);
if ($documento === '' && $documentoRequest !== '') {
    $documento = $documentoRequest;
}
if ($documentoRequest !== '' && $documento !== '' && $documentoRequest !== $documento) {
    http_response_code(403);
    echo json_encode(['erro' => '⚠️ Empresa selecionada inválida.']);
    exit;
}
if ($documento === '') {
    http_response_code(400);
    echo json_encode(['erro' => '⚠️ CNPJ ou CPF não encontrado.']);
    exit;
}

$produto = strtoupper(trim(filter_input(INPUT_POST, 'produto', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? ''));
$link = trim(filter_input(INPUT_POST, 'link', FILTER_SANITIZE_URL) ?? '');
$comentarioRaw = filter_has_var(INPUT_POST, 'comentario')
    ? (filter_input(INPUT_POST, 'comentario', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '')
    : null;
$comentario = $comentarioRaw !== null ? trim($comentarioRaw) : null;

if ($link && (!filter_var($link, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $link))) {
    echo json_encode(['erro' => 'Link inválido']);
    exit;
}

if (!$produto) {
    echo json_encode(['erro' => '⚠️ Referência Inválida']);
    exit;
}

if ($comentario !== null) {
    $comentario = mb_substr($comentario, 0, 1000);
}

$email = strtolower($dados['email'] ?? '');
if (!$email) {
    http_response_code(400);
    echo json_encode(['erro' => '⚠️ E-mail Inválido']);
    exit;
}

try {
    $pdo = new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
    ]);

    $pdo->beginTransaction();

   $sqlUpdate = "UPDATE _USR_CONF_SITE_HISTORICO_PRODFAV
                     SET DS_LINK = ?,
                         DT_DATA = CONVERT(VARCHAR(19), GETDATE(), 120)";
    $paramsUpdate = [$link];
    if ($comentario !== null) {
        $sqlUpdate .= ", DS_COMENTARIO = ?";
        $paramsUpdate[] = $comentario;
    }
    $sqlUpdate .= " WHERE DS_EMAIL = ? AND NR_CPFCNPJ = ? AND DS_REFERENCIA = ?";
    $paramsUpdate[] = $email;
    $paramsUpdate[] = $documento;
    $paramsUpdate[] = $produto;

    $stmt = $pdo->prepare($sqlUpdate);
    $stmt->execute($paramsUpdate);

    if ($stmt->rowCount() === 0) {
        $sqlInsert = "INSERT INTO _USR_CONF_SITE_HISTORICO_PRODFAV
                          (DS_EMAIL, NR_CPFCNPJ, DS_REFERENCIA, DT_DATA, DS_LINK, DS_COMENTARIO)
                       SELECT ?, ?, ?, CONVERT(VARCHAR(19), GETDATE(), 120), ?, ?
                        WHERE NOT EXISTS (
                            SELECT 1 FROM _USR_CONF_SITE_HISTORICO_PRODFAV
                             WHERE DS_EMAIL = ? AND NR_CPFCNPJ = ? AND DS_REFERENCIA = ?
                        )";
        $stmtIns = $pdo->prepare($sqlInsert);
        $stmtIns->execute([
            $email,
            $documento,
            $produto,
            $link,
            $comentario !== null ? $comentario : '',
            $email,
            $documento,
            $produto
        ]);
    }

    $pdo->commit();
    echo json_encode([
        'sucesso' => true,
        'mensagem' => '❤️ Produto Adicionado aos Favoritos!'
    ]);
    $pdo = null;
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    log_event('RegistrarFavorito: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['erro' => '⚠️ Erro ao Registrar Favorito']);
}