<?php

declare(strict_types=1);

require_once __DIR__ . '/NucleoApiChat.php';

const API_CHAT_ORQUESTRAR_MEMORIAS_ENDPOINT = '/APIChat/OrquestrarMemoriasETelemetria.php';
$baseDir = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
if ($baseDir === '') { $baseDir = dirname(__DIR__); }
api_chat_middleware_init($baseDir, API_CHAT_ORQUESTRAR_MEMORIAS_ENDPOINT);

$metodo = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$acaoPadrao = 'consultarMemorias';
$acao = api_chat_router_obter_acao($acaoPadrao);
$acoesMemoriaDiretas = ['create', 'privacy', 'write_pipeline', 'import_training'];

if (in_array($acao, $acoesMemoriaDiretas, true)) {
    $body = api_chat_payload_json_or_error();

    if (!isset($body['action']) || trim((string) $body['action']) === '') {
        $body['action'] = $acao;
        $GLOBALS['api_chat_cached_raw_input'] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $acao = 'executarAcaoMemoria';
}

if ($metodo === 'POST' && $acao === $acaoPadrao) {
    $body = api_chat_payload_json_or_error();

    $acaoMemoria = strtolower(trim((string) ($body['action'] ?? ($body['payload']['action'] ?? ''))));
    $temEventoTelemetria = trim((string) ($body['evento'] ?? ($body['payload']['evento'] ?? ''))) !== '';

    if ($acaoMemoria !== '') {
        $acao = 'executarAcaoMemoria';
    } elseif ($temEventoTelemetria) {
        $acao = 'registrarEventoTelemetriaUso';
    }
}

if ($acao === 'consultarMemorias') {
    $_SERVER['REQUEST_METHOD'] = 'GET';
}

if ($acao === 'executarAcaoMemoria' && $metodo === 'POST') {
    $body = api_chat_payload_json_or_error();

    $acaoMemoria = strtolower(trim((string) ($body['action'] ?? (($body['payload']['action'] ?? '')))));

    if ($acaoMemoria !== '' && !isset($body['action'])) {
        $body['action'] = $acaoMemoria;
    }

    $conteudoAtual = $body['conteudo'] ?? $body['content'] ?? $body['texto']
        ?? ($body['payload']['conteudo'] ?? $body['payload']['content'] ?? $body['payload']['texto'] ?? null);

    if (trim((string) $conteudoAtual) === '') {
        $comandoBruto = trim((string) ($body['comando'] ?? ($body['payload']['comando'] ?? '')));
        if ($comandoBruto !== '') {
            if (preg_match('/^\.RegistroMemoria(?:\s+|$)(.*)$/ui', $comandoBruto, $matches) === 1) {
                $conteudoExtraido = trim((string) ($matches[1] ?? ''));
                if ($conteudoExtraido !== '') {
                    $body['content'] = $conteudoExtraido;
                }
            }
        }
    } elseif (!isset($body['content']) && !isset($body['conteudo'])) {
        $body['content'] = (string) $conteudoAtual;
    }

    if ($acaoMemoria === '') {
        // Mantém POST para que GerenciarMemorias retorne erro explícito
        // em vez de cair silenciosamente em consulta (GET).
        $body['action'] = '__acao_memoria_obrigatoria__';
    }

    $GLOBALS['api_chat_cached_raw_input'] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

$despachado = api_chat_router_despachar($acao, [
    'consultarMemorias' => ['arquivo' => 'GerenciarMemorias.php'],
    'executarAcaoMemoria' => ['arquivo' => 'GerenciarMemorias.php'],
    'removerMemoria' => ['arquivo' => 'GerenciarMemorias.php'],
    'registrarEventoTelemetriaUso' => ['arquivo' => 'TelemetriaUso.php', 'metodo' => 'POST'],
]);

if ($despachado) {
    return;
}

$acoesPermitidas = array_merge(['consultarMemorias', 'removerMemoria', 'registrarEventoTelemetriaUso'], $acoesMemoriaDiretas);
api_chat_router_resposta_acao_invalida('OrquestrarMemoriasETelemetria', $acao, ['acoesPermitidas' => $acoesPermitidas]);

