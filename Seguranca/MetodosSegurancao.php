<?php
if (defined('METODOS_SEGURANCAO_LOADED')) {
    return;
}
define('METODOS_SEGURANCAO_LOADED', true);
$cspNonce = base64_encode(random_bytes(16));
$GLOBALS['csp_nonce'] = $cspNonce;
$_SERVER['CSP_NONCE'] = $cspNonce;

if (!function_exists('inicializar_request_id')) {
    function inicializar_request_id(): string
    {
        $candidatos = [
            'HTTP_X_REQUEST_ID',
            'HTTP_X_REQUESTID',
            'HTTP_REQUEST_ID',
            'HTTP_X_CORRELATION_ID',
            'HTTP_X_CORRELATIONID',
        ];

        foreach ($candidatos as $chave) {
            if (!empty($_SERVER[$chave])) {
                $requestId = trim((string) $_SERVER[$chave]);
                $requestId = preg_replace('/[^A-Za-z0-9._-]/', '', $requestId);
                $_SERVER['REQUEST_ID'] = $requestId;
                $_SERVER['HTTP_X_REQUEST_ID'] = $requestId;
                if (!headers_sent()) {
                    header('X-Request-Id: ' . $requestId);
                }
                return $requestId;
            }
        }

        $requestId = bin2hex(random_bytes(16));
        $_SERVER['REQUEST_ID'] = $requestId;
        $_SERVER['HTTP_X_REQUEST_ID'] = $requestId;
        if (!headers_sent()) {
            header('X-Request-Id: ' . $requestId);
        }
        return $requestId;
    }
}

inicializar_request_id();

if (!function_exists('csp_nonce')) {
    function csp_nonce(): string
    {
        return $GLOBALS['csp_nonce'] ?? '';
    }
}

if (!function_exists('csp_nonce_attr')) {
    function csp_nonce_attr(): string
    {
        $nonce = csp_nonce();
        if ($nonce === '') {
            return '';
        }

        return 'nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"';
    }
}

header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 0');
header('Strict-Transport-Security: max-age=63072000; includeSubDomains; preload');
header('X-Permitted-Cross-Domain-Policies: none');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: clipboard-read=(self), clipboard-write=(self), geolocation=(self), microphone=()');
header('X-IISSkipCustomErrors: 1');
header("Content-Security-Policy: default-src 'self'; "
    . "img-src 'self' data: https://www.googletagmanager.com https://www.google-analytics.com https://www.g.doubleclick.net https://www.google.com.br https://vlibras.gov.br https://www.vlibras.gov.br https://dicionario2.vlibras.gov.br https://traducao2.vlibras.gov.br; "
    . "script-src 'self' 'nonce-{$cspNonce}' https://cdn.jsdelivr.net https://www.googletagmanager.com https://www.google-analytics.com https://vlibras.gov.br https://www.vlibras.gov.br https://dicionario2.vlibras.gov.br https://traducao2.vlibras.gov.br; "
    . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; "
    . "font-src 'self' data: https://cdn.jsdelivr.net https://fonts.gstatic.com; "
    . "media-src 'self' https://cdn.jsdelivr.net; "
    . "connect-src 'self' https://cdn.jsdelivr.net https://www.google-analytics.com https://www.googletagmanager.com https://www.g.doubleclick.net https://analytics.google.com https://www.google.com.br https://fonts.googleapis.com https://fonts.gstatic.com https://vlibras.gov.br https://www.vlibras.gov.br https://dicionario2.vlibras.gov.br https://traducao2.vlibras.gov.br; "
    . "form-action 'self'; "
    . "object-src 'none'; "
    . "base-uri 'self'; "
    . "frame-src 'self' https://www.googletagmanager.com https://vlibras.gov.br https://www.vlibras.gov.br https://dicionario2.vlibras.gov.br https://traducao2.vlibras.gov.br; "
    . "manifest-src 'self'; "
    . "worker-src 'self'; "
    . "frame-ancestors 'self' https://redutoresibr.com.br https://www.redutoresibr.com.br; "
    . "upgrade-insecure-requests; "
    . "block-all-mixed-content;");

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

ini_set('log_errors', 1);
ini_set('session.cookie_secure', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');

$baseDir = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
if ($baseDir === '') {
    $baseDir = __DIR__;
    while ($baseDir && !is_dir($baseDir . '/Seguranca')) {
        $parent = dirname($baseDir);
        if ($parent === $baseDir) {
            break;
        }
        $baseDir = $parent;
    }
    if (!is_dir($baseDir . '/Seguranca')) {
        $baseDir = __DIR__;
    }
}
$logPath = $baseDir . '/LogsErros';
if (!is_dir($logPath)) {
    @mkdir($logPath, 0700, true);
}
$phpErrorLog = $logPath . '/php_errors.log';
ini_set('error_log', $phpErrorLog);
if (!file_exists($phpErrorLog)) {
    touch($phpErrorLog);
    chmod($phpErrorLog, 0600);
}
ini_set('error_log', $phpErrorLog);

require_once $baseDir . '/LogsErros/Logs.php';
require_once $baseDir . '/TokensGeradores/TokensLimpeza.php';
require_once __DIR__ . '/Sanitizacao.php';
require_once __DIR__ . '/CSRF.php';

if (!function_exists('is_oauth_callback_post')) {
    function is_oauth_callback_post(): bool
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return false;
        }

        $path = $_SERVER['REQUEST_URI'] ?? '';
        $path = parse_url($path, PHP_URL_PATH) ?: '';
        $allowed = [
            '/PaginasAreaClienteAcessoCadastro/LoginMicrosoft.php',
            '/PaginasAreaClienteAcessoCadastro/LoginGoogle.php',
        ];
        if (!in_array($path, $allowed, true)) {
            return false;
        }

        return isset($_POST['code']) || isset($_POST['error']);
    }
}

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true) && !is_oauth_callback_post()) {
    require_valid_csrf_token();
}

limpar_tokens_semanal();

if (!function_exists('set_cookie_compat')) {
    function set_cookie_compat(string $name, string $value, array $options): bool
    {
        if (PHP_VERSION_ID >= 70300) {
            return setcookie($name, $value, $options);
        }

        $expires = $options['expires'] ?? 0;
        $path = $options['path'] ?? '/';
        $domain = $options['domain'] ?? '';
        $secure = $options['secure'] ?? false;
        $httponly = $options['httponly'] ?? false;
        $samesite = $options['samesite'] ?? '';

        if ($samesite) {
            $path .= '; samesite=' . $samesite;
        }

        return setcookie($name, $value, $expires, $path, $domain, $secure, $httponly);
    }
}

if (!function_exists('set_cookie_multi_domain')) {
    function set_cookie_multi_domain(string $name, string $value, array $options, string $domain = ''): void
    {
        if ($domain !== '') {
            $optionsWithDomain = $options;
            $optionsWithDomain['domain'] = $domain;
            set_cookie_compat($name, $value, $optionsWithDomain);
        }

        $optionsHostOnly = $options;
        unset($optionsHostOnly['domain']);
        set_cookie_compat($name, $value, $optionsHostOnly);
    }
}

?>
