<?php
ini_set('display_errors', 0);
error_reporting(0);
$baseDir = dirname(__DIR__);
$documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
if ($documentRoot !== '') {
    $documentRootReal = realpath($documentRoot);
    if ($documentRootReal !== false && is_dir($documentRootReal . '/TokensGeradores')) {
        $baseDir = $documentRootReal;
    }
}

require_once $baseDir . '/Seguranca/MetodosSegurancao.php';
require_once $baseDir . '/LogsErros/Logs.php';
require_once $baseDir . '/TokensGeradores/TokenPadraoJson.php';
require_once $baseDir . '/TokensGeradores/TokenInvalidacao.php';
require_once $baseDir . '/TokensGeradores/TokensDataSenha.php';
require_once $baseDir . '/AcessosConsultas/ValidaEmpresasPorUsuario.php';
require_once $baseDir . '/AcessosConsultas/UltimaEmpresaLogadaUsuario.php';

require $baseDir . '/AcessosConsultas/CredenciaisBancoDados.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$metodoRequisicao = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($metodoRequisicao !== 'GET') {
    require_valid_csrf_token();
}

function criar_pdo_sqlsrv(string $dbhost, string $db, string $user, string $password): PDO
{
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 3
    ];

    if (defined('PDO::SQLSRV_ATTR_ENCODING') && defined('PDO::SQLSRV_ENCODING_UTF8')) {
        $options[PDO::SQLSRV_ATTR_ENCODING] = PDO::SQLSRV_ENCODING_UTF8;
    }

    try {
        return new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, $options);
    } catch (PDOException $e) {
        if (stripos($e->getMessage(), 'unsupported attribute') !== false) {
            return new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
        }

        throw $e;
    }
}
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

$token = $_COOKIE['auth_token'] ?? '';
$cookieConfig = obter_configuracao_cookie();
$cookieDomain = $cookieConfig['domain'];
$cookieSecure = $cookieConfig['secure'];
$cookieSameSite = $cookieConfig['samesite'];

if (!$token) {
    http_response_code(401);

    echo json_encode(["erro" => "⚠️ Usuário não autenticado."]);
    exit;
}

$segredo = getenv('JWT_SECRET');
if ($segredo === false) {
    $segredo = trim(file_get_contents($baseDir . '/Configuracoes/Segredo.jwt'));
}

$dados = JWTHelper::decode($token, $segredo);
if (!$dados || is_token_blacklisted($token)) {
    http_response_code(403);
    echo json_encode(["erro" => "⚠️ Credenciais inválidas."]);
    exit;
}

$maxExp = $dados['maxExp'] ?? 0;
if (!$maxExp || time() >= $maxExp) {
    http_response_code(403);
    echo json_encode(["erro" => "⚠️ Sessão expirada. Faça login novamente"]);
    exit;
}

$email = $dados['email'] ?? '';
$authProvider = $dados['authProvider'] ?? 'site';
if ($authProvider === 'site') {
    $timestampSenha = get_password_timestamp($email);
    if ($timestampSenha === 0 || password_expired($email)) {
        blacklist_token($token);
        $cookieLimpar = [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => $cookieSecure,
            'httponly' => true,
            'samesite' => $cookieSameSite
        ];
        if ($cookieDomain !== '') {
            $cookieLimpar['domain'] = $cookieDomain;
        }
        set_cookie_compat('auth_token', '', $cookieLimpar);
        http_response_code(401);
        echo json_encode(["sucesso" => false, "senhaExpirada" => true]);
        exit;
    }
}

// Verificação de dispositivo - expiração após 30 dias
if (empty($dados['deviceVerified'])) {
    $deviceHash = hash('sha256', strtolower($email) . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $dirDevices = $baseDir . '/Tokens/TokensDispositivos';
    $deviceFile = $dirDevices . '/' . $deviceHash . '.json';
    $needsVerification = true;
    if (file_exists($deviceFile)) {
        $info = json_decode(file_get_contents($deviceFile), true);
        if ($info && isset($info['verifiedAt']) && time() - $info['verifiedAt'] < 2592000) {
            $needsVerification = false;
        }
    }
    if ($needsVerification) {
        blacklist_token($token);
        $cookieLimpar = [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => $cookieSecure,
            'httponly' => true,
            'samesite' => $cookieSameSite
        ];
set_cookie_multi_domain('auth_token', '', $cookieLimpar, $cookieDomain);
        http_response_code(401);
        echo json_encode(["sucesso" => false, "codigoExpirado" => true]);
        exit;
    }
}

$documentoToken = preg_replace('/[^0-9A-Za-z]/', '', (string)($dados['empresaDocumento'] ?? ($dados['cpfcnpj'] ?? '')));
$documentoSolicitado = '';
if ($metodoRequisicao === 'POST') {
    $documentoSolicitado = preg_replace('/[^0-9A-Za-z]/', '', (string)($_POST['empresaDocumento'] ?? ''));
}
$documentoCookie = preg_replace('/[^0-9A-Za-z]/', '', (string)($_COOKIE['ibr_empresa_doc'] ?? ''));
$documentoPreferencia = '';
$listaEmpresas = [];
$mapaEmpresas = [];
$empresaAtiva = null;
$documentoAtivo = $documentoToken;
$novidades = 'NAO';
$erroConexaoDb = false;

$tentativasConexao = 0;
while ($tentativasConexao < 2) {
    try {
        $pdo = criar_pdo_sqlsrv($dbhost, $db, $user, $password);

        $stmtDocs = $pdo->prepare("SELECT NR_CPFCNPJ FROM _USR_CONF_SITE_CADASTROS WHERE LOWER(DS_EMAIL) = ?");
        $stmtDocs->execute([strtolower($email)]);
        $documentosBrutos = $stmtDocs->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $documentosUsuario = [];
        foreach ($documentosBrutos as $doc) {
            $docLimpo = preg_replace('/[^0-9A-Za-z]/', '', (string)$doc);
            if ($docLimpo !== '') {
                $documentosUsuario[] = $docLimpo;
            }
        }
        if ($documentoToken !== '' && empty($documentosUsuario)) {
            $documentosUsuario[] = $documentoToken;
        }

        $empresasUsuario = obter_empresas_usuario($pdo, $email, $documentosUsuario, $baseDir);
        $listaEmpresas = $empresasUsuario['lista'];
        $mapaEmpresas = $empresasUsuario['porDocumento'];

        $documentoPreferencia = obter_ultima_empresa_usuario($email, true);

        if ($metodoRequisicao === 'POST' && $documentoSolicitado !== '') {
            if (!isset($mapaEmpresas[$documentoSolicitado])) {
                http_response_code(400);
                echo json_encode(["sucesso" => false, "erro" => "⚠️ CNPJ ou CPF não encontrado." ]);
                exit;
            }
            $empresaAtiva = $mapaEmpresas[$documentoSolicitado];
            $documentoAtivo = $documentoSolicitado;
        } elseif ($documentoCookie !== '' && isset($mapaEmpresas[$documentoCookie])) {
            $empresaAtiva = $mapaEmpresas[$documentoCookie];
            $documentoAtivo = $documentoCookie;
        } elseif ($documentoPreferencia !== '' && isset($mapaEmpresas[$documentoPreferencia])) {
            $empresaAtiva = $mapaEmpresas[$documentoPreferencia];
            $documentoAtivo = $documentoPreferencia;
        } elseif ($documentoToken !== '' && isset($mapaEmpresas[$documentoToken])) {
            $empresaAtiva = $mapaEmpresas[$documentoToken];
            $documentoAtivo = $documentoToken;
        } elseif (!empty($listaEmpresas)) {
            $empresaAtiva = $listaEmpresas[0];
            $documentoAtivo = $empresaAtiva['documento'];
        }

        $docBuscaNovidades = $documentoAtivo !== '' ? $documentoAtivo : $documentoToken;
        if ($docBuscaNovidades !== '') {
            $stmtNovidades = $pdo->prepare("SELECT TOP 1 DS_NOVIDADES FROM _USR_CONF_SITE_CADASTROS WHERE LOWER(DS_EMAIL) = ? AND NR_CPFCNPJ = ?");
            $stmtNovidades->execute([strtolower($email), $docBuscaNovidades]);
            $novidadesBanco = strtoupper(trim((string) $stmtNovidades->fetchColumn()));
            if ($novidadesBanco === 'SIM') {
                $novidades = 'SIM';
            }
        }
        if ($novidades !== 'SIM') {
            $stmtNovidades = $pdo->prepare("SELECT TOP 1 DS_NOVIDADES FROM _USR_CONF_SITE_CADASTROS WHERE LOWER(DS_EMAIL) = ?");
            $stmtNovidades->execute([strtolower($email)]);
            $novidadesBanco = strtoupper(trim((string) $stmtNovidades->fetchColumn()));
            if ($novidadesBanco === 'SIM') {
                $novidades = 'SIM';
            }
        }

        $pdo = null;
        $erroConexaoDb = false;
        break;
    } catch (PDOException $e) {
        $tentativasConexao++;
        $erroConexaoDb = true;
        if ($tentativasConexao >= 2) {
            log_event('ValidarSessao empresas: ' . $e->getMessage());
            break;
        }
        usleep(250000);
    }
}

if ($erroConexaoDb) {
    http_response_code(503);
    echo json_encode([
        "sucesso" => false,
        "tipoErro" => "servico_indisponivel",
        "erro" => "⚠️ Serviço indisponível. Tente novamente em instantes."
    ]);
    exit;
}

if (!$empresaAtiva) {
    $nomeEmpresaToken = $dados['empresaNome'] ?? ($dados['empresa'] ?? '');
    $papelToken = $dados['empresaPapel'] ?? ($dados['grupo'] ?? 'PRATA');
    $codigoToken = (string)($dados['codigo'] ?? '');
    if ($documentoAtivo !== '') {
        $empresaAtiva = [
            'documento' => $documentoAtivo,
            'nome' => $nomeEmpresaToken ?: $documentoAtivo,
            'papel' => $papelToken ?: 'PRATA',
            'niveisPermissoes' => '',
            'codigo' => $codigoToken,
        ];
    }
}

if ($empresaAtiva && !empty($empresaAtiva['documento'])) {
    $documentoAtivoLimpo = preg_replace('/[^0-9A-Za-z]/', '', (string)$empresaAtiva['documento']);
    $cookieEmpresa = [
        'expires' => time() + 60 * 60 * 24 * 180,
        'path' => '/',
        'secure' => $cookieSecure,
        'httponly' => true,
        'samesite' => $cookieSameSite
    ];
    $documentoAtivo = $documentoAtivoLimpo !== '' ? $documentoAtivoLimpo : $empresaAtiva['documento'];
    set_cookie_multi_domain('ibr_empresa_doc', $documentoAtivo, $cookieEmpresa, $cookieDomain);
    if (strlen($documentoAtivoLimpo) === 14) {
        definir_ultima_empresa_usuario($email, $documentoAtivoLimpo, true, [
            'nome' => $dados['nome'] ?? '',
            'email' => $email,
            'empresaNome' => $empresaAtiva['nome'] ?? '',
        ]);
    }
} else {
    $cookieEmpresa = [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => $cookieSecure,
        'httponly' => true,
        'samesite' => $cookieSameSite
    ];
    set_cookie_multi_domain('ibr_empresa_doc', '', $cookieEmpresa, $cookieDomain);
}

$grupo = $empresaAtiva['papel'] ?? ($dados['grupo'] ?? 'PRATA');
$niveisPermissoes = trim((string)($empresaAtiva['niveisPermissoes'] ?? ''));
$empresaNome = $empresaAtiva['nome'] ?? ($dados['empresaNome'] ?? ($dados['empresa'] ?? ''));
$codigo = $empresaAtiva['codigo'] ?? (string)($dados['codigo'] ?? '');

$dados['grupo'] = $grupo;
$dados['cpfcnpj'] = $documentoAtivo ?? '';
$dados['empresa'] = $empresaNome;
$dados['empresaDocumento'] = $documentoAtivo ?? '';
$dados['empresaNome'] = $empresaNome;
$dados['empresaPapel'] = $grupo;
$dados['niveisPermissoes'] = $niveisPermissoes;
$dados['codigo'] = $codigo;
$dados['novidades'] = $novidades;

$ttl = getenv('JWT_TTL');
if ($ttl === false) {
    $ttl = 604800; // 7 dias por inatividade
} else {
    $ttl = (int)$ttl;
}
if ($ttl <= 0) {
    $ttl = 604800;
}

$novoExp = min($maxExp, time() + $ttl);
$dados['exp'] = $novoExp;
$dados['maxExp'] = $maxExp;
$novoToken = JWTHelper::encode($dados, $segredo);
$cookieAuth = [
    'expires' => $novoExp,
    'path' => '/',
    'secure' => $cookieSecure,
    'httponly' => true,
    'samesite' => $cookieSameSite
];
set_cookie_multi_domain('auth_token', $novoToken, $cookieAuth, $cookieDomain);
echo json_encode([
    "sucesso" => true,
    "email" => $dados['email'],
    "grupo" => $dados['grupo'],
    "nome" => strtoupper($dados['nome']),
    "cpfcnpj" => $dados['cpfcnpj'] ?? '',
    "empresa" => $empresaNome,
    "empresaDocumento" => $dados['empresaDocumento'],
    "empresaNome" => $dados['empresaNome'],
    "empresaPapel" => $dados['empresaPapel'],
    "niveisPermissoes" => $dados['niveisPermissoes'],
    "codigo" => $dados['codigo'],
    "novidades" => $dados['novidades'],
    "exp" => $dados['exp'],
    "empresas" => $listaEmpresas,
    "trocaRealizada" => $metodoRequisicao === 'POST'
]);
?>
