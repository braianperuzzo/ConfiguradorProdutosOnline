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

$produto = strtoupper(trim(filter_input(INPUT_POST, 'produto', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? ''));
$formatoRaw = filter_input(INPUT_POST, 'formato', FILTER_UNSAFE_RAW) ?? '';
$formatoDecodificado = html_entity_decode((string)$formatoRaw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$formato = strtoupper(trim($formatoDecodificado));
$drvwIdField = trim(filter_input(INPUT_POST, 'drvw_idfield', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '');
$link = trim(filter_input(INPUT_POST, 'link', FILTER_SANITIZE_URL) ?? '');

if ($link && (!filter_var($link, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $link))) {
    echo json_encode(['erro' => 'Link invalido']);
    exit;
}

if (!$produto || !$formato) {
    echo json_encode(['erro' => 'Dados invalidos']);
    exit;
}

try {
    $pdo = new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
    ]);

        $isCodigo = preg_match('/^[A-Z]{2,4}\.[0-9]{8}$/', $produto);
    if ($isCodigo) {
        $sqlRef = "SELECT TOP 1 DS_REFERENCIA FROM MMPR_PRODUTO WHERE CD_PRODUTO = ? AND ID_STATUS = 0 AND CD_PRODCONFIG IS NOT NULL";
        try {
            $stmRef = $pdo->prepare($sqlRef);
            $stmRef->execute([$produto]);
            $rowRef = $stmRef->fetch(PDO::FETCH_ASSOC);
            $produto = $rowRef['DS_REFERENCIA'] ?? $produto;
        } catch (PDOException $e) {
        }
    }

 $sqlAtualiza = "UPDATE _USR_CONF_SITE_HISTORICO_DESENHO
               SET DS_LINK = ?
             WHERE DS_EMAIL = ? AND NR_CPFCNPJ = ? AND DS_REFERENCIA = ? AND DS_FORMATO = ? AND DRVW_IDFIELD = ?
               AND (DS_LINK IS NULL OR LTRIM(RTRIM(CONVERT(VARCHAR(MAX), DS_LINK))) = '')";

    $stmtUpdate = $pdo->prepare($sqlAtualiza);
    $stmtUpdate->execute([
        $link,
        strtolower($dados['email']),
        $documento,
        $produto,
        $formato,
        $drvwIdField
    ]);

    $sql = "INSERT INTO _USR_CONF_SITE_HISTORICO_DESENHO (DS_EMAIL, NR_CPFCNPJ, DS_REFERENCIA, DS_FORMATO, DRVW_IDFIELD, DS_LINK, DT_DATA)
            SELECT ?, ?, ?, ?, ?, ?, CONVERT(VARCHAR(19), GETDATE(), 120)
             WHERE NOT EXISTS (
                 SELECT 1 FROM _USR_CONF_SITE_HISTORICO_DESENHO
                  WHERE DS_EMAIL = ? AND NR_CPFCNPJ = ? AND DS_REFERENCIA = ? AND DS_FORMATO = ? AND DRVW_IDFIELD = ?
             )";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
         strtolower($dados['email']),
        $documento,
        $produto,
        $formato,
        $drvwIdField,
        $link,
        strtolower($dados['email']),
        $documento,
        $produto,
        $formato,
        $drvwIdField
    ]);

    echo json_encode(['sucesso' => true]);
    $pdo = null;
} catch (PDOException $e) {
    log_event('RegistrarDesenho: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['erro' => 'Erro ao registrar desenho']);
}
?>