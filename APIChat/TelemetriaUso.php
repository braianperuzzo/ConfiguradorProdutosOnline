<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/NucleoApiChat.php';

const API_CHAT_TELEMETRIA_ENDPOINT = '/APIChat/TelemetriaUso.php';
const API_CHAT_TELEMETRIA_MAX_EVENTOS = 10000;
const API_CHAT_TELEMETRIA_RETENCAO_DIAS = -1;
const API_CHAT_TELEMETRIA_JANELA_DUPLICIDADE_SEGUNDOS = 90;
const API_CHAT_TELEMETRIA_MAX_CHAVES_METADADOS = 30;
const API_CHAT_TELEMETRIA_MAX_TAMANHO_VALOR = 240;
const API_CHAT_TELEMETRIA_EVENTOS_ESTRATEGICOS = [
    'configuracao_incompleta',
    'falha_falta_dado',
    'aplicacao_critica',
    'consulta_codigo_direto',
];

function api_chat_telemetria_normalizar_evento(string $evento): string
{
    $eventoNormalizado = strtolower(trim($evento));
    if ($eventoNormalizado === '') {
        return '';
    }

    $aliases = [
        'configuração incompleta' => 'configuracao_incompleta',
        'configuracao incompleta' => 'configuracao_incompleta',
        'falha por falta de dado' => 'falha_falta_dado',
        'aplicação crítica' => 'aplicacao_critica',
        'aplicacao critica' => 'aplicacao_critica',
        'consulta por código direto' => 'consulta_codigo_direto',
        'consulta por codigo direto' => 'consulta_codigo_direto',
    ];

    return $aliases[$eventoNormalizado] ?? $eventoNormalizado;
}

function api_chat_obter_cookie_consentimento_treinamento_ia(): bool
{
    $raw = $_COOKIE['lgpd-cookies-consent'] ?? '';
    if (!is_string($raw) || trim($raw) === '') {
        return false;
    }

    $decoded = urldecode($raw);
    $consentimento = json_decode($decoded, true);
    if (!is_array($consentimento)) {
        return false;
    }

    return !empty($consentimento['AITraining']);
}

function api_chat_telemetria_obter_header(string $nome): string
{
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    if (is_array($headers)) {
        foreach ($headers as $chave => $valor) {
            if (strcasecmp((string) $chave, $nome) === 0) {
                return trim((string) $valor);
            }
        }
    }

    $nomeServer = 'HTTP_' . strtoupper(str_replace('-', '_', $nome));
    if (isset($_SERVER[$nomeServer])) {
        return trim((string) $_SERVER[$nomeServer]);
    }

    return '';
}

function api_chat_telemetria_consentimento_ia_confirmado(array $input): bool
{
    if (api_chat_obter_cookie_consentimento_treinamento_ia()) {
        return true;
    }

    if (($input['consentimentoIA'] ?? null) === true) {
        return true;
    }

    $headerConsentimento = strtolower(api_chat_telemetria_obter_header('X-Consentimento-IA'));
    return in_array($headerConsentimento, ['1', 'true', 'sim', 'yes'], true);
}

function api_chat_telemetria_extrair_payload_registro(array $input): array
{
    $payload = $input;

    if (isset($input['payload']) && is_array($input['payload'])) {
        $payload = $input['payload'];
    } elseif (isset($input['dados']) && is_array($input['dados'])) {
        $payload = $input['dados'];
    }

    if (!array_key_exists('evento', $payload)) {
        $eventoAlternativo = $payload['acao']
            ?? $payload['ação']
            ?? $payload['event']
            ?? null;

        if ($eventoAlternativo !== null) {
            $payload['evento'] = $eventoAlternativo;
        }
    }

    return $payload;
}

function api_chat_telemetria_juntar_metadados(array $payload): array
{
    $metadados = is_array($payload['metadados'] ?? null) ? $payload['metadados'] : [];
    $contexto = is_array($payload['contexto'] ?? null) ? $payload['contexto'] : [];

    if (!empty($contexto)) {
        $metadados = array_merge($contexto, $metadados);
    }

    $requestIdsRelacionados = is_array($payload['requestIdsRelacionados'] ?? null)
        ? $payload['requestIdsRelacionados']
        : [];

    if (!empty($requestIdsRelacionados)) {
        $metadados['requestIdsRelacionados'] = array_values(array_filter(array_map(
            static fn($valor): string => trim((string) $valor),
            $requestIdsRelacionados
        ), static fn(string $valor): bool => $valor !== ''));
    }

    $severidade = trim((string) ($payload['severidade'] ?? ''));
    if ($severidade !== '') {
        $metadados['severidade'] = $severidade;
    }

    return $metadados;
}

function responder_telemetria_uso(int $status, array $payload): void
{
    api_chat_middleware_responder($status, $payload);
}

function api_chat_telemetria_storage_key(): string
{
    return api_chat_storage_key('/APIChat/TelemetriaUso.php', 'telemetria', 'eventos');
}

function api_chat_telemetria_storage_namespace(): string
{
    return 'telemetria_uso';
}

function api_chat_telemetria_limpar_legado_storage_principal(): void
{
    api_chat_storage_mutate(static function (array &$all): void {
        foreach (array_keys($all) as $key) {
            if (!is_string($key)) {
                continue;
            }

            if (str_starts_with(strtolower($key), '/apichat/telemetriauso.php|telemetria|')) {
                unset($all[$key]);
            }
        }
    }, 'storage');
}

function api_chat_telemetria_carregar(string $baseDir): array
{
    $conteudo = api_chat_storage_get(api_chat_telemetria_storage_key(), api_chat_telemetria_storage_namespace());
    if (!is_string($conteudo) || trim($conteudo) === '') {
        return [];
    }

    $dados = json_decode($conteudo, true);
    return is_array($dados) ? $dados : [];
}

function api_chat_telemetria_salvar(string $baseDir, array $eventos): void
{
    api_chat_storage_set(
        api_chat_telemetria_storage_key(),
        json_encode(array_values($eventos), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
        0,
        api_chat_telemetria_storage_namespace()
    );
}

function api_chat_telemetria_duplicidade_key(string $fingerprint): string
{
    return api_chat_storage_key('/APIChat/TelemetriaUso.php', 'telemetria', 'dedupe:' . $fingerprint);
}


function api_chat_telemetria_registrar_evento_atomico(array $dadosEvento): array
{
    api_chat_telemetria_limpar_legado_storage_principal();

    $storageKey = api_chat_telemetria_storage_key();
    $dedupeKey = api_chat_telemetria_duplicidade_key((string) ($dadosEvento['fingerprint'] ?? ''));

    return api_chat_storage_mutate(static function (array &$all) use ($storageKey, $dedupeKey, $dadosEvento): array {
        $eventosRaw = api_chat_storage_read_value($all, $storageKey);
        $eventos = [];
        if (is_string($eventosRaw) && trim($eventosRaw) !== '') {
            $decoded = json_decode($eventosRaw, true);
            if (is_array($decoded)) {
                $eventos = $decoded;
            }
        }

        $duplicado = api_chat_storage_read_value($all, $dedupeKey) !== null
            || api_chat_telemetria_evento_duplicado($eventos, (string) ($dadosEvento['fingerprint'] ?? ''));

        if ($duplicado) {
            $all[$storageKey] = [
                'val' => json_encode(array_values($eventos), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
                'exp' => 0,
            ];

            return ['duplicado' => true, 'eventos' => $eventos];
        }

        $all[$dedupeKey] = [
            'val' => (string) time(),
            'exp' => time() + API_CHAT_TELEMETRIA_JANELA_DUPLICIDADE_SEGUNDOS,
        ];

        $eventos[] = $dadosEvento;

        $all[$storageKey] = [
            'val' => json_encode(array_values($eventos), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
            'exp' => 0,
        ];

        return ['duplicado' => false, 'eventos' => $eventos];
    }, api_chat_telemetria_storage_namespace());
}

function api_chat_telemetria_hash_usuario(): string
{
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $ua = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'));
    return hash('sha256', $ip . '|' . $ua);
}

function api_chat_telemetria_sanitizar_metadados(array $metadados): array
{
    $bloqueios = ['senha', 'token', 'authorization', 'cpf', 'cnpj', 'email', 'telefone'];
    $saida = [];

    $totalChaves = 0;
    foreach ($metadados as $chave => $valor) {
        if ($totalChaves >= API_CHAT_TELEMETRIA_MAX_CHAVES_METADADOS) {
            break;
        }

        $nome = strtolower((string) $chave);
        $bloqueado = false;
        foreach ($bloqueios as $proibido) {
            if (strpos($nome, $proibido) !== false) {
                $bloqueado = true;
                break;
            }
        }

        if ($bloqueado) {
            continue;
        }

        if (is_scalar($valor) || $valor === null) {
            $texto = trim((string) $valor);
            $saida[$chave] = api_chat_substr($texto, 0, API_CHAT_TELEMETRIA_MAX_TAMANHO_VALOR);
            $totalChaves++;
            continue;
        }

        if (is_array($valor)) {
            $normalizado = json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($normalizado)) {
                $saida[$chave] = api_chat_substr($normalizado, 0, API_CHAT_TELEMETRIA_MAX_TAMANHO_VALOR);
                $totalChaves++;
            }
        }
    }

    return $saida;
}

function api_chat_telemetria_limpar_antigos(array $eventos): array
{
    return $eventos;
}

function api_chat_telemetria_politica_retencao(): array
{
    $retencaoInfinita = API_CHAT_TELEMETRIA_RETENCAO_DIAS < 1;

    return [
        'retencaoDias' => $retencaoInfinita ? null : API_CHAT_TELEMETRIA_RETENCAO_DIAS,
        'retencaoInfinita' => $retencaoInfinita,
        'descricao' => $retencaoInfinita ? 'retenção permanente' : ('retenção de ' . API_CHAT_TELEMETRIA_RETENCAO_DIAS . ' dias'),
        'janelaDuplicidadeSegundos' => API_CHAT_TELEMETRIA_JANELA_DUPLICIDADE_SEGUNDOS,
        'maxEventos' => API_CHAT_TELEMETRIA_MAX_EVENTOS,
    ];
}

function api_chat_telemetria_fingerprint(string $evento, string $origem, string $sessaoId, array $metadados): string
{
    ksort($metadados);
    $base = [
        'evento' => $evento,
        'origem' => $origem,
        'sessaoId' => $sessaoId,
        'metadados' => $metadados,
    ];
    return hash('sha256', json_encode($base, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function api_chat_telemetria_evento_duplicado(array $eventos, string $fingerprint): bool
{
    $agora = time();
    $janela = API_CHAT_TELEMETRIA_JANELA_DUPLICIDADE_SEGUNDOS;

    for ($i = count($eventos) - 1; $i >= 0; $i--) {
        $evento = $eventos[$i] ?? null;
        if (!is_array($evento)) {
            continue;
        }

        $timestamp = strtotime((string) ($evento['timestamp'] ?? ''));
        if ($timestamp === false) {
            continue;
        }

        if (($agora - $timestamp) > $janela) {
            break;
        }

        if (($evento['fingerprint'] ?? '') === $fingerprint) {
            return true;
        }
    }

    return false;
}

function api_chat_telemetria_resumo(array $eventos): array
{
    $porEvento = [];
    $porOrigem = [];
    $porPagina = [];
    $porProduto = [];
    $porSeletor = [];
    $eventosEstrategicos = [];

    foreach ($eventos as $evento) {
        $tipo = (string) ($evento['evento'] ?? 'indefinido');
        $origem = (string) ($evento['origem'] ?? 'desconhecida');

        $porEvento[$tipo] = (int) ($porEvento[$tipo] ?? 0) + 1;
        if (in_array($tipo, API_CHAT_TELEMETRIA_EVENTOS_ESTRATEGICOS, true)) {
            $eventosEstrategicos[$tipo] = (int) ($eventosEstrategicos[$tipo] ?? 0) + 1;
        }
        $porOrigem[$origem] = (int) ($porOrigem[$origem] ?? 0) + 1;

        $metadados = is_array($evento['metadados'] ?? null) ? $evento['metadados'] : [];

        $pagina = trim((string) ($metadados['pagina'] ?? $metadados['path'] ?? ''));
        if ($pagina !== '') {
            $porPagina[$pagina] = (int) ($porPagina[$pagina] ?? 0) + 1;
        }

        $produto = trim((string) ($metadados['produto'] ?? $metadados['produtoPai'] ?? $metadados['linha'] ?? ''));
        if ($produto !== '') {
            $porProduto[$produto] = (int) ($porProduto[$produto] ?? 0) + 1;
        }

        $seletor = trim((string) ($metadados['seletor'] ?? ''));
        if ($seletor !== '') {
            $porSeletor[$seletor] = (int) ($porSeletor[$seletor] ?? 0) + 1;
        }
    }

    arsort($porEvento);
    arsort($porOrigem);
    arsort($porPagina);
    arsort($porProduto);
    arsort($porSeletor);
    arsort($eventosEstrategicos);

    return [
        'totalEventos' => count($eventos),
        'eventosPorTipo' => $porEvento,
        'eventosPorOrigem' => $porOrigem,
        'paginasMaisAcessadas' => array_slice($porPagina, 0, 20, true),
        'produtosMaisConfigurados' => array_slice($porProduto, 0, 20, true),
        'seletoresMaisUtilizados' => array_slice($porSeletor, 0, 30, true),
        'eventosEstrategicos' => $eventosEstrategicos,
        'politicaRetencao' => api_chat_telemetria_politica_retencao(),
    ];
}

$baseDir = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
if ($baseDir === '') {
    $baseDir = dirname(__DIR__);
}

api_chat_middleware_init($baseDir, API_CHAT_TELEMETRIA_ENDPOINT);

$metodo = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($metodo === 'POST') {
    $input = api_chat_payload_json();

    if (!is_array($input)) {
        responder_telemetria_uso(400, ['ok' => false, 'erro' => 'Body JSON inválido.', 'codigoErro' => 'BODY_INVALIDO']);
    }

    auth_api_chat_validar_ou_responder($baseDir, 'responder_telemetria_uso');

    $payloadRegistro = api_chat_telemetria_extrair_payload_registro($input);

    $evento = api_chat_telemetria_normalizar_evento((string) ($payloadRegistro['evento'] ?? ''));
    if ($evento === '') {
        responder_telemetria_uso(400, ['ok' => false, 'erro' => 'Campo "evento" é obrigatório.', 'codigoErro' => 'PARAMETROS_INVALIDOS']);
    }

    $origem = trim((string) ($payloadRegistro['origem'] ?? 'site'));
    $sessaoId = trim((string) ($payloadRegistro['sessaoId'] ?? ''));
    $metadados = api_chat_telemetria_juntar_metadados($payloadRegistro);
    $metadadosSanitizados = api_chat_telemetria_sanitizar_metadados($metadados);

    $sessaoNormalizada = api_chat_substr($sessaoId !== '' ? $sessaoId : api_chat_telemetria_hash_usuario(), 0, 128);
    $fingerprint = api_chat_telemetria_fingerprint($evento, $origem !== '' ? $origem : 'site', $sessaoNormalizada, $metadadosSanitizados);

    $novoEvento = [
        'id' => bin2hex(random_bytes(10)),
        'timestamp' => gmdate('c'),
        'evento' => api_chat_substr($evento, 0, 120),
        'origem' => api_chat_substr($origem !== '' ? $origem : 'site', 0, 80),
        'sessaoId' => $sessaoNormalizada,
        'usuarioHash' => api_chat_telemetria_hash_usuario(),
        'metadados' => $metadadosSanitizados,
        'fingerprint' => $fingerprint,
    ];

    $registro = api_chat_telemetria_registrar_evento_atomico($novoEvento);
    $eventos = is_array($registro['eventos'] ?? null) ? $registro['eventos'] : [];

    if (($registro['duplicado'] ?? false) === true) {
        responder_telemetria_uso(200, [
            'ok' => true,
            'mensagem' => 'Evento ignorado por duplicidade na janela de deduplicação.',
            'duplicado' => true,
            'resumoAtual' => api_chat_telemetria_resumo($eventos),
        ]);
    }

    api_chat_registrar_evento_telemetria_uso($baseDir, ['tipo' => 'telemetria_uso', 'timestamp' => gmdate('c'), 'evento' => $novoEvento]);

    responder_telemetria_uso(201, [
        'ok' => true,
        'eventId' => $novoEvento['id'],
        'storedAt' => $novoEvento['timestamp'],
        'requestId' => api_chat_middleware_request_id_atual(),
        'mensagem' => 'Evento registrado com sucesso.',
        'resumoAtual' => api_chat_telemetria_resumo($eventos),
    ]);
}

if ($metodo === 'GET') {
    auth_api_chat_validar_ou_responder($baseDir, 'responder_telemetria_uso');

    $eventos = api_chat_telemetria_carregar($baseDir);
    $limite = max(1, min(200, (int) ($_GET['limite'] ?? 30)));
    $eventoFiltro = trim((string) ($_GET['evento'] ?? ''));

    if ($eventoFiltro !== '') {
        $eventos = array_values(array_filter($eventos, static function (array $evento) use ($eventoFiltro): bool {
            return isset($evento['evento']) && strcasecmp((string) $evento['evento'], $eventoFiltro) === 0;
        }));
    }

    $ultimos = array_slice($eventos, -$limite);

    responder_telemetria_uso(200, [
        'ok' => true,
        'resumo' => api_chat_telemetria_resumo($eventos),
        'ultimosEventos' => array_values($ultimos),
    ]);
}

responder_telemetria_uso(405, ['ok' => false, 'erro' => 'Use GET para leitura e POST para registrar telemetria.']);
