<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/NucleoApiChat.php';

function responder_capabilities_api_chat(int $status, array $payload): void
{
    api_chat_middleware_responder($status, $payload);
}

$acaoPadrao = 'consultarCapacidadesApiChat';
$acao = api_chat_router_obter_acao($acaoPadrao);

if ($acao !== $acaoPadrao) {
    $despachado = api_chat_router_despachar($acao, [
        'consultarContextoInicial' => ['arquivo' => 'ConsultarContextoInicial.php'],
        'consultarResumoTelemetriaUso' => ['arquivo' => 'TelemetriaUso.php', 'metodo' => 'GET'],
    ]);

    if ($despachado) {
        return;
    }

    api_chat_router_resposta_acao_invalida('ConsultarCapacidadesApiChat', $acao);
}

$baseDir = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
if ($baseDir === '') {
    $baseDir = dirname(__DIR__);
}

api_chat_middleware_init($baseDir, '/APIChat/ConsultarCapacidadesApiChat.php');

$metodo = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($metodo !== 'GET') {
    responder_capabilities_api_chat(405, [
        'ok' => false,
        'erro' => 'Use GET para consultar capabilities.',
    ]);
}

$estadoAutenticacao = auth_api_chat_estado_operacional($baseDir);
$chaveEsperada = (string) ($estadoAutenticacao['chaveEsperada'] ?? '');
$chaveConfigurada = (bool) ($estadoAutenticacao['chaveConfigurada'] ?? false);
$chaveRecebida = auth_api_chat_extrair_chave_recebida();
$autenticado = $chaveConfigurada && $chaveRecebida !== '' && hash_equals($chaveEsperada, $chaveRecebida);
$autenticacaoObrigatoria = (bool) ($estadoAutenticacao['autenticacaoObrigatoria'] ?? true);
$riscoAutenticacao = $chaveConfigurada && $autenticacaoObrigatoria && !$autenticado;
$readyForProtectedActions = !$autenticacaoObrigatoria || $autenticado;

if (!$readyForProtectedActions) {
    $statusAutenticacao = 'BLOCKED';
} elseif (!$chaveConfigurada || !$autenticado) {
    $statusAutenticacao = 'DEGRADED';
} else {
    $statusAutenticacao = 'OK';
}

$recursosHabilitados = [
    'explorarSite' => true,
    'buscaTextual' => true,
    'buscaPorCodigoExato' => true,
    'listagemProdutos' => true,
    'modoCompacto' => true,
    'somenteDescricao' => true,
    'montagemLinkConfigurador' => true,
    'telemetriaUso' => true,
    'dashboardCustoQualidadeLLM' => true,
    'banditFeedbackOutcomePosterior' => true,
    'banditSinaisAtivos' => ['resolved', 'fallback_needed', 'user_rephrase', 'tool_error', 'latency_bucket', 'estimated_cost'],
    'banditPolicyVersion' => '2.0.0',
    'toolRuntimeEtapas' => true,
    'toolRuntimeRequiresConfirmation' => true,
    'toolRuntimeIdempotency' => true,
    'toolRuntimeStructuredLogs' => true,
    'toolRuntimeTimeoutCompensacao' => true,
    'toolRuntimeRetryPolicyPorErro' => true,
    'toolRuntimeCircuitBreakerFerramenta' => true,
    'toolRuntimeToolContractVersionado' => true,
    'memorySubsystem' => true,
    'riskClassifierEntradaSaida' => true,
    'toolPolicyTenantAllowlistEscopoMinimo' => true,
    'toolPolicyAprovacaoHumanaOpcional' => true,
    'sanitizacaoTokenizacaoAntesModeloExterno' => true,
    'ciRedTeamPromptsTemplatesTools' => true,
    'consultaSelectSomenteLeitura' => true,
    'memoryModesChat' => ['off', 'read_only', 'read_write'],
    'indicesSemanticosVersionados' => true,
    'fallbackSemIndice' => true,
    'fallbackControladoSemActions' => true,
    'validacaoReferenciaEstruturada' => true,
    'aberturaSolicitacaoCotacao' => true,
    'aberturaSolicitacaoDesenho' => true,
    'envioEmailTeecConversa' => true,
    'imagensOficiaisIBR' => true,
    'contextoInicialChat' => true,
];

if (!$readyForProtectedActions) {
    foreach ([
        'explorarSite',
        'buscaTextual',
        'buscaPorCodigoExato',
        'listagemProdutos',
        'montagemLinkConfigurador',
        'telemetriaUso',
        'memorySubsystem',
        'consultaSelectSomenteLeitura',
        'validacaoReferenciaEstruturada',
        'aberturaSolicitacaoCotacao',
        'aberturaSolicitacaoDesenho',
        'envioEmailTeecConversa',
        'imagensOficiaisIBR',
        'contextoInicialChat',
    ] as $recursoProtegido) {
        $recursosHabilitados[$recursoProtegido] = false;
    }

    $recursosHabilitados['memoryModesChat'] = ['off'];
    $recursosHabilitados['fallbackSemIndice'] = false;
}

$versaoBaseConhecimento = api_chat_versao_base_conhecimento($baseDir);
$indiceProdutosDisponivel = api_chat_semantic_index_load($baseDir, 'produtos-embeddings') !== [];

responder_capabilities_api_chat(200, [
    'ok' => true,
    'autenticacao' => [
        'chaveConfigurada' => $chaveConfigurada,
        'configuracaoValida' => (bool) ($estadoAutenticacao['configuracaoValida'] ?? false),
        'autenticado' => $autenticado,
        'autenticacaoObrigatoria' => $autenticacaoObrigatoria,
        'failOpenHabilitado' => (bool) ($estadoAutenticacao['failOpenHabilitado'] ?? false),
        'ambienteProducao' => (bool) ($estadoAutenticacao['producao'] ?? false),
        'headerEsperado' => 'X-Api-Key',
        'diagnostico' => [
            'riscoOperacional' => $riscoAutenticacao,
            'status' => $statusAutenticacao,
            'readyForProtectedActions' => $readyForProtectedActions,
            'codigo' => $riscoAutenticacao ? 'AUTH_REQUIRED_NOT_AUTHENTICATED' : 'OK',
            'mensagem' => $riscoAutenticacao
                ? 'Autenticação obrigatória ativa sem chave válida na sessão de diagnóstico; recursos protegidos permanecem indisponíveis até regularizar autenticação.'
                : 'Autenticação em estado operacional esperado para o diagnóstico.',
        ],
    ],
    'capabilities' => [
        'configuradoresSuportados' => ['AC', 'AE', 'FX', 'HY', 'IN', 'MO', 'PL', 'QU', 'QUDR', 'VA'],
        'versoes' => [
            'openapi' => '3.1.0',
            'schema' => '1.5.0',
            'endpoints' => [
                'capabilities' => '1.0.0',
                'gerarLinkConfigurador' => '2.4.0',
                'buscarProdutos' => '3.0.0',
                'consultaPaginasSite' => '1.1.0',
                'telemetriaUsoChat' => '1.0.0',
                'memories' => '1.1.0',
                'consultaSelectBanco' => '1.0.0',
                'validarReferenciaEstruturada' => '1.0.0',
                'abrirSolicitacaoCotacao' => '1.0.0',
                'abrirSolicitacaoDesenho' => '1.0.0',
                'enviarTeecEmailConversa' => '1.0.0',
                'imagensOficiaisIBR' => '1.0.0',
                'contextoInicial' => '1.0.0',
                'consultaConteudo' => '1.1.0',
                'orquestrarMemoriasETelemetria' => '1.0.0',
                'orquestrarConfiguradorEReferencias' => '1.2.0',
            ],
        ],
        'limitesOperacionais' => [
            'limiteMaximo' => 1000,
            'maxBytesLeitura' => 250000,
        ],
        'recursosHabilitados' => [
            ...$recursosHabilitados,
        ],
        'politicasOperacionais' => [
            'fallbackSemActions' => [
                'habilitado' => true,
                'respostaConceitualPrimeiro' => true,
                'maximoPerguntasParaDestravar' => 1,
                'telemetriaObrigatoria' => ['configuracao_incompleta', 'falha_falta_dado'],
            ],
        ],
        'gruposAcoes' => [
            'buscaProdutos' => [
                'principal' => 'buscarProdutoTexto',
                'legadas' => [],
            ],
            'diagnostico' => ['capabilities', 'telemetriaUsoChat', 'memories'],
            'solicitacoes' => ['abrirSolicitacaoCotacao', 'abrirSolicitacaoDesenho', 'enviarTeecEmailConversa'],
        ],
        'indiceSemantico' => [
            'versaoBaseConhecimento' => $versaoBaseConhecimento,
            'produtosDisponivel' => $indiceProdutosDisponivel,
        ],
    ],
]);
