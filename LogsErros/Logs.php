<?php
function log_event($message, array $context = []) {
    if (!logs_collection_enabled()) {
        return;
    }

    $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
    $baseDir = $documentRoot !== '' ? rtrim($documentRoot, DIRECTORY_SEPARATOR) : dirname(__DIR__);
    $logDir = $baseDir . '/LogsErros';
    if (!is_dir($logDir)) {
        if (!mkdir($logDir, 0700, true) && !is_dir($logDir)) {
            error_log('Failed to create log directory: ' . $logDir);
            return;
        }
    }

    ensure_request_id();

    $payload = normalize_log_payload($message, $context);
    $rawMessage = json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($rawMessage === false) {
        $rawMessage = json_encode(['mensagem' => 'Falha ao serializar payload de log.']);
    }

    if (
        strpos($rawMessage, '"componente":"fila_jobs"') !== false &&
        strpos($rawMessage, '"nivel":"info"') !== false
    ) {
        return;
    }

    $sanitized = sanitize_sensitive_data($rawMessage);

    if (
        strpos($sanitized, '"componente":"fila_jobs"') !== false &&
        strpos($sanitized, '"nivel":"info"') !== false
    ) {
        return;
    }

    $entry = $sanitized . PHP_EOL;
    $logFile = $logDir . '/site.log';
    if (file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX) === false) {
        error_log('Failed to write to log file: ' . $logFile);
        return;
    }

    $shouldLogToPhpErrorLog = true;
    $nivel = strtolower((string) ($payload['nivel'] ?? ''));

    if ($nivel === 'info' || strpos($sanitized, '"nivel":"info"') !== false) {
        $shouldLogToPhpErrorLog = false;
    }

    if (
        strpos($sanitized, '"componente":"fila_jobs"') !== false &&
        strpos($sanitized, '"nivel":"info"') === false
    ) {
        $shouldLogToPhpErrorLog = true;
    }

    chmod($logFile, 0600);

    if ($shouldLogToPhpErrorLog) {
        error_log(trim($entry));
    }

    enviar_log_monitoramento($payload, $sanitized);
    enviar_log_elastic($payload, $sanitized);
    enviar_log_sentry($payload);
    enviar_alertas_log($payload, $sanitized);
}

function log_tag_error(array $payload): void
{
    if (!logs_collection_enabled()) {
        return;
    }

    $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
    $baseDir = $documentRoot !== '' ? rtrim($documentRoot, DIRECTORY_SEPARATOR) : dirname(__DIR__);
    $logDir = $baseDir . '/LogsErros';
    if (!is_dir($logDir)) {
        if (!mkdir($logDir, 0700, true) && !is_dir($logDir)) {
            error_log('Failed to create tag log directory: ' . $logDir);
            return;
        }
    }

    ensure_request_id();

    $payload['componente'] = $payload['componente'] ?? 'tags_google';
    $normalized = normalize_log_payload($payload);
    $rawMessage = json_encode(
        $normalized,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($rawMessage === false) {
        $rawMessage = json_encode(['mensagem' => 'Falha ao serializar payload de log.']);
    }

    $sanitized = sanitize_sensitive_data($rawMessage);
    $entry = $sanitized . PHP_EOL;
    $logFile = $logDir . '/tags-google.log';
    if (file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX) === false) {
        error_log('Failed to write to tag log file: ' . $logFile);
        return;
    }

    chmod($logFile, 0600);
}

function ensure_request_id(): string
{
    $requestId = get_request_id();
    if ($requestId !== '') {
        return $requestId;
    }

    $requestId = bin2hex(random_bytes(16));
    $_SERVER['REQUEST_ID'] = $requestId;
    $_SERVER['HTTP_X_REQUEST_ID'] = $requestId;

    if (!headers_sent()) {
        header('X-Request-Id: ' . $requestId);
    }

    return $requestId;
}

function sanitize_sensitive_data($message)
{
    $sanitized = (string) $message;

    $hashWithPrefix = static function (string $value, string $prefix): string {
        return sprintf('[%s:%s]', $prefix, substr(hash('sha256', $value), 0, 8));
    };

    $sanitized = preg_replace_callback(
        '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i',
        static function ($matches) use ($hashWithPrefix) {
            $email = strtolower($matches[0]);
            return $hashWithPrefix($email, 'EMAIL');
        },
        $sanitized
    );

    $sanitized = preg_replace_callback(
        '/(?<![A-Za-z0-9.])(\d{1,3}(?:\.\d{1,3}){3})(?![A-Za-z0-9])/',
        static function ($matches) use ($hashWithPrefix) {
            return $hashWithPrefix($matches[1], 'IP');
        },
        $sanitized
    );

    $sanitized = preg_replace('/\b\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}\b/', '[CNPJ]', $sanitized);
    $sanitized = preg_replace('/\b\d{14}\b/', '[CNPJ]', $sanitized);

    $sanitized = preg_replace('/\b\d{3}\.\d{3}\.\d{3}-\d{2}\b/', '[CPF]', $sanitized);
    $sanitized = preg_replace('/\b\d{11}\b/', '[CPF]', $sanitized);
    $sanitized = preg_replace('/[A-Fa-f0-9]{64}/', '[TOKEN]', $sanitized);

    return $sanitized;
}

function logs_collection_enabled(): bool
{
    $flag = getenv('LOG_COLLECTION_ENABLED');
    if ($flag === false) {
        return true;
    }
    $flag = strtolower(trim((string) $flag));
    return in_array($flag, ['1', 'true', 'on', 'yes'], true);
}

function logs_monitoring_enabled(): bool
{
    $flag = getenv('LOG_MONITORING_ENABLED');
    if ($flag === false || trim((string) $flag) === '') {
        return true;
    }
    $flag = strtolower(trim((string) $flag));
    return in_array($flag, ['1', 'true', 'on', 'yes'], true);
}

function get_request_trace_id(): string
{
    $traceId = '';
    $headerKeys = [
        'HTTP_X_TRACE_ID',
        'HTTP_X_TRACEID',
        'HTTP_TRACE_ID',
    ];
    foreach ($headerKeys as $headerKey) {
        if (!empty($_SERVER[$headerKey])) {
            $traceId = trim((string) $_SERVER[$headerKey]);
            break;
        }
    }
    if ($traceId === '' && isset($_COOKIE['trace_id'])) {
        $traceId = trim((string) $_COOKIE['trace_id']);
    }
    if ($traceId === '') {
        return '';
    }
    $traceId = preg_replace('/[^A-Za-z0-9._-]/', '', $traceId);
    return substr($traceId, 0, 120);
}

function get_request_id(): string
{
    $candidates = [
        'REQUEST_ID',
        'HTTP_X_REQUEST_ID',
        'HTTP_X_REQUESTID',
        'HTTP_REQUEST_ID',
        'HTTP_X_CORRELATION_ID',
        'HTTP_X_CORRELATIONID',
    ];

    foreach ($candidates as $key) {
        if (!empty($_SERVER[$key])) {
            $requestId = trim((string) $_SERVER[$key]);
            $requestId = preg_replace('/[^A-Za-z0-9._-]/', '', $requestId);
            return substr($requestId, 0, 120);
        }
    }

    return '';
}

function get_user_id(): string
{
    $candidates = [
        $_SERVER['HTTP_X_USER_ID'] ?? '',
        $_SERVER['HTTP_X_USERID'] ?? '',
        $_SERVER['REMOTE_USER'] ?? '',
        $_COOKIE['user_id'] ?? '',
        $_COOKIE['usuario_id'] ?? '',
        $_COOKIE['id_usuario'] ?? '',
    ];

    foreach ($candidates as $candidate) {
        $candidate = trim((string) $candidate);
        if ($candidate !== '') {
            return substr(preg_replace('/[^A-Za-z0-9._-]/', '', $candidate), 0, 120);
        }
    }

    return '';
}

function normalize_log_payload($message, array $context = []): array
{
    $payload = [];
    if (is_array($message)) {
        $payload = $message;
    } elseif (is_string($message)) {
        $trimmed = trim($message);
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }
        if (!$payload) {
            $payload = ['mensagem' => $message];
        }
    } else {
        $payload = ['mensagem' => (string) $message];
    }

    $base = [
        'timestamp' => gmdate('c'),
        'ambiente' => getenv('APP_ENV') ?: getenv('AMBIENTE') ?: 'desconhecido',
        'origem' => $payload['origem'] ?? 'backend',
    ];
    $requestId = get_request_id();
    if ($requestId !== '') {
        $base['request_id'] = $requestId;
    }
    $traceId = get_request_trace_id();
    if ($traceId !== '') {
        $base['trace_id'] = $traceId;
    }

    if (isset($_SERVER['REQUEST_METHOD'])) {
        $base['metodo'] = $_SERVER['REQUEST_METHOD'];
    }
    if (isset($_SERVER['REQUEST_URI'])) {
        $base['caminho'] = $_SERVER['REQUEST_URI'];
    }
    if (isset($_SERVER['HTTP_HOST'])) {
        $base['host'] = $_SERVER['HTTP_HOST'];
    }
    if (isset($_SERVER['REMOTE_ADDR'])) {
        $base['ip'] = $_SERVER['REMOTE_ADDR'];
    }

    $payload = array_merge($base, $payload, $context);

    if (empty($payload['request_id'])) {
        if (!empty($payload['trace_id'])) {
            $payload['request_id'] = $payload['trace_id'];
        } elseif ($requestId !== '') {
            $payload['request_id'] = $requestId;
        }
    }

    if (empty($payload['user_id'])) {
        $userId = get_user_id();
        if ($userId !== '') {
            $payload['user_id'] = $userId;
        }
    }

    if (!isset($payload['nivel'])) {
        $payload['nivel'] = 'error';
    }

    return $payload;
}

function enviar_log_monitoramento(array $payload, string $sanitizedJson): void
{
    $endpoint = getenv('LOG_MONITORING_ENDPOINT');
    if (!is_string($endpoint) || trim($endpoint) === '') {
        return;
    }

    if (!logs_monitoring_enabled()) {
        return;
    }

    $headers = "Content-Type: application/json\r\n";
    $apiKey = getenv('LOG_MONITORING_API_KEY');
    if (is_string($apiKey) && trim($apiKey) !== '') {
        $headers .= 'Authorization: Bearer ' . trim($apiKey) . "\r\n";
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => $headers,
            'content' => $sanitizedJson,
            'timeout' => 5,
        ],
    ]);

    @file_get_contents($endpoint, false, $context);
}

function enviar_log_elastic(array $payload, string $sanitizedJson): void
{
    $endpoint = getenv('LOG_ELASTIC_ENDPOINT');
    if (!is_string($endpoint) || trim($endpoint) === '') {
        return;
    }

    if (!logs_monitoring_enabled()) {
        return;
    }

    $headers = "Content-Type: application/json\r\n";
    $apiKey = getenv('LOG_ELASTIC_API_KEY');
    if (is_string($apiKey) && trim($apiKey) !== '') {
        $headers .= 'Authorization: ApiKey ' . trim($apiKey) . "\r\n";
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => $headers,
            'content' => $sanitizedJson,
            'timeout' => 5,
        ],
    ]);

    @file_get_contents($endpoint, false, $context);
}

function enviar_log_sentry(array $payload): void
{
    $dsn = getenv('SENTRY_DSN');
    if (!is_string($dsn) || trim($dsn) === '') {
        return;
    }

    if (!logs_monitoring_enabled()) {
        return;
    }

    $parsed = parse_url($dsn);
    if (!$parsed || empty($parsed['host']) || empty($parsed['user']) || empty($parsed['path'])) {
        return;
    }

    $projectId = ltrim((string) $parsed['path'], '/');
    if ($projectId === '') {
        return;
    }

    $host = $parsed['host'];
    $scheme = $parsed['scheme'] ?? 'https';
    $endpoint = sprintf('%s://%s/api/%s/store/', $scheme, $host, $projectId);

    $eventId = bin2hex(random_bytes(16));
    $nivel = strtolower((string) ($payload['nivel'] ?? 'error'));
    $sentryPayload = [
        'event_id' => $eventId,
        'timestamp' => $payload['timestamp'] ?? gmdate('c'),
        'level' => $nivel,
        'logger' => $payload['origem'] ?? 'backend',
        'message' => $payload['mensagem'] ?? json_encode($payload),
        'tags' => [
            'request_id' => $payload['request_id'] ?? '',
            'user_id' => $payload['user_id'] ?? '',
        ],
        'extra' => $payload,
    ];

    $authHeader = sprintf(
        'X-Sentry-Auth: Sentry sentry_version=7, sentry_key=%s, sentry_client=ibr-logger/1.0',
        $parsed['user']
    );

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n{$authHeader}\r\n",
            'content' => json_encode($sentryPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'timeout' => 5,
        ],
    ]);

    @file_get_contents($endpoint, false, $context);
}

function enviar_alertas_log(array $payload, string $sanitizedJson): void
{
    $emails = getenv('LOG_ALERT_EMAILS');
    $webhook = getenv('LOG_SLACK_WEBHOOK');

    if ((!is_string($emails) || trim($emails) === '') && (!is_string($webhook) || trim($webhook) === '')) {
        return;
    }

    $nivel = strtolower((string) ($payload['nivel'] ?? ''));
    $levelsEnv = getenv('LOG_ALERT_LEVELS');
    $levels = $levelsEnv !== false && trim((string) $levelsEnv) !== ''
        ? array_filter(array_map('trim', explode(',', strtolower($levelsEnv))))
        : ['alert', 'critical'];

    $shouldAlert = !empty($payload['alerta']) || in_array($nivel, $levels, true);
    if (!$shouldAlert) {
        return;
    }

    if (is_string($emails) && trim($emails) !== '') {
        $lista = array_filter(array_map('trim', explode(',', $emails)));
        if ($lista) {
            $titulo = '[Alerta] Logs do site';
            $mensagem = $titulo . "\n\n" . $sanitizedJson;
            foreach ($lista as $email) {
                @mail($email, $titulo, $mensagem);
            }
        }
    }

    if (is_string($webhook) && trim($webhook) !== '') {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => json_encode(['text' => "[Alerta] Logs do site\n" . $sanitizedJson]),
                'timeout' => 5,
            ],
        ]);

        @file_get_contents($webhook, false, $context);
    }
}
?>
