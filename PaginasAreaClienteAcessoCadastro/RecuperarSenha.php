<?php
ini_set('display_errors', 0);
error_reporting(0);
$baseDir = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
if ($baseDir === '') {
    $baseDir = dirname(__DIR__);
}

require_once $baseDir . '/Seguranca/MetodosSegurancao.php';
require_once $baseDir . '/LogsErros/Logs.php';
require_once $baseDir . '/TokensGeradores/TokensLimiteSolicitacoes.php';
require_once $baseDir . '/TokensGeradores/TokensDataSenha.php';
require_once $baseDir . '/Seguranca/CSRF.php';

header('Content-Type: application/json; charset=UTF-8');
require_valid_csrf_token();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$email = strtolower(trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL) ?? ''));
if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ E-mail inválido.']);
    exit;
}

if (!check_rate_limit('recuperar_senha', 5, 3600, $email)) {
    http_response_code(429);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => '⚠️ Quantidade de solicitações máximas atingidas. Tente novamente em 1 hora.'
    ]);
    exit;
}

require $baseDir . '/AcessosConsultas/CredenciaisBancoDados.php';
require_once $baseDir . '/AcessosConsultas/BuscarNomeEmpresaReceitaCache.php';

function formatar_documento_nacional(string $valor): string
{
    $digitos = preg_replace('/[^0-9A-Za-z]/', '', $valor);

    if (strlen($digitos) === 14) {
        return vsprintf('%s%s.%s%s%s.%s%s%s/%s%s%s%s-%s%s', str_split($digitos));
    }

    if (strlen($digitos) === 11) {
        return vsprintf('%s%s%s.%s%s%s.%s%s%s-%s%s', str_split($digitos));
    }

    return $valor;
}

try {
    $pdo = new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
    ]);

    $stmt = $pdo->prepare("SELECT DS_NOME, NR_CPFCNPJ FROM _USR_CONF_SITE_CADASTROS WHERE LOWER(DS_EMAIL) = ?");
    $stmt->execute([strtolower($email)]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$usuario) {
        echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ E-mail não cadastrado.']);
        exit;
    }

    $nomeUsuario = trim((string) ($usuario['DS_NOME'] ?? ''));
    $nomeUsuarioHtml = htmlspecialchars($nomeUsuario, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $documento = preg_replace('/[^0-9A-Za-z]/', '', (string) ($usuario['NR_CPFCNPJ'] ?? ''));
    $saudacaoTexto = $nomeUsuario;
    $saudacaoHtml = $nomeUsuarioHtml;
    if (strlen($documento) === 14) {
        $documentoFormatado = formatar_documento_nacional($documento);
        $documentoFormatadoHtml = htmlspecialchars($documentoFormatado, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $saudacaoTexto .= ' - ' . $documentoFormatado;
        $saudacaoHtml .= ' - ' . $documentoFormatadoHtml;

        $empresaNome = trim(obter_nome_empresa($documento, $baseDir));
        if ($empresaNome !== '') {
            $empresaNomeHtml = htmlspecialchars($empresaNome, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $saudacaoTexto .= ' - ' . $empresaNome;
            $saudacaoHtml .= ' - ' . $empresaNomeHtml;
        }
    }

    $token = bin2hex(random_bytes(32));
    $dirTokens = $baseDir . '/Tokens/TokensRecuperacao';
    if (!is_dir($dirTokens)) {
        mkdir($dirTokens, 0700, true);
        file_put_contents($dirTokens . '/web.config', "<?xml version=\"1.0\" encoding=\"UTF-8\"?><configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>");
    }
    $tokenFile = $dirTokens . '/' . $token . '.json';
    $senhaEm = get_password_timestamp($email);
    file_put_contents($tokenFile, json_encode([
        'email' => $email,
        'criadoEm' => time(),
        'senhaEm' => $senhaEm
    ]));
    chmod($tokenFile, 0600);

    require $baseDir . '/PHPMailer/src/PHPMailer.php';
    require $baseDir . '/PHPMailer/src/SMTP.php';
    require $baseDir . '/PHPMailer/src/Exception.php';
    require $baseDir . '/AcessosConsultas/CredenciaisAcessoEmail.php';

    $link = 'https://configurador.redutoresibr.com.br/AreaCliente?token=' . $token;
    $template = file_get_contents(__DIR__ . '/EmailRecuperacao.html');
    $html = str_replace(
        ['SAUDACAO_USUARIO', 'LINK_REDEFINICAO'],
        [$saudacaoHtml, $link],
        $template
    );

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
    $mail->addEmbeddedImage($baseDir . '/Imagens/Logotipo.png', 'logo_ibr');
    $mail->Subject = 'Recuperação de Senha - Configurador de Produtos IBR';
    $mail->isHTML(true);
    $mail->Body = $html;
    $mail->AltBody = "Olá, {$saudacaoTexto},\n\nPara redefinir sua senha acesse: $link";
    $mail->send();

    echo json_encode(['sucesso' => true, 'mensagem' => '✅ Verifique o e-mail ' . $email . ' para redefinir sua senha.']);
} catch (Exception $e) {
    log_event('RecuperarSenha: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Erro ao enviar instruções.']);
}
?>