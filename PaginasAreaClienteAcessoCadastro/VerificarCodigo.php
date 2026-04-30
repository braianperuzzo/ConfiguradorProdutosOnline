<?php
ini_set('display_errors', 0);
error_reporting(0);
$baseDir = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
if ($baseDir === '') {
    $baseDir = dirname(__DIR__);
}

require_once $baseDir . '/Seguranca/MetodosSegurancao.php';
require_once $baseDir . '/LogsErros/Logs.php';
require_once $baseDir . '/TokensGeradores/TokenPadraoJson.php';
require_once $baseDir . '/TokensGeradores/TokensLimiteSolicitacoes.php';
require_once $baseDir . '/Seguranca/CSRF.php';
require_once $baseDir . '/ProcessamentoFilaJobs/CriaFilaProcessamento.php';

header('Content-Type: application/json; charset=utf-8');
require_valid_csrf_token();

$email = strtolower(trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL) ?? ''));
$codigo = trim($_POST['codigo'] ?? '');

function obter_configuracao_cookie(): array
{
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $host = preg_replace('/:\d+$/', '', $host);
    $host = strtolower(trim($host));
    if (strpos($host, 'www.') === 0) {
        $host = substr($host, 4);
    }

    $seguro = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && stripos((string) $_SERVER['HTTP_X_FORWARDED_PROTO'], 'https') === 0);

    $dominio = '';
    if ($host !== '' && !in_array($host, ['localhost', '127.0.0.1'], true) && filter_var($host, FILTER_VALIDATE_IP) === false) {
        $sufixo = 'redutoresibr.com.br';
        $sufixoComPonto = '.' . $sufixo;
        if ($host === $sufixo || substr($host, -strlen($sufixoComPonto)) === $sufixoComPonto) {
            $dominio = $sufixo;
        } else {
            $partes = explode('.', $host);
            if (count($partes) > 2) {
                $tldsCompostos = ['com.br', 'net.br', 'org.br', 'gov.br', 'edu.br'];
                $finalDois = implode('.', array_slice($partes, -2));
                $finalTres = implode('.', array_slice($partes, -3));
                if (in_array($finalDois, $tldsCompostos, true)) {
                    $dominio = $finalTres;
                } else {
                    $dominio = $finalDois;
                }
            } else {
                $dominio = $host;
            }
        }
    }

    return [
        'domain' => $dominio,
        'secure' => $seguro,
        'samesite' => $seguro ? 'None' : 'Lax',
    ];
}

if (!check_rate_limit('verificar_codigo', 5, 60, $email)) {
    http_response_code(429);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Quantidade de solicitações máximas atingidas. Tente novamente em 1 hora.']);
    exit;
}

if (!$email || !$codigo || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Dados inválidos.']);
    exit;
}

/**
 * Localiza o arquivo de dispositivo associado ao e-mail, mesmo que o
 * user-agent tenha mudado (ex.: varredura de links seguros antes do acesso real).
 */
function localizar_arquivo_dispositivo(string $dirDevices, string $deviceFilePreferido, string $email): ?string
{
    if (file_exists($deviceFilePreferido)) {
        return $deviceFilePreferido;
    }

    $emailLimpo = strtolower(trim($email));
    $maisRecente = null;
    $melhorArquivo = null;
    foreach (glob($dirDevices . '/*.json') as $arquivo) {
        $conteudo = json_decode(file_get_contents($arquivo), true);
        $emailArquivo = strtolower(trim($conteudo['email'] ?? ($conteudo['payload']['email'] ?? '')));
        if ($emailArquivo !== $emailLimpo) {
            continue;
        }
        $referenciaTempo = (int)($conteudo['generatedAt'] ?? $conteudo['criadoEm'] ?? filemtime($arquivo));
        if ($maisRecente === null || $referenciaTempo > $maisRecente) {
            $maisRecente = $referenciaTempo;
            $melhorArquivo = $arquivo;
        }
    }

    return $melhorArquivo;
}

$deviceHash = hash('sha256', strtolower($email) . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
$dirDevices = $baseDir . '/Tokens/TokensDispositivos';
$deviceFilePreferido = $dirDevices . '/' . $deviceHash . '.json';
$deviceFile = localizar_arquivo_dispositivo($dirDevices, $deviceFilePreferido, $email);

if ($deviceFile === null) {
    http_response_code(404);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Código não encontrado.']);
    exit;
}

$data = json_decode(file_get_contents($deviceFile), true);
if (!$data || !isset($data['code']) || time() - $data['generatedAt'] > 600) {
    http_response_code(410);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Código expirado.']);
    exit;
}

if (!password_verify($codigo, $data['code'])) {
    http_response_code(403);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Código inválido.']);
    exit;
}

$segredo = getenv('JWT_SECRET');
if ($segredo === false) {
    $segredo = trim(file_get_contents($baseDir . '/Configuracoes/Segredo.jwt'));
}

$cookieConfig = obter_configuracao_cookie();
$cookieDomain = $cookieConfig['domain'];
$cookieSecure = $cookieConfig['secure'];
$cookieSameSite = $cookieConfig['samesite'];

$maxTime = 2592000; // 30 dias
$ttl = (int)($data['ttl'] ?? 604800);
if ($ttl <= 0) {
    $ttl = 604800;
}
$ttl = min($ttl, $maxTime);
$payload = $data['payload'];
$maxExp = $payload['maxExp'] ?? (time() + $maxTime);
$novoExp = min($maxExp, time() + $ttl);
$payload['deviceVerified'] = true;
$payload['exp'] = $novoExp;
$payload['maxExp'] = $maxExp;
$tokenJWT = JWTHelper::encode($payload, $segredo);

$cookieOptions = [
    'expires' => $novoExp,
    'path' => '/',
    'secure' => $cookieSecure,
    'httponly' => true,
    'samesite' => $cookieSameSite
];
set_cookie_multi_domain('auth_token', $tokenJWT, $cookieOptions, $cookieDomain);

$empresaDocumento = preg_replace('/[^0-9A-Za-z]/', '', (string)($payload['empresaDocumento'] ?? ($payload['cpfcnpj'] ?? '')));
if ($empresaDocumento !== '') {
    $empresaCookieOptions = $cookieOptions;
    $empresaCookieOptions['expires'] = time() + 60 * 60 * 24 * 180;
    set_cookie_multi_domain('ibr_empresa_doc', $empresaDocumento, $empresaCookieOptions, $cookieDomain);
}

$dirTokensCadastro = $baseDir . '/Tokens/TokensCadastro';
if (is_dir($dirTokensCadastro)) {
    foreach (glob($dirTokensCadastro . '/*.json') as $tokenFile) {
        $dadosToken = json_decode(file_get_contents($tokenFile), true);
        if (!$dadosToken) {
            continue;
        }
        $emailToken = strtolower(trim($dadosToken['email'] ?? ''));
        if ($emailToken !== $email) {
            continue;
        }
        $dadosToken['status'] = 'confirmado';
        $dadosToken['confirmadoEm'] = time();
        file_put_contents($tokenFile, json_encode($dadosToken));
        chmod($tokenFile, 0600);
    }
}

$data['verifiedAt'] = time();
$data['email'] = $data['email'] ?? $email;
unset($data['code'], $data['generatedAt']);
file_put_contents($deviceFile, json_encode($data));
chmod($deviceFile, 0600);

$resposta = [
    'sucesso' => true,
    'nome' => $payload['nome'] ?? '',
    'grupo' => $payload['grupo'] ?? '',
    'empresaDocumento' => $payload['empresaDocumento'] ?? ($payload['cpfcnpj'] ?? ''),
    'empresaNome' => $payload['empresaNome'] ?? ($payload['empresa'] ?? ''),
    'empresaPapel' => $payload['empresaPapel'] ?? '',
];

if ($deviceFile !== $deviceFilePreferido) {
    $data['dispositivo'] = $_SERVER['HTTP_USER_AGENT'] ?? ($data['dispositivo'] ?? '');
    file_put_contents($deviceFilePreferido, json_encode($data));
    chmod($deviceFilePreferido, 0600);
}

try {
    disparar_sincronizacao_cliente_background();
    } catch (Throwable $t) {
    log_event('VerificarCodigo - falha ao disparar sincronizacao do cliente: ' . $t->getMessage());
}

echo json_encode($resposta);
?>