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

$emailInput = filter_input(INPUT_POST, 'email', FILTER_UNSAFE_RAW) ?? '';
$produto    = strtoupper(trim(filter_input(INPUT_POST, 'produto', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? ''));
$oportunidade = trim(filter_input(INPUT_POST, 'oportunidade', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '');
$oportunidade = $oportunidade === '' ? null : $oportunidade;
$link       = trim(filter_input(INPUT_POST, 'link', FILTER_SANITIZE_URL) ?? '');

$emailParts = [];
if ($emailInput !== '') {
    $splits = array_filter(array_map('trim', preg_split('/[;\s,]+/', $emailInput)));
    $seen = [];
    foreach ($splits as $item) {
        $lower = strtolower($item);
        if ($lower === '') continue;
        if (!filter_var($lower, FILTER_VALIDATE_EMAIL)) continue;
        if (isset($seen[$lower])) continue;
        $seen[$lower] = true;
        $emailParts[] = $lower;
    }
}

if (!$emailParts) {
    $emailSessao = strtolower($dados['email'] ?? '');
    if (!filter_var($emailSessao, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['erro' => '⚠️ Email inválido.']);
        exit;
    }
    $emailParts = [$emailSessao];
}

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

  $sqlUpdate = "UPDATE _USR_CONF_SITE_HISTORICO_CADASTROS
                     SET DS_LINK = ?
                   WHERE DS_EMAIL = ?
                     AND NR_CPFCNPJ = ?
                     AND DS_REFERENCIA = ?
                     AND ((CD_OPORTUNIDADE = ? AND ? IS NOT NULL) OR (CD_OPORTUNIDADE IS NULL AND ? IS NULL))
                     AND (DS_LINK IS NULL OR DATALENGTH(DS_LINK) = 0)";
    $stmtUpdate = $pdo->prepare($sqlUpdate);

    $sqlInsert = "INSERT INTO _USR_CONF_SITE_HISTORICO_CADASTROS (DS_EMAIL, NR_CPFCNPJ, DS_REFERENCIA, CD_OPORTUNIDADE, DS_LINK, DT_DATA)
            SELECT ?, ?, ?, ?, ?, CONVERT(VARCHAR(19), GETDATE(), 120)
             WHERE NOT EXISTS (
                 SELECT 1 FROM _USR_CONF_SITE_HISTORICO_CADASTROS
                  WHERE DS_EMAIL = ?
                    AND NR_CPFCNPJ = ?
                    AND DS_REFERENCIA = ?
                    AND ((CD_OPORTUNIDADE = ? AND ? IS NOT NULL) OR (CD_OPORTUNIDADE IS NULL AND ? IS NULL))
                    AND CONVERT(VARCHAR(MAX), DS_LINK) = ?
             )";
    $stmtInsert = $pdo->prepare($sqlInsert);

    foreach ($emailParts as $email) {
        $stmtUpdate->execute([
            $link,
            $email,
            $documento,
            $produto,
            $oportunidade,
            $oportunidade,
            $oportunidade
        ]);

        $stmtInsert->execute([
            $email,
            $documento,
            $produto,
            $oportunidade,
            $link,
            $email,
            $documento,
            $produto,
            $oportunidade,
            $oportunidade,
            $oportunidade,
            $link
        ]);
    }
    
    echo json_encode(['sucesso' => true]);
    $pdo = null;
} catch (PDOException $e) {
    log_event('RegistrarCadastro: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['erro' => 'Erro ao registrar cadastro']);
}
?>