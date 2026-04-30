<?php

declare(strict_types=1);

require_once __DIR__ . '/NucleoApiChat.php';

$acao = api_chat_router_obter_acao('gerarLinkConfigurador');

if ($acao === 'validarReferenciaEstruturadaGet' || $acao === 'validarReferenciaEstruturadaPost') {
    $referenciaNormalizada = '';

    $candidatasQuery = [
        'referencia',
        'valor',
        'entrada',
        'referenciaEstruturada',
        'codigoOuReferencia',
    ];

    foreach ($candidatasQuery as $campo) {
        if (isset($_GET[$campo]) && is_scalar($_GET[$campo])) {
            $valor = trim((string) $_GET[$campo]);
            if ($valor !== '') {
                $referenciaNormalizada = $valor;
                break;
            }
        }
    }

    if ($referenciaNormalizada === '') {
        $payload = api_chat_payload_json();
        if (is_array($payload)) {
            $candidatasPayload = [
                'referencia',
                'valor',
                'entrada',
                'referenciaEstruturada',
                'codigoOuReferencia',
            ];

            foreach ($candidatasPayload as $campo) {
                if (isset($payload[$campo]) && is_scalar($payload[$campo])) {
                    $valor = trim((string) $payload[$campo]);
                    if ($valor !== '') {
                        $referenciaNormalizada = $valor;
                        break;
                    }
                }
            }
        }
    }

    if ($referenciaNormalizada !== '' && !isset($_GET['referencia'])) {
        $_GET['referencia'] = $referenciaNormalizada;
    }
}

$despachado = api_chat_router_despachar($acao, [
    'gerarLinkConfigurador' => ['arquivo' => 'GerarLinkConfigurador.php', 'metodo' => 'POST'],
    'validarReferenciaEstruturadaGet' => ['arquivo' => 'BuscaProduto.php', 'metodo' => 'GET'],
    'validarReferenciaEstruturadaPost' => ['arquivo' => 'BuscaProduto.php', 'metodo' => 'POST'],
]);

if ($despachado) {
    return;
}

api_chat_router_resposta_acao_invalida('OrquestrarConfiguradorEReferencias', $acao);
