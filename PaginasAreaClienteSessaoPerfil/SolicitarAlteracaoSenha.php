<?php
ini_set('display_errors', 0);
error_reporting(0);
$baseDir = $_SERVER['DOCUMENT_ROOT'] ?: dirname(__DIR__);
require $baseDir . '/Seguranca/MetodosSegurancao.php';
require_once $baseDir . '/LogsErros/Logs.php';
require_once $baseDir . '/TokensGeradores/TokenPadraoJson.php';
require_once $baseDir . '/TokensGeradores/TokensLimiteSolicitacoes.php';
require_once $baseDir . '/TokensGeradores/TokenInvalidacao.php';
require_once $baseDir . '/Seguranca/CSRF.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function formatar_cpf(string $valor): string {
    $valor = preg_replace('/[^0-9A-Za-z]/', '', $valor);
    $valor = substr($valor, 0, 11);
    $valor = preg_replace('/(\d{3})(\d)/', '$1.$2', $valor, 1);
    $valor = preg_replace('/(\d{3})(\d)/', '$1.$2', $valor, 1);
    $valor = preg_replace('/(\d{3})(\d{1,2})$/', '$1-$2', $valor);
    return $valor;
}

function formatar_cnpj(string $valor): string {
    $valor = preg_replace('/[^0-9A-Za-z]/', '', $valor);
    $valor = substr($valor, 0, 14);
    $valor = preg_replace('/^(\d{2})(\d)/', '$1.$2', $valor);
    $valor = preg_replace('/^(\d{2})\.(\d{3})(\d)/', '$1.$2.$3', $valor);
    $valor = preg_replace('/\.(\d{3})(\d)/', '.$1/$2', $valor);
    $valor = preg_replace('/(\d{4})(\d)/', '$1-$2', $valor);
    return $valor;
}

header('Content-Type: application/json; charset=UTF-8');
require_valid_csrf_token();

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

$dadosToken = JWTHelper::decode($token, $segredo);
if (!$dadosToken || is_token_blacklisted($token)) {
    http_response_code(403);
    echo json_encode(['erro' => '⚠️ Credenciais inválidas.']);
    exit;
}

$nomeAtual = mb_strtoupper(trim($dadosToken['nome'] ?? ''), 'UTF-8');
$emailAtual = strtolower(trim($dadosToken['email'] ?? ''));
$cpfAtual = preg_replace('/[^0-9A-Za-z]/', '', $dadosToken['cpfcnpj'] ?? '');

$senhaAtual = trim((string) ($_POST['senhaAtual'] ?? ''));
$novaSenha = (string) ($_POST['novaSenha'] ?? '');
$confirmacaoNovaSenha = (string) ($_POST['confirmacaoNovaSenha'] ?? '');

if ($senhaAtual === '' || $novaSenha === '' || $confirmacaoNovaSenha === '') {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Preencha todos os campos.']);
    exit;
}

if ($novaSenha !== $confirmacaoNovaSenha) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ As senhas não conferem.']);
    exit;
}

if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^\w\s]).{8,}$/', $novaSenha)) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Senha fora do padrão de segurança.']);
    exit;
}

if (!$emailAtual) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Não foi possível identificar o usuário.']);
    exit;
}

if (!check_rate_limit('alterar_senha', 5, 60, $emailAtual)) {
    http_response_code(429);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Quantidade de solicitações máximas atingidas. Tente novamente em 1 hora.']);
    exit;
}

require_once $baseDir . '/AcessosConsultas/CredenciaisBancoDados.php';
require_once $baseDir . '/AcessosConsultas/BuscarNomeEmpresaReceitaCache.php';

try {
    $pdo = new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
    ]);

    $stmtSenha = $pdo->prepare("SELECT DS_SENHA FROM _USR_CONF_SITE_CADASTROS WHERE LOWER(DS_EMAIL)=? AND NR_CPFCNPJ=?");
    $stmtSenha->execute([strtolower($emailAtual), $cpfAtual]);
    $senhaHash = $stmtSenha->fetchColumn();
    if (!$senhaHash) {
        $stmtSenha = $pdo->prepare("SELECT TOP 1 DS_SENHA FROM _USR_CONF_SITE_CADASTROS WHERE LOWER(DS_EMAIL)=?");
        $stmtSenha->execute([strtolower($emailAtual)]);
        $senhaHash = $stmtSenha->fetchColumn();
    }

    if (!$senhaHash || !password_verify($senhaAtual, $senhaHash)) {
        http_response_code(200);
        echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Alguma informação está errada. Tente novamente.']);
        exit;
    }

    if (password_verify($novaSenha, $senhaHash)) {
        http_response_code(400);
        echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Senha não aceita. Escolha uma nova senha.']);
        exit;
    }

    $tokenNovo = bin2hex(random_bytes(32));
    $dirTokens = $baseDir . '/Tokens/TokensEmail';
    if (!is_dir($dirTokens)) {
        mkdir($dirTokens, 0700, true);
        file_put_contents($dirTokens . '/web.config', "<?xml version=\"1.0\" encoding=\"UTF-8\"?><configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>");
    }

    $documentosVinculados = [];
    $adicionarDocumento = static function ($valor) use (&$documentosVinculados): void {
        $docLimpo = preg_replace('/[^0-9A-Za-z]/', '', (string) $valor);
        if ($docLimpo === '' || !in_array(strlen($docLimpo), [11, 14], true)) {
            return;
        }
        if (!in_array($docLimpo, $documentosVinculados, true)) {
            $documentosVinculados[] = $docLimpo;
        }
    };

    $stmtDocumentos = $pdo->prepare("SELECT NR_CPFCNPJ FROM _USR_CONF_SITE_CADASTROS WHERE LOWER(DS_EMAIL) = ?");
    $stmtDocumentos->execute([strtolower($emailAtual)]);
    while (($docLinha = $stmtDocumentos->fetchColumn()) !== false) {
        $adicionarDocumento($docLinha);
    }
    $adicionarDocumento($cpfAtual);

    $dadosTokenFile = [
        'nome' => $nomeAtual,
        'cpfcnpj' => $cpfAtual,
        'novoEmail' => $emailAtual,
        'emailAtual' => $emailAtual,
        'cpfcnpjAtual' => $cpfAtual,
        'documentosVinculados' => $documentosVinculados,
        'criadoEm' => time(),
        'senha' => password_hash($novaSenha, PASSWORD_BCRYPT, ['cost' => 10])
    ];

    $tokenFile = $dirTokens . '/' . $tokenNovo . '.json';
    file_put_contents($tokenFile, json_encode($dadosTokenFile));
    chmod($tokenFile, 0600);

    $detalhesHtml = 'Senha: **** -> Senha: ****';
    $detalhesTxt = 'Senha: **** -> Senha: ****';

    $empresaAvisoHtml = '';
    $empresaAvisoTxt = '';
    $empresaNome = obter_nome_empresa($cpfAtual, $baseDir);
    $nomeEscapado = htmlspecialchars($nomeAtual, ENT_QUOTES, 'UTF-8');
    $saudacaoHtml = $nomeEscapado;
    $saudacaoTexto = $nomeAtual;
    if (strlen($cpfAtual) === 14) {
        $cnpjFormatado = formatar_cnpj($cpfAtual);
        $empresaMaiuscula = mb_strtoupper(trim((string) $empresaNome), 'UTF-8');
        $empresaEscapada = htmlspecialchars($empresaMaiuscula, ENT_QUOTES, 'UTF-8');
        $cnpjEscapado = htmlspecialchars($cnpjFormatado, ENT_QUOTES, 'UTF-8');
        $saudacaoHtml = implode(' - ', array_filter([
            $nomeEscapado,
            $cnpjEscapado,
            $empresaEscapada
        ], 'strlen'));
        $saudacaoTexto = implode(' - ', array_filter([
            $nomeAtual,
            $cnpjFormatado,
            $empresaMaiuscula
        ], static function ($parte): bool {
            return trim((string) $parte) !== '';
        }));
        $empresaAvisoHtml = <<<HTML
        <div style="text-align: center; margin: 32px 0;">
            <div
                style="display: inline-block; padding: 16px 32px; font-size: 18px; font-weight: bold; background-color: #ec4115; color: #fff; border-radius: 8px;">
                Atualização vinculada à empresa {$empresaEscapada}
            </div>
        </div>
HTML;
        $empresaAvisoTxt = 'Empresa vinculada: ' . $empresaMaiuscula;
    }

    require $baseDir . '/PHPMailer/src/PHPMailer.php';
    require $baseDir . '/PHPMailer/src/SMTP.php';
    require $baseDir . '/PHPMailer/src/Exception.php';
    require $baseDir . '/AcessosConsultas/CredenciaisAcessoEmail.php';

    $linkConfirmacao = 'https://configurador.redutoresibr.com.br/AreaCliente/Sessao/EditarPerfil/EditarPerfil.php?token=' . $tokenNovo;
    $template = file_get_contents(__DIR__ . '/EmailConfirmacao.html');
    $html = str_replace(
        ['SAUDACAO_USUARIO', 'LINK_CONFIRMACAO', 'DETALHES_MUDANCA', 'EMPRESA_AVISO'],
        [$saudacaoHtml, $linkConfirmacao, htmlspecialchars($detalhesHtml, ENT_QUOTES, 'UTF-8'), $empresaAvisoHtml],
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
        $mail->addAddress($emailAtual);
        $mail->addEmbeddedImage(
            $baseDir . '/Imagens/Logotipo.png',
            'logo_ibr'
        );
        $mail->Subject = 'Confirmação de Atualização de Dados - Configurador de Produtos IBR';
        $mail->isHTML(true);
        $mail->Body = $html;
        $altBody = "Olá, $saudacaoTexto,\n";
        if ($empresaAvisoTxt !== '') {
            $altBody .= "\n$empresaAvisoTxt\n";
        }
        $altBody .= "\nSolicitações de Mudança:\n$detalhesTxt\n";
        $altBody .= "\nConfirme sua alteração acessando: $linkConfirmacao";
        $mail->AltBody = $altBody;
        $mail->send();
    } catch (Exception $e) {
        log_event('Erro ao enviar email de alteracao de senha para ' . $emailAtual . ': ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Erro ao enviar e-mail de confirmação.']);
        exit;
    }

    echo json_encode(['sucesso' => true, 'mensagem' => '✅ Verifique o e-mail ' . $emailAtual . ' para confirmar a alteração.']);
    $pdo = null;
} catch (Throwable $e) {
    log_event('SolicitarAlteracaoSenha: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Erro ao solicitar alteração de senha.']);
}