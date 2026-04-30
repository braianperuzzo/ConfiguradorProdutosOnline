<?php
header('Content-Type: application/json; charset=UTF-8');

$baseDir = $_SERVER['DOCUMENT_ROOT'] ?: dirname(__DIR__);
require $baseDir . '/Seguranca/MetodosSegurancao.php';
require_once $baseDir . '/TokensGeradores/TokenPadraoJson.php';
require $baseDir . '/AcessosConsultas/CredenciaisBancoDados.php';

$token = $_COOKIE['auth_token'] ?? '';
if (!$token) {
    http_response_code(401);
    echo json_encode(['sucesso' => false, 'erro' => '⚠️ Usuário não autenticado.']);
    exit;
}

$segredo = getenv('JWT_SECRET');
if ($segredo === false) {
    $segredo = trim(file_get_contents($baseDir . '/Configuracoes/Segredo.jwt'));
}

$dados = JWTHelper::decode($token, $segredo);
if (!$dados) {
    http_response_code(403);
    echo json_encode(['sucesso' => false, 'erro' => '⚠️ Credenciais inválidas.']);
    exit;
}

$emailEquipe = strtolower(trim((string)(filter_input(INPUT_GET, 'email', FILTER_UNSAFE_RAW) ?? '')));
$documento = preg_replace('/[^0-9A-Za-z]/', '', (string)(filter_input(INPUT_GET, 'documento', FILTER_UNSAFE_RAW) ?? ''));

if ($emailEquipe === '' || !filter_var($emailEquipe, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'erro' => '⚠️ E-mail inválido.']);
    exit;
}

if ($documento === '' || !in_array(strlen($documento), [11, 14], true)) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'erro' => '⚠️ Documento inválido.']);
    exit;
}

try {
    $pdo = new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
    ]);

    $stmtPessoa = $pdo->prepare(
        "SELECT TOP 1 CD_PESSOA
         FROM MBAD_PESSOA
         WHERE REPLACE(REPLACE(REPLACE(NR_CPFCNPJ, '.', ''), '-', ''), '/', '') = ?"
    );
    $stmtPessoa->execute([$documento]);
    $codigoPessoa = (string)($stmtPessoa->fetchColumn() ?: '');

    $stmtCadastro = $pdo->prepare(
        "SELECT TOP 1 1 FROM _USR_CONF_SITE_CADASTROS WHERE LOWER(DS_EMAIL) = ?"
    );
    $stmtCadastro->execute([$emailEquipe]);
    $possuiCadastro = (bool) $stmtCadastro->fetchColumn();

    if ($codigoPessoa === '') {
        echo json_encode(['sucesso' => true, 'grupo' => '', 'possuiCadastro' => $possuiCadastro]);
        exit;
    }

    $stmtPermissao = $pdo->prepare(
        "SELECT MAX(TIPO.DS_TIPO) AS PERMISSAO
         FROM MBAD_PESSOACONTATO AS CONTATO
         INNER JOIN MBAD_PESSOACONTATOTIPO AS TIPO ON CONTATO.CD_TIPO = TIPO.CD_TIPO
         WHERE CONTATO.CD_PESSOA = ?
           AND CONTATO.CD_FUNCAO = 'SITE'
           AND LOWER(CONTATO.DS_EMAIL) = ?"
    );
    $stmtPermissao->execute([$codigoPessoa, $emailEquipe]);
    $grupo = trim((string)($stmtPermissao->fetchColumn() ?: ''));

    echo json_encode([
        'sucesso' => true,
        'grupo' => $grupo,
        'possuiCadastro' => $possuiCadastro
    ]);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode([
        'sucesso' => false,
        'erro' => '⚠️ Serviço indisponível. Tente novamente em instantes.'
    ]);
}
