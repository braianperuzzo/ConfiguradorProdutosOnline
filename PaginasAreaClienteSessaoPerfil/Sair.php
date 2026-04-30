<?php
$baseDir = $_SERVER['DOCUMENT_ROOT'] ?: dirname(__DIR__);
require $baseDir . '/Seguranca/MetodosSegurancao.php';
require_once $baseDir . '/TokensGeradores/TokenInvalidacao.php';
require_once $baseDir . '/TokensGeradores/TokenPadraoJson.php';
require_once $baseDir . '/Seguranca/CSRF.php';
require_once $baseDir . '/LogsErros/Logs.php';
require_once $baseDir . '/ProcessamentoFilaJobs/CriaFilaProcessamento.php';

$metodo = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($metodo !== 'POST') {
    if (!headers_sent()) {
        http_response_code(405);
        header('Allow: POST');
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo 'Método não permitido.';
    exit;
}

require_valid_csrf_token();

$dadosRequisicao = array_merge($_GET, $_POST);

$seguro = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && stripos((string) $_SERVER['HTTP_X_FORWARDED_PROTO'], 'https') === 0);

function limpar_cookie_autenticacao(string $dominioPadrao, bool $seguro): void
{
    $opcoesBase = [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => $seguro,
        'httponly' => true,
        'samesite' => $seguro ? 'None' : 'Lax',
    ];

    $hostAtual = isset($_SERVER['HTTP_HOST']) ? trim((string) $_SERVER['HTTP_HOST']) : '';
    $dominios = array_unique(array_filter([
        $dominioPadrao,
        $hostAtual,
        ltrim($dominioPadrao, '.'),
        $dominioPadrao ? '.' . ltrim($dominioPadrao, '.') : '',
    ]));

    foreach ($dominios as $dominio) {
        set_cookie_compat('auth_token', '', array_merge($opcoesBase, ['domain' => $dominio]));
    }

    set_cookie_compat('auth_token', '', $opcoesBase);
}
$token = $_COOKIE['auth_token'] ?? '';
if ($token) {
    $segredo = getenv('JWT_SECRET');
    if ($segredo === false) {
        $segredo = trim(@file_get_contents($baseDir . '/Configuracoes/Segredo.jwt'));
    }
    $dados = JWTHelper::decode($token, $segredo);
    $email = '';
    if ($dados && isset($dados['email'])) {
        $email = preg_replace('/[^a-zA-Z0-9_@.\-]/', '', $dados['email']);
    }
    if ($email) {
        try {
            disparar_sincronizacao_cliente_background();
        } catch (Throwable $t) {
            log_event('Sair - falha ao disparar sincronizacao do cliente: ' . $t->getMessage());
        }

        $cartDir = $baseDir . '/Tokens/HistoricoCarrinhos';
        $cartId = $_COOKIE['cart_id'] ?? bin2hex(random_bytes(16));
        set_cookie_compat('cart_id', $cartId, [
            'expires' => time() + 31536000,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => $seguro ? 'None' : 'Lax',
        ]);
        $src = $cartDir . '/' . $email . '.json';
        $dst = $cartDir . '/' . $cartId . '.json';
        if (file_exists($src)) {
            @copy($src, $dst);
        }
    }
    blacklist_token($token);
}

limpar_cookie_autenticacao('configurador.redutoresibr.com.br', $seguro);

$extras = [];
foreach ($dadosRequisicao as $key => $value) {
    if ($key === 'retorno' || $key === 'csrf_token') {
        continue;
    }
    if (preg_match('/^[A-Za-z0-9_]+$/', (string)$key)) {
        $extras[$key] = (string)$value;
    }
}

$estadoExclusao = isset($dadosRequisicao['exclusao']) ? strtolower((string) $dadosRequisicao['exclusao']) : '';
if ($estadoExclusao === 'sucesso') {
    set_cookie_compat('exclusao_conta', 'sucesso', [
        'expires'  => time() + 300,
        'path'     => '/',
        'secure'   => $seguro,
        'httponly' => false,
        'samesite' => $seguro ? 'None' : 'Lax',
    ]);
}

$extrasQuery = !empty($extras)
    ? http_build_query($extras, '', '&', PHP_QUERY_RFC3986)
    : '';

$destino = '/';
$extrasAplicadosNoRetorno = false;
if (!empty($dadosRequisicao['retorno'])) {
    $ret = str_replace(["\r", "\n"], '', $dadosRequisicao['retorno']);

    if ($extrasQuery !== '') {
        $separator = strpos($ret, '?') === false ? '?' : '&';
        $ret .= $separator . $extrasQuery;
        $extrasAplicadosNoRetorno = true;
    }

    if (preg_match('#^[a-z]+://#i', $ret)) {
        $parsed = parse_url($ret);
        if ($parsed !== false) {
            $host = $parsed['host'] ?? '';
            if ($host === '' || $host === 'configurador.redutoresibr.com.br') {
                $path = $parsed['path'] ?? '/';
                $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';
                $fragment = isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '';
                $destino = $path . $query . $fragment;
            }
        }
    } elseif (strpos($ret, '/') === 0) {
        $destino = $ret;
    }
} elseif (!empty($_SERVER['HTTP_REFERER'])) {
    $ref = parse_url($_SERVER['HTTP_REFERER']);
    if ($ref !== false) {
        $host = $ref['host'] ?? '';
        $path = $ref['path'] ?? '';
        $query = isset($ref['query']) ? '?' . $ref['query'] : '';
        $fragment = isset($ref['fragment']) ? '#' . $ref['fragment'] : '';
        if (($host === '' || $host === 'configurador.redutoresibr.com.br') &&
            strpos($path, '/') === 0) {
            $destino = $path . $query . $fragment;
        }
    }
}

$destinoFinal = $destino;

if ($extrasQuery !== '' && !$extrasAplicadosNoRetorno) {
    $separator = strpos($destinoFinal, '?') === false ? '?' : '&';
    $destinoFinal .= $separator . $extrasQuery;
}

if ($destinoFinal) {
    $destinoPath = parse_url($destinoFinal, PHP_URL_PATH);
    if (!is_string($destinoPath)) {
        $destinoPath = $destinoFinal;
    }

    $destinoPath = strtolower((string) $destinoPath);
    $destinoPath = preg_replace('#/+#', '/', $destinoPath);

    $ehArquivoEstatico = false;
    if (preg_match('#^/service-worker(\.min)?\.js$#', $destinoPath)) {
        $ehArquivoEstatico = true;
    } elseif (preg_match('#\.(?:js|json|css|map|png|jpe?g|gif|webp|svg|ico|txt|xml)$#', $destinoPath)) {
        $ehArquivoEstatico = true;
    }

    $ehAreaCliente = str_starts_with($destinoPath, '/areacliente')
        || str_starts_with($destinoPath, '/paginasareacliente');

    if ($ehArquivoEstatico || $ehAreaCliente) {
        $destinoFinal = '/';
    }
}

if ($destinoFinal) {
    $destinoFinal = preg_replace_callback(
        '/[^A-Za-z0-9\-._~!$&\'()*+,;=:@\/?%#]/u',
        static fn(array $match) => rawurlencode($match[0]),
        $destinoFinal
    );
    header("Location: https://configurador.redutoresibr.com.br$destinoFinal");
    exit;
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8" />
    <title>Logout da Sessão | Configurador de Produtos IBR</title>
    <script nonce="<?= htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8'); ?>" defer data-versioned-src="/SEO/TagsGoogle.min.js"></script>
    <script nonce="<?= htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8'); ?>">
        if (window.history.length > 1) {
            window.history.back();
        } else {
            window.location.href = 'https://configurador.redutoresibr.com.br/';
        }
    </script>
</head>
<body></body>
</html>
<?php
exit;
