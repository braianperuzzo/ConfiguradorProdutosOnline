<?php
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

ini_set('display_errors', 0);
error_reporting(0);
$baseDir = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
if ($baseDir === '') {
    $baseDir = dirname(__DIR__);
}

require_once $baseDir . '/Seguranca/MetodosSegurancao.php';
require_once $baseDir . '/LogsErros/Logs.php';
require_once $baseDir . '/TokensGeradores/TokenPadraoJson.php';
require_once $baseDir . '/TokensGeradores/TokenInvalidacao.php';
require_once $baseDir . '/AcessosConsultas/UltimaEmpresaLogadaUsuario.php';
require_once $baseDir . '/ProcessamentoFilaJobs/CriaFilaProcessamento.php';

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

function obter_diretorio_cache_oauth(string $baseDir): string
{
    $dir = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'Tokens' . DIRECTORY_SEPARATOR . 'CacheOAuth';
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    if (is_dir($dir) && is_writable($dir)) {
        return $dir;
    }

    $fallback = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'oauth_cache';
    if (!is_dir($fallback)) {
        mkdir($fallback, 0700, true);
    }
    return $fallback;
}

function codigo_oauth_foi_utilizado(string $codigo, string $cacheDir, int $ttlSeconds = 600): bool
{
    $codigo = trim($codigo);
    if ($codigo === '') {
        return false;
    }

    $hash = hash('sha256', $codigo);
    $arquivo = rtrim($cacheDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $hash . '.lock';
    $agora = time();

    $handle = fopen($arquivo, 'c+');
    if ($handle === false) {
        return false;
    }

    $utilizado = false;
    if (flock($handle, LOCK_EX)) {
        rewind($handle);
        $conteudo = trim((string) stream_get_contents($handle));
        $ultimaAtualizacao = is_numeric($conteudo) ? (int) $conteudo : 0;
        if ($ultimaAtualizacao > 0 && ($agora - $ultimaAtualizacao) < $ttlSeconds) {
            $utilizado = true;
        } else {
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) $agora);
            fflush($handle);
        }
        flock($handle, LOCK_UN);
    }

    fclose($handle);
    return $utilizado;
}

function registrar_callback_duplicado(string $provedor, string $motivo): void
{
    $path = $_SERVER['REQUEST_URI'] ?? '';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $scheme = 'http';
    if (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) ||
        (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && stripos((string) $_SERVER['HTTP_X_FORWARDED_PROTO'], 'https') === 0)
    ) {
        $scheme = 'https';
    }
    $url = $host !== '' ? $scheme . '://' . $host . $path : $path;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

    log_event([
        'mensagem' => 'OAuth callback duplicate',
        'nivel' => 'info',
        'componente' => 'oauth',
        'provedor' => $provedor,
        'motivo' => $motivo,
        'url' => $url,
        'path' => $path,
        'user_agent' => $userAgent,
    ]);
}

$sessionPath = ini_get('session.save_path');
if (empty($sessionPath) || !is_dir($sessionPath)) {
    $sessionPath = $baseDir . '/Tokens/Sessoes';
    if (!is_dir($sessionPath)) {
        mkdir($sessionPath, 0700, true);
    }
    ini_set('session.save_path', $sessionPath);
}
$cookieConfig = obter_configuracao_cookie();
session_set_cookie_params([
    'domain' => $cookieConfig['domain'] ?: '',
    'secure' => $cookieConfig['secure'],
    'httponly' => true,
    'samesite' => $cookieConfig['samesite'],
]);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$cookieDomain = $cookieConfig['domain'];
$cookieSecure = $cookieConfig['secure'];
$cookieSameSite = $cookieConfig['samesite'];

function carregar_configuracao_google(string $baseDir): array
{
    $config = [
        'client_id' => getenv('GOOGLE_CLIENT_ID') ?: null,
        'client_secret' => getenv('GOOGLE_CLIENT_SECRET') ?: null,
        'redirect_uri' => getenv('GOOGLE_REDIRECT_URI') ?: null,
    ];

    $config = array_map(static function ($valor) {
        return is_string($valor) ? trim($valor) : $valor;
    }, $config);

    $iniPath = $baseDir . '/Configuracoes/GoogleOAuth.ini';
    if (file_exists($iniPath)) {
        $ini = parse_ini_file($iniPath, false, INI_SCANNER_TYPED);
        if (is_array($ini)) {
            $config['client_id'] = $config['client_id'] ?: ($ini['client_id'] ?? null);
            $config['client_secret'] = $config['client_secret'] ?: ($ini['client_secret'] ?? null);
            $config['redirect_uri'] = $config['redirect_uri'] ?: ($ini['redirect_uri'] ?? null);
        }
    }

    $config = array_map(static function ($valor) {
        return is_string($valor) ? trim($valor) : $valor;
    }, $config);

    $redirectPadrao = 'https://configurador.redutoresibr.com.br/PaginasAreaClienteAcessoCadastro/LoginGoogle.php';
    $redirectConfigurado = $config['redirect_uri'] ?: $redirectPadrao;
    if (!filter_var($redirectConfigurado, FILTER_VALIDATE_URL)) {
        $redirectConfigurado = $redirectPadrao;
    }
    $config['redirect_uri'] = $redirectConfigurado;

    if (empty($config['client_id']) || empty($config['client_secret'])) {
        throw new RuntimeException('Configuração do Google OAuth incompleta.');
    }

    return $config;
}

function responder_html(string $mensagem, int $status = 400): void
{
    http_response_code($status);
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><title>Autenticação Google | Configurador de Produtos IBR</title>';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta name="robots" content="noindex, nofollow">';
    echo '<style>body{font-family:Arial, sans-serif; background:#f8f9fa; padding:32px; color:#333;}';
    echo '.card{max-width:560px;margin:0 auto;background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:24px;box-shadow:0 4px 20px rgba(0,0,0,0.05);}';
    echo '.title{font-size:1.25rem;margin-bottom:12px;}';
    echo '.msg{font-size:1rem;line-height:1.5;}';
    echo 'a.btn{display:inline-block;margin-top:12px;padding:10px 14px;background:#ec4115;color:#fff;text-decoration:none;border-radius:4px;}</style>';
    echo '</head><body><div class="card">';
    echo '<div class="title">Autenticação com Google</div>';
    echo '<div class="msg">' . htmlspecialchars($mensagem, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
    echo '<a class="btn" href="/AreaCliente">Voltar</a>';
    echo '</div></body></html>';
    exit;
}

function validar_retorno(?string $retorno): ?string
{
    if (!is_string($retorno)) {
        return null;
    }

    $retorno = trim($retorno);
    if ($retorno === '' || $retorno[0] !== '/') {
        return null;
    }

    $partes = parse_url($retorno);
    if ($partes === false || !empty($partes['scheme']) || !empty($partes['host'])) {
        return null;
    }

    return $retorno;
}

function adicionar_login_sucesso(string $destino): string
{
    $partes = parse_url($destino);
    if ($partes === false) {
        return $destino;
    }

    $params = [];
    if (isset($partes['query'])) {
        parse_str($partes['query'], $params);
    }

    if (($params['login'] ?? null) !== 'sucesso') {
        $params['login'] = 'sucesso';
    }

    $novo = '';
    if (isset($partes['scheme'], $partes['host'])) {
        $novo .= $partes['scheme'] . '://' . $partes['host'];
        if (isset($partes['port'])) {
            $novo .= ':' . $partes['port'];
        }
    }
    $novo .= $partes['path'] ?? '';
    $novo .= '?' . http_build_query($params);
    if (isset($partes['fragment'])) {
        $novo .= '#' . $partes['fragment'];
    }

    return $novo;
}

function token_autenticado_valido(string $token, string $baseDir): bool
{
    if ($token === '') {
        return false;
    }

    $segredo = getenv('JWT_SECRET');
    if ($segredo === false || $segredo === '') {
        $segredo = trim((string) file_get_contents($baseDir . '/Configuracoes/Segredo.jwt'));
    }

    $dados = JWTHelper::decode($token, $segredo);
    if (!$dados || is_token_blacklisted($token)) {
        return false;
    }

    $maxExp = $dados['maxExp'] ?? 0;
    if (!$maxExp || time() >= $maxExp) {
        return false;
    }

    return true;
}

function lidar_callback_duplicado(string $provedor, ?string $retornoSessao): void
{
    $destinoLogado = $retornoSessao ?? '/AreaCliente/Sessao';
    $agora = time();
    $sucessoRecente = $_SESSION['ggl_oauth_success'] ?? null;
    $token = $_COOKIE['auth_token'] ?? '';
    global $baseDir;
    if (token_autenticado_valido($token, $baseDir) || (is_int($sucessoRecente) && ($agora - $sucessoRecente) <= 30)) {
        header('Location: ' . $destinoLogado);
        exit;
    }
    if ($token !== '') {
        global $cookieDomain, $cookieSecure, $cookieSameSite;
        $cookieLimpar = [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => $cookieSecure,
            'httponly' => true,
            'samesite' => $cookieSameSite
        ];
        set_cookie_multi_domain('auth_token', '', $cookieLimpar, $cookieDomain);
    }

    if ($provedor === 'google') {
        unset($_SESSION['ggl_oauth_state'], $_SESSION['ggl_oauth_last_code'], $_SESSION['ggl_oauth_retorno'], $_SESSION['ggl_oauth_redirect_uri']);
    }

    $params = [
        'tab' => 'entrar',
        'erro' => 'oauth_duplicado',
        'prov' => $provedor,
    ];
    if ($retornoSessao !== null) {
        $params['retorno'] = $retornoSessao;
    }

    header('Location: /PaginasAreaClienteAcessoCadastro/AcessoCadastro.html?' . http_build_query($params));
    exit;
}

function iniciar_fluxo(array $config): void
{
    $state = bin2hex(random_bytes(16));
    $_SESSION['ggl_oauth_state'] = $state;
    global $cookieDomain, $cookieSecure, $cookieSameSite;
    $cookieState = [
        'expires' => time() + 900,
        'path' => '/',
        'secure' => $cookieSecure,
        'httponly' => true,
        'samesite' => $cookieSameSite
    ];
    set_cookie_multi_domain('ggl_oauth_state', $state, $cookieState, $cookieDomain);

    $retorno = validar_retorno(filter_input(INPUT_GET, 'retorno', FILTER_UNSAFE_RAW));
    if ($retorno !== null) {
        $_SESSION['ggl_oauth_retorno'] = $retorno;
    }

    $_SESSION['ggl_oauth_redirect_uri'] = $config['redirect_uri'];

    session_write_close();

    $params = [
        'client_id' => $config['client_id'],
        'response_type' => 'code',
        'response_mode' => 'form_post',
        'redirect_uri' => $config['redirect_uri'],
        'scope' => 'openid profile email',
        'state' => $state,
        'prompt' => 'select_account',
        'access_type' => 'offline'
    ];

    $authorizeUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);

    header('Location: ' . $authorizeUrl);
    exit;
}

function erro_codigo_oauth_duplicado(string $mensagem): bool
{
    $mensagem = strtolower($mensagem);
    if (strpos($mensagem, 'invalid_grant') !== false) {
        return true;
    }
    if (strpos($mensagem, 'already redeemed') !== false) {
        return true;
    }
    return false;
}

function requisitar_token(array $config, string $code, string $redirectUriFluxo): array
{
    $redirectUriFluxo = trim($redirectUriFluxo);
    if (!filter_var($redirectUriFluxo, FILTER_VALIDATE_URL)) {
        $redirectUriFluxo = $config['redirect_uri'];
    }

    $url = 'https://oauth2.googleapis.com/token';
    $payload = http_build_query([
        'client_id' => $config['client_id'],
        'scope' => 'openid profile email',
        'code' => $code,
        'redirect_uri' => $redirectUriFluxo,
        'grant_type' => 'authorization_code',
        'client_secret' => $config['client_secret'],
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('Erro ao solicitar token: ' . $error);
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        throw new RuntimeException('Resposta inválida do Google.');
    }

    if (isset($data['error'])) {
        $descricao = is_array($data['error']) ? ($data['error']['message'] ?? '') : ($data['error_description'] ?? $data['error']);
        throw new RuntimeException('Erro do Google OAuth: ' . $descricao);
    }

    return $data;
}

function buscar_perfil(string $accessToken): array
{
    $ch = curl_init('https://www.googleapis.com/oauth2/v3/userinfo');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('Erro ao consultar o perfil do Google: ' . $error);
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        throw new RuntimeException('Perfil do Google inválido.');
    }

    return $data;
}

function extrair_email(array $dadosToken, array $perfil): ?string
{
    $possiveis = [];
    if (!empty($perfil['email'])) {
        $possiveis[] = $perfil['email'];
    }
    if (!empty($dadosToken['id_token'])) {
        $partes = explode('.', $dadosToken['id_token']);
        if (count($partes) >= 2) {
            $payload = json_decode(base64_decode(strtr($partes[1], '-_', '+/')), true);
            if (!empty($payload['email'])) {
                $possiveis[] = $payload['email'];
            }
        }
    }

    foreach ($possiveis as $email) {
        $limpo = strtolower(trim((string) $email));
        if (filter_var($limpo, FILTER_VALIDATE_EMAIL)) {
            return $limpo;
        }
    }

    return null;
}

function extrair_nome(array $dadosToken, array $perfil): string
{
    $possiveis = [];
    if (!empty($perfil['name'])) {
        $possiveis[] = $perfil['name'];
    }

    $nomeComSobrenome = trim(($perfil['given_name'] ?? '') . ' ' . ($perfil['family_name'] ?? ''));
    if ($nomeComSobrenome !== '') {
        $possiveis[] = $nomeComSobrenome;
    }

    if (!empty($dadosToken['id_token'])) {
        $partes = explode('.', $dadosToken['id_token']);
        if (count($partes) >= 2) {
            $payload = json_decode(base64_decode(strtr($partes[1], '-_', '+/')), true);
            if (!empty($payload['name'])) {
                $possiveis[] = $payload['name'];
            }
        }
    }

    foreach ($possiveis as $nome) {
        $limpo = trim(preg_replace('/\s+/', ' ', (string) $nome));
        if ($limpo !== '') {
            return $limpo;
        }
    }

    return '';
}

function gerar_nome_por_email(string $email): string
{
    $partesEmail = explode('@', $email);
    $local = strtolower(trim($partesEmail[0] ?? ''));
    if ($local === '') {
        return '';
    }

    $normalizado = preg_replace('/[^\p{L}\s._-]+/u', ' ', $local);
    $normalizado = preg_replace('/[._-]+/', ' ', $normalizado);
    $normalizado = trim(preg_replace('/\s+/', ' ', (string) $normalizado));

    if ($normalizado === '') {
        return '';
    }

    return implode(' ', array_map(
        fn(string $p) => mb_convert_case($p, MB_CASE_TITLE, 'UTF-8'),
        explode(' ', $normalizado)
    ));
}

function localizar_dispositivo_verificado(string $dirDevices, string $email): ?array
{
    if (!is_dir($dirDevices)) {
        return null;
    }

    $emailLimpo = strtolower(trim($email));
    $maisRecente = null;
    $melhorArquivo = null;

    foreach (glob($dirDevices . '/*.json') as $arquivo) {
        $conteudo = json_decode(file_get_contents($arquivo), true);
        if (!is_array($conteudo)) {
            continue;
        }

        $emailArquivo = strtolower(trim($conteudo['email'] ?? ($conteudo['payload']['email'] ?? '')));
        if ($emailArquivo !== $emailLimpo) {
            continue;
        }

        $verificadoEm = (int)($conteudo['verifiedAt'] ?? 0);
        if ($verificadoEm === 0 || time() - $verificadoEm > 2592000) {
            continue;
        }

        if ($maisRecente === null || $verificadoEm > $maisRecente) {
            $maisRecente = $verificadoEm;
            $melhorArquivo = ['path' => $arquivo, 'data' => $conteudo];
        }
    }

    return $melhorArquivo;
}

function formatar_documento_nacional(?string $valor): string
{
    if ($valor === null) {
        return '';
    }

    $digitos = preg_replace('/[^0-9A-Za-z]/', '', $valor);

    if (strlen($digitos) === 14) {
        return vsprintf('%s%s.%s%s%s.%s%s%s/%s%s%s%s-%s%s', str_split($digitos));
    }

    if (strlen($digitos) === 11) {
        return vsprintf('%s%s%s.%s%s%s.%s%s%s-%s%s', str_split($digitos));
    }

    return $valor;
}

function autenticar_usuario_por_email(string $email, string $baseDir): bool
{
    global $cookieDomain, $cookieSecure, $cookieSameSite;

    require $baseDir . '/AcessosConsultas/CredenciaisBancoDados.php';
    require_once $baseDir . '/AcessosConsultas/BuscarNomeEmpresaReceitaCache.php';
    require_once $baseDir . '/AcessosConsultas/ValidaEmpresasPorUsuario.php';

    $pdo = new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
    ]);

    $stmt = $pdo->prepare("SELECT DS_NOME, NR_CPFCNPJ FROM _USR_CONF_SITE_CADASTROS WHERE LOWER(DS_EMAIL) = ?");
    $stmt->execute([strtolower($email)]);
    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$registros) {
        return false;
    }

    $nomeUsuario = '';
    $documentosUsuario = [];
    foreach ($registros as $linha) {
        if (!$nomeUsuario && !empty($linha['DS_NOME'])) {
            $nomeUsuario = $linha['DS_NOME'];
        }
        $docLimpo = preg_replace('/[^0-9A-Za-z]/', '', (string)($linha['NR_CPFCNPJ'] ?? ''));
        if ($docLimpo !== '' && !in_array($docLimpo, $documentosUsuario, true)) {
            $documentosUsuario[] = $docLimpo;
        }
    }

    $listaEmpresas = [];
    $mapaEmpresas = [];
    try {
        $empresasUsuario = obter_empresas_usuario($pdo, $email, $documentosUsuario, $baseDir);
        if (is_array($empresasUsuario)) {
            $listaEmpresas = $empresasUsuario['lista'] ?? [];
            $mapaEmpresas = $empresasUsuario['porDocumento'] ?? [];
        }
    } catch (Throwable $e) {
    }

    $documentoSelecionado = preg_replace('/[^0-9A-Za-z]/', '', (string)($_COOKIE['ibr_empresa_doc'] ?? ''));
    $empresaAtiva = null;
    if ($documentoSelecionado !== '' && isset($mapaEmpresas[$documentoSelecionado])) {
        $empresaAtiva = $mapaEmpresas[$documentoSelecionado];
    } elseif (!empty($listaEmpresas)) {
        $empresaAtiva = $listaEmpresas[0];
        $documentoSelecionado = $empresaAtiva['documento'];
    } elseif (!empty($documentosUsuario)) {
        $documentoSelecionado = $documentosUsuario[0];
        $empresaAtiva = [
            'documento' => $documentoSelecionado,
            'nome' => obter_nome_empresa($documentoSelecionado, $baseDir) ?: $documentoSelecionado,
            'papel' => 'PRATA',
            'codigo' => '',
        ];
        $listaEmpresas = [$empresaAtiva];
    }

    $grupo = $empresaAtiva['papel'] ?? 'PRATA';
    $codigo = (string)($empresaAtiva['codigo'] ?? '');
    $empresaNome = $empresaAtiva['nome'] ?? '';
    $cpfcnpj = $empresaAtiva['documento'] ?? ($documentosUsuario[0] ?? '');

    $segredo = getenv('JWT_SECRET');
    if ($segredo === false) {
        $segredo = trim(file_get_contents($baseDir . '/Configuracoes/Segredo.jwt'));
    }
    $ttlPadrao = getenv('JWT_TTL');
    $ttlPadrao = $ttlPadrao === false ? 604800 : (int)$ttlPadrao;
    if ($ttlPadrao <= 0) {
        $ttlPadrao = 604800;
    }
    $maxTime = 2592000;
    $ttl = min($ttlPadrao, $maxTime);
    $agora = time();
    $payload = [
        'email' => $email,
        'grupo' => $grupo,
        'authProvider' => 'google',
        'nome' => $nomeUsuario,
        'cpfcnpj' => $cpfcnpj,
        'empresa' => $empresaNome,
        'empresaDocumento' => $documentoSelecionado,
        'empresaNome' => $empresaNome,
        'empresaPapel' => $grupo,
        'codigo' => $codigo,
        'exp' => $agora + $ttl,
        'maxExp' => $agora + $maxTime
    ];

    $deviceHash = hash('sha256', strtolower($email) . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $dirDevices = $baseDir . '/Tokens/TokensDispositivos';
    if (!is_dir($dirDevices)) {
        mkdir($dirDevices, 0700, true);
        file_put_contents($dirDevices . '/web.config', "<?xml version=\"1.0\" encoding=\"UTF-8\"?><configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>");
    }

    $deviceFile = $dirDevices . '/' . $deviceHash . '.json';
    $needsVerification = true;
    $dadosDispositivo = null;
    if (file_exists($deviceFile)) {
        $info = json_decode(file_get_contents($deviceFile), true);
        if ($info && isset($info['verifiedAt']) && time() - $info['verifiedAt'] < 2592000) {
            $needsVerification = false;
            $dadosDispositivo = $info;
        }
    }

    if ($needsVerification) {
        $dispositivoVerificado = localizar_dispositivo_verificado($dirDevices, $email);
        if ($dispositivoVerificado) {
            $needsVerification = false;
            $dadosDispositivo = $dispositivoVerificado['data'];
               $deviceFile = $dirDevices . '/' . $deviceHash . '.json';
        }
    }

        $payload['deviceVerified'] = !$needsVerification;

    if (!$needsVerification) {
        $dadosPersistir = is_array($dadosDispositivo) ? $dadosDispositivo : [];
        $dadosPersistir['payload'] = $payload;
        $dadosPersistir['ttl'] = $ttl;
        $dadosPersistir['email'] = strtolower($email);
        $dadosPersistir['verifiedAt'] = $dadosPersistir['verifiedAt'] ?? time();
        file_put_contents($deviceFile, json_encode($dadosPersistir));
        chmod($deviceFile, 0600);
    }

    if ($needsVerification) {
        $codigoLogin = random_int(100000, 999999);
        require $baseDir . '/PHPMailer/src/PHPMailer.php';
        require $baseDir . '/PHPMailer/src/SMTP.php';
        require $baseDir . '/PHPMailer/src/Exception.php';
        require $baseDir . '/AcessosConsultas/CredenciaisAcessoEmail.php';
        $template = file_get_contents(__DIR__ . '/EmailCodigoLogin.html');
        $documentoLimpo = preg_replace('/[^0-9A-Za-z]/', '', (string) $cpfcnpj);
        $nomeUsuarioSaudacao = trim((string) $nomeUsuario);
        $componentesSaudacao = [];
        if ($nomeUsuarioSaudacao !== '') {
            $componentesSaudacao[] = $nomeUsuarioSaudacao;
        }
        if (strlen($documentoLimpo) === 14) {
            $componentesSaudacao[] = formatar_documento_nacional($documentoLimpo);
            if ($empresaNome !== '') {
                $componentesSaudacao[] = $empresaNome;
            }
        }
        if (empty($componentesSaudacao) && $nomeUsuarioSaudacao !== '') {
            $componentesSaudacao[] = $nomeUsuarioSaudacao;
        }
        $saudacaoConteudo = trim(implode(' - ', array_filter($componentesSaudacao, function ($parte) {
            return $parte !== '';
        })));
        $saudacaoHtml = htmlspecialchars($saudacaoConteudo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = str_replace(
            ['SAUDACAO_USUARIO', 'CODIGO_LOGIN'],
            [$saudacaoHtml, $codigoLogin],
            $template
        );

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
            $mail->setFrom($smtpUser, 'Não Responder - Comunicação Redutores IBR');
            $mail->addAddress($email);
            $mail->addEmbeddedImage($baseDir . '/Imagens/Logotipo.png', 'logo_ibr');
            $mail->Subject = 'Código de Acesso - Configurador de Produtos IBR';
            $mail->isHTML(true);
            $mail->Body = $html;
            $saudacaoTexto = 'Olá, ' . ($saudacaoConteudo !== '' ? $saudacaoConteudo : $nomeUsuarioSaudacao) . ',';
            $altBody = $saudacaoTexto . "\n\nSeu código de acesso é: $codigoLogin";
            $mail->AltBody = $altBody;

            if (!$mail->send()) {
                throw new Exception($mail->ErrorInfo);
            }
        } catch (Exception $e) {
            throw new RuntimeException('⚠️ Erro ao enviar código de validação.');
        }

        $info = [
            'email' => $email,
            'code' => password_hash($codigoLogin, PASSWORD_DEFAULT),
            'payload' => $payload,
            'ttl' => $ttl,
            'generatedAt' => time()
        ];
        file_put_contents($deviceFile, json_encode($info));
        chmod($deviceFile, 0600);

        header('Location: /PaginasAreaClienteAcessoCadastro/AcessoCadastro.html?validacao=1&email=' . rawurlencode($email));
        exit;
    }

    $tokenJWT = JWTHelper::encode($payload, $segredo);

    $cookieAuth = [
        'expires' => $payload['exp'],
        'path' => '/',
        'secure' => $cookieSecure,
        'httponly' => true,
        'samesite' => $cookieSameSite
    ];
    set_cookie_multi_domain('auth_token', $tokenJWT, $cookieAuth, $cookieDomain);

    try {
        disparar_sincronizacao_cliente_background();
    } catch (Throwable $t) {
        log_event('LoginGoogle - falha ao disparar sincronizacao do cliente: ' . $t->getMessage());
    }

    if ($documentoSelecionado !== '') {
        $cookieEmpresa = [
            'expires' => time() + 60 * 60 * 24 * 180,
            'path' => '/',
            'secure' => $cookieSecure,
            'httponly' => true,
            'samesite' => $cookieSameSite
        ];
        set_cookie_multi_domain('ibr_empresa_doc', $documentoSelecionado, $cookieEmpresa, $cookieDomain);
    }
    
    session_regenerate_id(true);
    
    echo '';

    return true;
}

try {
    $config = carregar_configuracao_google($baseDir);

    if (isset($_GET['action']) && $_GET['action'] === 'start') {
        iniciar_fluxo($config);
    }

    $code = $_POST['code'] ?? ($_GET['code'] ?? '');
    $state = $_POST['state'] ?? ($_GET['state'] ?? '');

    if ($code === '' || $state === '') {
        responder_html('Nenhum código de autorização foi recebido.');
    }

    $stateSessao = $_SESSION['ggl_oauth_state'] ?? '';
    $stateCookie = $_COOKIE['ggl_oauth_state'] ?? '';
    if (!hash_equals($stateSessao, $state) && !hash_equals($stateCookie, $state)) {
        responder_html('Falha na validação do estado da sessão.');
    }
    if ($stateCookie !== '') {
        $cookieState = [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => $cookieSecure,
            'httponly' => true,
            'samesite' => $cookieSameSite
        ];
        set_cookie_multi_domain('ggl_oauth_state', '', $cookieState, $cookieDomain);
    }

    $_SESSION['ggl_oauth_processing'] = time();

    $cacheDir = obter_diretorio_cache_oauth($baseDir);
    if (codigo_oauth_foi_utilizado($code, $cacheDir)) {
        registrar_callback_duplicado('google', 'codigo_oauth_foi_utilizado');
        $retornoSessao = validar_retorno($_SESSION['ggl_oauth_retorno'] ?? null);
        lidar_callback_duplicado('google', $retornoSessao);
    }

    
    if (!empty($_SESSION['ggl_oauth_last_code']) && hash_equals($_SESSION['ggl_oauth_last_code'], $code)) {
        registrar_callback_duplicado('google', 'ggl_oauth_last_code');
        $retornoSessao = validar_retorno($_SESSION['ggl_oauth_retorno'] ?? null);
        lidar_callback_duplicado('google', $retornoSessao);
    }
    $_SESSION['ggl_oauth_last_code'] = $code;

    $redirectUriFluxo = trim((string) ($_SESSION['ggl_oauth_redirect_uri'] ?? ''));
    $dadosToken = requisitar_token($config, $code, $redirectUriFluxo);
    if (empty($dadosToken['access_token'])) {
        responder_html('Token de acesso não recebido do Google.');
    }

    $perfil = buscar_perfil($dadosToken['access_token']);
    $email = extrair_email($dadosToken, $perfil);
    $nome = extrair_nome($dadosToken, $perfil);
    if ($nome === '' && $email) {
        $nome = gerar_nome_por_email($email);
    }
    if (!$email) {
        responder_html('Não foi possível identificar o e-mail do usuário Google.');
    }

    $autenticado = autenticar_usuario_por_email($email, $baseDir);

    if (!$autenticado) {
        unset($_SESSION['ggl_oauth_processing']);
        $destinoCadastro = '/PaginasAreaClienteAcessoCadastro/AcessoCadastro.html';
        $params = [
            'tab' => 'cadastrar',
            'prov' => 'google',
            'nome' => $nome,
            'email' => $email,
        ];

        $retornoSessao = validar_retorno($_SESSION['ggl_oauth_retorno'] ?? null);
        if ($retornoSessao !== null) {
            $params['retorno'] = $retornoSessao;
        }

        header('Location: ' . $destinoCadastro . '?' . http_build_query($params));
        exit;
    }

    $destino = '/AreaCliente/Sessao';
    $retornoSessao = validar_retorno($_SESSION['ggl_oauth_retorno'] ?? null);
    if ($retornoSessao !== null) {
        $destino = $retornoSessao;
    }

    $_SESSION['ggl_oauth_success'] = time();
    unset($_SESSION['ggl_oauth_redirect_uri']);

    $destino = adicionar_login_sucesso($destino);
    header('Location: ' . $destino);
    exit;
} catch (RuntimeException $e) {
    if (erro_codigo_oauth_duplicado($e->getMessage())) {
        registrar_callback_duplicado('google', 'codigo_ja_resgatado');
        $retornoSessao = validar_retorno($_SESSION['ggl_oauth_retorno'] ?? null);
        lidar_callback_duplicado('google', $retornoSessao);
    }
    log_event('LoginGoogle: ' . $e->getMessage());
    responder_html('⚠️ Não foi possível completar o login com o Google. ' . $e->getMessage(), 500);
} catch (Throwable $t) {
    log_event('LoginGoogle: ' . $t->getMessage());
    responder_html('⚠️ Erro inesperado ao autenticar com o Google.', 500);
}
