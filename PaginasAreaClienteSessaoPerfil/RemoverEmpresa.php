<?php
ini_set('display_errors', 0);
error_reporting(0);
$baseDir = $_SERVER['DOCUMENT_ROOT'] ?: dirname(__DIR__);
require $baseDir . '/Seguranca/MetodosSegurancao.php';
require_once $baseDir . '/LogsErros/Logs.php';
require_once $baseDir . '/TokensGeradores/TokenPadraoJson.php';
require_once $baseDir . '/TokensGeradores/TokenInvalidacao.php';
require_once $baseDir . '/TokensGeradores/TokensLimiteSolicitacoes.php';
require_once $baseDir . '/Seguranca/CSRF.php';
require_once $baseDir . '/AcessosConsultas/BuscarNomeEmpresaReceitaCache.php';
require_once $baseDir . '/PHPMailer/src/PHPMailer.php';
require_once $baseDir . '/PHPMailer/src/SMTP.php';
require_once $baseDir . '/PHPMailer/src/Exception.php';
require_once $baseDir . '/AcessosConsultas/CredenciaisAcessoEmail.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function formatar_documento_cnpj(string $valor): string
{
    $numeros = preg_replace('/[^0-9A-Za-z]/', '', $valor);
    if (strlen($numeros) !== 14) {
        return $numeros;
    }
    return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $numeros) ?: $numeros;
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

$emailUsuario = strtolower(trim($dadosToken['email'] ?? ''));
if ($emailUsuario === '') {
    http_response_code(400);
    echo json_encode(['erro' => '⚠️ Não foi possível identificar o usuário.']);
    exit;
}

if (!check_rate_limit('remover_empresa', 5, 60, $emailUsuario)) {
    http_response_code(429);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Quantidade de solicitações máximas atingidas. Tente novamente em 1 hora.']);
    exit;
}

$documentoRemover = preg_replace('/[^0-9A-Za-z]/', '', filter_input(INPUT_POST, 'documento', FILTER_UNSAFE_RAW) ?? '');
if (strlen($documentoRemover) !== 14) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Informe um CNPJ válido para remover.']);
    exit;
}

require $baseDir . '/AcessosConsultas/CredenciaisBancoDados.php';

try {
    $pdo = new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
    ]);

    $stmtDocs = $pdo->prepare('SELECT NR_CPFCNPJ FROM _USR_CONF_SITE_CADASTROS WHERE LOWER(DS_EMAIL) = ?');
    $stmtDocs->execute([$emailUsuario]);
    $documentosBrutos = $stmtDocs->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $documentosNormalizados = [];
    foreach ($documentosBrutos as $doc) {
        $docLimpo = preg_replace('/[^0-9A-Za-z]/', '', (string)$doc);
        if ($docLimpo !== '') {
            $documentosNormalizados[] = $docLimpo;
        }
    }

    if (!in_array($documentoRemover, $documentosNormalizados, true)) {
        http_response_code(404);
        echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Empresa não encontrada no seu cadastro.']);
        exit;
    }

    if (count($documentosNormalizados) <= 1) {
        http_response_code(400);
        echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Não é possível remover a única empresa vinculada. Utilize a exclusão completa da conta.']);
        exit;
    }

    $nomeUsuario = mb_strtoupper(trim($dadosToken['nome'] ?? ''), 'UTF-8');
    $cpfcnpjUsuario = preg_replace('/[^0-9A-Za-z]/', '', (string)($dadosToken['cpfcnpj'] ?? ''));
    $grupoUsuario = $dadosToken['grupo'] ?? '';
    $codigoUsuario = $dadosToken['codigo'] ?? '';
    $empresaNome = obter_nome_empresa($documentoRemover, $baseDir);
    if ($empresaNome === '') {
        $empresaNome = $documentoRemover;
    }

    $tokenNovo = bin2hex(random_bytes(32));
    $dirTokens = $baseDir . '/Tokens/TokensExclusao';
    if (!is_dir($dirTokens)) {
        mkdir($dirTokens, 0700, true);
        if (!file_exists($dirTokens . '/web.config')) {
            file_put_contents($dirTokens . '/web.config', "<?xml version=\"1.0\" encoding=\"UTF-8\"?><configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>");
        }
    }

$tokenFile = $dirTokens . '/' . $tokenNovo . '.json';
    $dadosTokenExclusao = [
        'email' => $emailUsuario,
        'escopo' => 'empresa',
        'documento' => $documentoRemover,
        'criadoEm' => time()
    ];
    if (file_put_contents($tokenFile, json_encode($dadosTokenExclusao)) === false) {
        http_response_code(500);
        echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Não foi possível gerar a solicitação de remoção.']);
        exit;
    }
    @chmod($tokenFile, 0600);

    $linkConfirmacao = 'https://configurador.redutoresibr.com.br/PaginasAreaClienteSessaoPerfil/ExcluirConta.php?token=' . $tokenNovo;
    $template = @file_get_contents(__DIR__ . '/EmailRemocaoEmpresa.html');
    if ($template === false) {
        if (file_exists($tokenFile)) {
            unlink($tokenFile);
        }
        http_response_code(500);
        echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Não foi possível preparar o e-mail de confirmação.']);
        exit;
    }
    $nomeSaudacao = $nomeUsuario ?: $emailUsuario;
    $html = str_replace(
        ['NOME_USUARIO', 'LINK_CONFIRMACAO', 'NOME_EMPRESA', 'DOCUMENTO_EMPRESA'],
        [$nomeSaudacao, $linkConfirmacao, $empresaNome, formatar_documento_cnpj($documentoRemover)],
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
        $mail->addAddress($emailUsuario);
        $mail->addEmbeddedImage(
            $baseDir . '/Imagens/Logotipo.png',
            'logo_ibr'
        );
        $mail->Subject = 'Confirmação de Remoção de Empresa - Configurador de Produtos IBR';
        $mail->isHTML(true);
        $mail->Body = $html;
        $mail->AltBody = "Olá {$nomeSaudacao},\n\nConfirme a remoção da empresa acessando: {$linkConfirmacao}";
        $mail->send();
    } catch (Exception $e) {
        if (file_exists($tokenFile)) {
            unlink($tokenFile);
        }
        http_response_code(500);
        echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Erro ao enviar e-mail de confirmação.']);
        exit;
    }

    echo json_encode([
        'sucesso' => true,
        'mensagem' => '✅ Verifique o e-mail ' . $emailUsuario . ' para confirmar a remoção da empresa.',
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Erro ao remover a empresa.']);
}