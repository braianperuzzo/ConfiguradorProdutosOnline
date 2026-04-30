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

header('Content-Type: application/json; charset=UTF-8');
require_valid_csrf_token();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$nome = filter_input(INPUT_POST, 'nome', FILTER_UNSAFE_RAW) ?? '';
$nome = mb_strtoupper(trim(strip_tags($nome)), 'UTF-8');
$email = strtolower(trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL) ?? ''));
$cpfcnpj = preg_replace('/[^0-9A-Za-z]/', '', filter_input(INPUT_POST, 'cpfcnpj', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '');
$senha = $_POST['senha'] ?? '';
$senhaRep = $_POST['senhaRepetida'] ?? '';
$novidades = strtoupper(trim((string) ($_POST['novidades'] ?? 'NAO')));
if ($novidades !== 'SIM') {
    $novidades = 'NAO';
}

function formatarCpfCnpj($valor)
{
    $apenasNumeros = preg_replace('/[^0-9A-Za-z]/', '', (string) $valor);
    if (strlen($apenasNumeros) === 14) {
        return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $apenasNumeros);
    }
    if (strlen($apenasNumeros) === 11) {
        return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $apenasNumeros);
    }
    return $valor;
}

if (!check_rate_limit('cadastro', 5, 60, $email)) {
    http_response_code(429);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Quantidade de solicitações máximas atingidas. Tente novamente em 1 hora.']);
    exit;
}

if ($senha !== $senhaRep) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ As senhas não conferem.']);
    exit;
}

if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^\w\s]).{8,}$/', $senha)) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Senha fora do padrão de segurança.']);
    exit;
}
$hashSenha = password_hash($senha, PASSWORD_BCRYPT, ['cost' => 10]);

$empresa = '';
if (strlen($cpfcnpj) === 14) {
    $ctx = stream_context_create([
        'http' => ['timeout' => 10, 'user_agent' => 'ConfiguradorIBR']
    ]);
    $resp = @file_get_contents('https://www.receitaws.com.br/v1/cnpj/' . $cpfcnpj, false, $ctx);
    $dadosEmpresa = $resp ? json_decode($resp, true) : null;
    $nomeReceita = $dadosEmpresa['nome'] ?? ($dadosEmpresa['nome_fantasia'] ?? ($dadosEmpresa['fantasia'] ?? ''));
    if ($nomeReceita) {
        $empresa = strtoupper($nomeReceita);
    }
}

if (strlen($cpfcnpj) === 14 && !$empresa) {
    http_response_code(404);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Não encontramos este CNPJ. Confira os dados e tente novamente.']);
    exit;
}

if (!$nome || !$email || !$cpfcnpj || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255 || !in_array(strlen($cpfcnpj), [11,14])) {
        http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Dados inválidos.']);
    exit;
}

require $baseDir . '/AcessosConsultas/CredenciaisBancoDados.php';

try {
    $pdo = new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
    ]);

   $emailLower = strtolower($email);
    $stmt = $pdo->prepare("SELECT NR_CPFCNPJ FROM _USR_CONF_SITE_CADASTROS WHERE LOWER(DS_EMAIL) = ?");
    $stmt->execute([$emailLower]);
    $cadastroExistente = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($cadastroExistente) {
  if (strlen($cpfcnpj) === 14) {
            echo json_encode([
                'sucesso' => false,
                'status' => 409,
                'mensagem' => '⚠️ Já existe um cadastro ativo para este e-mail. Se deseja adicionar uma nova empresa, utilize o seletor de empresas no seu cadastro.'
             ]);
        } else {
            echo json_encode([
                'sucesso' => false,
                'status' => 409,
                'mensagem' => '⚠️ Identificamos um cadastro ativo para este e-mail. Por favor, efetue o login ou utilize a opção de recuperação de senha.'
            ]);
        }
        exit;
    }

    $token = bin2hex(random_bytes(32));
    $dirTokens = $baseDir . '/Tokens/TokensCadastro';
    if (!is_dir($dirTokens)) {
        mkdir($dirTokens, 0700, true);
        file_put_contents($dirTokens . '/web.config', "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>");
    }
    $tokenFile = $dirTokens . '/' . $token . '.json';
    file_put_contents($tokenFile, json_encode([
        'nome' => $nome,
        'email' => $email,
        'cpfcnpj' => $cpfcnpj,
        'senha' => $hashSenha,
        'novidades' => $novidades,
        'dtSenha' => time(),
        'criadoEm' => time(),
        'status' => 'pendente'
    ]));
    chmod($tokenFile, 0600);

    require $baseDir . '/PHPMailer/src/PHPMailer.php';
    require $baseDir . '/PHPMailer/src/SMTP.php';
    require $baseDir . '/PHPMailer/src/Exception.php';
    require $baseDir . '/AcessosConsultas/CredenciaisAcessoEmail.php';

 $linkConfirmacao = 'https://configurador.redutoresibr.com.br/PaginasAreaClienteAcessoCadastro/ConfirmacaoCadastro.php?token=' . $token;
        $template  = file_get_contents(__DIR__ . '/EmailCadastro.html');

        $nomeEscapado = htmlspecialchars($nome, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $saudacaoHtml = $nomeEscapado;
        $saudacaoTexto = $nome;
        $detalheEmpresaHtml = '';
        $detalheEmpresaTexto = '';
        if (strlen($cpfcnpj) === 14 && $empresa) {
            $empresaEscapada = htmlspecialchars($empresa, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $cnpjFormatado = formatarCpfCnpj($cpfcnpj);
            $cnpjEscapado = htmlspecialchars($cnpjFormatado, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $detalheEmpresaHtml = '<div style="margin: 24px 0; padding: 16px; border-radius: 6px; background-color: #fff5f2; border-left: 4px solid #ec4115;">'
                . '<p style="margin: 0; font-weight: bold; color: #ec4115;">Cadastro vinculado à empresa</p>'
                . '<p style="margin: 8px 0 0 0;">' . $empresaEscapada . '</p>'
                . '<p style="margin: 4px 0 0 0; color: #555;">CNPJ: ' . $cnpjEscapado . '</p>'
                . '</div>';
            $detalheEmpresaTexto = 'Empresa vinculada a este cadastro: ' . $empresa . ' (CNPJ: ' . $cnpjFormatado . ')';
            $saudacaoHtml = $nomeEscapado . ' - ' . $cnpjEscapado . ' - ' . $empresaEscapada;
            $saudacaoTexto = $nome . ' - ' . $cnpjFormatado . ' - ' . $empresa;
        }

        $html = str_replace(
            ['SAUDACAO_USUARIO', 'LINK_CONFIRMACAO', 'DETALHE_EMPRESA'],
            [$saudacaoHtml, $linkConfirmacao, $detalheEmpresaHtml],
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
        $mail->addEmbeddedImage(
            $baseDir . '/Imagens/Logotipo.png',
            'logo_ibr'
        );
        $mail->Subject = 'Confirmação de Cadastro - Configurador de Produtos IBR';
        $mail->isHTML(true);
        $mail->Body = $html;
      $altBody = "Olá, $saudacaoTexto,\n\nConfirme seu cadastro acessando: $linkConfirmacao";
        if ($detalheEmpresaTexto) {
            $altBody .= "\n\n" . $detalheEmpresaTexto;
        }
        $mail->AltBody = $altBody;

        $mail->send();
    } catch (Exception $e) {
                http_response_code(500);
        echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Erro ao enviar e-mail de confirmação.']);
        exit;
    }

    echo json_encode(['sucesso' => true, 'mensagem' => '✅ Verifique o e-mail ' . $email . ' para confirmar o cadastro.']);
    $pdo = null;
} catch (PDOException $e) {
    log_event('CadastrarUsuario: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Erro ao cadastrar usuário. Entre em contato com nossos Consultores Comerciais.']);
}
?>
