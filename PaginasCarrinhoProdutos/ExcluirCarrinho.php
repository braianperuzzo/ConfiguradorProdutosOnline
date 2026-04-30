<?php
header('Content-Type: application/json; charset=UTF-8');
$baseDir = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
if ($baseDir === '') {
    $baseDir = dirname(__DIR__);
}
require_once $baseDir . '/Seguranca/CSRF.php';
require_valid_csrf_token();
require $baseDir . '/Seguranca/MetodosSegurancao.php';
require_once $baseDir . '/LogsErros/Logs.php';
require_once $baseDir . '/TokensGeradores/TokenPadraoJson.php';
require $baseDir . '/AcessosConsultas/CredenciaisBancoDados.php';
require_once $baseDir . '/TokensGeradores/TokensCarrinhosCompartilhados.php';

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

$dadosToken = JWTHelper::decode($token, $segredo);
if (!$dadosToken) {
    http_response_code(403);
    echo json_encode(['erro' => '⚠️ Token Inválido ou Expirado.']);
    exit;
}

$documentoRequest = preg_replace('/[^0-9A-Za-z]/', '', (string) (filter_input(INPUT_POST, 'documento', FILTER_UNSAFE_RAW) ?? ''));
$documento = preg_replace(
    '/[^0-9A-Za-z]/',
    '',
    (string)($dadosToken['empresaDocumento'] ?? $_COOKIE['ibr_empresa_doc'] ?? '')
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

$nome = trim(filter_input(INPUT_POST, 'nome', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '');
$nome = mb_substr($nome, 0, 500);
if ($nome === '') {
    http_response_code(400);
    echo json_encode(['erro' => '⚠️ Informe o nome do carrinho.']);
    exit;
}

$email = strtolower($dadosToken['email'] ?? '');
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

    $stmtBusca = $pdo->prepare('SELECT DS_LINKCARRINHO FROM _USR_CONF_SITE_HISTORICO_CARRFAV WHERE DS_EMAIL = ? AND NR_CPFCNPJ = ? AND DS_NOMECARRINHO = ?');
    $stmtBusca->execute([$email, $documento, $nome]);
    $links = $stmtBusca->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $links = array_map('strval', $links);

    $stmt = $pdo->prepare('DELETE FROM _USR_CONF_SITE_HISTORICO_CARRFAV WHERE DS_EMAIL = ? AND NR_CPFCNPJ = ? AND DS_NOMECARRINHO = ?');
    $stmt->execute([$email, $documento, $nome]);
    $removidos = $stmt->rowCount();

    if ($removidos > 0) {
        if (!empty($links)) {
            excluir_carrinhos_compartilhados_por_links($links, $baseDir);
        }
        echo json_encode([
            'sucesso' => true,
            'mensagem' => '🗑️ Carrinho Excluído com Sucesso.'
        ]);
    } else {
        echo json_encode([
            'sucesso' => true,
            'mensagem' => '⚠️ Carrinho não encontrado ou já excluído.'
        ]);
    }
} catch (PDOException $e) {
    log_event('ExcluirCarrinho: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['erro' => '⚠️ Erro ao Excluir Carrinho.']);
}