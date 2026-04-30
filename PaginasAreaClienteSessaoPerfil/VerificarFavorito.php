<?php
header('Content-Type: application/json; charset=UTF-8');
$baseDir = $_SERVER['DOCUMENT_ROOT'] ?: dirname(__DIR__);
require $baseDir . '/Seguranca/MetodosSegurancao.php';
require_once $baseDir . '/TokensGeradores/TokenPadraoJson.php';
require $baseDir . '/AcessosConsultas/CredenciaisBancoDados.php';
require_once $baseDir . '/LogsErros/Logs.php';

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

$documentoRequest = preg_replace('/[^0-9A-Za-z]/', '', (string) (filter_input(INPUT_GET, 'documento', FILTER_UNSAFE_RAW) ?? ''));
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

$produto = strtoupper(trim(filter_input(INPUT_GET, 'produto', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? ''));
if (!$produto) {
    http_response_code(400);
    echo json_encode(['erro' => '⚠️ Referência inválida.']);
    exit;
}

$email = strtolower($dados['email'] ?? '');
if (!$email) {
    http_response_code(400);
    echo json_encode(['erro' => '⚠️ E-mail inválido.']);
    exit;
}

try {
    $pdo = new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
    ]);

$stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM _USR_CONF_SITE_HISTORICO_PRODFAV WHERE DS_EMAIL = ? AND NR_CPFCNPJ = ? AND DS_REFERENCIA = ?"
    );
    $stmt->execute([$email, $documento, $produto]);
    $favorito = ((int)$stmt->fetchColumn()) > 0;

    echo json_encode([
        'favorito' => $favorito
    ]);
    $pdo = null;
} catch (PDOException $e) {
    log_event('VerificarFavorito: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['erro' => '⚠️ Erro ao verificar favorito.']);
}