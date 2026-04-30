<?php
ini_set('display_errors', 0);
error_reporting(0);
$baseDir = $_SERVER['DOCUMENT_ROOT'] ?: dirname(__DIR__);
require $baseDir . '/Seguranca/MetodosSegurancao.php';
require_once $baseDir . '/LogsErros/Logs.php';
require_once $baseDir . '/TokensGeradores/TokenPadraoJson.php';
require_once $baseDir . '/TokensGeradores/TokensLimiteSolicitacoes.php';
require_once $baseDir . '/TokensGeradores/TokenInvalidacao.php';
require_once $baseDir . '/AcessosConsultas/BuscarNomeEmpresaReceitaCache.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json; charset=UTF-8');

function formatar_documento_cnpj(string $valor): string
{
    $numeros = preg_replace('/[^0-9A-Za-z]/', '', $valor);
    if (strlen($numeros) !== 14) {
        return $numeros;
    }
    return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $numeros) ?: $numeros;
}

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
if (!$dados || is_token_blacklisted($token)) {
    http_response_code(403);
    echo json_encode(['erro' => '⚠️ Credenciais inválidas.']);
    exit;
}

$nome = mb_strtoupper(trim($dados['nome'] ?? ''), 'UTF-8');
$email = strtolower(trim($dados['email'] ?? ''));
$cpfcnpj = preg_replace('/[^0-9A-Za-z]/', '', $dados['cpfcnpj'] ?? '');
$grupo = $dados['grupo'] ?? '';
$codigo = $dados['codigo'] ?? 0;
$documentoSessao = preg_replace('/[^0-9A-Za-z]/', '', (string)($dados['empresaDocumento'] ?? ''));

$escopoSolicitado = strtolower(trim((string)($_POST['escopo'] ?? '')));
$escopo = $escopoSolicitado === 'empresa' ? 'empresa' : 'todas';
$documentoInformado = preg_replace('/[^0-9A-Za-z]/', '', (string)($_POST['documento'] ?? ''));
$documentoSelecionado = '';
$empresaSelecionadaNome = '';

if ($escopo === 'empresa') {
    if (strlen($documentoSessao) !== 14) {
        http_response_code(400);
        echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Não foi possível identificar a empresa selecionada para remoção.']);
        exit;
    }

    if ($documentoInformado !== '' && $documentoInformado !== $documentoSessao) {
    }

    require $baseDir . '/AcessosConsultas/CredenciaisBancoDados.php';
    $pdo = null;
    try {
        $pdo = new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
        ]);

        $stmt = $pdo->prepare('SELECT 1 FROM _USR_CONF_SITE_CADASTROS WHERE LOWER(DS_EMAIL) = ? AND NR_CPFCNPJ = ?');
        $stmt->execute([$email, $documentoSessao]);
        $existeVinculo = $stmt->fetchColumn();
        if (!$existeVinculo) {
            http_response_code(404);
            echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Empresa não vinculada à sua conta.']);
            exit;
        }
        $documentoSelecionado = $documentoSessao;
        $empresaSelecionadaNome = obter_nome_empresa($documentoSessao, $baseDir) ?: $documentoSessao;
    } catch (PDOException $e) {
        log_event('SolicitarExclusao validar empresa: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Não foi possível validar a empresa selecionada.']);
        exit;
    } finally {
        if ($pdo instanceof PDO) {
            $pdo = null;
        }
    }
}

if (!check_rate_limit('excluir_conta', 5, 60, $email)) {
    http_response_code(429);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Quantidade de solicitações máximas atingidas. Tente novamente em 1 hora.']);
    exit;
}

$tokenNovo = bin2hex(random_bytes(32));
$dirTokens = $baseDir . '/Tokens/TokensExclusao';
if (!is_dir($dirTokens)) {
    mkdir($dirTokens, 0700, true);
    file_put_contents($dirTokens . '/web.config', "<?xml version=\"1.0\" encoding=\"UTF-8\"?><configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>");
}
$tokenFile = $dirTokens . '/' . $tokenNovo . '.json';
file_put_contents($tokenFile, json_encode([
    'email' => $email,
    'escopo' => $escopo,
    'documento' => $documentoSelecionado,
    'criadoEm' => time()
]));
chmod($tokenFile, 0600);

require $baseDir . '/PHPMailer/src/PHPMailer.php';
require $baseDir . '/PHPMailer/src/SMTP.php';
require $baseDir . '/PHPMailer/src/Exception.php';
require $baseDir . '/AcessosConsultas/CredenciaisAcessoEmail.php';

$linkConfirmacao = 'https://configurador.redutoresibr.com.br/PaginasAreaClienteSessaoPerfil/ExcluirConta.php?token=' . $tokenNovo;
$nomeSaudacao = $nome ?: $email;
$saudacaoUsuario = $nomeSaudacao;
$assuntoEmail = 'Confirmação de Exclusão de Conta - Configurador de Produtos';
$templatePath = __DIR__ . '/EmailExclusao.html';

if ($escopo !== 'empresa' && strlen($cpfcnpj) === 14) {
    $documentoSolicitante = formatar_documento_cnpj($cpfcnpj);
    $nomeEmpresaSolicitante = trim((string) obter_nome_empresa($cpfcnpj, $baseDir));
    if ($nomeEmpresaSolicitante !== '') {
        $nomeEmpresaSolicitante = mb_strtoupper($nomeEmpresaSolicitante, 'UTF-8');
    }
    $partesSaudacao = array_filter([
        $nomeSaudacao,
        $documentoSolicitante,
        $nomeEmpresaSolicitante,
    ], static function ($valor) {
        return $valor !== null && $valor !== '';
    });
    if (!empty($partesSaudacao)) {
        $saudacaoUsuario = implode(' - ', $partesSaudacao);
    }
}

$altBody = "Olá $saudacaoUsuario,\n\nConfirme a exclusão acessando: $linkConfirmacao";
$substituicoes = [
    'SAUDACAO_USUARIO' => htmlspecialchars($saudacaoUsuario, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    'LINK_CONFIRMACAO' => $linkConfirmacao,
];

if ($escopo === 'empresa') {
    $assuntoEmail = 'Confirmação de Remoção de Empresa - Configurador de Produtos Redutores IBR';
    $templatePath = __DIR__ . '/EmailRemocaoEmpresa.html';
    if (!file_exists($templatePath)) {
        $alternativo = dirname(__DIR__) . '/Empresa/EmailRemocaoEmpresa.html';
        if (file_exists($alternativo)) {
            $templatePath = $alternativo;
        }
    }    $documentoFormatado = formatar_documento_cnpj($documentoSelecionado);
    $altBody = "Olá $nomeSaudacao,\n\nConfirme a remoção da empresa $empresaSelecionadaNome";
    if ($documentoFormatado) {
        $altBody .= " ($documentoFormatado)";
    }
    $altBody .= " acessando: $linkConfirmacao";
    $substituicoes = [
        'NOME_USUARIO' => $nomeSaudacao,
        'LINK_CONFIRMACAO' => $linkConfirmacao,
        'NOME_EMPRESA' => $empresaSelecionadaNome,
        'DOCUMENTO_EMPRESA' => $documentoFormatado,
    ];
}

$template = @file_get_contents($templatePath);
if ($template === false) {
    if (file_exists($tokenFile)) {
        unlink($tokenFile);
    }
    log_event('SolicitarExclusao template indisponivel para ' . $email . ' em ' . $templatePath);
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Não foi possível preparar o e-mail de confirmação.']);
    exit;
}

$html = str_replace(array_keys($substituicoes), array_values($substituicoes), $template);

try {
    $mail = new PHPMailer(true);
    $mail->CharSet = 'UTF-8';
    $mail->isSMTP();
    $mail->Host = $smtpHost;
    $mail->SMTPAuth = true;
    $mail->Username = $smtpUser;
    $mail->Password = $smtpPass;
    $mail->SMTPSecure = $smtpSecure;
    $mail->Port = $smtpPort;
    $mail->setFrom($smtpUser, 'Não Responder - Comunicação Redutores IBR');
    $mail->addAddress($email);
    $mail->addEmbeddedImage(
        $baseDir . '/Imagens/Logotipo.png',
        'logo_ibr'
    );
    $mail->Subject = $assuntoEmail;
    $mail->isHTML(true);
    $mail->Body = $html;
    $mail->AltBody = $altBody;
    $mail->send();
    echo json_encode(['sucesso' => true, 'mensagem' => '✅ Verifique o e-mail ' . $email . ' para confirmar a exclusão.']);
} catch (Exception $e) {
    log_event('Erro ao enviar email de exclusao para ' . $email . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Erro ao enviar e-mail de confirmação.']);
}
?>