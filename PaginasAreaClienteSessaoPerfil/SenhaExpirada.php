<?php
ini_set('display_errors', 0);
error_reporting(0);
$baseDir = $_SERVER['DOCUMENT_ROOT'] ?: dirname(__DIR__);
require $baseDir . '/Seguranca/MetodosSegurancao.php';
require_once $baseDir . '/LogsErros/Logs.php';
require_once $baseDir . '/TokensGeradores/TokenPadraoJson.php';
require_once $baseDir . '/TokensGeradores/TokenInvalidacao.php';
require_once $baseDir . '/TokensGeradores/TokensDataSenha.php';
require_once $baseDir . '/ProcessamentoFilaJobs/CriaFilaProcessamento.php';
registrar_verificacao_periodica_fila_jobs($baseDir);
require_once $baseDir . '/Seguranca/CSRF.php';
require_once $baseDir . '/AcessosConsultas/BuscarNomeEmpresaReceitaCache.php';
require_once $baseDir . '/Versionamento/Versao.php';

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

$formatarDocumento = function (?string $valor): string {
    $digitos = preg_replace('/[^0-9A-Za-z]/', '', (string) $valor);

    if (strlen($digitos) === 14) {
        return vsprintf('%s%s.%s%s%s.%s%s%s/%s%s%s%s-%s%s', str_split($digitos));
    }

    if (strlen($digitos) === 11) {
        return vsprintf('%s%s%s.%s%s%s.%s%s%s-%s%s', str_split($digitos));
    }

    return trim((string) $valor);
};

$montarSaudacao = function (?string $nome, ?string $documento, ?string $empresa) use ($formatarDocumento): string {
    $componentes = [];
    $nome = trim((string) $nome);
    if ($nome !== '') {
        $componentes[] = $nome;
    }

    $documentoLimpo = preg_replace('/[^0-9A-Za-z]/', '', (string) $documento);
    if (strlen($documentoLimpo) === 14) {
        $componentes[] = $formatarDocumento($documentoLimpo);
        $empresa = trim((string) $empresa);
        if ($empresa !== '') {
            $componentes[] = $empresa;
        }
    }

    if (empty($componentes) && $nome !== '') {
        $componentes[] = $nome;
    }

    return implode(' - ', $componentes);
};

$codigoEnviado = false;
$redirectUrl = '/AreaCliente?senha=sucesso';
$mensagemSucesso = '✅ Senha atualizada com sucesso.';
$versaoApp = obterVersaoAplicacao();

$token = $_COOKIE['auth_token'] ?? '';
if (!$token) {
    header('Location: /AreaCliente');
    exit;
}
$segredo = getenv('JWT_SECRET');
if ($segredo === false) {
    $segredo = trim(file_get_contents($baseDir . '/Configuracoes/Segredo.jwt'));
}
$dados = JWTHelper::decode($token, $segredo);
if (!$dados || is_token_blacklisted($token)) {
    setcookie('auth_token', '', time()-3600, '/', 'configurador.redutoresibr.com.br', true, true);
    header('Location: /AreaCliente');
    exit;
}
$email = strtolower($dados['email'] ?? '');
$erro = '';
$sucesso = false;
$dirDevices = $baseDir . '/Tokens/TokensDispositivos';
if (!is_dir($dirDevices)) {
    mkdir($dirDevices, 0700, true);
    file_put_contents($dirDevices . '/web.config', "<?xml version=\"1.0\" encoding=\"UTF-8\"?><configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>");
}
$deviceHash = hash('sha256', strtolower($email) . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
$deviceFile = $dirDevices . '/' . $deviceHash . '.json';
$deviceData = null;
if (is_file($deviceFile)) {
    $conteudo = file_get_contents($deviceFile);
    $json = json_decode($conteudo, true);
    if (is_array($json)) {
        $deviceData = $json;
    }
}
$pendenteValidacao = $deviceData && !isset($deviceData['verifiedAt']);
if (isset($_GET['validacao'])) {
    $destino = '/AreaCliente?validacao=1';
    if ($email) {
        $destino .= '&email=' . rawurlencode($email);
    }
    header('Location: ' . $destino);
    exit;
}
$isAjax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf_token();
    $senha = $_POST['senha'] ?? '';
    $senhaRep = $_POST['senhaRepetida'] ?? '';

    if ($senha !== $senhaRep) {
        $erro = '⚠️ As senhas não conferem.';
    } elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^\w\s]).{8,}$/', $senha)) {
        $erro = '⚠️ Senha fora do padrão de segurança.';
    } else {
        require $baseDir . '/AcessosConsultas/CredenciaisBancoDados.php';
        try {
            $pdo = new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
            ]);

            $stmt = $pdo->prepare("SELECT DS_SENHA FROM _USR_CONF_SITE_CADASTROS WHERE LOWER(DS_EMAIL)=?");
            $stmt->execute([strtolower($email)]);
            $senhaAtual = $stmt->fetchColumn();

            if ($senhaAtual && password_verify($senha, $senhaAtual)) {
                $erro = '⚠️ Senha não aceita. Escolha uma nova senha.';
            } else {
                $hash = password_hash($senha, PASSWORD_BCRYPT, ['cost' => 10]);
                $upd = $pdo->prepare("UPDATE _USR_CONF_SITE_CADASTROS SET DS_SENHA=? WHERE LOWER(DS_EMAIL)=?");
                $upd->execute([$hash, strtolower($email)]);

                $stmt = $pdo->prepare("SELECT DS_NOME FROM _USR_CONF_SITE_CADASTROS WHERE LOWER(DS_EMAIL)=?");
                $stmt->execute([strtolower($email)]);
                $nomeUsuario = $stmt->fetchColumn() ?: '';

                set_password_timestamp($email, time(), $nomeUsuario);

                try {
        disparar_sincronizacao_cliente_background();
    } catch (Throwable $t) {
        log_event('SenhaExpirada - falha ao disparar sincronizacao do cliente: ' . $t->getMessage());
    }

                $sucesso = true;

if ($pendenteValidacao || ($deviceData && isset($deviceData['payload']))) {
                    try {
                        $codigoLogin = random_int(100000, 999999);
                        require $baseDir . '/PHPMailer/src/PHPMailer.php';
                        require $baseDir . '/PHPMailer/src/SMTP.php';
                        require $baseDir . '/PHPMailer/src/Exception.php';
                        require $baseDir . '/AcessosConsultas/CredenciaisAcessoEmail.php';
                        $payload = $deviceData['payload'] ?? [];
                        if (!is_array($payload)) {
                            $payload = [];
                        }
                        if (!$payload) {
                            $payload = [
                                'email' => $dados['email'] ?? $email,
                                'grupo' => $dados['grupo'] ?? 'BRONZE',
                                'nome' => $dados['nome'] ?? $nomeUsuario,
                                'cpfcnpj' => $dados['cpfcnpj'] ?? '',
                                'empresa' => $dados['empresa'] ?? '',
                                'codigo' => $dados['codigo'] ?? 0,
                                'exp' => $dados['exp'] ?? (time() + 604800),
                                'maxExp' => $dados['maxExp'] ?? (time() + 2592000)
                            ];
                        }

                        $cpfcnpjPayload = preg_replace('/[^0-9A-Za-z]/', '', (string)($payload['cpfcnpj'] ?? ''));
                        $empresaPayload = trim((string)($payload['empresa'] ?? ''));
                        if (strlen($cpfcnpjPayload) === 14 && $empresaPayload === '') {
                            $empresaPayload = trim((string) obter_nome_empresa($cpfcnpjPayload, $baseDir));
                        }
                        $payload['cpfcnpj'] = $cpfcnpjPayload;
                        if ($empresaPayload !== '') {
                            $payload['empresa'] = $empresaPayload;
                        }

                        $nomeEmail = $nomeUsuario;
                        if ($nomeEmail === '') {
                            $nomeEmail = trim((string)($payload['nome'] ?? ''));
                        }
                        $template = file_get_contents($baseDir . '/PaginasAreaClienteAcessoCadastro/EmailCodigoLogin.html');
                        $cpfcnpjLimpo = preg_replace('/[^0-9A-Za-z]/', '', (string) ($payload['cpfcnpj'] ?? ''));
                        $saudacaoConteudo = $montarSaudacao($nomeEmail, $cpfcnpjLimpo, $payload['empresa'] ?? '');
                        $saudacaoHtml = htmlspecialchars($saudacaoConteudo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                        $html = str_replace(
                            ['SAUDACAO_USUARIO', 'CODIGO_LOGIN'],
                            [$saudacaoHtml, $codigoLogin],
                            $template
                        );
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
                        $saudacaoTexto = 'Olá, ' . ($saudacaoConteudo !== '' ? $saudacaoConteudo : $nomeEmail) . ',';
                        $mail->AltBody = $saudacaoTexto . "\n\nSeu código de acesso é: $codigoLogin";
                        if (!$mail->send()) {
                            throw new Exception($mail->ErrorInfo);
                        }

                        $ttl = isset($deviceData['ttl']) ? (int)$deviceData['ttl'] : 604800;
                        $info = [
                            'code' => password_hash($codigoLogin, PASSWORD_DEFAULT),
                            'payload' => $payload,
                            'ttl' => $ttl,
                            'generatedAt' => time()
                        ];

                        file_put_contents($deviceFile, json_encode($info));
                        chmod($deviceFile, 0600);

                        $codigoEnviado = true;
                        $pendenteValidacao = true;
                        $redirectUrl = '/AreaCliente?validacao=1';
                        if ($email) {
                            $redirectUrl .= '&email=' . rawurlencode($email);
                        }
                        $mensagemSucesso = '✅ Código enviado para o e-mail ' . $email . '.';
                    } catch (Throwable $e) {
                        $sucesso = false;
                        $erro = '⚠️ Erro ao enviar código.';
                    }
                }
            }
        } catch (PDOException $e) {
            $erro = '⚠️ Erro ao atualizar senha.';
        }
    }

    if ($sucesso) {
        try {
        disparar_sincronizacao_cliente_background();
    } catch (Throwable $t) {
        log_event('SenhaExpirada - falha ao disparar sincronizacao do cliente: ' . $t->getMessage());
    }
    }

    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        if ($sucesso) {
            echo json_encode([
                'sucesso' => true,
                'verificacaoNecessaria' => $codigoEnviado,
                'redirect' => $redirectUrl,
                'mensagem' => $mensagemSucesso
            ]);
        } else {
            echo json_encode(['sucesso' => false, 'mensagem' => $erro ?: 'Erro ao atualizar senha.']);
        }
        exit;
    }

    if ($sucesso) {
        header('Location: ' . $redirectUrl);
        exit;
    }
}
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0, viewport-fit=cover" name="viewport" />
    <link href="/Imagens/Icone.ico" rel="shortcut icon" type="image/x-icon" />
    <link href="/Imagens/Icone.ico" rel="icon" type="image/x-icon" />
    <link rel="apple-touch-icon" href="/Imagens/LogotipoAplicativo192192.png">
    <link rel="manifest" href="/Manifest.json">
    <script nonce="<?= htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8'); ?>">
        window.APP_VERSION = '<?= htmlspecialchars($versaoApp, ENT_QUOTES, 'UTF-8') ?>';
        window.__APP_VERSION_SOURCE__ = 'inline';
    </script>
    <script nonce="<?= htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8'); ?>" src="/Versionamento/CarregarVersao.js" defer></script>
    <meta name="theme-color" content="#ec4115">
    <meta name="color-scheme" content="light dark">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <title>Sessão Expirada | Configurador de Produtos IBR</title>
    <base href="/PaginasAreaClienteAcessoCadastro/" />
    <link href="/Layout/TipografiaAreaCliente.min.css" rel="stylesheet"
        data-versioned-href="/Layout/TipografiaAreaCliente.min.css">
    <link href="/Layout/LayoutCadastroAreaCliente.min.css" rel="stylesheet" as="style"
        data-versioned-href="/Layout/LayoutCadastroAreaCliente.min.css" onload="this.onload=null;">
    <link href="/Layout/LayoutAreaCliente.min.css" rel="stylesheet" as="style"
        data-versioned-href="/Layout/LayoutAreaCliente.min.css" onload="this.onload=null;">
    <script nonce="<?= htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8'); ?>" data-versioned-src="/CSRFInicia.js"></script>
    <script nonce="<?= htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8'); ?>" data-versioned-src="/Layout/TemasCor.min.js"></script>
    <script nonce="<?= htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8'); ?>" defer data-versioned-src="/SEO/TagsGoogle.min.js"></script>
</head>
<body>
    <main class="container my-5">
        <div class="row justify-content-center">
            <div class="col-lg-4 col-md-6 col-12">
                <h3 class="mb-3 text-center">Senha Expirada</h3>
                <p class="mb-4 text-center">Digite uma nova senha para continuar.</p>
                <form id="formSenha" method="post" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                    <div class="mb-3">
                        <div class="input-group bg-white border rounded py-2 input-form position-relative">
                            <span class="input-group-text bg-transparent px-4 border-top-0 border-bottom-0 border-start-0">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 13.216 15.419">
                                    <g id="noun-lock-6031914" transform="translate(-4 -2)">
                                        <g id="Layer_2" data-name="Layer 2" transform="translate(4 2)">
                                            <path id="Caminho_932" data-name="Caminho 932" d="M15.564,6.956h-1.1V5.3a3.3,3.3,0,0,0-3.3-3.3h-1.1a3.3,3.3,0,0,0-3.3,3.3V6.956h-1.1A1.652,1.652,0,0,0,4,8.608v7.159a1.652,1.652,0,0,0,1.652,1.652h9.912a1.652,1.652,0,0,0,1.652-1.652V8.608a1.652,1.652,0,0,0-1.652-1.652ZM7.855,5.3a2.2,2.2,0,0,1,2.2-2.2h1.1a2.2,2.2,0,0,1,2.2,2.2V6.956H7.855Zm8.26,10.463a.551.551,0,0,1-.551.551H5.652a.551.551,0,0,1-.551-.551V8.608a.551.551,0,0,1,.551-.551h9.912a.551.551,0,0,1,.551.551Zm-4.956-2.2v1.1a.551.551,0,1,1-1.1,0v-1.1a.551.551,0,0,1,1.1,0Z" transform="translate(-4 -2)"></path>
                                        </g>
                                    </g>
                                </svg>
                            </span>
                            <div class="form-floating flex-grow-1">
                                <input type="password" class="form-control bg-transparent border-0" id="senhaCadastro" name="senha" placeholder="Senha" autocomplete="new-password" required>
                                <label for="senhaCadastro">Senha</label>
                            </div>
                            <button type="button" class="btn btn-outline-secondary border-0 toggle-password" data-target="#senhaCadastro"><svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3.97993 8.22257C3.05683 9.31382 2.35242 10.596 1.93436 12.0015C3.22565 16.338 7.24311 19.5 11.9991 19.5C12.9917 19.5 13.9521 19.3623 14.8623 19.1049M6.22763 6.22763C7.88389 5.13558 9.86771 4.5 12 4.5C16.756 4.5 20.7734 7.66205 22.0647 11.9985C21.3528 14.3919 19.8106 16.4277 17.772 17.772M6.22763 6.22763L3 3M6.22763 6.22763L9.87868 9.87868M17.772 17.772L21 21M17.772 17.772L14.1213 14.1213M14.1213 14.1213C14.6642 13.5784 15 12.8284 15 12C15 10.3431 13.6569 9 12 9C11.1716 9 10.4216 9.33579 9.87868 9.87868M14.1213 14.1213L9.87868 9.87868" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg></button>
                        </div>
                    </div>
                    <ul id="passwordCriteria" class="password-popover list-unstyled small d-none">
                        <li id="criterion-length" class="text-danger">Mínimo 8 caracteres</li>
                        <li id="criterion-upper" class="text-danger">Uma letra maiúscula</li>
                        <li id="criterion-lower" class="text-danger">Uma letra minúscula</li>
                        <li id="criterion-digit" class="text-danger">Um número</li>
                        <li id="criterion-special" class="text-danger">Um caractere especial</li>
                    </ul>
                    <div class="mb-3">
                        <div class="input-group bg-white border rounded py-2 mt-3 input-form position-relative">
                            <span class="input-group-text bg-transparent px-4 border-top-0 border-bottom-0 border-start-0">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 13.216 15.419">
                                    <g id="noun-lock-6031914" transform="translate(-4 -2)">
                                        <g id="Layer_2" data-name="Layer 2" transform="translate(4 2)">
                                            <path id="Caminho_932" data-name="Caminho 932" d="M15.564,6.956h-1.1V5.3a3.3,3.3,0,0,0-3.3-3.3h-1.1a3.3,3.3,0,0,0-3.3,3.3V6.956h-1.1A1.652,1.652,0,0,0,4,8.608v7.159a1.652,1.652,0,0,0,1.652,1.652h9.912a1.652,1.652,0,0,0,1.652-1.652V8.608a1.652,1.652,0,0,0-1.652-1.652ZM7.855,5.3a2.2,2.2,0,0,1,2.2-2.2h1.1a2.2,2.2,0,0,1,2.2,2.2V6.956H7.855Zm8.26,10.463a.551.551,0,0,1-.551.551H5.652a.551.551,0,0,1-.551-.551V8.608a.551.551,0,0,1,.551-.551h9.912a.551.551,0,0,1,.551.551Zm-4.956-2.2v1.1a.551.551,0,1,1-1.1,0v-1.1a.551.551,0,0,1,1.1,0Z" transform="translate(-4 -2)"></path>
                                        </g>
                                    </g>
                                </svg>
                            </span>
                            <div class="form-floating flex-grow-1">
                                <input type="password" class="form-control bg-transparent border-0" id="senhaRepetida" name="senhaRepetida" placeholder="Repita a Senha" autocomplete="new-password" required>
                                <label for="senhaRepetida">Repita a Senha</label>
                            </div>
                            <button type="button" class="btn btn-outline-secondary border-0 toggle-password" data-target="#senhaRepetida"><svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3.97993 8.22257C3.05683 9.31382 2.35242 10.596 1.93436 12.0015C3.22565 16.338 7.24311 19.5 11.9991 19.5C12.9917 19.5 13.9521 19.3623 14.8623 19.1049M6.22763 6.22763C7.88389 5.13558 9.86771 4.5 12 4.5C16.756 4.5 20.7734 7.66205 22.0647 11.9985C21.3528 14.3919 19.8106 16.4277 17.772 17.772M6.22763 6.22763L3 3M6.22763 6.22763L9.87868 9.87868M17.772 17.772L21 21M17.772 17.772L14.1213 14.1213M14.1213 14.1213C14.6642 13.5784 15 12.8284 15 12C15 10.3431 13.6569 9 12 9C11.1716 9 10.4216 9.33579 9.87868 9.87868M14.1213 14.1213L9.87868 9.87868" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg></button>
                        </div>
                    </div>
                    <div id="passwordMatch" class="mt-2"></div>
                    <div id="mensagemRetorno" style="margin-top:1rem;color:#ec4115;font-weight:bold">
 <?php if ($erro) echo htmlspecialchars($erro, ENT_QUOTES); ?>
                    </div>
                    <button type="submit" class="btn btn-orange btn-lg w-100 mt-4 py-3 rounded-0">Salvar Nova Senha</button>
                </form>

                <script nonce="<?= htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8'); ?>" data-versioned-src="/Cookies/Cookies.min.js"></script>
                <script nonce="<?= htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8'); ?>">
                    document.addEventListener('DOMContentLoaded', () => {
                        const senhaCampo = document.getElementById('senhaCadastro');
                        const senhaRepCampo = document.getElementById('senhaRepetida');
                        const criterios = {
                            length: document.getElementById('criterion-length'),
                            upper: document.getElementById('criterion-upper'),
                            lower: document.getElementById('criterion-lower'),
                            digit: document.getElementById('criterion-digit'),
                            special: document.getElementById('criterion-special')
                        };
                        const mensagens = {
                            length: 'Mínimo 8 caracteres',
                            upper: 'Uma letra maiúscula',
                            lower: 'Uma letra minúscula',
                            digit: 'Um número',
                            special: 'Um caractere especial'
                        };

                        for (const [k, li] of Object.entries(criterios)) {
                            if (li) li.textContent = `❌ ${mensagens[k]}`;
                        }

                        const matchMsg = document.getElementById('passwordMatch');
                        function validarSenha() {
                            const valor = senhaCampo.value;
                            const checks = {
                                length: valor.length >= 8,
                                upper: /[A-Z]/.test(valor),
                                lower: /[a-z]/.test(valor),
                                digit: /\d/.test(valor),
                                special: /[^\w\s]/.test(valor)
                            };

                            Object.entries(checks).forEach(([key, ok]) => {
                                const li = criterios[key];
                                if (!li) return;
                                li.textContent = `${ok ? '✅' : '❌'} ${mensagens[key]}`;
                                li.classList.toggle('text-success', ok);
                                li.classList.toggle('text-danger', !ok);
                            });

                            const matches = senhaRepCampo.value !== '' && senhaRepCampo.value === valor;
                            if (matchMsg) {
                                matchMsg.textContent = matches ? '✅ Senhas conferem.' : '⚠️ Senhas não conferem.';
                                matchMsg.style.color = matches ? 'green' : '#ec4115';
                            }
                        }

                        if (senhaCampo) {
                            senhaCampo.addEventListener('input', validarSenha);
                        }
                        if (senhaRepCampo) {
                            senhaRepCampo.addEventListener('input', validarSenha);
                        }

                        const criteria = document.getElementById('passwordCriteria');
                        if (senhaCampo && criteria) {
                            senhaCampo.addEventListener('focus', () => criteria.classList.remove('d-none'));
                            senhaCampo.addEventListener('blur', () => criteria.classList.add('d-none'));
                        }
                    });

                    document.addEventListener('DOMContentLoaded', () => {
                        document.querySelectorAll('input[type="password"]').forEach(inp => {
                            const warn = document.createElement('div');
                            warn.className = 'caps-warning mt-1 d-none';
                            warn.style.color = '#ec4115';
                            warn.style.fontSize = '0.875rem';
                            warn.textContent = 'Caps Lock ativado';
                            inp.insertAdjacentElement('afterend', warn);
                            const toggle = e => {
                                const caps = e.getModifierState && e.getModifierState('CapsLock');
                                warn.classList.toggle('d-none', !caps);
                            };
                            inp.addEventListener('keydown', toggle);
                            inp.addEventListener('keyup', toggle);
                            inp.addEventListener('focus', toggle);
                            inp.addEventListener('blur', () => warn.classList.add('d-none'));
                        });
                    });

                    document.addEventListener('DOMContentLoaded', () => {
                        const formSenha = document.getElementById('formSenha');
                        if (!formSenha) return;
                        const mensagem = document.getElementById('mensagemRetorno');
                        formSenha.addEventListener('submit', async e => {
                            e.preventDefault();
                            const btn = formSenha.querySelector('button[type="submit"]');
                            if (btn) btn.disabled = true;
                            if (mensagem) {
                                mensagem.textContent = '🔐 Salvando...';
                                mensagem.style.color = '#ec4115';
                            }
                            try {
                                const dados = new URLSearchParams(new FormData(formSenha));
                                const res = await fetch(formSenha.action || window.location.pathname, {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                                        'X-Requested-With': 'XMLHttpRequest'
                                    },
                                    body: dados,
                                    credentials: 'same-origin'
                                });
                                let resposta = null;
                                try { resposta = await res.json(); } catch { }
                                if (res.ok && resposta?.sucesso) {
                                    if (mensagem) {
                                        mensagem.textContent = resposta.mensagem || '✅ Senha atualizada com sucesso.';
                                        mensagem.style.color = 'green';
                                    }
                                    const destino = resposta.redirect || '/AreaCliente?senha=sucesso';
                                    setTimeout(() => { window.location.href = destino; }, 1200);
                                } else if (mensagem) {
                                    mensagem.textContent = resposta?.mensagem || '⚠️ Erro ao atualizar senha.';
                                    mensagem.style.color = '#ec4115';
                                }
                            } catch {
                                if (mensagem) {
                                    mensagem.textContent = '⚠️ Erro ao atualizar senha.';
                                    mensagem.style.color = '#ec4115';
                                }
                            } finally {
                                if (btn) btn.disabled = false;
                            }
                        });
                    });
                </script>
</body>
</html>
