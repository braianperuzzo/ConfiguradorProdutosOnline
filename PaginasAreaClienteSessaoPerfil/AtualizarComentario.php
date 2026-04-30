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
    echo json_encode(['erro' => '⚠️ Usuário não autenticado.']);
    exit;
}

$segredo = getenv('JWT_SECRET');
if ($segredo === false) {
    $segredo = trim(file_get_contents($baseDir . '/Configuracoes/Segredo.jwt'));
}

$dados = JWTHelper::decode($token, $segredo);
if (!$dados) {
    http_response_code(403);
    echo json_encode(['erro' => '⚠️ Token inválido ou expirado.']);
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
$comentario = trim(filter_input(INPUT_POST, 'comentario', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '');
$comentario = mb_substr($comentario, 0, 1000);

if (!$produto) {
    http_response_code(400);
    echo json_encode(['erro' => '⚠️ Referência inválida']);
    exit;
}

$email = strtolower($dados['email'] ?? '');
if (!$email) {
    http_response_code(400);
    echo json_encode(['erro' => '⚠️ E-mail inválido']);
    exit;
}

try {
    $pdo = new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
    ]);

    $check = $pdo->prepare("SELECT 1 FROM _USR_CONF_SITE_HISTORICO_PRODFAV WHERE DS_EMAIL = ? AND NR_CPFCNPJ = ? AND DS_REFERENCIA = ?");
    $check->execute([$email, $documento, $produto]);
    if (!$check->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['erro' => '⚠️ Favorito não encontrado']);
        $pdo = null;
        return;
    }

    $sql = "UPDATE _USR_CONF_SITE_HISTORICO_PRODFAV
               SET DS_COMENTARIO = ?
             WHERE DS_EMAIL = ? AND NR_CPFCNPJ = ? AND DS_REFERENCIA = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$comentario, $email, $documento, $produto]);

    echo json_encode([
        'sucesso' => true,
        'mensagem' => '✅ Comentário Atualizado com Sucesso!'
    ]);
    $pdo = null;
} catch (PDOException $e) {
    log_event('AtualizarComentarioFavorito: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['erro' => '⚠️ Erro ao Atualizar Comentário']);
}