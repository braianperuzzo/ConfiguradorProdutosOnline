<?php
$baseDir = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
if ($baseDir === '') {
    $baseDir = dirname(__DIR__);
}
require_once $baseDir . '/LogsErros/Logs.php';
require_once $baseDir . '/Seguranca/MetodosSegurancao.php';
require_once $baseDir . '/Seguranca/CSRF.php';
require_once $baseDir . '/TokensGeradores/TokensDataSenha.php';
require_once $baseDir . '/ProcessamentoFilaJobs/CriaFilaProcessamento.php';
registrar_verificacao_periodica_fila_jobs($baseDir);

ini_set('display_errors', 0);
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    log_event(sprintf(
        'RedefinirSenhaAPI - erro PHP [%s] %s em %s:%d',
        (string) $severity,
        (string) $message,
        (string) $file,
        (int) $line
    ));
    return true;
});
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        log_event(sprintf(
            'RedefinirSenhaAPI - erro fatal [%s] %s em %s:%d',
            (string) ($err['type'] ?? 'desconhecido'),
            (string) ($err['message'] ?? 'sem mensagem'),
            (string) ($err['file'] ?? 'arquivo desconhecido'),
            (int) ($err['line'] ?? 0)
        ));
    }
});

header('Content-Type: application/json; charset=utf-8');

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

$token = filter_input(INPUT_POST, 'token', FILTER_UNSAFE_RAW) ?: filter_input(INPUT_GET, 'token', FILTER_UNSAFE_RAW) ?: '';
if (!preg_match('/^[A-Fa-f0-9]{64}$/', $token)) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Token inválido.']);
    exit;
}

$arquivo = $baseDir . '/Tokens/TokensRecuperacao/' . $token . '.json';
if (!file_exists($arquivo)) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Token inválido ou expirado.']);
    exit;
}

$dados = json_decode(file_get_contents($arquivo), true);
$criado = $dados['criadoEm'] ?? 0;
if (time() - $criado > 24*60*60) {
    unlink($arquivo);
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Token expirado.']);
    exit;
}

$email = strtolower(trim($dados['email'] ?? ''));
$senhaEmToken = $dados['senhaEm'] ?? null;
if ($senhaEmToken !== null) {
    $senhaAtualizadaEm = get_password_timestamp($email);
    if ($senhaAtualizadaEm && $senhaAtualizadaEm > (int) $senhaEmToken) {
        unlink($arquivo);

        try {
        disparar_sincronizacao_cliente_background();
    } catch (Throwable $t) {
        log_event('RedefinirSenhaAPI - falha ao disparar sincronizacao do cliente: ' . $t->getMessage());
    }
        http_response_code(400);
        echo json_encode(['sucesso' => false, 'mensagem' => 'Token expirado.']);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf_token();
    $senha = $_POST['senha'] ?? '';
    $senhaRep = $_POST['senhaRepetida'] ?? '';
    if ($senha !== $senhaRep) {
        http_response_code(400);
        echo json_encode(['sucesso' => false, 'mensagem' => 'As senhas não conferem.']);
        exit;
    } elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^\w\s]).{8,}$/', $senha)) {
        http_response_code(400);
        echo json_encode(['sucesso' => false, 'mensagem' => 'Senha fora do padrão de segurança.']);
        exit;
    }
    require $baseDir . '/AcessosConsultas/CredenciaisBancoDados.php';
    try {
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
        if (defined('PDO::SQLSRV_ATTR_ENCODING') && defined('PDO::SQLSRV_ENCODING_UTF8')) {
            $options[PDO::SQLSRV_ATTR_ENCODING] = PDO::SQLSRV_ENCODING_UTF8;
        }
        $pdo = new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, $options);
        $stmt = $pdo->prepare("SELECT DS_SENHA FROM _USR_CONF_SITE_CADASTROS WHERE LOWER(DS_EMAIL)=?");
        $stmt->execute([strtolower($email)]);
        $senhaAtual = $stmt->fetchColumn();
        if ($senhaAtual && password_verify($senha, $senhaAtual)) {
            echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Senha não aceita. Escolha uma nova senha.']);
            exit;
        }
        $hash = password_hash($senha, PASSWORD_BCRYPT, ['cost' => 10]);
        $upd = $pdo->prepare("UPDATE _USR_CONF_SITE_CADASTROS SET DS_SENHA=? WHERE LOWER(DS_EMAIL)=?");
        $upd->execute([$hash, strtolower($email)]);

        $stmt = $pdo->prepare("SELECT DS_NOME, NR_CPFCNPJ FROM _USR_CONF_SITE_CADASTROS WHERE LOWER(DS_EMAIL)=?");
        $stmt->execute([strtolower($email)]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        require_once $baseDir . '/TokensGeradores/TokensDataSenha.php';
        set_password_timestamp($email, time(), $usuario['DS_NOME'] ?? '');
        require_once $baseDir . '/AcessosConsultas/BuscarNomeEmpresaReceitaCache.php';
        require_once $baseDir . '/TokensGeradores/TokenPadraoJson.php';

        $cpfcnpj = $usuario['NR_CPFCNPJ'] ?? '';
        $cpfcnpjNumeros = preg_replace('/[^0-9A-Za-z]/', '', $cpfcnpj);
        $empresa = obter_nome_empresa($cpfcnpjNumeros, $baseDir);

        $codigo = 0;
        $grupo = 'BRONZE';
        try {
            $codStmt = $pdo->prepare("SELECT TOP 1 CD_PESSOA FROM MBAD_PESSOA WHERE NR_CPFCNPJ = ?");
            $codStmt->execute([$cpfcnpjNumeros]);
            $codigo = $codStmt->fetchColumn() ?: 0;
            if ($codigo) {
                $permStmt = $pdo->prepare("SELECT MAX(TIPO.DS_TIPO) AS PERMISSAO\nFROM MBAD_PESSOACONTATO AS CONTATO\nINNER JOIN MBAD_PESSOACONTATOTIPO AS TIPO ON CONTATO.CD_TIPO = TIPO.CD_TIPO\nWHERE CONTATO.CD_PESSOA = ? AND CONTATO.CD_FUNCAO = 'SITE' AND LOWER(CONTATO.DS_EMAIL) = ?");
                $permStmt->execute([$codigo, strtolower(trim($email))]);
                $permissao = $permStmt->fetchColumn();
                $grupo = $permissao ? $permissao : 'PRATA';
            }
        } catch (PDOException $e) {
        }

        $segredo = getenv('JWT_SECRET');
        if ($segredo === false) {
            $segredo = trim(file_get_contents($baseDir . '/Configuracoes/Segredo.jwt'));
        }

        $ttlPadrao = getenv('JWT_TTL');
        $ttlPadrao = $ttlPadrao === false ? 604800 : (int)$ttlPadrao;
        if ($ttlPadrao <= 0) {
            $ttlPadrao = 604800;
        }
        $maxTime = 2592000; // 30 dias
        $ttl = min($ttlPadrao, $maxTime);
        $agora = time();

        $payload = [
            'email' => $email,
            'grupo' => $grupo,
            'nome' => $usuario['DS_NOME'] ?? '',
            'cpfcnpj' => $cpfcnpj,
            'empresa' => $empresa,
            'codigo' => $codigo,
            'deviceVerified' => true,
            'exp' => $agora + $ttl,
            'maxExp' => $agora + $maxTime
        ];

        $tokenJWT = JWTHelper::encode($payload, $segredo);

        $cookieConfig = obter_configuracao_cookie();
        $cookieDomain = $cookieConfig['domain'];
        $cookieSecure = $cookieConfig['secure'];
                $cookieSameSite = $cookieConfig['samesite'];
        $cookieAuth = [
            'expires' => $payload['exp'],
            'path' => '/',
            'secure' => $cookieSecure,
            'httponly' => true,
            'samesite' => $cookieSameSite
                    ];
        set_cookie_multi_domain('auth_token', $tokenJWT, $cookieAuth, $cookieDomain);
        
        unlink($arquivo);

        try {
        disparar_sincronizacao_cliente_background();
    } catch (Throwable $t) {
        log_event('RedefinirSenhaAPI - falha ao disparar sincronizacao do cliente: ' . $t->getMessage());
    }
        http_response_code(200);
        echo json_encode(['sucesso' => true]);
        exit;
    } catch (PDOException $e) {
        log_event('RedefinirSenhaAPI - erro PDO ao atualizar senha: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['sucesso' => false, 'mensagem' => 'Erro ao atualizar senha.']);
    } catch (Throwable $e) {
        log_event('RedefinirSenhaAPI - erro inesperado ao atualizar senha: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['sucesso' => false, 'mensagem' => 'Erro inesperado.']);
    }
} else {
    http_response_code(200);
    echo json_encode(['sucesso' => true]);
    exit;
}
?>
