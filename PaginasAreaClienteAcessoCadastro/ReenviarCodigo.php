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
require_once $baseDir . '/Seguranca/CSRF.php';
require_once $baseDir . '/AcessosConsultas/BuscarNomeEmpresaReceitaCache.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json; charset=utf-8');
require_valid_csrf_token();

$formatarDocumento = function (?string $valor): string {
    $digitos = preg_replace('/[^0-9A-Za-z]/', '', (string) $valor);

    if (strlen($digitos) === 14) {
        return vsprintf('%s%s.%s%s%s.%s%s%s/%s%s%s%s-%s%s', str_split($digitos));
    }

    if (strlen($digitos) === 11) {
        return vsprintf('%s%s%s.%s%s%s.%s%s%s-%s%s', str_split($digitos));
    }

    return trim((string) $valor);
};

$montarSaudacao = function (?string $nome, ?string $documento, ?string $empresa) use ($formatarDocumento): string {
    $componentes = [];
    $nome = trim((string) $nome);
    if ($nome !== '') {
        $componentes[] = $nome;
    }

    $documentoLimpo = preg_replace('/[^0-9A-Za-z]/', '', (string) $documento);
    if (strlen($documentoLimpo) === 14) {
        $componentes[] = $formatarDocumento($documentoLimpo);
        $empresa = trim((string) $empresa);
        if ($empresa !== '') {
            $componentes[] = $empresa;
        }
    }

    if (empty($componentes) && $nome !== '') {
        $componentes[] = $nome;
    }

    return implode(' - ', $componentes);
};

$email = strtolower(trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL) ?? ''));

if (!check_rate_limit('reenviar_codigo', 5, 60, $email)) {
    http_response_code(429);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Quantidade de solicitações máximas atingidas. Tente novamente em 1 hora.']);
    exit;
}

if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Dados inválidos.']);
    exit;
}

/**
 * Localiza o arquivo de dispositivo associado ao e-mail, mesmo que tenha sido
 * criado com outro user-agent (ex.: varredura automática de links seguros).
 */
function localizar_arquivo_dispositivo(string $dirDevices, string $deviceFilePreferido, string $email): ?string
{
    if (file_exists($deviceFilePreferido)) {
        return $deviceFilePreferido;
    }

    $emailLimpo = strtolower(trim($email));
    $maisRecente = null;
    $melhorArquivo = null;
    foreach (glob($dirDevices . '/*.json') as $arquivo) {
        $conteudo = json_decode(file_get_contents($arquivo), true);
        $emailArquivo = strtolower(trim($conteudo['email'] ?? ($conteudo['payload']['email'] ?? '')));
        if ($emailArquivo !== $emailLimpo) {
            continue;
        }
        $referenciaTempo = (int)($conteudo['generatedAt'] ?? $conteudo['criadoEm'] ?? filemtime($arquivo));
        if ($maisRecente === null || $referenciaTempo > $maisRecente) {
            $maisRecente = $referenciaTempo;
            $melhorArquivo = $arquivo;
        }
    }

    return $melhorArquivo;
}

$deviceHash = hash('sha256', strtolower($email) . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
$dirDevices = $baseDir . '/Tokens/TokensDispositivos';
$deviceFilePreferido = $dirDevices . '/' . $deviceHash . '.json';
$deviceFile = localizar_arquivo_dispositivo($dirDevices, $deviceFilePreferido, $email);

if ($deviceFile === null) {
    http_response_code(404);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Solicitação inválida.']);
    exit;
}

$data = json_decode(file_get_contents($deviceFile), true);
if (!$data || !isset($data['payload'])) {
    http_response_code(404);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Solicitação inválida.']);
    exit;
}

$payload = $data['payload'];
if (!is_array($payload)) {
    $payload = [];
}
$nomeUsuario = $payload['nome'] ?? '';
$ttl = $data['ttl'] ?? 604800;

$codigoLogin = random_int(100000, 999999);

require $baseDir . '/PHPMailer/src/PHPMailer.php';
require $baseDir . '/PHPMailer/src/SMTP.php';
require $baseDir . '/PHPMailer/src/Exception.php';
require $baseDir . '/AcessosConsultas/CredenciaisAcessoEmail.php';

$template = file_get_contents(__DIR__ . '/EmailCodigoLogin.html');
$cpfcnpjPayload = preg_replace('/[^0-9A-Za-z]/', '', (string)($payload['cpfcnpj'] ?? ''));
$empresaPayload = trim((string)($payload['empresa'] ?? ''));
if (strlen($cpfcnpjPayload) === 14 && $empresaPayload === '') {
    $empresaPayload = trim((string) obter_nome_empresa($cpfcnpjPayload, $baseDir));
}
$payload['cpfcnpj'] = $cpfcnpjPayload;
if ($empresaPayload !== '') {
    $payload['empresa'] = $empresaPayload;
}
$saudacaoConteudo = $montarSaudacao($nomeUsuario, $cpfcnpjPayload, $empresaPayload);
$saudacaoHtml = htmlspecialchars($saudacaoConteudo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$html = str_replace(
    ['SAUDACAO_USUARIO', 'CODIGO_LOGIN'],
    [$saudacaoHtml, $codigoLogin],
    $template
);

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
    $mail->addEmbeddedImage($baseDir . '/Imagens/Logotipo.png', 'logo_ibr');
    $mail->Subject = 'Código de Acesso - Configurador de Produtos IBR';
    $mail->isHTML(true);
    $mail->Body = $html;
    $saudacaoTexto = 'Olá, ' . ($saudacaoConteudo !== '' ? $saudacaoConteudo : $nomeUsuario) . ',';
    $mail->AltBody = $saudacaoTexto . "\n\nSeu código de acesso é: $codigoLogin";
    $mail->send();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Erro ao enviar código.']);
    exit;
}

$data['payload'] = $payload;
$data['code'] = password_hash($codigoLogin, PASSWORD_DEFAULT);
$data['generatedAt'] = time();
unset($data['verifiedAt']);
file_put_contents($deviceFile, json_encode($data));
chmod($deviceFile, 0600);

echo json_encode([
    'sucesso' => true,
    'mensagem' => '✅ Código reenviado para o e-mail ' . $email . '.'
]);
?>