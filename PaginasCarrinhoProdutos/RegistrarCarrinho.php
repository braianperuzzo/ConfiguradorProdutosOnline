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
require_once $baseDir . '/ProcessamentoFilaJobs/CriaFilaProcessamento.php';
registrar_verificacao_periodica_fila_jobs($baseDir);

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

$nomeAnterior = '';
if (filter_has_var(INPUT_POST, 'nomeAnterior')) {
    $nomeAnterior = trim(filter_input(INPUT_POST, 'nomeAnterior', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '');
    $nomeAnterior = mb_substr($nomeAnterior, 0, 500);
}
if ($nomeAnterior === '') {
    $nomeAnterior = $nome;
}

$link = trim(filter_input(INPUT_POST, 'link', FILTER_SANITIZE_URL) ?? '');
if ($link === '') {
    http_response_code(400);
    echo json_encode(['erro' => '⚠️ Link do carrinho inválido.']);
    exit;
}
if (!filter_var($link, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $link)) {
    http_response_code(400);
    echo json_encode(['erro' => '⚠️ Link do carrinho inválido.']);
    exit;
}

$comentario = '';
if (filter_has_var(INPUT_POST, 'comentario')) {
    $comentario = filter_input(INPUT_POST, 'comentario', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '';
    $comentario = trim($comentario);
    $comentario = mb_substr($comentario, 0, 1000);
}

$substituirRaw = filter_input(INPUT_POST, 'substituir', FILTER_DEFAULT);
$substituir = in_array(strtolower((string) $substituirRaw), ['1', 'true', 'sim', 'on'], true);

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

    $pdo->beginTransaction();

$nomeOrigem = $nomeAnterior ?: $nome;

    $stmtOrigem = $pdo->prepare('SELECT 1 FROM _USR_CONF_SITE_HISTORICO_CARRFAV WHERE DS_EMAIL = ? AND NR_CPFCNPJ = ? AND DS_NOMECARRINHO = ?');
    $stmtOrigem->execute([$email, $documento, $nomeOrigem]);
    $existeOrigem = (bool) $stmtOrigem->fetchColumn();

    $stmtDestino = $pdo->prepare('SELECT 1 FROM _USR_CONF_SITE_HISTORICO_CARRFAV WHERE DS_EMAIL = ? AND NR_CPFCNPJ = ? AND DS_NOMECARRINHO = ?');
    $stmtDestino->execute([$email, $documento, $nome]);
    $existeDestino = (bool) $stmtDestino->fetchColumn();

    if ($existeOrigem && $nomeOrigem !== $nome) {
        if ($existeDestino && !$substituir) {
            $pdo->rollBack();
            echo json_encode([
                'duplicado' => true,
                'mensagem' => '⚠️ Já existe um carrinho com esse nome. Deseja substituir?'
            ]);
            exit;
        }
        if ($existeDestino && $substituir) {
            $stmtDel = $pdo->prepare('DELETE FROM _USR_CONF_SITE_HISTORICO_CARRFAV WHERE DS_EMAIL = ? AND NR_CPFCNPJ = ? AND DS_NOMECARRINHO = ?');
            $stmtDel->execute([$email, $documento, $nome]);
        }
        $stmtAtualiza = $pdo->prepare('
            UPDATE _USR_CONF_SITE_HISTORICO_CARRFAV
               SET DS_NOMECARRINHO = ?,
                   DT_DATA = CONVERT(VARCHAR(19), GETDATE(), 120),
                   DS_LINKCARRINHO = ?,
                   DS_COMENTARIO = ?
             WHERE DS_EMAIL = ? AND NR_CPFCNPJ = ? AND DS_NOMECARRINHO = ?
        ');
        $stmtAtualiza->execute([$nome, $link, $comentario, $email, $documento, $nomeOrigem]);
        $mensagem = '🛒 Atualização de Carrinho Realizada com Sucesso!';
        $acao = 'atualizado';
    } else {
        $existe = $existeDestino;
        if ($existe && !$substituir) {
            $pdo->rollBack();
            echo json_encode([
                'duplicado' => true,
                'mensagem' => '⚠️ Já existe um carrinho com esse nome. Deseja substituir?'
            ]);
            exit;
        }

        if ($existe) {
            $stmtAtualiza = $pdo->prepare('
                UPDATE _USR_CONF_SITE_HISTORICO_CARRFAV
                   SET DT_DATA = CONVERT(VARCHAR(19), GETDATE(), 120),
                       DS_LINKCARRINHO = ?,
                       DS_COMENTARIO = ?
                 WHERE DS_EMAIL = ? AND NR_CPFCNPJ = ? AND DS_NOMECARRINHO = ?
            ');
            $stmtAtualiza->execute([$link, $comentario, $email, $documento, $nome]);
            $mensagem = '🛒 Atualização de Carrinho Realizada com Sucesso!';
            $acao = 'atualizado';
        } else {
            $stmtInsere = $pdo->prepare('
                INSERT INTO _USR_CONF_SITE_HISTORICO_CARRFAV
                    (DS_EMAIL, NR_CPFCNPJ, DS_NOMECARRINHO, DT_DATA, DS_LINKCARRINHO, DS_COMENTARIO)
                VALUES (?, ?, ?, CONVERT(VARCHAR(19), GETDATE(), 120), ?, ?)
            ');
            $stmtInsere->execute([$email, $documento, $nome, $link, $comentario]);
            $mensagem = '🛒 Carrinho Salvo com Sucesso!';
            $acao = 'criado';
        }
    }

    $pdo->commit();

    echo json_encode([
        'sucesso' => true,
        'mensagem' => $mensagem,
        'acao' => $acao
    ]);
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    log_event('RegistrarCarrinho: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['erro' => '⚠️ Erro ao Salvar Carrinho.']);
}