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
$link = trim($payload['link'] ?? '');
$produtosIbr = isset($payload['produtosIbr']) && is_array($payload['produtosIbr']) ? $payload['produtosIbr'] : [];
$produtosIndicados = isset($payload['produtosIndicados']) && is_array($payload['produtosIndicados']) ? $payload['produtosIndicados'] : [];

if ($nome === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Dados inválidos para envio do email.']);
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

$comentarioHtml = '';
if ($comentario !== '') {
    $comentarioHtml = '<div style="margin-top: 24px; background-color: #fff5f1; border-left: 4px solid #ec4115; padding: 16px;">'
        . '<p style="margin: 0 0 8px 0; font-size: 14px; font-weight: bold; color:#ec4115;">Mensagem do Responsável pelo Envio:</p>'
        . '<p style="margin: 0; color: #444;">' . nl2br(htmlspecialchars($comentario, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>'
        . '</div>';
}

$listaItensHtml = [];
foreach ($produtosIbr as $produto) {
    if (!is_array($produto)) {
        continue;
    }

    $linha = htmlspecialchars(trim((string) ($produto['linha'] ?? 'Não informado')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $tamanho = htmlspecialchars(trim((string) ($produto['tamanho'] ?? 'Não informado')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $reducoes = htmlspecialchars(trim((string) ($produto['reducoes'] ?? 'Não informado')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $diametro = htmlspecialchars(trim((string) ($produto['diametro'] ?? 'Não informado')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $sobreProduto = htmlspecialchars(trim((string) ($produto['sobreProduto'] ?? 'Não informado')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $linkCatalogo = trim((string) ($produto['linkCatalogo'] ?? ''));
    $linkSite = trim((string) ($produto['linkSite'] ?? ''));
    $linkConfigurar = trim((string) ($produto['linkConfigurar'] ?? ''));

    $catalogoHtml = $linkCatalogo !== ''
        ? '<a href="' . htmlspecialchars($linkCatalogo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" style="color: #ec4115; text-decoration: none;">Acessar o Catálogo</a>'
        : 'Não disponível';
    $siteHtml = $linkSite !== ''
        ? '<a href="' . htmlspecialchars($linkSite, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" style="color: #ec4115; text-decoration: none;">Acessar o Site</a>'
        : 'Não disponível';
    $configurarHtml = $linkConfigurar !== ''
        ? '<a href="' . htmlspecialchars($linkConfigurar, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" style="color: #ec4115; text-decoration: none;">Configurar o Produto</a>'
        : 'Não disponível';

    $listaItensHtml[] = '<div style="padding: 12px 0;">'
        . '<p style="margin: 0 0 4px;"><strong>ℹ️ Linha:</strong> ' . $linha . '</p>'
        . '<p style="margin: 0 0 4px;"><strong>ℹ️ Tamanhos:</strong> ' . $tamanho . '</p>'
        . '<p style="margin: 0 0 4px;"><strong>ℹ️ Reduções:</strong> ' . $reducoes . '</p>'
        . '<p style="margin: 0 0 4px;"><strong>ℹ️ Diâmetro:</strong> ' . $diametro . '</p>'
        . '<p style="margin: 0 0 4px; text-align: justify;"><strong>ℹ️ Sobre o Produto:</strong> ' . $sobreProduto . '</p>'
        . '<p style="margin: 0 0 4px;"><strong>📝 Catálogo:</strong> ' . $catalogoHtml . '</p>'
        . '<p style="margin: 0 0 4px;"><strong>🌐 Site:</strong> ' . $siteHtml . '</p>'
        . '<p style="margin: 0;"><strong>📦 Configurador:</strong> ' . $configurarHtml . '</p>'
        . '</div>';
}

$listaHtml = !empty($listaItensHtml)
    ? implode('<hr style="margin: 12px 0; border: none; border-top: 2px solid #ec4115;">', $listaItensHtml)
    : '<p style="margin:0;color:#666;">Nenhuma informação adicional de produto IBR foi enviada.</p>';

$blocoLink = '';
if ($link !== '') {
    $linkSanitizado = htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $blocoLink = '<p style="text-align: center; margin: 32px 0;">'
        . '<a href="' . $linkSanitizado . '" style="background-color: #ec4115; color: #fff; font-weight: bold; padding: 14px 28px; border-radius: 6px; text-decoration: none; display: inline-block;">Acessar a Conversão</a>'
        . '</p>'
        . '<p style="text-align: center; font-size: 13px; color: #666;">Se o botão não funcionar, copie e cole este link no navegador:<br>'
        . '<a href="' . $linkSanitizado . '" style="color: #ec4115;">https://configurador.redutoresibr.com.br/Intercambialidade</a>'
        . '</p>';
} else {
    $blocoLink = '<p style="margin: 24px 0 0 0;">Acesse o configurador para conferir os dados da conversão de produto compartilhada.</p>';
}

$linhasTabela = [];
foreach (array_slice($produtosIndicados, 0, 5) as $produtoIndicado) {
    if (!is_array($produtoIndicado)) {
        continue;
    }
    $descricao = htmlspecialchars(trim((string) ($produtoIndicado['descricao'] ?? '--')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $codigo = htmlspecialchars(trim((string) ($produtoIndicado['codigo'] ?? '--')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $referencia = htmlspecialchars(trim((string) ($produtoIndicado['referencia'] ?? '--')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $linhasTabela[] = '<tr>'
        . '<td style="padding: 7px 6px; border: 1px solid #f0d6ce; word-break: break-word;">' . $descricao . '</td>'
        . '<td style="padding: 7px 6px; border: 1px solid #f0d6ce; word-break: break-word;">' . $codigo . '</td>'
        . '<td style="padding: 7px 6px; border: 1px solid #f0d6ce; word-break: break-word;">' . $referencia . '</td>'
        . '</tr>';
}

$tabelaProdutosIndicadosHtml = !empty($linhasTabela)
    ? '<div style="width:100%; overflow-x:auto;"><table role="presentation" style="width:100%; max-width:100%; table-layout:fixed; border-collapse: collapse; background:#fffaf8; border:1px solid #f0d6ce; border-radius:6px; overflow:hidden; font-size:12px;">'
        . '<thead>'
        . '<tr style="background:#fff0eb;">'
        . '<th style="width:54%; padding: 8px 6px; border: 1px solid #f0d6ce; text-align:left; color:#ec4115;">Descrição</th>'
        . '<th style="width:20%; padding: 8px 6px; border: 1px solid #f0d6ce; text-align:left; color:#ec4115;">Código</th>'
        . '<th style="width:26%; padding: 8px 6px; border: 1px solid #f0d6ce; text-align:left; color:#ec4115;">Referência</th>'
        . '</tr>'
        . '</thead>'
        . '<tbody>' . implode('', $linhasTabela) . '</tbody>'
        . '</table></div>'
    : '<p style="margin:0;color:#666;">Nenhum produto indicado disponível no momento.</p>';

$templatePath = __DIR__ . '/EmailCompartilharIntercambialidade.html';
$templateHtml = @file_get_contents($templatePath);

if ($templateHtml !== false) {
    $bodyHtml = str_replace(
        ['{{NOME_DESTINATARIO}}', '{{BLOCO_LINK}}', '{{BLOCO_COMENTARIO}}', '{{LISTA_INFORMACOES}}', '{{TABELA_PRODUTOS_INDICADOS}}'],
        [
            htmlspecialchars($nome, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $blocoLink,
            $comentarioHtml,
            $listaHtml,
            $tabelaProdutosIndicadosHtml
        ],
        $templateHtml
    );
    $bodyHtml = preg_replace('/\{\{[^}]+\}\}/', '', $bodyHtml);
} else {
    $bodyHtml = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#333;">' . $blocoLink . $comentarioHtml . $listaHtml . '</div>';
}

$bodyTexto = "Olá {$nome}\n\n";
$bodyTexto .= "Você recebeu uma conversão de produto compartilhada pelo Configurador de Produtos.\n\n";
if ($comentario !== '') {
    $bodyTexto .= "Mensagem do Responsável pelo Envio:\n{$comentario}\n\n";
}
if ($link !== '') {
    $bodyTexto .= "Link da Conversão: {$link}\n";
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
    $mail->setFrom($smtpUser, 'Não Responder - Comunicação - Redutores IBR');
    $mail->addAddress($email, $nome);
    $mail->addReplyTo($smtpUser, 'Não Responder - Comunicação - Redutores IBR');
    $mail->Subject = 'Compartilhamento de Conversão de Produto - Configurador de Produtos IBR';
    $mail->isHTML(true);
    $mail->Body = $bodyHtml;
    $mail->AltBody = $bodyTexto;

    $mail->send();
    echo json_encode(['sucesso' => true, 'mensagem' => '✅ Conversão de Produto Enviada por Email com Sucesso!']);
} catch (Exception $e) {
    log_event('Erro ao enviar email de conversão de produto.', [
        'erro' => $e->getMessage(),
        'email' => $email,
        'link' => $link
    ]);
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Não foi possível enviar o email. Tente novamente.']);
}
