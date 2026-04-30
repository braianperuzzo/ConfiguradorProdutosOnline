<?php
ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json; charset=UTF-8');

$baseDir = $_SERVER['DOCUMENT_ROOT'] ?: dirname(__DIR__);
require $baseDir . '/Seguranca/MetodosSegurancao.php';
require_once $baseDir . '/LogsErros/Logs.php';
require_once $baseDir . '/TokensGeradores/TokenPadraoJson.php';
require_once $baseDir . '/TokensGeradores/TokenInvalidacao.php';
require_once $baseDir . '/TokensGeradores/TokensLimiteSolicitacoes.php';
require_once $baseDir . '/Seguranca/CSRF.php';
require $baseDir . '/AcessosConsultas/CredenciaisBancoDados.php';
require_once $baseDir . '/PHPMailer/src/PHPMailer.php';
require_once $baseDir . '/PHPMailer/src/SMTP.php';
require_once $baseDir . '/PHPMailer/src/Exception.php';
require_once $baseDir . '/AcessosConsultas/CredenciaisAcessoEmail.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;


function formatar_cnpj(string $valor): string {
    $valor = preg_replace('/[^0-9A-Za-z]/', '', $valor);
    $valor = substr($valor, 0, 14);
    $valor = preg_replace('/^(\d{2})(\d)/', '$1.$2', $valor);
    $valor = preg_replace('/^(\d{2})\.(\d{3})(\d)/', '$1.$2.$3', $valor);
    $valor = preg_replace('/\.(\d{3})(\d)/', '.$1/$2', $valor);
    $valor = preg_replace('/(\d{4})(\d)/', '$1-$2', $valor);
    return $valor;
}

require_valid_csrf_token();

$token = $_COOKIE['auth_token'] ?? '';
if ($token === '') {
    http_response_code(401);
    echo json_encode(['sucesso' => false, 'erro' => '⚠️ Usuário não autenticado.']);
    exit;
}

$segredo = getenv('JWT_SECRET');
if ($segredo === false) {
    $segredo = trim(file_get_contents($baseDir . '/Configuracoes/Segredo.jwt'));
}

$dadosToken = JWTHelper::decode($token, $segredo);
if (!$dadosToken || is_token_blacklisted($token)) {
    http_response_code(403);
    echo json_encode(['sucesso' => false, 'erro' => '⚠️ Credenciais inválidas.']);
    exit;
}

$emailSolicitante = strtolower(trim((string)($dadosToken['email'] ?? '')));
if ($emailSolicitante === '' || !check_rate_limit('convite_permissao_equipe', 10, 3600, $emailSolicitante)) {
    http_response_code(429);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Limite de envio de convites atingido. Tente novamente em 1 hora.']);
    exit;
}

$entradaBruta = file_get_contents('php://input') ?: '';
$entrada = json_decode($entradaBruta, true);
$convites = is_array($entrada['convites'] ?? null) ? $entrada['convites'] : [];

if (empty($convites)) {
    echo json_encode(['sucesso' => true, 'enviados' => 0]);
    exit;
}

try {
    $pdo = new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
    ]);

    $template = file_get_contents(__DIR__ . '/EmailConvite.html');
    if ($template === false) {
        throw new RuntimeException('Template de convite não encontrado.');
    }

    $mailBase = new PHPMailer(true);
    $mailBase->CharSet = 'UTF-8';
    $mailBase->isSMTP();
    $mailBase->Host = $smtpHost;
    $mailBase->SMTPAuth = true;
    $mailBase->Username = $smtpUser;
    $mailBase->Password = $smtpPass;
    $mailBase->SMTPSecure = $smtpSecure;
    $mailBase->Port = $smtpPort;
    $mailBase->setFrom($smtpUser, 'Não Responder - Comunicação Redutores IBR');

    $enviados = 0;
    $emailsProcessados = [];

    foreach ($convites as $item) {
        $email = strtolower(trim((string)($item['email'] ?? '')));
        $cnpj = preg_replace('/[^0-9A-Za-z]/', '', (string)($item['cnpj'] ?? ''));

        if ($email === '' || isset($emailsProcessados[$email]) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        $emailsProcessados[$email] = true;

        if ($cnpj === '' || strlen($cnpj) !== 14) {
            continue;
        }

        $stmtCadastro = $pdo->prepare('SELECT TOP 1 1 FROM _USR_CONF_SITE_CADASTROS WHERE LOWER(DS_EMAIL) = ?');
        $stmtCadastro->execute([$email]);
        $jaCadastrado = (bool) $stmtCadastro->fetchColumn();
        if ($jaCadastrado) {
            continue;
        }

        $linkCadastro = 'https://configurador.redutoresibr.com.br/AreaCliente?tab=cadastrar&convite=1&cnpj=' . urlencode($cnpj) . '&email=' . urlencode($email);
        $cnpjFormatado = formatar_cnpj($cnpj);
        $html = str_replace(
            [
                'LINK_CADASTRO',
                'LINK_CONVITE',
                'LINK_CONFIRMACAO',
                'LINK_AREA_CLIENTE',
                'EMAIL_CONVIDADO',
                'EMAIL_PREENCHIDO',
                'CNPJ_PREENCHIDO',
                'CNPJ_CONVIDADO'
            ],
            [
                htmlspecialchars($linkCadastro, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($linkCadastro, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($linkCadastro, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($linkCadastro, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($email, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($email, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($cnpjFormatado, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($cnpjFormatado, ENT_QUOTES, 'UTF-8')
            ],
            $template
        );

        $mail = clone $mailBase;
        $mail->clearAllRecipients();
        $mail->clearAttachments();
        $mail->addAddress($email);
        $mail->addEmbeddedImage($baseDir . '/Imagens/Logotipo.png', 'logo_ibr');
        $mail->Subject = 'Você Recebeu um Convite - Configurador de Produtos IBR';
        $mail->isHTML(true);
        $mail->Body = $html;
        $mail->AltBody = "Olá,\n\nVocê recebeu um convite para concluir seu cadastro na Área do Cliente IBR.\nAcesse: $linkCadastro\n\nE-mail sugerido: $email\nCNPJ sugerido: $cnpjFormatado";

        try {
            $mail->send();
            $enviados++;
        } catch (Exception $e) {
            log_event('Erro ao enviar convite de cadastro para ' . $email . ': ' . $e->getMessage());
        }
    }

    echo json_encode(['sucesso' => true, 'enviados' => $enviados]);
} catch (Throwable $e) {
    log_event('EnviarConvitePermissaoEquipe: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Erro ao enviar convites para cadastro.']);
}
