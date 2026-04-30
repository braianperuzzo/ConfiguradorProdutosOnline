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
require_once $baseDir . '/TokensGeradores/TokensDataSenha.php';
require_once $baseDir . '/AcessosConsultas/UltimaEmpresaLogadaUsuario.php';
require_once $baseDir . '/ProcessamentoFilaJobs/CriaFilaProcessamento.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
require_valid_csrf_token();

$email = strtolower(trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL) ?? ''));
$senha = $_POST['senha'] ?? '';
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
        // SameSite=None deve ser aplicado apenas quando houver necessidade comprovada de envio em contexto cross-site.
        'samesite' => 'Lax',
    ];
}

// Mensagens específicas usadas no tratamento de erros durante a autenticação.
$MSG_EMAIL_NAO_ENCONTRADO = '⚠️ E-mail não encontrado. Realize seu cadastro.';
$MSG_INFO_ERRADA = '⚠️ Alguma informação está errada. Cadastre-se ou clique em Esqueceu a Senha.';

/**
 * Envia uma resposta de erro padronizada em JSON e encerra o script.
 */
function responderErro(int $status, string $mensagem): void
{
    http_response_code($status);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => $mensagem,
        'status' => $status
    ]);
    exit;
}

/**
 * Realiza o retroajuste dos registros antigos que não possuem NR_CPFCNPJ vinculado.
 * Ao acessar com um documento válido, garante que todos os históricos do e-mail
 * recebam o mesmo identificador para evitar que fiquem "sem dono".
 */
function retroajustarDocumentosAntigos(PDO $pdo, string $email, string $documento): void
{
    $documentoLimpo = preg_replace('/[^0-9A-Za-z]/', '', $documento);
    $emailLimpo = strtolower(trim($email));

    if ($documentoLimpo === '' || $emailLimpo === '') {
        return;
    }

    $tabelas = [
        ['nome' => '_USR_CONF_SITE_CADASTROS', 'colunaEmail' => 'DS_EMAIL'],
        ['nome' => '_USR_CONF_SITE_HISTORICO_COTACAO', 'colunaEmail' => 'DS_EMAIL'],
        ['nome' => '_USR_CONF_SITE_HISTORICO_PRODUTO', 'colunaEmail' => 'DS_EMAIL'],
        ['nome' => '_USR_PMPR_PROJETO', 'colunaEmail' => 'CD_EMAILCLIENTE'],
    ];

    foreach ($tabelas as $tabela) {
        $sql = sprintf(
            "UPDATE %s SET NR_CPFCNPJ = ? WHERE LOWER(%s) = ? AND (NR_CPFCNPJ IS NULL OR LTRIM(RTRIM(NR_CPFCNPJ)) = '')",
            $tabela['nome'],
            $tabela['colunaEmail']
        );
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$documentoLimpo, $emailLimpo]);
    }
}

function formatar_documento_nacional(string $valor): string
{
    $digitos = preg_replace('/[^0-9A-Za-z]/', '', $valor);

    if (strlen($digitos) === 14) {
        return vsprintf('%s%s.%s%s%s.%s%s%s/%s%s%s%s-%s%s', str_split($digitos));
    }

    if (strlen($digitos) === 11) {
        return vsprintf('%s%s%s.%s%s%s.%s%s%s-%s%s', str_split($digitos));
    }

    return $valor;
}

if (!check_rate_limit('login', 5, 60, $email)) {
    responderErro(429, '⚠️ Quantidade de solicitações máximas atingidas. Tente novamente em 1 hora.');
}

if (!$email || !$senha || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
    responderErro(400, '⚠️ Dados inválidos.');
}

require $baseDir . '/AcessosConsultas/CredenciaisBancoDados.php';

try {
    $pdo = new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
    ]);

    $stmt = $pdo->prepare("SELECT DS_SENHA, DS_NOME, NR_CPFCNPJ FROM _USR_CONF_SITE_CADASTROS WHERE LOWER(DS_EMAIL) = ?");
    $stmt->execute([strtolower($email)]);
    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$registros) {
        responderErro(404, $MSG_EMAIL_NAO_ENCONTRADO);
    }

    require_once $baseDir . '/AcessosConsultas/BuscarNomeEmpresaReceitaCache.php';
    require_once $baseDir . '/AcessosConsultas/ValidaEmpresasPorUsuario.php';

    $senhaHash = '';
    $nomeUsuario = '';
    $documentosUsuario = [];
    foreach ($registros as $linha) {
        if (!$senhaHash && !empty($linha['DS_SENHA'])) {
            $senhaHash = $linha['DS_SENHA'];
        }
        if (!$nomeUsuario && !empty($linha['DS_NOME'])) {
            $nomeUsuario = $linha['DS_NOME'];
        }
        $docLimpo = preg_replace('/[^0-9A-Za-z]/', '', (string)($linha['NR_CPFCNPJ'] ?? ''));
        if ($docLimpo !== '' && !in_array($docLimpo, $documentosUsuario, true)) {
            $documentosUsuario[] = $docLimpo;
        }
    }

    if (!$senhaHash || !password_verify($senha, $senhaHash)) {
        responderErro(403, $MSG_INFO_ERRADA);
    }

    $senhaExpirada = password_expired($email);

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

    $documentoSelecionado = preg_replace('/[^0-9A-Za-z]/', '', (string)($_POST['empresaDocumento'] ?? ''));
    $documentoPreferencia = obter_ultima_empresa_usuario($email, true);
    $documentoCookie = preg_replace('/[^0-9A-Za-z]/', '', (string)($_COOKIE['ibr_empresa_doc'] ?? ''));

    $empresaAtiva = null;
    if ($documentoSelecionado !== '' && isset($mapaEmpresas[$documentoSelecionado])) {
        $empresaAtiva = $mapaEmpresas[$documentoSelecionado];
    } elseif ($documentoPreferencia !== '' && isset($mapaEmpresas[$documentoPreferencia])) {
        $empresaAtiva = $mapaEmpresas[$documentoPreferencia];
        $documentoSelecionado = $documentoPreferencia;
    } elseif ($documentoCookie !== '' && isset($mapaEmpresas[$documentoCookie])) {
        $empresaAtiva = $mapaEmpresas[$documentoCookie];
        $documentoSelecionado = $documentoCookie;
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
        $mapaEmpresas = [$documentoSelecionado => $empresaAtiva];
    }

    $cookieConfig = obter_configuracao_cookie();
    $cookieDomain = $cookieConfig['domain'];
    $cookieSecure = $cookieConfig['secure'];
    $cookieSameSite = $cookieConfig['samesite'];

    if ($empresaAtiva) {
        $documentoAtivoLimpo = preg_replace('/[^0-9A-Za-z]/', '', (string)($empresaAtiva['documento'] ?? ''));
        $cookieEmpresa = [
            'expires' => time() + 60 * 60 * 24 * 180,
            'path' => '/',
            'secure' => $cookieSecure,
            'httponly' => true,
            'samesite' => $cookieSameSite
        ];
        set_cookie_multi_domain('ibr_empresa_doc', $empresaAtiva['documento'], $cookieEmpresa, $cookieDomain);
        if (strlen($documentoAtivoLimpo) === 14) {
            definir_ultima_empresa_usuario($email, $documentoAtivoLimpo, true, [
                'nome' => $nomeUsuario,
                'email' => $email,
                'empresaNome' => $empresaAtiva['nome'] ?? '',
            ]);
        }
    }

    $grupo = $empresaAtiva['papel'] ?? 'PRATA';
    $codigo = (string)($empresaAtiva['codigo'] ?? '');
    $empresaNome = $empresaAtiva['nome'] ?? '';
    $cpfcnpj = $empresaAtiva['documento'] ?? ($documentosUsuario[0] ?? '');
        retroajustarDocumentosAntigos($pdo, $email, $cpfcnpj);
    $usuario = ['DS_NOME' => $nomeUsuario, 'NR_CPFCNPJ' => $cpfcnpj];

    if (!$empresaNome) {
        $empresaNome = obter_nome_empresa($cpfcnpj, $baseDir);
    }
    $empresaNome = trim((string) $empresaNome);

    $segredo = getenv('JWT_SECRET');
    if ($segredo === false) {
        $segredo = trim(file_get_contents($baseDir . '/Configuracoes/Segredo.jwt'));
    }

    $ttlPadrao = getenv('JWT_TTL');
    // Tempo de inatividade padrão de 7 dias (604800 segundos)
    $ttlPadrao = $ttlPadrao === false ? 604800 : (int)$ttlPadrao;
    if ($ttlPadrao <= 0) {
        $ttlPadrao = 604800;
    }
    $maxTime = 2592000; // 30 dias
    // Expiração da sessão por inatividade nunca ultrapassa 7 dias
    $ttl = min($ttlPadrao, $maxTime);
    $agora = time();
    $payload = [
        'email' => $email,
        'grupo' => $grupo,
        'authProvider' => 'site',
        'nome' => $usuario['DS_NOME'],
        'cpfcnpj' => $cpfcnpj,
        'empresa' => $empresaNome,
        'empresaDocumento' => $documentoSelecionado,
        'empresaNome' => $empresaNome,
        'empresaPapel' => $grupo,
        'codigo' => $codigo,
        'exp' => $agora + $ttl,
        'maxExp' => $agora + $maxTime
    ];

    // Verificação de dispositivo e envio de código
    $deviceHash = hash('sha256', strtolower($email) . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $dirDevices = $baseDir . '/Tokens/TokensDispositivos';
    if (!is_dir($dirDevices)) {
        mkdir($dirDevices, 0700, true);
        file_put_contents($dirDevices . '/web.config', "<?xml version=\"1.0\" encoding=\"UTF-8\"?><configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>");
    }
       $deviceFile = $dirDevices . '/' . $deviceHash . '.json';
    $needsVerification = true;
    if (file_exists($deviceFile)) {
        $info = json_decode(file_get_contents($deviceFile), true);
        if ($info && isset($info['verifiedAt']) && time() - $info['verifiedAt'] < 2592000) {
            $needsVerification = false;
        }
    }

     $payload['deviceVerified'] = !$needsVerification;

    if ($needsVerification && !$senhaExpirada) {
        $codigoLogin = random_int(100000, 999999);
        require $baseDir . '/PHPMailer/src/PHPMailer.php';
        require $baseDir . '/PHPMailer/src/SMTP.php';
        require $baseDir . '/PHPMailer/src/Exception.php';
        require $baseDir . '/AcessosConsultas/CredenciaisAcessoEmail.php';
        $template = file_get_contents(__DIR__ . '/EmailCodigoLogin.html');
        $documentoLimpo = preg_replace('/[^0-9A-Za-z]/', '', (string) $cpfcnpj);
        $nomeUsuarioSaudacao = trim((string) ($usuario['DS_NOME'] ?? ''));
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
        if (empty($componentesSaudacao) && $usuario['DS_NOME'] !== '') {
            $componentesSaudacao[] = $usuario['DS_NOME'];
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
            $saudacaoTexto = 'Olá, ' . ($saudacaoConteudo !== '' ? $saudacaoConteudo : $usuario['DS_NOME']) . ',';
            $altBody = $saudacaoTexto . "\n\nSeu código de acesso é: $codigoLogin";
            $mail->AltBody = $altBody;

            if (!$mail->send()) {
                throw new Exception($mail->ErrorInfo);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Erro ao enviar código.', 'status' => 500]);
            exit;
        }
        $info = [
            'code' => password_hash($codigoLogin, PASSWORD_DEFAULT),
            'payload' => $payload,
            'ttl' => $ttl,
            'generatedAt' => time()
        ];
        file_put_contents($deviceFile, json_encode($info));
        chmod($deviceFile, 0600);
        echo json_encode([
            'sucesso' => true,
            'verificacaoNecessaria' => true,
            'mensagem' => 'Código enviado para o e-mail ' . $email . '.',
            'empresaDocumento' => $documentoSelecionado,
            'empresaNome' => $empresaNome,
            'empresaPapel' => $grupo,
            'empresas' => $listaEmpresas
        ]);
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
        log_event('Login - falha ao disparar sincronizacao do cliente: ' . $t->getMessage());
    }

    if ($needsVerification && $senhaExpirada) {
        $info = [
            'payload' => $payload,
            'ttl' => $ttl
        ];
        file_put_contents($deviceFile, json_encode($info));
        chmod($deviceFile, 0600);
    }

    echo json_encode([
        'sucesso' => true,
        'senhaExpirada' => $senhaExpirada,
        'verificacaoNecessaria' => $needsVerification,
        'empresaDocumento' => $documentoSelecionado,
        'empresaNome' => $empresaNome,
        'empresaPapel' => $grupo,
        'empresas' => $listaEmpresas
    ]);
} catch (PDOException $e) {
    log_event('Login: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Erro no servidor.', 'status' => 500]);
}
?>
