<?php
ini_set('display_errors', 0);
error_reporting(0);
$baseDir = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
if ($baseDir === '') {
    $baseDir = dirname(__DIR__);
}
require_once $baseDir . '/LogsErros/Logs.php';
require_once $baseDir . '/Seguranca/CSRF.php';
require_once $baseDir . '/TokensGeradores/TokensDataSenha.php';
require_once $baseDir . '/ProcessamentoFilaJobs/CriaFilaProcessamento.php';
registrar_verificacao_periodica_fila_jobs($baseDir);
require_once $baseDir . '/Versionamento/Versao.php';

$token = trim(filter_input(INPUT_GET, 'token', FILTER_UNSAFE_RAW) ?: filter_input(INPUT_POST, 'token', FILTER_UNSAFE_RAW) ?: '');
if (!preg_match('/^[A-Fa-f0-9]{64}$/', $token)) {
    header('Location: /AreaCliente?erro=token_invalido');
    exit;
}

$arquivo = $baseDir . '/Tokens/TokensRecuperacao/' . $token . '.json';
if (!file_exists($arquivo)) {
    header('Location: /AreaCliente?erro=token_invalido');
    exit;
}

$dados = json_decode(file_get_contents($arquivo), true);
$criado = $dados['criadoEm'] ?? 0;
if (time() - $criado > 24*60*60) {
    unlink($arquivo);
    header('Location: /AreaCliente?erro=token_expirado');
    exit;
}

$email = strtolower(trim($dados['email'] ?? ''));
$senhaEmToken = $dados['senhaEm'] ?? null;
if ($senhaEmToken !== null) {
    $senhaAtualizadaEm = get_password_timestamp($email);
    if ($senhaAtualizadaEm && $senhaAtualizadaEm > (int) $senhaEmToken) {
        unlink($arquivo);
        header('Location: /AreaCliente?erro=token_expirado');
        exit;
    }
}
$erro = '';
$sucesso = false;
$versaoApp = obterVersaoAplicacao();

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
                require_once $baseDir . '/TokensGeradores/TokensDataSenha.php';
                set_password_timestamp($email, time(), $nomeUsuario);
                unlink($arquivo);

                try {
        disparar_sincronizacao_cliente_background();
    } catch (Throwable $t) {
        log_event('RedefinirSenha - falha ao disparar sincronizacao do cliente: ' . $t->getMessage());
    }

                $sucesso = true;
            }
        } catch (PDOException $e) {
            $erro = '⚠️ Erro ao atualizar senha.';
            log_event('RedefinirSenha: ' . $e->getMessage());
        }
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
    <title>Redefinir Senha | Configurador de Produtos IBR</title>
    <meta name="robots" content="noindex, nofollow" />
    <base href="/PaginasAreaClienteAcessoCadastro/" />
    <link href="/Layout/TipografiaAreaCliente.min.css" rel="stylesheet"
        data-versioned-href="/Layout/TipografiaAreaCliente.min.css">
    <link href="/Layout/LayoutCadastroAreaCliente.min.css" rel="stylesheet" as="style"
        data-versioned-href="/Layout/LayoutCadastroAreaCliente.min.css" onload="this.onload=null;">
    <link href="/Layout/LayoutAreaCliente.min.css" rel="stylesheet" as="style"
        data-versioned-href="/Layout/LayoutAreaCliente.min.css" onload="this.onload=null;">
    <script nonce="<?= htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8'); ?>" data-versioned-src="/CSRFInicia.js"></script>
    <script nonce="<?= htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8'); ?>" id="lgpd-key" prefix="COOKIES"></script>
    <script nonce="<?= htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8'); ?>" defer data-versioned-src="/SEO/TagsGoogle.min.js"></script>
</head>
<body>
    <main id="main-content">
        <div class="container-fluid" id="content">
            <div class="row justify-content-end">
                <div class="col-xl-4 col-lg-6 col-12 me-xl-5">
                    <div class="header__login d-flex gap-3 gap-lg-5 flex-nowrap align-items-center my-5 mx-3 mx-lg-0 justify-content-between">
                        <svg xmlns="http://www.w3.org/2000/svg" width="135.078" height="91.522" viewBox="0 0 135.078 91.522">
                            <g id="logo" transform="translate(-189.856 -139.634)">
                                <path id="Caminho_1" data-name="Caminho 1" d="M215.662,139.634,189.856,164.9v66.254H324.935V139.634Z" transform="translate(0 0)" fill="#ec4115" fill-rule="evenodd"></path>
                                <path id="Caminho_2" data-name="Caminho 2" d="M328.963,236.475q7.391,0,11.752,3.809t4.361,11.426a14.973,14.973,0,0,1-2.625,8.818q2.625,3.532,2.625,9.338a14.744,14.744,0,0,1-4.324,10.855q-4.324,4.334-11.42,4.334H319.5v-12.2h9.535a3.177,3.177,0,0,0,3.252-3.218,2.816,2.816,0,0,0-.739-1.946,3.139,3.139,0,0,0-2.439-.824H319.5V255.045l9.461-.074a3.2,3.2,0,0,0,2.434-.9,3.071,3.071,0,0,0,.892-2.252,2.77,2.77,0,0,0-.817-2.027,3.3,3.3,0,0,0-2.451-.826H316.1v36.088H302.8V249.825l13.305-13.35Zm-42.168,13.35,13.3-13.35v12.181l-13.3,13.218Z" transform="translate(-80.953 -80.872)" fill="#fff" fill-rule="evenodd"></path>
                                <path id="Caminho_3" data-name="Caminho 3" d="M369.716,295.995v-7.781h8.6v.768h-7.1v2.656h6.8v.726h-6.8v2.864h7.1v.767Zm-5.575-.9v-4.157l-3.764,4.157H358.4v-7.781h.9v4.134l3.744-4.134h1.1l-3.962,4.312,4.167,3.469Z" transform="translate(-80.953 -80.872)" fill="#fff" fill-rule="evenodd"></path>
                            </g>
                        </svg>
                         <a href="https://configurador.redutoresibr.com.br/" target="_self" class="fs-5 fw-semibold link-dark link-opacity-50-hover d-flex gap-2 align-items-center link-voltar-configurador" data-gtag-event="areacliente-voltar-configurador" data-gtag-category="Navegacao" data-gtag-label="area_cliente_redefinir">
                            Voltar Para o Configurador de Produtos
                            <svg xmlns="http://www.w3.org/2000/svg" width="19.057" height="19.183" viewBox="0 0 19.057 19.183">
                                <line id="Linha_17" data-name="Linha 17" x1="11.65" y2="11.788" transform="translate(0.711 6.692)" fill="none" stroke="currentColor" stroke-width="2"></line>
                                <path id="Caminho_938" data-name="Caminho 938" d="M0,0l7.18,7.178L0,14.357" transform="translate(2.331 6.574) rotate(-45)" fill="none" stroke="currentColor" stroke-width="2"></path>
                            </svg>
                        </a>
                    </div>

                    <div class="login__box my-5">
                        <h1 class="fs-1 fw-normal text-dark text-center mb-1">
                            Área do Cliente<br>
                            <span style="color: #ec4115;">Configurador Online</span>
                        </h1>
                        <br>
<?php if ($sucesso): ?>
                        <h3 class="mb-3 text-center">Senha redefinida com sucesso.</h3>
                        <p class="text-center">Você já pode <a href="/AreaCliente">fazer login</a>.</p>
<?php else: ?>
                        <h3 class="mb-3 text-center">Redefinir Senha</h3>
                        <p class="mb-4 text-center">Sua senha expirou e por questões de segurança é necessário criar uma nova senha.</p>
                        <form method="post" class="login-form" novalidate>
                            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES); ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
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
                                        <input type="password" class="form-control bg-transparent border-0" id="senhaCadastro" name="senha" placeholder="Senha" autocomplete="new-password" required>
                                        <label for="senhaCadastro">Senha</label>
                                    </div>
                                    <button type="button" class="btn btn-outline-secondary border-0 toggle-password" data-target="#senhaCadastro" data-tag-event="exibir-senha-redefinir"><svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3.97993 8.22257C3.05683 9.31382 2.35242 10.596 1.93436 12.0015C3.22565 16.338 7.24311 19.5 11.9991 19.5C12.9917 19.5 13.9521 19.3623 14.8623 19.1049M6.22763 6.22763C7.88389 5.13558 9.86771 4.5 12 4.5C16.756 4.5 20.7734 7.66205 22.0647 11.9985C21.3528 14.3919 19.8106 16.4277 17.772 17.772M6.22763 6.22763L3 3M6.22763 6.22763L9.87868 9.87868M17.772 17.772L21 21M17.772 17.772L14.1213 14.1213M14.1213 14.1213C14.6642 13.5784 15 12.8284 15 12C15 10.3431 13.6569 9 12 9C11.1716 9 10.4216 9.33579 9.87868 9.87868M14.1213 14.1213L9.87868 9.87868" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg></button>
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
                                    </span>                                    <div class="form-floating flex-grow-1">
                                        <input type="password" class="form-control bg-transparent border-0" id="senhaRepetida" name="senhaRepetida" placeholder="Repita a Senha" autocomplete="new-password" required>
                                        <label for="senhaRepetida">Repita a Senha</label>
                                    </div>
                                    <button type="button" class="btn btn-outline-secondary border-0 toggle-password" data-target="#senhaRepetida" data-tag-event="exibir-senha-redefinir-repetir"><svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3.97993 8.22257C3.05683 9.31382 2.35242 10.596 1.93436 12.0015C3.22565 16.338 7.24311 19.5 11.9991 19.5C12.9917 19.5 13.9521 19.3623 14.8623 19.1049M6.22763 6.22763C7.88389 5.13558 9.86771 4.5 12 4.5C16.756 4.5 20.7734 7.66205 22.0647 11.9985C21.3528 14.3919 19.8106 16.4277 17.772 17.772M6.22763 6.22763L3 3M6.22763 6.22763L9.87868 9.87868M17.772 17.772L21 21M17.772 17.772L14.1213 14.1213M14.1213 14.1213C14.6642 13.5784 15 12.8284 15 12C15 10.3431 13.6569 9 12 9C11.1716 9 10.4216 9.33579 9.87868 9.87868M14.1213 14.1213L9.87868 9.87868" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg></button>                                </div>
                            </div>
                            <div id="passwordMatch" class="mt-2"></div>
                            <div id="mensagemRetorno" style="margin-top:1rem;color:#ec4115;font-weight:bold">
<?php if ($erro) echo htmlspecialchars($erro, ENT_QUOTES); ?>
                            </div>
                            <button type="submit" class="btn btn-orange btn-lg w-100 mt-4 py-3 rounded-0">Salvar Nova Senha</button>
                        </form>
<?php endif; ?>
                    </div>
                </div>

                <div class="col-xl-7 col-lg-6 col-12 d-none d-lg-block position-relative pe-0">
                    <div class="img__login position-sticky top-0 start-0">
                        <picture>
                            <source data-srcset="/Imagens/ImagemAreaCliente.webp" type="image/webp" srcset="/Imagens/ImagemAreaCliente.webp">
                            <img data-src="/Imagens/ImagemAreaCliente.webp" class="img-fluid lazyloaded" alt="Foto Área do Cliente IBR" src="/Imagens/ImagemAreaCliente.webp">
                        </picture>
                    </div>
                </div>
            </div>
        </div>
    </main>
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
            length: /.{8,}/.test(valor),
            upper: /[A-Z]/.test(valor),
            lower: /[a-z]/.test(valor),
            digit: /\d/.test(valor),
            special: /[^\w\s]/.test(valor)
        };
        let todos = true;
        for (const [k, ok] of Object.entries(checks)) {
            const li = criterios[k];
            if (li) {
                li.classList.toggle('text-success', ok);
                li.classList.toggle('text-danger', !ok);
                li.textContent = `${ok ? '✅' : '❌'} ${mensagens[k]}`;
            }
            todos &&= ok;
        }
        senhaCampo.classList.toggle('is-valid', todos);
        senhaCampo.classList.toggle('is-invalid', !todos && valor !== '');
        validarConfirmacao();
    }
    function validarConfirmacao() {
        const match = senhaRepCampo.value === senhaCampo.value && senhaCampo.value !== '';
        senhaRepCampo.classList.toggle('is-valid', match);
        senhaRepCampo.classList.toggle('is-invalid', !match && senhaRepCampo.value !== '');
        if (matchMsg) {
            if (senhaRepCampo.value === '') {
                matchMsg.textContent = '';
                matchMsg.className = 'mt-2';
            } else if (match) {
                matchMsg.textContent = 'As Senhas Conferem.';
                matchMsg.className = 'valid-feedback d-block';
            } else {
                matchMsg.textContent = 'As Senhas Não Conferem.';
                matchMsg.className = 'invalid-feedback d-block';
            }
        }
    }
    senhaCampo?.addEventListener('input', validarSenha);
    senhaRepCampo?.addEventListener('input', validarConfirmacao);
    const closedEyeSvg = `<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3.97993 8.22257C3.05683 9.31382 2.35242 10.596 1.93436 12.0015C3.22565 16.338 7.24311 19.5 11.9991 19.5C12.9917 19.5 13.9521 19.3623 14.8623 19.1049M6.22763 6.22763C7.88389 5.13558 9.86771 4.5 12 4.5C16.756 4.5 20.7734 7.66205 22.0647 11.9985C21.3528 14.3919 19.8106 16.4277 17.772 17.772M6.22763 6.22763L3 3M6.22763 6.22763L9.87868 9.87868M17.772 17.772L21 21M17.772 17.772L14.1213 14.1213M14.1213 14.1213C14.6642 13.5784 15 12.8284 15 12C15 10.3431 13.6569 9 12 9C11.1716 9 10.4216 9.33579 9.87868 9.87868M14.1213 14.1213L9.87868 9.87868" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>`;
    const openEyeSvg = `<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M2.03555 12.3224C1.96647 12.1151 1.9664 11.8907 2.03536 11.6834C3.42372 7.50972 7.36079 4.5 12.0008 4.5C16.6387 4.5 20.5742 7.50692 21.9643 11.6776C22.0334 11.8849 22.0335 12.1093 21.9645 12.3166C20.5761 16.4903 16.6391 19.5 11.9991 19.5C7.36119 19.5 3.42564 16.4931 2.03555 12.3224Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M15 12C15 13.6569 13.6569 15 12 15C10.3431 15 9 13.6569 9 12C9 10.3431 10.3431 9 12 9C13.6569 9 15 10.3431 15 12Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>`;
    document.querySelectorAll('.toggle-password').forEach(btn => {
        const target = document.querySelector(btn.dataset.target);
        if (target) btn.innerHTML = closedEyeSvg;
        btn.addEventListener('click', () => {
            if (!target) return;
            if (typeof gtag === 'function' && btn.dataset.tagEvent) {
                gtag('event', btn.dataset.tagEvent, {
                    event_category: 'Conta',
                    event_label: target.id || 'senha'
                });
            }
            const vis = target.type === 'text';
            target.type = vis ? 'password' : 'text';
            btn.innerHTML = vis ? closedEyeSvg : openEyeSvg;
            target.focus();
        });
    });
    const criteria = document.getElementById('passwordCriteria');
    if (senhaCampo && criteria) {
        senhaCampo.addEventListener('focus', () => criteria.classList.remove('d-none'));
        senhaCampo.addEventListener('blur', () => criteria.classList.add('d-none'));
    }
});
</script>
<script nonce="<?= htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8'); ?>">
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
</script>

<script nonce="<?= htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8'); ?>">
function ehAreaClienteUrl(valor) {
    if (!valor || typeof valor !== 'string') return false;
    try {
        const url = new URL(valor, window.location.origin);
        return url.pathname.toLowerCase().startsWith('/areacliente');
    } catch {
        const texto = String(valor).trim().toLowerCase();
        return texto.startsWith('/areacliente');
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const link = document.querySelector('a.link-voltar-configurador');
    const destino = sessionStorage.getItem('retornoPosLogin');
    if (!link || !destino || ehAreaClienteUrl(destino)) return;
    link.setAttribute('href', destino);
    link.addEventListener('click', (e) => {
        if (
            e.button === 0 &&
            !e.ctrlKey &&
            !e.metaKey &&
            !e.shiftKey &&
            !e.altKey
        ) {
            e.preventDefault();
            window.location.href = destino;
        }
    });
});
</script>
</body>
</html>
