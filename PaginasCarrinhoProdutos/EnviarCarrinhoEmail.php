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
$arquivoBase64 = $payload['arquivo'] ?? '';
$nomeArquivo = trim($payload['nomeArquivo'] ?? 'Redutores IBR.html');

if ($nome === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $arquivoBase64 === '') {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Dados inválidos.']);
    exit;
}

$conteudoArquivo = base64_decode($arquivoBase64, true);
if ($conteudoArquivo === false) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Arquivo inválido.']);
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

function respostaSmtpIndicaSucesso($texto)
{
    if (!is_string($texto)) {
        return false;
    }

    $texto = trim($texto);
    if ($texto === '') {
        return false;
    }

    $textoNormalizado = function_exists('mb_strtolower')
        ? mb_strtolower($texto, 'UTF-8')
        : strtolower($texto);
    $indicadoresFortes = [
        'queued as',
        'queued for delivery',
        'queued mail for delivery',
        'queued for sending',
        'queued for processing',
        'queued id',
        'message queued',
        'accepted for delivery',
        'message accepted',
        'message accepted for delivery',
        'message received',
        'delivery ok',
        'mensagem aceita',
        'mensagem aceita para entrega',
        'mensagem aceita para envio',
        'mensagem enfileirada',
        'mensagem recebida',
        'enfileirada para envio',
        'enfileirada para processamento',
        'aceita para entrega',
        'aceito para entrega',
        'aceita para envio',
        'aceito para envio',
        'ok queued',
        'ok: queued',
        'ok queued as',
        'requested mail action okay',
        'smtp error: data not accepted',
    ];

    foreach ($indicadoresFortes as $indicador) {
        if ($indicador === '') {
            continue;
        }

        if (strpos($textoNormalizado, $indicador) === false) {
            continue;
        }

        $negacoes = ['not ' . $indicador, 'nao ' . $indicador, 'não ' . $indicador];
        $temNegacao = false;
        foreach ($negacoes as $negacao) {
            if (strpos($textoNormalizado, $negacao) !== false) {
                $temNegacao = true;
                break;
            }
        }

        if ($temNegacao) {
            continue;
        }

        return true;
    }

    $temCodigo2xx = (bool) preg_match('/\b2\d{2}\b/', $textoNormalizado);
    if ($temCodigo2xx) {
        $contextosPositivos = ['2.0.0', '2.5.0', '2.6.0', '2.7.0', 'accepted', 'accept', 'aceit', 'ok', 'queued', 'enfileirad'];
        $temContextoPositivo = false;
        foreach ($contextosPositivos as $contexto) {
            if ($contexto !== '' && strpos($textoNormalizado, $contexto) !== false) {
                $temContextoPositivo = true;
                break;
            }
        }

        if ($temContextoPositivo) {
            $negacoesGerais = ['/\bnot\s+2\d{2}\b/', '/\bnao\s+2\d{2}\b/', '/\bnão\s+2\d{2}\b/'];
            foreach ($negacoesGerais as $negacaoRegex) {
                if (preg_match($negacaoRegex, $textoNormalizado)) {
                    $temContextoPositivo = false;
                    break;
                }
            }

            if ($temContextoPositivo) {
                if (strpos($textoNormalizado, 'queued') !== false && preg_match('/\b(?:not|nao|não)\s+queued\b/', $textoNormalizado)) {
                    $temContextoPositivo = false;
                }

                if ($temContextoPositivo && strpos($textoNormalizado, 'enfileirad') !== false
                    && preg_match('/\b(?:nao|não)\s+enfileirad/', $textoNormalizado)
                ) {
                    $temContextoPositivo = false;
                }

                if ($temContextoPositivo && strpos($textoNormalizado, ' ok') !== false
                    && preg_match('/\b(?:not|nao|não)\s+ok\b/', $textoNormalizado)
                ) {
                    $temContextoPositivo = false;
                }

                if ($temContextoPositivo) {
                    $frasesResposta = [
                        'server response',
                        'server responded',
                        'server replied',
                        'resposta do servidor',
                        'resposta smtp',
                        'resposta:',
                        'respondeu',
                        'status code',
                    ];

                    foreach ($frasesResposta as $frase) {
                        if ($frase !== '' && strpos($textoNormalizado, $frase) !== false) {
                            return true;
                        }
                    }

                    if (strpos($textoNormalizado, '2.0.0') !== false || strpos($textoNormalizado, '2.6.0') !== false) {
                        return true;
                    }
                }
            }
        }
    }

    return false;
}

$comentarioHtml = '';
if ($comentario !== '') {
    $comentarioHtml = '<div style="margin-top: 24px; background-color: #fff5f1; border-left: 4px solid #ec4115; padding: 16px;">'
        . '<p style="margin: 0 0 8px 0; font-size: 14px; font-weight: bold; color:#ec4115;">Mensagem do Responsável pelo Envio:</p>'
        . '<p style="margin: 0; color: #444;">' . nl2br(htmlspecialchars($comentario, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>'
        . '</div>';
}

$listaItens = [];
$nomeArquivoFinal = $nomeArquivo !== '' ? $nomeArquivo : 'Redutores IBR.html';

$listaItens[] = '📎 <strong>Arquivo Anexo:</strong> ' . htmlspecialchars($nomeArquivoFinal, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$listaHtml = '<ul style="list-style-type: none; padding-left: 0; margin: 0;">';
foreach ($listaItens as $item) {
    $listaHtml .= '<li style="margin-bottom: 8px;">' . $item . '</li>';
}
$listaHtml .= '</ul>';

$blocoLink = '';
if ($link !== '') {
    $linkSanitizado = htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $blocoLink = '<p style="text-align: center; margin: 32px 0;">'
        . '<a href="' . $linkSanitizado . '" style="background-color: #ec4115; color: #fff; font-weight: bold; padding: 14px 28px; border-radius: 6px; text-decoration: none; display: inline-block;">Acessar o Carrinho</a>'
        . '</p>'
        . '<p style="text-align: center; font-size: 13px; color: #666;">Se o botão não funcionar, copie e cole este link no navegador:<br>'
        . '<a href="' . $linkSanitizado . '" style="color: #ec4115;">' . $linkSanitizado . '</a>'
        . '</p>';
} else {
    $blocoLink = '<p style="margin: 24px 0 0 0;">Abra o arquivo em anexo para conferir todos os itens do carrinho compartilhado.</p>';
}

$templatePath = __DIR__ . '/EmailCompartilharCarrinho.html';
$templateHtml = @file_get_contents($templatePath);

if ($templateHtml !== false) {
    $bodyHtml = str_replace(
        ['{{NOME_DESTINATARIO}}', '{{BLOCO_LINK}}', '{{BLOCO_COMENTARIO}}', '{{LISTA_INFORMACOES}}'],
        [
            htmlspecialchars($nome, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $blocoLink,
            $comentarioHtml,
            $listaHtml
        ],
        $templateHtml
    );
    $bodyHtml = preg_replace('/\{\{[^}]+\}\}/', '', $bodyHtml);
} else {
    $bodyHtml = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#333;">' . $blocoLink . $comentarioHtml . $listaHtml . '</div>';
}

$bodyTexto = "Olá {$nome}\n\n";
$bodyTexto .= "Você recebeu um carrinho compartilhado pelo Configurador de Produtos.\n\n";
if ($comentario !== '') {
    $bodyTexto .= "Mensagem do Responsável pelo Envio:\n{$comentario}\n\n";
}
else {
    $bodyTexto .= "Para conferir os itens, utilize o arquivo em anexo.\n";
}
$bodyTexto .= "Arquivo anexo: {$nomeArquivoFinal}\n";
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
    $mail->Subject = 'Compartilhamento de Carrinho - Configurador Redutores IBR';
    $mail->isHTML(true);
    $mail->Body = $bodyHtml;
    $mail->AltBody = $bodyTexto;
    $mail->addStringAttachment($conteudoArquivo, $nomeArquivoFinal, 'base64', 'text/html; charset=UTF-8');

  $mail->send();
    http_response_code(200);
    echo json_encode([
        'sucesso' => true,
        'mensagem' => '✅ Carrinho de Produtos Enviado por Email com Sucesso!'
    ]);
    exit;
} catch (Exception $e) {
    $transactionId = null;
    $ultimaRespostaSmtp = '';
    if (isset($mail) && method_exists($mail, 'getSMTPInstance')) {
        try {
            $smtpInstance = $mail->getSMTPInstance();
            if ($smtpInstance && method_exists($smtpInstance, 'getLastTransactionID')) {
                $transactionId = $smtpInstance->getLastTransactionID();
            }
                   if ($smtpInstance && method_exists($smtpInstance, 'getLastReply')) {
                $ultimaRespostaSmtp = (string) $smtpInstance->getLastReply();
            }
        } catch (\Throwable $inner) {
            log_event('Erro ao verificar status do envio do carrinho.', [
                'erro' => $inner->getMessage(),
                'transaction_id' => $transactionId ?? '',
            ]);
        }
    }

    if (!empty($transactionId)) {
        log_event('Email de compartilhamento de carrinho enviado com alerta.', [
            'transaction_id' => $transactionId ?? '',
            'erro' => $e->getMessage(),
        ]);
        http_response_code(200);
        echo json_encode([
            'sucesso' => true,
            'mensagem' => '✅ Email enviado, mas não foi possível confirmar o status automaticamente. (ID: ' . $transactionId . ')'
        ]);
        exit;
    }

    $errorInfo = '';
    if (isset($mail) && property_exists($mail, 'ErrorInfo')) {
        $errorInfo = (string) $mail->ErrorInfo;
    }

    $mensagemErro = $e->getMessage();
    $textoParaAnalise = trim(($mensagemErro ?? '') . ' ' . $errorInfo . ' ' . $ultimaRespostaSmtp);

    if (respostaSmtpIndicaSucesso($textoParaAnalise)) {
        $detalheServidor = $textoParaAnalise !== ''
            ? preg_replace('/\s+/', ' ', $textoParaAnalise)
            : '';

        log_event('Email de compartilhamento de carrinho enviado com alerta tratado.', [
            'detalhes' => $detalheServidor,
            'transaction_id' => $transactionId ?? '',
        ]);
        http_response_code(200);
        echo json_encode([
            'sucesso' => true,
            'mensagem' => $detalheServidor !== ''
                ? '✅ Carrinho de Produtos enviado por email. Resposta do servidor: ' . $detalheServidor
                : '✅ Carrinho de Produtos enviado por email, mas não foi possível confirmar automaticamente o status de entrega.'
        ]);
        exit;
    }

    log_event('Erro ao enviar email de compartilhamento de carrinho.', [
        'erro' => $e->getMessage(),
        'email' => $email,
        'nome' => $nome,
    ]);
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Não foi possível enviar o email. Tente novamente.']);
    exit;
}
