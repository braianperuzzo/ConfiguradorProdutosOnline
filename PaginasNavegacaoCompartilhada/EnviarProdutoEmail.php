<?php
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Método não permitido.']);
    exit;
}

$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$nome = trim($payload['nome'] ?? '');
$email = trim($payload['email'] ?? '');
$comentario = trim($payload['comentario'] ?? '');
$codigo = trim($payload['codigo'] ?? '');
$descricao = trim($payload['descricao'] ?? '');
$referencia = trim($payload['referencia'] ?? '');
$informacoes = trim($payload['informacoesTecnicas'] ?? '');
$link = trim($payload['link'] ?? '');
$produtoExisteRaw = $payload['produtoExiste'] ?? true;
$produtoExiste = filter_var($produtoExisteRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
if ($produtoExiste === null) {
    $produtoExiste = true;
}
$mensagemNaoCadastrado = trim($payload['mensagemProdutoNaoCadastrado'] ?? '');
$mensagemPadraoNaoCadastrado = "😕 Ops! Este produto ainda não está cadastrado em nossa base.\n\n📞 Fale com um de nossos representantes comerciais para realizar o cadastro e obter mais informações.\n\n💬 Estamos à disposição para te ajudar!";
if (!$produtoExiste && $mensagemNaoCadastrado === '') {
    $mensagemNaoCadastrado = $mensagemPadraoNaoCadastrado;
}

if ($nome === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Dados inválidos.']);
    exit;
}

$baseDir = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
if ($baseDir === '') {
    $baseDir = dirname(__DIR__);
}
require $baseDir . '/PHPMailer/src/PHPMailer.php';
require $baseDir . '/PHPMailer/src/SMTP.php';
require $baseDir . '/PHPMailer/src/Exception.php';
require $baseDir . '/AcessosConsultas/CredenciaisAcessoEmail.php';
require $baseDir . '/LogsErros/Logs.php';

if (!function_exists('montarListaInformacoesCompartilharProduto')) {
    function montarListaInformacoesCompartilharProduto(array $dados): array
    {
        $itens = [];

        if ($dados['codigo'] !== '') {
            $itens[] = '🔢 <strong>Código:</strong> ' . htmlspecialchars($dados['codigo'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        if ($dados['referencia'] !== '') {
            $itens[] = '🏷️ <strong>Referência:</strong> ' . htmlspecialchars($dados['referencia'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        if ($dados['produtoExiste'] && $dados['descricao'] !== '') {
            $itens[] = '📝 <strong>Descrição:</strong><br>' . nl2br(htmlspecialchars($dados['descricao'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }
        if ($dados['produtoExiste'] && $dados['informacoes'] !== '') {
            $itens[] = '📄 <strong>Datasheet:</strong><br>' . nl2br(htmlspecialchars($dados['informacoes'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }
        if (!$dados['produtoExiste'] && $dados['mensagemNaoCadastrado'] !== '') {
            $itens[] = 'ℹ️ <strong>Informações:</strong><br>' . nl2br(htmlspecialchars($dados['mensagemNaoCadastrado'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }

        return $itens;
    }
}

$dadosProduto = [
    'codigo' => $produtoExiste ? $codigo : '',
    'referencia' => $referencia,
    'descricao' => $produtoExiste ? $descricao : '',
    'informacoes' => $produtoExiste ? $informacoes : '',
    'produtoExiste' => $produtoExiste,
    'mensagemNaoCadastrado' => !$produtoExiste ? $mensagemNaoCadastrado : '',
];

$listaInformacoes = montarListaInformacoesCompartilharProduto($dadosProduto);

$listaHtml = '';
if ($listaInformacoes) {
    $listaHtml = '<ul style="list-style-type: none; padding-left: 0; margin: 0;">';
    foreach ($listaInformacoes as $item) {
        $listaHtml .= '<li style="margin-bottom: 6px;">' . $item . '</li>';
    }
    $listaHtml .= '</ul>';
} else {
    $listaHtml = '<p style="margin: 0 0 12px 0;">Os detalhes do produto não estão disponíveis no momento.</p>';
}

$comentarioHtml = '';
if ($comentario !== '') {
    $comentarioHtml = '<div style="margin-top: 24px; background-color: #fff5f1; border-left: 4px solid #ec4115; padding: 16px;">'
        . '<p style="margin: 0 0 8px 0; font-size: 14px; font-weight: bold; color:#ec4115;">Mensagem do Responsável pelo Envio:</p>'
        . '<p style="margin: 0; color: #444;">' . nl2br(htmlspecialchars($comentario, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>'
        . '</div>';
}

$linkSanitizado = htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$templatePath = __DIR__ . '/EmailCompartilharProduto.html';
$templateHtml = @file_get_contents($templatePath);

if ($templateHtml !== false) {
    $bodyHtml = str_replace(
        ['{{NOME_DESTINATARIO}}', '{{BLOCO_COMENTARIO}}', '{{LISTA_INFORMACOES}}', '{{LINK_PRODUTO}}'],
        [
            htmlspecialchars($nome, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $comentarioHtml,
            $listaHtml,
            $linkSanitizado
        ],
        $templateHtml
    );
    $bodyHtml = preg_replace('/\{\{[^}]+\}\}/', '', $bodyHtml);
} else {
    $bodyHtml = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#333;">' . $comentarioHtml . $listaHtml . '</div>';
}

$bodyTexto = "Olá {$nome}\n\n";
$bodyTexto .= "Você recebeu um produto compartilhado pelo Configurador de Produtos.\n\n";
$comentarioHtml = '';
if ($comentario !== '') {
    $comentarioHtml = '<div style="margin-top: 24px; background-color: #fff5f1; border-left: 4px solid #ec4115; padding: 16px;">'
        . '<p style="margin: 0 0 8px 0; font-size: 14px; font-weight: bold; color:#ec4115;">Mensagem do Responsável pelo Envio:</p>'
        . '<p style="margin: 0; color: #444;">' . nl2br(htmlspecialchars($comentario, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>'
        . '</div>';
}
$bodyTexto .= "Detalhes do Produto:\n";
if ($produtoExiste && $codigo !== '') {
    $bodyTexto .= "- Código: {$codigo}\n";
}
if ($referencia !== '') {
    $bodyTexto .= "- Referência: {$referencia}\n";
}
if ($produtoExiste && $descricao !== '') {
    $bodyTexto .= "- Descrição: {$descricao}\n";
}
if ($produtoExiste && $informacoes !== '') {
    $bodyTexto .= "- Datasheet:\n{$informacoes}\n";
}
if (!$produtoExiste && $mensagemNaoCadastrado !== '') {
    $bodyTexto .= "{$mensagemNaoCadastrado}\n\n";
}
if ($link !== '') {
    $bodyTexto .= "- Link do Produto: {$link}\n";
}
$bodyTexto .= "\nRedutores IBR - Soluções em Movimento que Transformam o Tempo.";

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
    $mail->setFrom($smtpUser, 'Não Responder - Configurador Redutores IBR');
    $mail->addAddress($email, $nome);
    $mail->addReplyTo($smtpUser, 'Não Responder - Configurador Redutores IBR');

    $mail->Subject = 'Compartilhamento de Produto - Configurador Redutores IBR';
    $mail->isHTML(true);
    $mail->Body = $bodyHtml;
    $mail->AltBody = $bodyTexto;

    $mail->send();
    echo json_encode(['sucesso' => true]);
} catch (Exception $e) {
    log_event('Erro ao enviar email de compartilhamento de produto.', [
        'erro' => $e->getMessage(),
        'email' => $email,
        'codigo' => $codigo,
        'referencia' => $referencia,
    ]);
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Não foi possível enviar o email. Tente novamente.']);
}
