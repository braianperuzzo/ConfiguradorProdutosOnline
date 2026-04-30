<?php

declare(strict_types=1);

if (defined('API_CHAT_LITE_BOOTSTRAP')) {
    return;
}
define('API_CHAT_LITE_BOOTSTRAP', true);

$GLOBALS['api_chat_ctx'] = $GLOBALS['api_chat_ctx'] ?? [];
$GLOBALS['api_chat_tool_runtime'] = $GLOBALS['api_chat_tool_runtime'] ?? [];

function api_chat_strlen(string $texto, string $encoding = 'UTF-8'): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($texto, $encoding);
    }

    return strlen($texto);
}

function api_chat_substr(string $texto, int $inicio, ?int $comprimento = null, string $encoding = 'UTF-8'): string
{
    if (function_exists('mb_substr')) {
        return $comprimento === null
            ? mb_substr($texto, $inicio, null, $encoding)
            : mb_substr($texto, $inicio, $comprimento, $encoding);
    }

    return $comprimento === null
        ? substr($texto, $inicio)
        : substr($texto, $inicio, $comprimento);
}

function api_chat_lite_base_path(): string
{
    $candidatos = [];

    $envBase = trim((string) getenv('API_CHAT_LITE_BASE_PATH'));
    if ($envBase !== '') {
        $candidatos[] = $envBase;
    }

    $repoBase = dirname(__DIR__);
    $candidatos[] = $repoBase . DIRECTORY_SEPARATOR . 'APIChat' . DIRECTORY_SEPARATOR . 'TreinamentoMemórias';

    $appDataBase = $repoBase . DIRECTORY_SEPARATOR . 'App_Data' . DIRECTORY_SEPARATOR . 'api-chat-lite';
    $candidatos[] = $appDataBase;
    $candidatos[] = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'api-chat-lite';

    foreach ($candidatos as $base) {
        $base = rtrim((string) $base, DIRECTORY_SEPARATOR);
        if ($base === '') {
            continue;
        }

        if (!is_dir($base)) {
            @mkdir($base, 0777, true);
        }

        if (is_dir($base) && is_writable($base)) {
            return $base;
        }
    }

    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'api-chat-lite';
}

function api_chat_lite_store_path(string $namespace): string
{
    $safe = preg_replace('/[^a-zA-Z0-9_.-]+/', '_', $namespace) ?? 'store';
    return api_chat_lite_base_path() . DIRECTORY_SEPARATOR . $safe . '.json';
}

function api_chat_storage_key(string $endpoint, string $dominio, string $chave): string
{
    return strtolower(trim($endpoint)) . '|' . strtolower(trim($dominio)) . '|' . trim($chave);
}

function api_chat_storage_cache_key(string $endpoint, string $tipo, array $filtros, string $dominio, int $schemaVersion = 1): string
{
    ksort($filtros);
    return api_chat_storage_key($endpoint, $dominio . ':' . $tipo . ':v' . $schemaVersion, hash('sha256', json_encode($filtros, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
}

function api_chat_storage_path(string $namespace = 'storage'): string
{
    return api_chat_lite_store_path($namespace);
}

function api_chat_storage_all(string $namespace = 'storage'): array
{
    $path = api_chat_storage_path($namespace);
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function api_chat_storage_save_all(array $data, string $namespace = 'storage'): void
{
    @file_put_contents(api_chat_storage_path($namespace), json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function api_chat_storage_mutate(callable $mutator, string $namespace = 'storage')
{
    $path = api_chat_storage_path($namespace);
    $handle = @fopen($path, 'c+');
    if (!is_resource($handle)) {
        $fallback = api_chat_storage_all($namespace);
        $result = $mutator($fallback);
        api_chat_storage_save_all($fallback, $namespace);
        return $result;
    }

    if (!@flock($handle, LOCK_EX)) {
        fclose($handle);
        $fallback = api_chat_storage_all($namespace);
        $result = $mutator($fallback);
        api_chat_storage_save_all($fallback, $namespace);
        return $result;
    }

    try {
        rewind($handle);
        $raw = stream_get_contents($handle);
        $all = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : [];
        if (!is_array($all)) {
            $all = [];
        }

        $result = $mutator($all);
        $encoded = json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            $encoded = '{}';
        }

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, $encoded);
        fflush($handle);

        return $result;
    } finally {
        @flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function api_chat_storage_read_value(array &$all, string $key): ?string
{
    $item = $all[$key] ?? null;
    if (!is_array($item)) {
        return null;
    }

    $exp = (int) ($item['exp'] ?? 0);
    if ($exp > 0 && $exp < time()) {
        unset($all[$key]);
        return null;
    }

    $val = $item['val'] ?? null;
    return is_string($val) ? $val : null;
}


function api_chat_storage_json_mutate(string $key, callable $mutator, int $ttl = 0, array $default = [], string $namespace = 'storage')
{
    return api_chat_storage_mutate(static function (array &$all) use ($key, $mutator, $ttl, $default) {
        $atual = api_chat_storage_read_value($all, $key);
        $estado = $default;
        if (is_string($atual) && trim($atual) !== '') {
            $decoded = json_decode($atual, true);
            if (is_array($decoded)) {
                $estado = $decoded;
            }
        }

        $resultado = $mutator($estado);
        $all[$key] = [
            'val' => json_encode($estado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            'exp' => $ttl > 0 ? time() + $ttl : 0,
        ];

        return $resultado;
    }, $namespace);
}

function api_chat_storage_get(string $key, string $namespace = 'storage'): ?string
{
    return api_chat_storage_mutate(static function (array &$all) use ($key): ?string {
        return api_chat_storage_read_value($all, $key);
    }, $namespace);
}

function api_chat_storage_set(string $key, string $value, int $ttl = 0, string $namespace = 'storage'): void
{
    api_chat_storage_mutate(static function (array &$all) use ($key, $value, $ttl): void {
        $all[$key] = ['val' => $value, 'exp' => $ttl > 0 ? time() + $ttl : 0];
    }, $namespace);
}

function api_chat_storage_setnx(string $key, string $value, int $ttl = 0, string $namespace = 'storage'): bool
{
    return api_chat_storage_mutate(static function (array &$all) use ($key, $value, $ttl): bool {
        if (api_chat_storage_read_value($all, $key) !== null) {
            return false;
        }

        $all[$key] = ['val' => $value, 'exp' => $ttl > 0 ? time() + $ttl : 0];
        return true;
    }, $namespace);
}

function api_chat_storage_invalidate_cache_domain(string $domain): int
{
    $key = 'cache_domain_version|' . strtolower(trim($domain));
    $atual = (int) (api_chat_storage_get($key) ?? '0');
    $novo = $atual + 1;
    api_chat_storage_set($key, (string) $novo);
    return $novo;
}

function api_chat_middleware_init(string $baseDir, string $endpoint): void
{
    $rid = bin2hex(random_bytes(8));
    $tenant = (string) ($_SERVER['HTTP_X_TENANT'] ?? 'default');
    $GLOBALS['api_chat_ctx'] = [
        'baseDir' => $baseDir,
        'endpoint' => $endpoint,
        'requestId' => $rid,
        'compliance' => [
            'policy' => api_chat_compliance_default_policy(),
            'tenantPolicy' => api_chat_compliance_default_policy()['defaultTenant'] ?? [],
        ],
        'tenant' => $tenant,
    ];

    api_chat_middleware_aplicar_rate_limit($endpoint);
}

function api_chat_middleware_cliente_ip(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $nomeHeader) {
        $valor = trim((string) ($_SERVER[$nomeHeader] ?? ''));
        if ($valor === '') {
            continue;
        }

        if ($nomeHeader === 'HTTP_X_FORWARDED_FOR' && strpos($valor, ',') !== false) {
            $partes = explode(',', $valor);
            $valor = trim((string) ($partes[0] ?? ''));
        }

        if ($valor !== '') {
            return $valor;
        }
    }

    return '0.0.0.0';
}

function api_chat_middleware_rate_limit_config(): array
{
    $limite = (int) (getenv('API_CHAT_RATE_LIMIT_PER_MINUTE') ?: 90);
    $janelaSegundos = (int) (getenv('API_CHAT_RATE_LIMIT_WINDOW_SECONDS') ?: 60);
    $limite = max(10, min($limite, 10000));
    $janelaSegundos = max(10, min($janelaSegundos, 300));

    $allowlistRaw = trim((string) getenv('API_CHAT_RATE_LIMIT_ALLOWLIST_IPS'));
    $allowlist = [];
    if ($allowlistRaw !== '') {
        foreach (preg_split('/[\s,;]+/', $allowlistRaw) as $item) {
            $ip = trim((string) $item);
            if ($ip !== '') {
                $allowlist[$ip] = true;
            }
        }
    }

    return [
        'habilitado' => (string) getenv('API_CHAT_RATE_LIMIT_DISABLED') !== '1',
        'limite' => $limite,
        'janelaSegundos' => $janelaSegundos,
        'allowlist' => $allowlist,
    ];
}

function api_chat_middleware_aplicar_rate_limit(string $endpoint): void
{
    $config = api_chat_middleware_rate_limit_config();
    if (!$config['habilitado']) {
        return;
    }

    $ip = api_chat_middleware_cliente_ip();
    if (isset($config['allowlist'][$ip])) {
        return;
    }

    $janela = (int) $config['janelaSegundos'];
    $bucketAtual = (int) floor(time() / $janela);
    $bucketKey = api_chat_storage_key($endpoint, 'ratelimit', hash('sha256', $ip) . ':' . $bucketAtual);
    $raw = api_chat_storage_get($bucketKey, 'ratelimit');
    $contador = max(0, (int) $raw);
    $contador++;
    api_chat_storage_set($bucketKey, (string) $contador, $janela + 2, 'ratelimit');

    $restante = max(0, (int) $config['limite'] - $contador);
    header('X-RateLimit-Limit: ' . (int) $config['limite']);
    header('X-RateLimit-Remaining: ' . $restante);
    header('X-RateLimit-Window-Seconds: ' . $janela);

    if ($contador <= (int) $config['limite']) {
        return;
    }

    $retryAfter = max(1, ($bucketAtual + 1) * $janela - time());
    header('Retry-After: ' . $retryAfter);
    api_chat_middleware_responder(429, [
        'ok' => false,
        'erro' => 'Muitas requisições em janela curta. Tente novamente em instantes.',
        'codigoErro' => 'RATE_LIMIT_EXCEEDED',
        'rateLimit' => [
            'limite' => (int) $config['limite'],
            'janelaSegundos' => $janela,
            'retryAfterSeconds' => $retryAfter,
        ],
        'detalhes' => [['retryAfterSeconds' => $retryAfter]],
    ]);
}


function api_chat_middleware_raw_input(): string
{
    if (array_key_exists('api_chat_cached_raw_input', $GLOBALS)) {
        return is_string($GLOBALS['api_chat_cached_raw_input']) ? $GLOBALS['api_chat_cached_raw_input'] : '';
    }

    $raw = file_get_contents('php://input');
    $GLOBALS['api_chat_cached_raw_input'] = is_string($raw) ? $raw : '';

    return $GLOBALS['api_chat_cached_raw_input'];
}

function api_chat_payload_json(): array
{
    $raw = api_chat_middleware_raw_input();
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $payload = json_decode($raw, true);
    return is_array($payload) ? $payload : [];
}

function api_chat_documento_somente_digitos(string $valor): string
{
    return preg_replace('/\D+/', '', $valor) ?? '';
}

function api_chat_documento_validar_cpf(string $cpf): bool
{
    if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf) === 1) {
        return false;
    }

    $soma = 0;
    for ($i = 0; $i < 9; $i++) {
        $soma += (int) $cpf[$i] * (10 - $i);
    }

    $resto = $soma % 11;
    $digito = $resto < 2 ? 0 : 11 - $resto;
    if ((int) $cpf[9] !== $digito) {
        return false;
    }

    $soma = 0;
    for ($i = 0; $i < 10; $i++) {
        $soma += (int) $cpf[$i] * (11 - $i);
    }

    $resto = $soma % 11;
    $digito = $resto < 2 ? 0 : 11 - $resto;
    return (int) $cpf[10] === $digito;
}

function api_chat_documento_validar_cnpj(string $cnpj): bool
{
    if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1{13}$/', $cnpj) === 1) {
        return false;
    }

    $pesosPrimeiro = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
    $pesosSegundo = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

    $soma = 0;
    for ($i = 0; $i < 12; $i++) {
        $soma += (int) $cnpj[$i] * $pesosPrimeiro[$i];
    }

    $resto = $soma % 11;
    $digito1 = $resto < 2 ? 0 : 11 - $resto;

    $soma = 0;
    for ($i = 0; $i < 13; $i++) {
        $soma += (int) $cnpj[$i] * $pesosSegundo[$i];
    }

    $resto = $soma % 11;
    $digito2 = $resto < 2 ? 0 : 11 - $resto;
    return $digito1 === (int) $cnpj[12] && $digito2 === (int) $cnpj[13];
}

function api_chat_documento_validar_generico(string $documento): bool
{
    $documento = strtoupper(preg_replace('/[^0-9A-Z]/', '', $documento));
    if ($documento === '') {
        return false;
    }

    $len = strlen($documento);
    if ($len === 11) {
        return preg_match('/^\d{11}$/', $documento) === 1;
    }

    return $len === 14;
}

function api_chat_validar_nome_completo_padrao(string $nome): bool
{
    $partes = preg_split('/\s+/', trim($nome)) ?: [];
    $partes = array_values(array_filter($partes, static function ($parte): bool {
        return api_chat_strlen((string) $parte) >= 2;
    }));

    return count($partes) >= 2;
}



function api_chat_router_obter_acao(?string $acaoPadrao = null): string
{
    $acao = '';
    if (isset($_GET['acao']) && is_string($_GET['acao'])) {
        $acao = trim($_GET['acao']);
    }

    if ($acao === '') {
        $raw = api_chat_middleware_raw_input();
        $payload = json_decode($raw, true);
        if (is_array($payload) && isset($payload['acao']) && is_string($payload['acao'])) {
            $acao = trim($payload['acao']);
        }
    }

    if ($acao === '' && $acaoPadrao !== null) {
        $acao = $acaoPadrao;
    }

    return $acao;
}

/**
 * @param array<string, array{arquivo: string, metodo?: string}> $rotas
 */
function api_chat_router_despachar(string $acao, array $rotas): bool
{
    if (!isset($rotas[$acao])) {
        return false;
    }

    $rota = $rotas[$acao];
    if (isset($rota['metodo']) && is_string($rota['metodo']) && $rota['metodo'] !== '') {
        $_SERVER['REQUEST_METHOD'] = strtoupper($rota['metodo']);
    }

    require __DIR__ . '/' . $rota['arquivo'];
    return true;
}

function api_chat_router_resposta_acao_invalida(string $origem, string $acao, array $detalhesExtras = []): void
{
    $acaoNormalizada = trim($acao);
    $statusCode = $acaoNormalizada === '' ? 422 : 400;

    $detalhes = [[
        'campo' => 'acao',
        'regra' => $acaoNormalizada === '' ? 'obrigatorio' : 'enum',
        'valorRecebido' => $acao,
        'mensagem' => $acaoNormalizada === ''
            ? 'Informe o campo "acao" com uma action suportada por este endpoint.'
            : 'Ação inválida para ' . $origem . '.',
    ]];

    if ($detalhesExtras !== []) {
        $detalhes[] = $detalhesExtras;
    }

    api_chat_middleware_responder($statusCode, [
        'ok' => false,
        'codigoErro' => $acaoNormalizada === '' ? 'VALIDATION_ERROR' : 'INVALID_ACTION',
        'erro' => $acaoNormalizada === ''
            ? 'Nenhuma action foi informada para ' . $origem . '.'
            : 'Ação inválida para ' . $origem . '.',
        'detalhes' => $detalhes,
    ]);
}

function api_chat_payload_json_or_error(): array
{
    $raw = api_chat_middleware_raw_input();
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $payload = json_decode($raw, true);
    if (!is_array($payload) && json_last_error() !== JSON_ERROR_NONE) {
        api_chat_middleware_responder(400, [
            'ok' => false,
            'codigoErro' => 'PAYLOAD_INVALIDO',
            'erro' => 'Payload JSON inválido.',
            'detalhes' => [[
                'campo' => 'body',
                'regra' => 'json_valido',
                'valorRecebido' => null,
                'mensagem' => json_last_error_msg(),
            ]],
        ]);
    }

    return is_array($payload) ? $payload : [];
}

function api_chat_middleware_contexto(): array
{
    return is_array($GLOBALS['api_chat_ctx'] ?? null) ? $GLOBALS['api_chat_ctx'] : [];
}

function api_chat_middleware_request_id_atual(): string
{
    return (string) (($GLOBALS['api_chat_ctx']['requestId'] ?? 'req-' . bin2hex(random_bytes(4))));
}

function api_chat_middleware_set_job_context(string $jobId, int $retryAfter = 2): void
{
    header('X-Job-Id: ' . $jobId);
    header('Retry-After: ' . max(1, $retryAfter));
}

function api_chat_middleware_idempotency_header_key(): string
{
    return trim((string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? ''));
}

function api_chat_middleware_idempotency_iniciar(string $endpoint, string $key, array $payload): array
{
    $contratoBase = ['status' => 'new', 'response' => null, 'error' => null];
    if ($key === '') {
        return $contratoBase;
    }

    $storageKey = api_chat_storage_key($endpoint, 'idempotency', $key);
    $payloadHash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $raw = api_chat_storage_get($storageKey);
    if (is_string($raw)) {
        $data = json_decode($raw, true);
        if (is_array($data)) {
            $storedHash = '';
            if (isset($data['payloadHash']) && is_string($data['payloadHash'])) {
                $storedHash = $data['payloadHash'];
            } elseif (isset($data['payload']) && is_array($data['payload'])) {
                $storedHash = hash('sha256', json_encode($data['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }

            if ($storedHash !== '' && !hash_equals($storedHash, $payloadHash)) {
                return [
                    'status' => 'conflict',
                    'response' => null,
                    'error' => ['code' => 'IDEMPOTENCY_CONFLICT', 'message' => 'Idempotency-Key já foi usado com payload diferente.'],
                ];
            }

            if (isset($data['response']) && is_array($data['response'])) {
                return ['status' => 'replay', 'response' => $data['response'], 'error' => null];
            }

            return [
                'status' => 'in_progress',
                'response' => null,
                'error' => ['code' => 'IDEMPOTENCY_IN_PROGRESS', 'message' => 'Requisição idempotente ainda em processamento.'],
            ];
        }
    }

    api_chat_storage_set($storageKey, json_encode([
        'payload' => $payload,
        'payloadHash' => $payloadHash,
        'createdAt' => time(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 600);
    return $contratoBase;
}

function api_chat_middleware_idempotency_finalizar(string $endpoint, string $key, int $status, array $payload): void
{
    if ($key === '') {
        return;
    }
    $storageKey = api_chat_storage_key($endpoint, 'idempotency', $key);

    $dadosPersistidos = [];
    $raw = api_chat_storage_get($storageKey);
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $dadosPersistidos = $decoded;
        }
    }

    $payloadOriginal = is_array($dadosPersistidos['payload'] ?? null) ? $dadosPersistidos['payload'] : null;
    $payloadHash = isset($dadosPersistidos['payloadHash']) && is_string($dadosPersistidos['payloadHash'])
        ? $dadosPersistidos['payloadHash']
        : ($payloadOriginal !== null ? hash('sha256', json_encode($payloadOriginal, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) : '');

    api_chat_storage_set($storageKey, json_encode([
        'payload' => $payloadOriginal,
        'payloadHash' => $payloadHash,
        'response' => ['status' => $status, 'body' => $payload],
        'updatedAt' => time(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 3600);
}

function api_chat_middleware_responder(int $status, array $payload, bool $limparBuffer = false): void
{
    if ($limparBuffer) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }

    $payload = api_chat_middleware_normalizar_envelope($status, $payload);
    $ctx = api_chat_middleware_contexto();
    api_chat_registrar_treinamento_erros_telemetria(
        (string) ($ctx['baseDir'] ?? dirname(__DIR__)),
        (string) ($ctx['endpoint'] ?? ''),
        $status,
        $payload
    );

    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_chat_middleware_normalizar_envelope(int $statusHttp, array $payload): array
{
    $timestamp = gmdate('c');
    $requestId = (string) ($payload['requestId'] ?? api_chat_middleware_request_id_atual());
    $warnings = is_array($payload['warnings'] ?? null) ? array_values($payload['warnings']) : [];
    $redactionsApplied = (bool) ($payload['redactionsApplied'] ?? false);

    if (($payload['status'] ?? null) === 'error' && isset($payload['codigoErro'])) {
        $payload['requestId'] = $requestId;
        $payload['timestamp'] = $payload['timestamp'] ?? $timestamp;
        $payload['warnings'] = $warnings;
        $payload['redactionsApplied'] = $redactionsApplied;
        return $payload;
    }

    $isError = $statusHttp >= 400 || (isset($payload['ok']) && $payload['ok'] === false) || trim((string) ($payload['codigoErro'] ?? '')) !== '';
    if ($isError) {
        $detalhes = $payload['detalhes'] ?? [];
        if (!is_array($detalhes)) {
            $detalhes = [['mensagem' => (string) $detalhes]];
        }

        return [
            'status' => 'error',
            'codigoErro' => (string) ($payload['codigoErro'] ?? 'INTERNAL_ERROR'),
            'mensagemUsuario' => (string) ($payload['mensagemUsuario'] ?? $payload['erro'] ?? 'Falha ao processar solicitação.'),
            'mensagemTecnica' => (string) ($payload['mensagemTecnica'] ?? $payload['erroTecnico'] ?? $payload['erro'] ?? ''),
            'detalhes' => $detalhes,
            'warnings' => $warnings,
            'requestId' => $requestId,
            'timestamp' => $timestamp,
            'redactionsApplied' => $redactionsApplied,
        ];
    }

    $status = (string) ($payload['status'] ?? 'success');
    $data = $payload['data'] ?? $payload;
    if (!is_array($data)) {
        $data = ['resultado' => $data];
    }
    unset($data['ok'], $data['status'], $data['requestId'], $data['timestamp'], $data['warnings'], $data['redactionsApplied']);

    return [
        'status' => $status,
        'data' => $data,
        'warnings' => $warnings,
        'requestId' => $requestId,
        'timestamp' => $timestamp,
        'redactionsApplied' => $redactionsApplied,
    ];
}

function api_chat_registrar_treinamento_erros_telemetria(string $baseDir, string $endpoint, int $status, array $payload): void
{
    $temErro = $status >= 400 || trim((string) ($payload['erro'] ?? '')) !== '' || trim((string) ($payload['codigoErro'] ?? '')) !== '';
    $telemetria = is_array($payload['telemetria'] ?? null) ? $payload['telemetria'] : [];
    if (!$temErro && $telemetria === []) {
        return;
    }

    $registro = [
        'tipo' => 'erro_telemetria',
        'timestamp' => gmdate('c'),
        'endpoint' => $endpoint,
        'requestId' => (string) ($payload['requestId'] ?? api_chat_middleware_request_id_atual()),
        'status' => $status,
        'ok' => (bool) ($payload['ok'] ?? false),
        'erro' => trim((string) ($payload['erro'] ?? '')),
        'codigoErro' => trim((string) ($payload['codigoErro'] ?? '')),
        'telemetria' => api_chat_privacy_redact_recursive($telemetria),
    ];

    api_chat_append_jsonl(api_chat_treinamento_arquivo_erros_telemetria($baseDir), $registro);
}

function api_chat_privacy_redact_text(string $texto): string
{
    $redigido = $texto;
    $redigido = preg_replace_callback('/\b\d{3}\.?\d{3}\.?\d{3}\-?\d{2}\b/', static function (array $m): string {
        $d = preg_replace('/\D+/', '', (string) $m[0]) ?? '';
        $fim = substr($d, -2);
        return str_repeat('*', max(0, strlen($d) - 2)) . $fim;
    }, $redigido) ?? $redigido;

    $redigido = preg_replace_callback('/\b\d{2}\.?\d{3}\.?\d{3}\/?\d{4}\-?\d{2}\b/', static function (array $m): string {
        $d = preg_replace('/\D+/', '', (string) $m[0]) ?? '';
        $fim = substr($d, -2);
        return str_repeat('*', max(0, strlen($d) - 2)) . $fim;
    }, $redigido) ?? $redigido;

    $redigido = preg_replace_callback('/\b([a-zA-Z0-9._%+\-]{2})[a-zA-Z0-9._%+\-]*@([a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})\b/', static function (array $m): string {
        return $m[1] . '***@' . $m[2];
    }, $redigido) ?? $redigido;

    $redigido = preg_replace_callback('/(?:\+?55\s?)?(\(?\d{2}\)?\s?)?\d{4,5}[\s\-]?\d{4}\b/', static function (array $m): string {
        $d = preg_replace('/\D+/', '', (string) $m[0]) ?? '';
        if (strlen($d) < 10) {
            return $m[0];
        }
        $ddd = substr($d, 0, 2);
        $fim = substr($d, -2);
        return $ddd . str_repeat('*', max(0, strlen($d) - 4)) . $fim;
    }, $redigido) ?? $redigido;

    return $redigido;
}

function api_chat_privacy_redact_recursive($valor)
{
    if (is_string($valor)) {
        return api_chat_privacy_redact_text($valor);
    }

    if (!is_array($valor)) {
        return $valor;
    }

    $saida = [];
    foreach ($valor as $chave => $item) {
        $saida[$chave] = api_chat_privacy_redact_recursive($item);
    }

    return $saida;
}

function api_chat_append_jsonl(string $arquivo, array $registro): void
{
    $linha = json_encode($registro, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($linha) || $linha === '') {
        return;
    }

    $pasta = dirname($arquivo);
    if (!is_dir($pasta)) {
        @mkdir($pasta, 0775, true);
    }
    if (!is_dir($pasta)) {
        return;
    }

    @file_put_contents($arquivo, $linha . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function api_chat_treinamento_arquivo_unificado(string $baseDir): string
{
    return rtrim($baseDir, '/\\') . '/APIChat/TreinamentoMemórias/treinamento_unificado.jsonl';
}

function api_chat_treinamento_arquivo_erros_telemetria(string $baseDir): string
{
    return rtrim($baseDir, '/\\') . '/APIChat/TreinamentoMemórias/erros_telemetria.jsonl';
}

function api_chat_treinamento_arquivo_memory_retrieve(string $baseDir): string
{
    return rtrim($baseDir, '/\\') . '/APIChat/TreinamentoMemórias/memory_retrieve.jsonl';
}

function api_chat_treinamento_arquivo_telemetria_uso(string $baseDir): string
{
    return rtrim($baseDir, '/\\') . '/APIChat/TreinamentoMemórias/telemetria_uso.jsonl';
}

function api_chat_registrar_evento_unificado(string $baseDir, array $registro): void
{
    api_chat_append_jsonl(api_chat_treinamento_arquivo_unificado($baseDir), $registro);
}

function api_chat_registrar_evento_memory_retrieve(string $baseDir, array $registro): void
{
    api_chat_append_jsonl(api_chat_treinamento_arquivo_memory_retrieve($baseDir), $registro);
}

function api_chat_registrar_evento_telemetria_uso(string $baseDir, array $registro): void
{
    api_chat_append_jsonl(api_chat_treinamento_arquivo_telemetria_uso($baseDir), $registro);
}

function api_chat_treinamento_ler_eventos_unificados(string $baseDir): array
{
    $arquivo = api_chat_treinamento_arquivo_unificado($baseDir);
    if (!is_file($arquivo)) {
        return [];
    }

    $linhas = @file($arquivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($linhas)) {
        return [];
    }

    $eventos = [];
    foreach ($linhas as $linha) {
        $decoded = json_decode((string) $linha, true);
        if (is_array($decoded)) {
            $eventos[] = $decoded;
        }
    }

    return $eventos;
}

function auth_api_chat_obter_chave(string $baseDir): string
{
    $ambiente = strtolower(trim((string) (getenv('API_CHAT_ENV') ?: getenv('APP_ENV') ?: getenv('ENVIRONMENT') ?: '')));
    $escopo = strtolower(trim((string) (getenv('API_CHAT_KEY_SCOPE') ?: 'actions')));

    $envCandidates = [];
    if ($ambiente !== '') {
        $envCandidates[] = 'API_CHAT_KEY_' . strtoupper($ambiente);
        $envCandidates[] = 'GPT_ACTIONS_API_KEY_' . strtoupper($ambiente);
        if ($escopo !== '') {
            $envCandidates[] = 'API_CHAT_KEY_' . strtoupper($escopo) . '_' . strtoupper($ambiente);
            $envCandidates[] = 'GPT_ACTIONS_API_KEY_' . strtoupper($escopo) . '_' . strtoupper($ambiente);
        }
    }
    if ($escopo !== '') {
        $envCandidates[] = 'API_CHAT_KEY_' . strtoupper($escopo);
        $envCandidates[] = 'GPT_ACTIONS_API_KEY_' . strtoupper($escopo);
    }
    $envCandidates[] = 'API_CHAT_KEY';
    $envCandidates[] = 'GPT_ACTIONS_API_KEY';

    foreach ($envCandidates as $envName) {
        $env = trim((string) getenv($envName));
        if ($env !== '') {
            return $env;
        }
    }
    $arquivo = rtrim($baseDir, '/\\') . '/Configuracoes/GPTActionsApiKey.ini';
    if (!is_file($arquivo)) {
        return '';
    }
    $txt = trim((string) file_get_contents($arquivo));
    if ($txt === '') {
        return '';
    }
    $ini = @parse_ini_string($txt, true, INI_SCANNER_TYPED);
    if (is_array($ini)) {
        $sections = [];
        if ($ambiente !== '' && $escopo !== '') {
            $sections[] = $ambiente . '.' . $escopo;
            $sections[] = $escopo . '.' . $ambiente;
        }
        if ($ambiente !== '') {
            $sections[] = $ambiente;
        }
        if ($escopo !== '') {
            $sections[] = $escopo;
        }

        foreach ($sections as $section) {
            if (isset($ini[$section]) && is_array($ini[$section])) {
                foreach (['api_key', 'apikey', 'key', 'token'] as $k) {
                    if (!empty($ini[$section][$k])) {
                        return trim((string) $ini[$section][$k]);
                    }
                }
            }
        }

        foreach (['api_key', 'apikey', 'key', 'token'] as $k) {
            if (!empty($ini[$k])) {
                return trim((string) $ini[$k]);
            }
        }
    }
    return trim($txt);
}

function auth_api_chat_extrair_chave_recebida(): string
{
    $headerApiKey = trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ''));
    if ($headerApiKey !== '') {
        return $headerApiKey;
    }

    $authorization = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    if ($authorization !== '') {
        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) === 1) {
            return trim((string) ($matches[1] ?? ''));
        }
        return $authorization;
    }

    if ((string) getenv('API_CHAT_RUNTIME_SESSION_AUTH') === '1') {
        foreach (['API_CHAT_SESSION_API_KEY', 'GPT_ACTIONS_SESSION_API_KEY'] as $envName) {
            $sessionKey = trim((string) getenv($envName));
            if ($sessionKey !== '') {
                return $sessionKey;
            }
        }
    }

    return '';
}

function auth_api_chat_ambiente_producao(): bool
{
    $ambiente = strtolower(trim((string) (getenv('API_CHAT_ENV') ?: getenv('APP_ENV') ?: getenv('ENVIRONMENT') ?: '')));
    return in_array($ambiente, ['prod', 'producao', 'production'], true);
}

function auth_api_chat_fail_open_habilitado(): bool
{
    return (string) getenv('API_CHAT_AUTH_OPTIONAL') === '1';
}

function auth_api_chat_estado_operacional(string $baseDir): array
{
    $chaveEsperada = auth_api_chat_obter_chave($baseDir);
    $failOpenHabilitado = auth_api_chat_fail_open_habilitado();
    $producao = auth_api_chat_ambiente_producao();
    $chaveConfigurada = $chaveEsperada !== '';
    $autenticacaoObrigatoria = !$failOpenHabilitado;

    return [
        'chaveEsperada' => $chaveEsperada,
        'chaveConfigurada' => $chaveConfigurada,
        'failOpenHabilitado' => $failOpenHabilitado,
        'autenticacaoObrigatoria' => $autenticacaoObrigatoria,
        'producao' => $producao,
        'configuracaoValida' => $failOpenHabilitado || $chaveConfigurada,
    ];
}

function auth_api_chat_validar_ou_responder(string $baseDir, string $responder): void
{
    $estado = auth_api_chat_estado_operacional($baseDir);
    if ($estado['failOpenHabilitado']) {
        return;
    }

    if (!$estado['chaveConfigurada']) {
        call_user_func($responder, 500, [
            'ok' => false,
            'erro' => 'Autenticação obrigatória, mas a chave da API não está configurada no servidor.',
            'codigoErro' => 'AUTH_CONFIG_MISSING_API_KEY',
        ]);
    }

    $esperada = (string) $estado['chaveEsperada'];
    $recebida = auth_api_chat_extrair_chave_recebida();
    if ($recebida !== '' && hash_equals($esperada, $recebida)) {
        return;
    }

    call_user_func($responder, 401, [
        'ok' => false,
        'erro' => 'Não autenticado. Informe X-Api-Key válido.',
        'codigoErro' => 'NAO_AUTORIZADO',
    ]);
}

function api_chat_normalizar_limite(int $valor, int $padrao, int $minimo, int $maximo): int
{
    if ($valor <= 0) {
        return $padrao;
    }
    if ($valor < $minimo) {
        return $minimo;
    }
    if ($valor > $maximo) {
        return $maximo;
    }
    return $valor;
}

function api_chat_versao_base_conhecimento(string $baseDir): string
{
    $arquivo = rtrim($baseDir, '/\\') . '/PaginasConsultaProdutos/EmbeddingsCatalogoStatus.json';
    if (!is_file($arquivo)) {
        return 'indisponivel';
    }
    $json = json_decode((string) file_get_contents($arquivo), true);
    if (!is_array($json)) {
        return 'indisponivel';
    }
    return (string) ($json['versao'] ?? $json['version'] ?? date('Ymd'));
}

function api_chat_semantic_index_load(string $baseDir, string $nome): array
{
    $map = [
        'produtos-embeddings' => '/PaginasConsultaProdutos/EmbeddingsCatalogo.json',
    ];
    $rel = $map[$nome] ?? null;
    if (!is_string($rel)) {
        return [];
    }
    $arquivo = rtrim($baseDir, '/\\') . $rel;
    if (!is_file($arquivo)) {
        return [];
    }
    $json = json_decode((string) file_get_contents($arquivo), true);
    return is_array($json) ? $json : [];
}

function api_chat_compliance_default_policy(): array
{
    return [
        'version' => 'lite-1',
        'defaultTenant' => [
            'allowExternalModelPayload' => true,
        ],
    ];
}

function api_chat_compliance_prepare_external_payload(array $payload, array $tenantPolicy): array
{
    unset($payload['senha'], $payload['token'], $payload['apiKey']);
    return $payload;
}

function api_chat_compliance_classificar_texto(string $texto): array
{
    $score = preg_match('/senha|token|cpf|cart[aã]o/i', $texto) ? 0.8 : 0.1;
    return ['score' => $score, 'categoria' => $score > 0.5 ? 'sensivel' : 'normal'];
}

function api_chat_compliance_classificar_risco(array $entrada): array
{
    $texto = json_encode($entrada, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return api_chat_compliance_classificar_texto((string) $texto);
}

function api_chat_memory_owner_key(...$args): string
{
    return 'especialista_ibr';
}

function api_chat_memory_owner_payload_normalizado(array $payload): array
{
    $owner = api_chat_memory_owner_key($payload);

    foreach (['owner', 'ownerId', 'userId', 'clienteId'] as $alias) {
        $payload[$alias] = $owner;
    }

    return $payload;
}

function api_chat_memory_scope_default_ttl(string $scope): int
{
    $scopeNormalizado = api_chat_memory_normalizar_scope($scope);
    $defaults = [
        'session' => 86400,
        'user' => 2592000,
        'workspace' => 7776000,
        'tenant' => 7776000,
    ];

    return (int) ($defaults[$scopeNormalizado] ?? 0);
}

function api_chat_memory_tipo_normalizado(array $payload): string
{
    $tipo = strtolower(trim((string) ($payload['memoryType'] ?? $payload['memoryKind'] ?? $payload['tipoMemoria'] ?? '')));
    return in_array($tipo, ['structural', 'contextual'], true) ? $tipo : 'contextual';
}

function api_chat_memory_ttl_resolver(string $scope, array $payload, string $tipoMemoria): int
{
    $ttlInformado = (int) ($payload['ttlSec'] ?? $payload['ttl'] ?? 0);
    $ttlBase = $ttlInformado > 0 ? $ttlInformado : api_chat_memory_scope_default_ttl($scope);

    if ($tipoMemoria === 'structural' && $ttlInformado <= 0) {
        return 0;
    }

    if ($ttlBase <= 0) {
        return 0;
    }

    $limiteMaximo = $tipoMemoria === 'contextual' ? 7776000 : 31536000;
    return max(1, min($ttlBase, $limiteMaximo));
}

function api_chat_memory_normalizar_scope(string $scope): string
{
    $s = strtolower(trim($scope));
    return $s !== '' ? $s : 'default';
}

function api_chat_memory_scope_persistencia(array $payload, string $fallback = 'global'): string
{
    $scope = api_chat_memory_normalizar_scope((string) ($payload['scope'] ?? $fallback));
    if (!in_array($scope, ['session', 'default'], true)) {
        return $scope;
    }

    $tenantId = trim((string) ($payload['tenantId'] ?? ''));
    if ($tenantId !== '') {
        return 'tenant';
    }

    return 'global';
}

function api_chat_memory_storage_key(): string
{
    return api_chat_storage_key('/APIChat/GerenciarMemorias.php', 'memory', 'db');
}

function api_chat_memory_mutate(string $baseDir, callable $mutator)
{
    return api_chat_storage_json_mutate(api_chat_memory_storage_key(), static function (array &$state) use ($mutator, $baseDir) {
        api_chat_memory_limpar_expiradas($state);
        return $mutator($state, $baseDir);
    });
}

function api_chat_memory_carregar(string $baseDir): array
{
    return api_chat_memory_mutate($baseDir, static function (array &$state): array {
        return $state;
    });
}

function api_chat_memory_salvar(string $baseDir, array $db): void
{
    api_chat_storage_set(api_chat_memory_storage_key(), json_encode($db, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function api_chat_memory_limpar_expiradas(array &$db): int
{
    $now = time();
    $removidos = 0;

    foreach ($db as $owner => &$scopes) {
        if (!is_array($scopes)) {
            unset($db[$owner]);
            $removidos++;
            continue;
        }

        foreach ($scopes as $scope => &$items) {
            if (!is_array($items)) {
                unset($scopes[$scope]);
                $removidos++;
                continue;
            }

            $originais = count($items);
            $items = array_values(array_filter(
                $items,
                static fn($i): bool => is_array($i) && (!isset($i['exp']) || (int) $i['exp'] === 0 || (int) $i['exp'] > $now)
            ));
            $removidos += ($originais - count($items));
        }
    }

    return $removidos;
}


function api_chat_memory_confirmacao_sobrescrita(array $payload): bool
{
    foreach (['confirmed', 'confirmar', 'confirmarSobrescricao', 'force'] as $chave) {
        if (($payload[$chave] ?? null) === true) {
            return true;
        }
    }

    return false;
}

function api_chat_memory_conteudo_normalizado($conteudo): string
{
    if (is_array($conteudo)) {
        $json = json_encode($conteudo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? trim($json) : '';
    }

    return trim((string) $conteudo);
}

function api_chat_memory_chave_semantica(array $payload): string
{
    $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
    foreach (['chave', 'key', 'codigo', 'codigoErro'] as $chave) {
        $valor = trim((string) ($metadata[$chave] ?? ''));
        if ($valor !== '') {
            return strtolower($valor);
        }
    }

    return '';
}

function api_chat_memory_avaliar_conflito(array $itens, array $payload): array
{
    $id = trim((string) ($payload['id'] ?? ''));
    $namespaceNovo = strtolower(trim((string) ($payload['namespace'] ?? '')));
    $conteudoNovo = api_chat_memory_conteudo_normalizado($payload['conteudo'] ?? $payload['content'] ?? $payload['data'] ?? null);
    $chaveNova = api_chat_memory_chave_semantica($payload);

    $duplicada = null;
    $conflito = null;

    foreach ($itens as $item) {
        if (!is_array($item)) {
            continue;
        }

        $conteudoAtual = api_chat_memory_conteudo_normalizado($item['conteudo'] ?? null);
        $idAtual = trim((string) ($item['id'] ?? ''));
        $namespaceAtual = strtolower(trim((string) ($item['namespace'] ?? '')));
        $metadataAtual = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];
        $chaveAtual = '';
        foreach (['chave', 'key', 'codigo', 'codigoErro'] as $chave) {
            $valor = trim((string) ($metadataAtual[$chave] ?? ''));
            if ($valor !== '') {
                $chaveAtual = strtolower($valor);
                break;
            }
        }
        if ($conteudoAtual !== '' && $conteudoAtual === $conteudoNovo && $namespaceAtual === $namespaceNovo && $chaveAtual === $chaveNova) {
            $duplicada = $item;
            continue;
        }

        $mesmoId = $id !== '' && $idAtual !== '' && $id === $idAtual;
        $mesmaChaveSemantica = $chaveNova !== '' && $chaveAtual !== '' && $chaveNova === $chaveAtual;

        if (($mesmoId || $mesmaChaveSemantica) && $conteudoAtual !== $conteudoNovo) {
            $conflito = $item;
            break;
        }
    }

    return ['duplicada' => $duplicada, 'conflito' => $conflito];
}

function api_chat_memory_upsert(array &$state, string $baseDir, array $payload): array
{
    $scope = api_chat_memory_scope_persistencia($payload, 'global');
    $tipoMemoria = api_chat_memory_tipo_normalizado($payload);
    $ttlSec = api_chat_memory_ttl_resolver($scope, $payload, $tipoMemoria);
    $owner = api_chat_memory_owner_key($baseDir, $scope, $payload);
    $state[$owner] = is_array($state[$owner] ?? null) ? $state[$owner] : [];
    $state[$owner][$scope] = is_array($state[$owner][$scope] ?? null) ? $state[$owner][$scope] : [];

    $avaliacao = api_chat_memory_avaliar_conflito($state[$owner][$scope], $payload);
    if (is_array($avaliacao['duplicada'] ?? null)) {
        return ['ok' => true, 'memory' => $avaliacao['duplicada'], 'written' => false, 'duplicada' => true];
    }

    if (is_array($avaliacao['conflito'] ?? null) && !api_chat_memory_confirmacao_sobrescrita($payload)) {
        return [
            'ok' => false,
            'erro' => 'Foi encontrada memória conflitante. Confirme a sobrescrição para continuar.',
            'codigoErro' => 'MEMORIA_REQUER_CONFIRMACAO',
            'requiresConfirmation' => true,
            'conflito' => $avaliacao['conflito'],
        ];
    }

    if (is_array($avaliacao['conflito'] ?? null) && api_chat_memory_confirmacao_sobrescrita($payload)) {
        $idConflito = (string) (($avaliacao['conflito']['id'] ?? ''));
        if ($idConflito !== '') {
            $state[$owner][$scope] = array_values(array_filter(
                $state[$owner][$scope],
                static fn($i): bool => (string) ($i['id'] ?? '') !== $idConflito
            ));
        }
    }

    $conteudo = $payload['conteudo'] ?? $payload['content'] ?? $payload['data'] ?? null;
    if (api_chat_memory_conteudo_normalizado($conteudo) === '') {
        return [
            'ok' => false,
            'erro' => 'Conteúdo da memória é obrigatório para criação.',
            'codigoErro' => 'CONTEUDO_OBRIGATORIO',
        ];
    }

    $item = [
        'id' => (string) ($payload['id'] ?? ('mem_' . bin2hex(random_bytes(6)))),
        'scope' => $scope,
        'ownerKey' => $owner,
        'namespace' => trim((string) ($payload['namespace'] ?? '')),
        'memoryType' => $tipoMemoria,
        'conteudo' => $conteudo,
        'metadata' => is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
        'createdAt' => gmdate('c'),
    ];
    $item['ttlSec'] = $ttlSec;
    $item['exp'] = $ttlSec > 0 ? (time() + $ttlSec) : 0;
    $state[$owner][$scope][] = $item;
    if (($payload['__skipUnifiedLog'] ?? false) !== true) {
        api_chat_registrar_evento_unificado($baseDir, ['tipo' => 'memory_upsert', 'timestamp' => gmdate('c'), 'memory' => $item]);
    }

    return ['ok' => true, 'memory' => $item, 'written' => true, 'sobrescreveuConflito' => is_array($avaliacao['conflito'] ?? null)];
}

function api_chat_memory_importar_treinamento_unificado(array &$state, string $baseDir, array $payload = []): array
{
    $eventos = api_chat_treinamento_ler_eventos_unificados($baseDir);
    $seedStoragePath = rtrim($baseDir, '/\\') . '/APIChat/TreinamentoMemórias/storage.json';

    if (is_file($seedStoragePath)) {
        $seedStorage = json_decode((string) file_get_contents($seedStoragePath), true);
        if (is_array($seedStorage)) {
            foreach ($seedStorage as $seedOwner => $seedScopes) {
                if (!is_array($seedScopes)) {
                    continue;
                }

                foreach ($seedScopes as $seedScope => $seedItems) {
                    if (!is_array($seedItems)) {
                        continue;
                    }

                    foreach ($seedItems as $seedItem) {
                        if (!is_array($seedItem)) {
                            continue;
                        }

                        $eventos[] = [
                            'tipo' => 'memory_upsert',
                            'timestamp' => (string) ($seedItem['createdAt'] ?? gmdate('c')),
                            'memory' => [
                                'id' => (string) ($seedItem['id'] ?? ''),
                                'scope' => (string) ($seedItem['scope'] ?? $seedScope),
                                'ownerKey' => (string) ($seedItem['ownerKey'] ?? $seedOwner),
                                'namespace' => (string) ($seedItem['namespace'] ?? ''),
                                'memoryType' => (string) ($seedItem['memoryType'] ?? 'contextual'),
                                'conteudo' => $seedItem['conteudo'] ?? null,
                                'metadata' => is_array($seedItem['metadata'] ?? null) ? $seedItem['metadata'] : [],
                                'ttlSec' => (int) ($seedItem['ttlSec'] ?? 0),
                            ],
                        ];
                    }
                }
            }
        }
    }

    if ($eventos === []) {
        // compatibilidade com instalações antigas
        $storage = api_chat_storage_all();
        $eventos = $storage['__treinamento_unificado'] ?? [];
    }
    if (!is_array($eventos) || $eventos === []) {
        return ['ok' => true, 'importados' => 0, 'duplicados' => 0, 'processados' => 0, 'ignorado' => 0];
    }

    $scopePadrao = api_chat_memory_normalizar_scope((string) ($payload['scope'] ?? 'global'));
    $ownerPadrao = trim((string) ($payload['owner'] ?? 'anon'));

    $importados = 0;
    $duplicados = 0;
    $processados = 0;
    $ignorado = 0;

    foreach ($eventos as $evento) {
        if (!is_array($evento) || strtolower(trim((string) ($evento['tipo'] ?? ''))) !== 'memory_upsert') {
            $ignorado++;
            continue;
        }

        $memoria = is_array($evento['memory'] ?? null) ? $evento['memory'] : [];
        if ($memoria === []) {
            $ignorado++;
            continue;
        }

        $payloadUpsert = [
            'id' => (string) ($memoria['id'] ?? ''),
            'scope' => api_chat_memory_normalizar_scope((string) ($memoria['scope'] ?? $scopePadrao)),
            'namespace' => (string) ($memoria['namespace'] ?? ''),
            'memoryType' => (string) ($memoria['memoryType'] ?? 'contextual'),
            'conteudo' => $memoria['conteudo'] ?? null,
            'metadata' => is_array($memoria['metadata'] ?? null) ? $memoria['metadata'] : [],
            'ttlSec' => (int) ($memoria['ttlSec'] ?? 0),
            '__skipUnifiedLog' => true,
        ];

        $ownerEvento = trim((string) ($memoria['ownerKey'] ?? $ownerPadrao));
        if ($ownerEvento !== '') {
            $payloadUpsert['owner'] = $ownerEvento;
            $payloadUpsert['ownerId'] = $ownerEvento;
        }

        $resultado = api_chat_memory_upsert($state, $baseDir, $payloadUpsert);
        if (($resultado['ok'] ?? false) !== true) {
            continue;
        }

        $processados++;
        if (($resultado['written'] ?? false) === true) {
            $importados++;
            continue;
        }

        if (($resultado['duplicada'] ?? false) === true) {
            $duplicados++;
        }
    }

    return [
        'ok' => true,
        'importados' => $importados,
        'duplicados' => $duplicados,
        'processados' => $processados,
        'ignorado' => $ignorado,
    ];
}

function api_chat_memory_update(array &$state, string $baseDir, array $payload): array
{
    $scope = api_chat_memory_scope_persistencia($payload, 'global');
    $tipoMemoria = api_chat_memory_tipo_normalizado($payload);
    $ttlSec = api_chat_memory_ttl_resolver($scope, $payload, $tipoMemoria);
    $owner = api_chat_memory_owner_key($baseDir, $scope, $payload);
    $id = trim((string) ($payload['id'] ?? ''));

    if ($id === '') {
        return ['ok' => false, 'erro' => 'Informe id para atualizar.', 'codigoErro' => 'ID_OBRIGATORIO'];
    }

    $items = $state[$owner][$scope] ?? null;
    if (!is_array($items)) {
        return ['ok' => false, 'erro' => 'Memória não encontrada para owner/scope informado.', 'codigoErro' => 'MEMORIA_NAO_ENCONTRADA'];
    }

    foreach ($items as $index => $item) {
        if (!is_array($item) || (string) ($item['id'] ?? '') !== $id) {
            continue;
        }

        $item['conteudo'] = $payload['conteudo'] ?? $payload['content'] ?? $payload['data'] ?? null;
        $item['metadata'] = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $item['memoryType'] = $tipoMemoria;
        $item['createdAt'] = (string) ($item['createdAt'] ?? gmdate('c'));
        $item['updatedAt'] = gmdate('c');

        $item['ttlSec'] = $ttlSec;
        $item['exp'] = $ttlSec > 0 ? (time() + $ttlSec) : 0;

        $state[$owner][$scope][$index] = $item;
        api_chat_registrar_evento_unificado($baseDir, ['tipo' => 'memory_update', 'timestamp' => gmdate('c'), 'memory' => $item]);

        return ['ok' => true, 'memory' => $item, 'written' => true, 'updated' => true];
    }

    return ['ok' => false, 'erro' => 'Memória não encontrada para owner/scope informado.', 'codigoErro' => 'MEMORIA_NAO_ENCONTRADA'];
}

function api_chat_memory_retrieve(array $state, string $baseDir, array $payload): array
{
    $scopePadrao = api_chat_memory_scope_persistencia($payload, 'global');
    $owner = api_chat_memory_owner_key($baseDir, $scopePadrao, $payload);
    $limit = max(1, (int) ($payload['limite'] ?? $payload['limit'] ?? 20));
    $cursorRaw = (string) ($payload['cursor'] ?? '');
    $cursor = ctype_digit($cursorRaw) ? max(0, (int) $cursorRaw) : 0;
    $tipoMemoria = strtolower(trim((string) ($payload['memoryType'] ?? $payload['memoryKind'] ?? '')));
    $namespace = strtolower(trim((string) ($payload['namespace'] ?? '')));
    $query = strtolower(trim((string) ($payload['query'] ?? '')));

    $scopes = [];
    if (is_array($payload['scopes'] ?? null)) {
        foreach ($payload['scopes'] as $scopeRaw) {
            $scopeNormalizado = api_chat_memory_scope_persistencia(['scope' => (string) $scopeRaw] + $payload, $scopePadrao);
            if ($scopeNormalizado !== '' && !in_array($scopeNormalizado, $scopes, true)) {
                $scopes[] = $scopeNormalizado;
            }
        }
    }
    if ($scopes === []) {
        $scopes[] = $scopePadrao;
    }

    $items = [];
    foreach ($scopes as $scopeAtual) {
        $itemsScope = $state[$owner][$scopeAtual] ?? [];
        if (!is_array($itemsScope)) {
            continue;
        }

        foreach ($itemsScope as $item) {
            if (is_array($item)) {
                $items[] = $item;
            }
        }
    }

    if (in_array($tipoMemoria, ['contextual', 'structural'], true)) {
        $items = array_values(array_filter($items, static fn($item): bool => strtolower((string) ($item['memoryType'] ?? 'contextual')) === $tipoMemoria));
    }

    if ($namespace !== '') {
        $items = array_values(array_filter($items, static fn($item): bool => strtolower(trim((string) ($item['namespace'] ?? ''))) === $namespace));
    }

    if ($query !== '') {
        $items = array_values(array_filter($items, static function ($item) use ($query): bool {
            $conteudo = strtolower(api_chat_memory_conteudo_normalizado($item['conteudo'] ?? ''));
            return $conteudo !== '' && str_contains($conteudo, $query);
        }));
    }

    $items = array_values($items);
    $total = count($items);
    $offset = min($cursor, $total);
    $pagina = array_slice($items, $offset, $limit);
    $proximoCursor = ($offset + count($pagina)) < $total ? (string) ($offset + count($pagina)) : null;

    return [
        'ok' => true,
        'scope' => $scopePadrao,
        'scopes' => $scopes,
        'ownerKey' => $owner,
        'items' => $pagina,
        'total' => $total,
        'limit' => $limit,
        'cursor' => (string) $offset,
        'nextCursor' => $proximoCursor,
    ];
}

function api_chat_memory_delete(array &$state, string $id = '', string $scope = 'session', string $ownerKey = ''): int
{
    $removidas = 0;
    if ($ownerKey !== '') {
        if (isset($state[$ownerKey][$scope]) && is_array($state[$ownerKey][$scope])) {
            $removidas = count($state[$ownerKey][$scope]);
            $state[$ownerKey][$scope] = [];
        }
        return $removidas;
    }

    foreach ($state as $owner => &$scopes) {
        if (!is_array($scopes)) { continue; }
        foreach ($scopes as $sc => &$items) {
            if (!is_array($items)) { continue; }
            $before = count($items);
            $items = array_values(array_filter($items, static fn($i): bool => (string)($i['id'] ?? '') !== $id));
            $removidas += ($before - count($items));
        }
    }

    return $removidas;
}




function api_chat_memory_definir_privacy(array &$state, string $scope, string $ownerKey, bool $optIn): array
{
    $state['_privacy'][$ownerKey][$scope] = ['optIn' => $optIn, 'updatedAt' => gmdate('c')];
    return ['ok' => true, 'optIn' => $optIn];
}

function api_chat_async_job_store_key(string $endpoint): string
{
    return api_chat_storage_key($endpoint, 'jobs', 'db');
}

function api_chat_async_job_load(string $endpoint): array
{
    $raw = api_chat_storage_get(api_chat_async_job_store_key($endpoint));
    $db = is_string($raw) ? json_decode($raw, true) : [];
    return is_array($db) ? $db : [];
}

function api_chat_async_job_save(string $endpoint, array $db): void
{
    api_chat_storage_set(api_chat_async_job_store_key($endpoint), json_encode($db, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function api_chat_async_job_create(string $endpoint, array $job): string
{
    $db = api_chat_async_job_load($endpoint);
    $id = 'job_' . bin2hex(random_bytes(8));
    $job['jobId'] = $id;
    $job['createdAt'] = gmdate('c');
    $db[$id] = $job;
    api_chat_async_job_save($endpoint, $db);
    return $id;
}

function api_chat_async_job_get(string $endpoint, string $jobId): ?array
{
    $db = api_chat_async_job_load($endpoint);
    return is_array($db[$jobId] ?? null) ? $db[$jobId] : null;
}

function api_chat_async_job_cancel(string $endpoint, string $jobId): ?array
{
    $db = api_chat_async_job_load($endpoint);
    if (!is_array($db[$jobId] ?? null)) {
        return null;
    }
    $db[$jobId]['status'] = 'cancelled';
    $db[$jobId]['cancelledAt'] = gmdate('c');
    api_chat_async_job_save($endpoint, $db);
    return $db[$jobId];
}

function inference_profiles_catalog(): array
{
    return [
        'fast' => ['sla' => 'low_latency', 'max_tokens' => 800],
        'balanced' => ['sla' => 'balanced', 'max_tokens' => 2400],
        'deep' => ['sla' => 'high_quality', 'max_tokens' => 4800],
    ];
}

function inference_profiles_normalize(string $profile): string
{
    $p = strtolower(trim($profile));
    return array_key_exists($p, inference_profiles_catalog()) ? $p : 'balanced';
}

function inference_profiles_token_bucket(int $tokens): string
{
    if ($tokens <= 800) return '0_800';
    if ($tokens <= 2400) return '801_2400';
    return '2401_plus';
}

function inference_profiles_context_key(array $payload, string $tipoTarefa, int $tokens): string
{
    $tenant = strtolower(trim((string) ($payload['tenant'] ?? 'default')));
    return $tipoTarefa . '|' . $tenant . '|' . inference_profiles_token_bucket($tokens);
}

function inference_profiles_resolve(array $payload): array
{
    $requested = (string) ($payload['perfil'] ?? $payload['profile'] ?? 'balanced');
    $applied = inference_profiles_normalize($requested);
    $cfg = inference_profiles_catalog()[$applied];
    $cfg['requested_profile'] = $requested;
    $cfg['applied_profile'] = $applied;
    return $cfg;
}

function api_chat_tool_runtime_iniciar(string $baseDir, string $tool, array $payload, array $meta = []): array
{
    $GLOBALS['api_chat_tool_runtime'] = [
        'tool' => $tool,
        'startedAt' => microtime(true),
        'deadline' => microtime(true) + (((int)($meta['timeoutMs'] ?? $meta['timeout_ms'] ?? 3000))/1000),
        'steps' => [],
        'decisions' => [],
        'compensations' => [],
        'status' => 'running',
    ];
    return $GLOBALS['api_chat_tool_runtime'];
}
function api_chat_tool_runtime_etapa(string $etapa, string $status, array $extra = []): void { $GLOBALS['api_chat_tool_runtime']['steps'][$etapa]=['status'=>$status]+$extra; }
function api_chat_tool_runtime_registrar_decisao(string $nome, array $dados = []): void { $GLOBALS['api_chat_tool_runtime']['decisions'][]=['nome'=>$nome,'dados'=>$dados,'ts'=>gmdate('c')]; }
function api_chat_tool_runtime_registrar_compensacao(string $nome, callable $fn): void { $GLOBALS['api_chat_tool_runtime']['compensations'][$nome]=$fn; }
function api_chat_tool_runtime_compensar(string $motivo=''): array { $executadas=[]; foreach(($GLOBALS['api_chat_tool_runtime']['compensations'] ?? []) as $nome=>$fn){ try{$fn();$executadas[]=$nome;}catch(Throwable $e){}} return $executadas; }
function api_chat_tool_runtime_timeout_excedido(): bool { return microtime(true) > (float)($GLOBALS['api_chat_tool_runtime']['deadline'] ?? PHP_INT_MAX); }
function api_chat_tool_runtime_chamar(...$args): array {
    try {
        if (count($args) >= 4 && is_string($args[3]) && function_exists($args[3])) {
            $data = call_user_func($args[3], $args[2]);
            return ['ok' => true, 'data' => is_array($data) ? $data : ['resultado' => $data], 'attempts' => 1];
        }
        $fn = $args[0] ?? null;
        if (is_callable($fn)) {
            $r = $fn();
            return ['ok' => true, 'data' => is_array($r) ? $r : ['resultado' => $r], 'attempts' => 1];
        }
        return ['ok' => false, 'errorType' => 'invalid_callable', 'error' => 'Executável inválido.', 'attempts' => 0];
    } catch (Throwable $e) {
        return ['ok' => false, 'errorType' => 'exception', 'error' => $e->getMessage(), 'attempts' => 1, 'codigoErro' => 'TOOL_ERROR'];
    }
}
function api_chat_tool_runtime_finalizar(string $baseDir, bool $ok, array $resposta = [], array $meta = []): void { $GLOBALS['api_chat_tool_runtime']['status']=$ok?'completed':'failed'; }
