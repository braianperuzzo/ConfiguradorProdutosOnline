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

function formatar_documento(string $valor): string {
    $valor = preg_replace('/[^0-9A-Za-z]/', '', $valor);
    return strlen($valor) > 11 ? formatar_cnpj($valor) : formatar_cpf($valor);
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

$nome = filter_input(INPUT_POST, 'nome', FILTER_UNSAFE_RAW) ?? '';
$nome = mb_strtoupper(trim(strip_tags($nome)), 'UTF-8');
$emailNovo = strtolower(trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL) ?? ''));
$cpfcnpj = preg_replace('/[^0-9A-Za-z]/', '', filter_input(INPUT_POST, 'cpfcnpj', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '');
$senhaConfirmacao = trim((string) ($_POST['senha'] ?? ''));
$novidades = strtoupper(trim((string) ($_POST['novidades'] ?? 'NAO')));
if ($novidades !== 'SIM') {
    $novidades = 'NAO';
}
if ($senhaConfirmacao === '') {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Informe sua Senha para Confirmar as Alterações.']);
    exit;
}
$nomeAtual = mb_strtoupper(trim($dadosToken['nome'] ?? ''), 'UTF-8');
$emailAtual = strtolower(trim($dadosToken['email'] ?? ''));
$cpfAtual = preg_replace('/[^0-9A-Za-z]/', '', $dadosToken['cpfcnpj'] ?? '');
$novidadesAtual = strtoupper(trim((string) ($dadosToken['novidades'] ?? 'NAO')));
if ($novidadesAtual !== 'SIM') {
    $novidadesAtual = 'NAO';
}
require_once $baseDir . '/AcessosConsultas/BuscarNomeEmpresaReceitaCache.php';
$empresa = obter_nome_empresa($cpfcnpj, $baseDir);

if (strlen($cpfcnpj) === 14 && !$empresa) {
    http_response_code(404);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Não encontramos este CNPJ. Confira os dados e tente novamente.']);
    exit;
}

if (!check_rate_limit('editar_perfil', 5, 60, $emailAtual)) {
    http_response_code(429);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Quantidade de solicitações máximas atingidas. Tente novamente em 1 hora.']);
    exit;
}


if (!$nome || !$emailNovo || !$cpfcnpj || !filter_var($emailNovo, FILTER_VALIDATE_EMAIL) || strlen($emailNovo) > 255 || !in_array(strlen($cpfcnpj), [11,14])) {
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

    if ($emailNovo !== $emailAtual || $cpfcnpj !== $cpfAtual) {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM _USR_CONF_SITE_CADASTROS " .
            "WHERE LOWER(DS_EMAIL)=? AND NR_CPFCNPJ = ? " .
            "AND NOT (LOWER(DS_EMAIL)=? AND NR_CPFCNPJ = ?)"
        );
        $stmt->execute([$emailNovo, $cpfcnpj, $emailAtual, $cpfAtual]);
        if ($stmt->fetch()) {
            http_response_code(409);
            echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Já existe um cadastro para este e-mail com o mesmo CPF/CNPJ.']);
            exit;
        }
    }

    $stmtSenhaAtual = $pdo->prepare("SELECT DS_SENHA FROM _USR_CONF_SITE_CADASTROS WHERE LOWER(DS_EMAIL)=? AND NR_CPFCNPJ=?");
    $stmtSenhaAtual->execute([strtolower($emailAtual), $cpfAtual]);
    $senhaAtualHash = $stmtSenhaAtual->fetchColumn();
    if (!$senhaAtualHash) {
        $stmtSenhaAtual = $pdo->prepare("SELECT TOP 1 DS_SENHA FROM _USR_CONF_SITE_CADASTROS WHERE LOWER(DS_EMAIL)=?");
        $stmtSenhaAtual->execute([strtolower($emailAtual)]);
        $senhaAtualHash = $stmtSenhaAtual->fetchColumn();
    }
    if (!$senhaAtualHash || !password_verify($senhaConfirmacao, $senhaAtualHash)) {
         http_response_code(200);
        echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Alguma informação está errada. Tente novamente.']);
        exit;
    }
    
    $tokenNovo = bin2hex(random_bytes(32));
    $dirTokens = $baseDir . '/Tokens/TokensEmail';
    if (!is_dir($dirTokens)) {
        mkdir($dirTokens, 0700, true);
        file_put_contents($dirTokens . '/web.config', "<?xml version=\"1.0\" encoding=\"UTF-8\"?><configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>");
    }
 $tokenFile = $dirTokens . '/' . $tokenNovo . '.json';
    $documentosVinculados = [];
    $emailsVinculados = [];
    $adicionarDocumento = static function ($valor) use (&$documentosVinculados): void {
        $docLimpo = preg_replace('/[^0-9A-Za-z]/', '', (string)$valor);
        if ($docLimpo === '' || !in_array(strlen($docLimpo), [11, 14], true)) {
            return;
        }
        if (!in_array($docLimpo, $documentosVinculados, true)) {
            $documentosVinculados[] = $docLimpo;
        }
    };
    $adicionarEmail = static function ($valor) use (&$emailsVinculados): void {
        $emailLimpo = strtolower(trim((string) $valor));
        if ($emailLimpo === '') {
            return;
        }
        if (!in_array($emailLimpo, $emailsVinculados, true)) {
            $emailsVinculados[] = $emailLimpo;
        }
    };

    $adicionarDocumento($cpfAtual);
    $adicionarDocumento($cpfcnpj);
    $adicionarEmail($emailAtual);
    $adicionarEmail($emailNovo);

    if (!empty($documentosVinculados)) {
        $placeholdersDocs = implode(',', array_fill(0, count($documentosVinculados), '?'));
        $stmtEmailsRelacionados = $pdo->prepare(
            "SELECT DISTINCT LOWER(DS_EMAIL) AS DS_EMAIL FROM _USR_CONF_SITE_CADASTROS WHERE NR_CPFCNPJ IN ($placeholdersDocs)"
        );
        $stmtEmailsRelacionados->execute($documentosVinculados);
        foreach ($stmtEmailsRelacionados->fetchAll(PDO::FETCH_COLUMN) as $emailRelacionado) {
            $adicionarEmail($emailRelacionado);
        }
    }

    $dadosTokenFile = [
        'nome' => $nome,
        'cpfcnpj' => $cpfcnpj,
        'novoEmail' => $emailNovo,
        'emailAtual' => $emailAtual,
        'cpfcnpjAtual' => $cpfAtual,
        'documentosVinculados' => $documentosVinculados,
        'emailsVinculados' => $emailsVinculados,
        'novidades' => $novidades,
        'criadoEm' => time()
    ];
    file_put_contents($tokenFile, json_encode($dadosTokenFile));
    chmod($tokenFile, 0600);
    
    $alteracoes = [];
    if ($nome !== $nomeAtual) {
        $alteracoes[] = 'Nome: ' . $nomeAtual . ' -> ' . $nome;
    }
    if ($emailNovo !== $emailAtual) {
        $alteracoes[] = 'E-mail: ' . $emailAtual . ' -> ' . $emailNovo;
    }
    if ($cpfcnpj !== $cpfAtual) {
        $alteracoes[] = 'CPF/CNPJ: ' . formatar_documento($cpfAtual) . ' -> ' . formatar_documento($cpfcnpj);
    }
    if ($novidades !== $novidadesAtual) {
        $alteracoes[] = 'Aceite de novidades IBR: ' . $novidadesAtual . ' -> ' . $novidades;
    }
    $detalhesHtml = implode('<br>', array_map('htmlspecialchars', $alteracoes));
    $detalhesTxt = implode("\n", $alteracoes);

    $empresaAvisoHtml = '';
    $empresaAvisoTxt = '';
    $nomeEscapado = htmlspecialchars($nome, ENT_QUOTES, 'UTF-8');
    $saudacaoHtml = $nomeEscapado;
    $saudacaoTexto = $nome;
    if (strlen($cpfcnpj) === 14) {
        $cnpjFormatado = formatar_cnpj($cpfcnpj);
        $empresaMaiuscula = mb_strtoupper(trim((string) $empresa), 'UTF-8');
        $empresaEscapada = htmlspecialchars($empresaMaiuscula, ENT_QUOTES, 'UTF-8');
        $cnpjEscapado = htmlspecialchars($cnpjFormatado, ENT_QUOTES, 'UTF-8');
        $saudacaoHtml = implode(' - ', array_filter([
            $nomeEscapado,
            $cnpjEscapado,
            $empresaEscapada
        ], 'strlen'));
        $saudacaoTexto = implode(' - ', array_filter([
            $nome,
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
            [$saudacaoHtml, $linkConfirmacao, $detalhesHtml, $empresaAvisoHtml],
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
            $emailDestino = $emailAtual;
            $mail->addAddress($emailDestino);
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
            if ($detalhesTxt) {
                $altBody .= "\nSolicitações de Mudança:\n$detalhesTxt\n";
            } else {
                $altBody .= "\n";
            }
            $altBody .= "\nConfirme sua alteração acessando: $linkConfirmacao";
            $mail->AltBody = $altBody;
            $mail->send();
        } catch (Exception $e) {
            log_event('Erro ao enviar email de confirmacao para ' . $emailDestino . ': ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Erro ao enviar e-mail de confirmação.']);
            exit;
        }
        echo json_encode(['sucesso' => true, 'mensagem' => '✅ Verifique o e-mail ' . $emailDestino . ' para confirmar a alteração.']);
        $pdo = null;
} catch (Throwable $e) {
    log_event('SolicitarAtualizacao: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Erro ao atualizar dados.']);
}
?>
