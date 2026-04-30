<?php

declare(strict_types=1);

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/NucleoApiChat.php';

const API_CHAT_ENVIAR_TEEC_ENDPOINT = '/APIChat/EnviarTEEC.php';
const API_CHAT_ENVIAR_TEEC_DESTINATARIO = 'braian.peruzzo@redutoresibr.com.br';
const API_CHAT_ENVIAR_TEEC_COMANDO = '.enviarteec';
const API_CHAT_ENVIAR_TEEC_MAX_TEXTO = 100000;
const API_CHAT_ENVIAR_TEEC_MAX_OBSERVACAO = 500;

function responder_enviar_teec_api_chat(int $status, array $payload): void
{
    $idempotencyKey = api_chat_middleware_idempotency_header_key();
    if ($idempotencyKey !== '') {
        api_chat_middleware_idempotency_finalizar(API_CHAT_ENVIAR_TEEC_ENDPOINT, $idempotencyKey, $status, $payload);
    }
    api_chat_middleware_responder($status, $payload);
}

function api_chat_enviar_teec_normalizar_linhas(string $texto): array
{
    $linhas = preg_split('/\R/u', $texto) ?: [];
    $saida = [];

    foreach ($linhas as $linha) {
        $linhaNormalizada = trim((string) $linha);
        if ($linhaNormalizada === '') {
            continue;
        }

        $saida[] = $linhaNormalizada;
    }

    return $saida;
}

function api_chat_enviar_teec_extrair_observacao_por_comando(string $comando): string
{
    $comandoNormalizado = trim($comando);
    if ($comandoNormalizado === '') {
        return '';
    }

    $comandoLower = function_exists('mb_strtolower')
        ? mb_strtolower($comandoNormalizado, 'UTF-8')
        : strtolower($comandoNormalizado);

    if (strpos($comandoLower, API_CHAT_ENVIAR_TEEC_COMANDO) !== 0) {
        return '';
    }

    $observacao = trim((string) api_chat_substr($comandoNormalizado, strlen(API_CHAT_ENVIAR_TEEC_COMANDO)));
    if ($observacao === '') {
        return '';
    }

    return api_chat_substr($observacao, 0, API_CHAT_ENVIAR_TEEC_MAX_OBSERVACAO);
}

function api_chat_enviar_teec_template_html(string $requestId, string $observacao, array $linhasConversa): string
{
    $itens = '';
    foreach ($linhasConversa as $indice => $linha) {
        $numero = $indice + 1;
        $itens .= '<li style="margin:0 0 8px 0;padding:10px;border:1px solid #e5e7eb;border-radius:8px;">'
            . '<strong style="color:#ec4115;">Linha ' . $numero . ':</strong> '
            . nl2br(htmlspecialchars($linha, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
            . '</li>';
    }

    $blocoObservacao = '<p style="margin:0;color:#6b7280;">Sem observações adicionais.</p>';
    if ($observacao !== '') {
        $blocoObservacao = '<p style="margin:0;color:#111827;">'
            . nl2br(htmlspecialchars($observacao, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
            . '</p>';
    }

    return '<div style="font-family:Arial,Helvetica,sans-serif;color:#111827;line-height:1.45;">'
        . '<h2 style="margin:0 0 8px 0;color:#ec4115;">TEEC - Conversa Exportada do GPT</h2>'
        . '<p style="margin:0 0 14px 0;"><strong>Request ID:</strong> ' . htmlspecialchars($requestId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
        . '<h3 style="margin:0 0 8px 0;">Observação</h3>'
        . $blocoObservacao
        . '<h3 style="margin:18px 0 8px 0;">Conversa</h3>'
        . '<ol style="padding-left:18px;margin:0;">' . $itens . '</ol>'
        . '</div>';
}

function api_chat_enviar_teec_template_texto(string $requestId, string $observacao, array $linhasConversa): string
{
    $texto = "Conversa Exportada do GPT\n";
    $texto .= "Request ID: {$requestId}\n\n";
    $texto .= "Observação:\n";
    $texto .= $observacao !== '' ? $observacao . "\n\n" : "Sem observações adicionais.\n\n";
    $texto .= "Conversa:\n";

    foreach ($linhasConversa as $indice => $linha) {
        $texto .= ($indice + 1) . '. ' . $linha . "\n";
    }

    return $texto;
}

$baseDir = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
if ($baseDir === '') {
    $baseDir = dirname(__DIR__);
}

api_chat_middleware_init($baseDir, API_CHAT_ENVIAR_TEEC_ENDPOINT);
auth_api_chat_validar_ou_responder($baseDir, 'responder_enviar_teec_api_chat');

$metodo = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($metodo !== 'POST') {
    responder_enviar_teec_api_chat(405, [
        'ok' => false,
        'erro' => 'Use POST para enviar .EnviarTEEC.',
        'codigoErro' => 'METODO_NAO_PERMITIDO',
    ]);
}

$input = api_chat_payload_json_or_error();
if (!is_array($input)) {
    $input = $_POST;
}
if (!is_array($input)) {
    $input = [];
}

$idempotencyKey = api_chat_middleware_idempotency_header_key();
$idempotencyState = api_chat_middleware_idempotency_iniciar(API_CHAT_ENVIAR_TEEC_ENDPOINT, $idempotencyKey, $input);
if (($idempotencyState['status'] ?? '') === 'replay') {
    $resposta = is_array($idempotencyState['response']['body'] ?? null) ? $idempotencyState['response']['body'] : [];
    responder_enviar_teec_api_chat((int) ($idempotencyState['response']['status'] ?? 200), $resposta);
}
if (($idempotencyState['status'] ?? '') === 'conflict') {
    responder_enviar_teec_api_chat(409, ['ok' => false, 'erro' => 'Idempotency-Key já foi usado com payload diferente.', 'codigoErro' => 'IDEMPOTENCY_CONFLICT']);
}
if (($idempotencyState['status'] ?? '') === 'in_progress') {
    responder_enviar_teec_api_chat(409, ['ok' => false, 'erro' => 'Requisição idempotente ainda em processamento.', 'codigoErro' => 'IDEMPOTENCY_IN_PROGRESS']);
}

$comando = trim((string) ($input['comando'] ?? $input['mensagem'] ?? ''));
$conversaChatGPT = trim((string) ($input['conversaChatGPT'] ?? $input['conversa'] ?? ''));
$conversaChatGPT = api_chat_privacy_redact_text($conversaChatGPT);
$observacao = trim((string) ($input['observacao'] ?? ''));

if ($observacao === '') {
    $observacao = api_chat_enviar_teec_extrair_observacao_por_comando($comando);
}
$observacao = api_chat_substr($observacao, 0, API_CHAT_ENVIAR_TEEC_MAX_OBSERVACAO);
$observacao = api_chat_privacy_redact_text($observacao);

if ($comando !== '') {
    $comandoLower = function_exists('mb_strtolower')
        ? mb_strtolower($comando, 'UTF-8')
        : strtolower($comando);

    if (strpos($comandoLower, API_CHAT_ENVIAR_TEEC_COMANDO) !== 0) {
        responder_enviar_teec_api_chat(400, [
            'ok' => false,
            'erro' => 'Comando inválido. Use .EnviarTEEC ou .EnviarTEEC <observação>.',
            'codigoErro' => 'COMANDO_INVALIDO',
        ]);
    }
}

if ($conversaChatGPT === '') {
    responder_enviar_teec_api_chat(400, [
        'ok' => false,
        'erro' => 'Informe a conversa atual no campo "conversaChatGPT".',
        'codigoErro' => 'CONVERSA_OBRIGATORIA',
    ]);
}

$conversaChatGPT = api_chat_substr($conversaChatGPT, 0, API_CHAT_ENVIAR_TEEC_MAX_TEXTO);
$linhasConversa = api_chat_enviar_teec_normalizar_linhas($conversaChatGPT);
if ($linhasConversa === []) {
    responder_enviar_teec_api_chat(400, [
        'ok' => false,
        'erro' => 'A conversa enviada está vazia após normalização.',
        'codigoErro' => 'CONVERSA_VAZIA',
    ]);
}

require $baseDir . '/PHPMailer/src/PHPMailer.php';
require $baseDir . '/PHPMailer/src/SMTP.php';
require $baseDir . '/PHPMailer/src/Exception.php';
require $baseDir . '/AcessosConsultas/CredenciaisAcessoEmail.php';

$requestId = api_chat_middleware_request_id_atual();
$bodyHtml = api_chat_enviar_teec_template_html($requestId, $observacao, $linhasConversa);
$bodyTexto = api_chat_enviar_teec_template_texto($requestId, $observacao, $linhasConversa);

$arquivoConversa = tempnam(sys_get_temp_dir(), 'teec-conversa-');
if ($arquivoConversa !== false) {
    file_put_contents($arquivoConversa, $bodyTexto);
}

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
    $mail->addAddress(API_CHAT_ENVIAR_TEEC_DESTINATARIO, 'Braian Peruzzo');
    $mail->addReplyTo($smtpUser, 'Não Responder - Comunicação - Redutores IBR');

    $mail->Subject = 'Relatório de Erro - TEEC via APIChat GPT';
    $mail->isHTML(true);
    $mail->Body = $bodyHtml;
    $mail->AltBody = $bodyTexto;

    if (is_string($arquivoConversa) && is_file($arquivoConversa)) {
        $mail->addAttachment($arquivoConversa, 'conversa-chatgpt.txt');
    }

    $mail->send();

    responder_enviar_teec_api_chat(200, [
        'ok' => true,
        'solicitacaoId' => $requestId,
        'redactionsApplied' => true,
        'mensagem' => 'Email TEEC enviado com sucesso.',
        'destinatario' => API_CHAT_ENVIAR_TEEC_DESTINATARIO,
        'linhasConversa' => count($linhasConversa),
        'observacaoInformada' => $observacao !== '',
    ]);
} catch (Exception $e) {
    responder_enviar_teec_api_chat(500, [
        'ok' => false,
        'erro' => 'Erro ao enviar email TEEC.',
        'codigoErro' => 'ERRO_ENVIO_EMAIL',
        'detalhes' => $e->getMessage(),
    ]);
} finally {
    if (is_string($arquivoConversa) && is_file($arquivoConversa)) {
        @unlink($arquivoConversa);
    }
}
