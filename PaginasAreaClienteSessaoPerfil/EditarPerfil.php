<?php
ini_set('display_errors', 0);
error_reporting(0);
$baseDir = $_SERVER['DOCUMENT_ROOT'] ?: dirname(__DIR__);
require $baseDir . '/Seguranca/MetodosSegurancao.php';
require_once $baseDir . '/LogsErros/Logs.php';
require_once $baseDir . '/TokensGeradores/TokenPadraoJson.php';
require_once $baseDir . '/TokensGeradores/TokenInvalidacao.php';
require_once $baseDir . '/ProcessamentoFilaJobs/CriaFilaProcessamento.php';
registrar_verificacao_periodica_fila_jobs($baseDir);

$token = filter_input(INPUT_GET, 'token', FILTER_UNSAFE_RAW) ?? '';
if (!preg_match('/^[A-Fa-f0-9]{64}$/', $token)) {
    header('Location: /AreaCliente/Sessao?erro=token_invalido');
    exit;
}

$arquivo = $baseDir . '/Tokens/TokensEmail/' . $token . '.json';
$diretorioTokensAplicados = $baseDir . '/Tokens/TokensEmailAplicados';
$arquivoTokenAplicado = $diretorioTokensAplicados . '/' . $token . '.json';

if (!is_dir($diretorioTokensAplicados)) {
    @mkdir($diretorioTokensAplicados, 0700, true);
}

if (!file_exists($arquivo)) {
    if (is_file($arquivoTokenAplicado)) {
        header('Location: /AreaCliente/Sessao?atualizacao=sucesso');
        exit;
    }
    header('Location: /AreaCliente/Sessao?erro=token_invalido');
    exit;
}

$dados = json_decode(file_get_contents($arquivo), true);
$criado = $dados['criadoEm'] ?? 0;
if (time() - $criado > 24*60*60) {
    unlink($arquivo);
    header('Location: /AreaCliente/Sessao?erro=token_expirado');
    exit;
}

$nome = mb_strtoupper($dados['nome'] ?? '', 'UTF-8');
$cpfcnpj = preg_replace('/[^0-9A-Za-z]/', '', $dados['cpfcnpj'] ?? '');
$cpfcnpjAtual = $dados['cpfcnpjAtual'] ?? ($dados['cpfAtual'] ?? ($dados['cpfAnterior'] ?? ''));
$cpfcnpjAtual = preg_replace('/[^0-9A-Za-z]/', '', $cpfcnpjAtual);
if ($cpfcnpjAtual === '') {
    $cpfcnpjAtual = $cpfcnpj;
}
require_once $baseDir . '/AcessosConsultas/BuscarNomeEmpresaReceitaCache.php';
require_once $baseDir . '/TokensGeradores/TokensLimpeza.php';
$empresa = obter_nome_empresa($cpfcnpj, $baseDir);
$novoEmail = strtolower(trim($dados['novoEmail'] ?? ''));
$emailAtual = strtolower(trim($dados['emailAtual'] ?? ''));
$hashSenha = $dados['senha'] ?? '';
$novidades = strtoupper(trim((string) ($dados['novidades'] ?? 'NAO')));
if ($novidades !== 'SIM') {
    $novidades = 'NAO';
}
require $baseDir . '/AcessosConsultas/CredenciaisBancoDados.php';

try {
    $pdo = new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
    ]);

    $stmt = $pdo->prepare(
        "SELECT 1 FROM _USR_CONF_SITE_CADASTROS " .
        "WHERE LOWER(DS_EMAIL)=? AND NR_CPFCNPJ = ? " .
        "AND NOT (LOWER(DS_EMAIL)=? AND NR_CPFCNPJ = ?)"
    );
    $stmt->execute([$novoEmail, $cpfcnpj, $emailAtual, $cpfcnpjAtual]);
    if ($stmt->fetch()) {
        unlink($arquivo);
        header('Location: /AreaCliente/Sessao?erro=email_em_uso');
        exit;
    }

    if ($cpfcnpjAtual !== '' && $cpfcnpjAtual !== $cpfcnpj) {
        $emailsParaAtualizarDoc = array_values(array_unique(array_filter([
            $emailAtual,
            $novoEmail,
        ], static function ($email): bool {
            return trim((string) $email) !== '';
        })));

        foreach ($emailsParaAtualizarDoc as $emailReferencia) {
            $stmtAtualizaDoc = $pdo->prepare(
                "UPDATE _USR_CONF_SITE_CADASTROS SET NR_CPFCNPJ=? WHERE LOWER(DS_EMAIL)=? AND NR_CPFCNPJ=?"
            );
            $stmtAtualizaDoc->execute([$cpfcnpj, $emailReferencia, $cpfcnpjAtual]);

            $stmtAtualizaDocEmail = $pdo->prepare(
                "UPDATE _USR_CONF_SITE_CADASTROS SET NR_CPFCNPJ=? WHERE LOWER(DS_EMAIL)=? AND (NR_CPFCNPJ IS NULL OR LTRIM(RTRIM(NR_CPFCNPJ))='')"
            );
            $stmtAtualizaDocEmail->execute([$cpfcnpj, $emailReferencia]);
        }
    }

    $sqlCampos = "DS_EMAIL=?, DS_NOME=?, DS_NOVIDADES=?";
    $paramsBase = [$novoEmail, $nome, $novidades];
    if ($hashSenha) {
        $sqlCampos .= ", DS_SENHA=?";
        $paramsBase[] = $hashSenha;
    }

    $condicoes = [];
    $paramsCondicoes = [];
    $emailsAtualizacao = array_values(array_unique(array_filter([
        $emailAtual,
        $novoEmail,
    ], static function ($email): bool {
        return trim((string) $email) !== '';
    })));
    if (empty($emailsAtualizacao)) {
        $emailsAtualizacao[] = strtolower($emailAtual ?: $novoEmail);
    }
    $documentosAtualizacao = array_values(array_unique(array_filter([
        $cpfcnpjAtual,
        $cpfcnpj,
    ], static function ($documento): bool {
        $documentoLimpo = preg_replace('/[^0-9A-Za-z]/', '', (string) $documento);
        return in_array(strlen($documentoLimpo), [11, 14], true);
    })));

    foreach ($emailsAtualizacao as $emailReferencia) {
        if (!empty($documentosAtualizacao)) {
            $placeholdersDocs = implode(',', array_fill(0, count($documentosAtualizacao), '?'));
            $condicoes[] = "(LOWER(DS_EMAIL)=? AND NR_CPFCNPJ IN ($placeholdersDocs))";
            $paramsCondicoes[] = $emailReferencia;
            $paramsCondicoes = array_merge($paramsCondicoes, $documentosAtualizacao);
        }

        $condicoes[] = "(LOWER(DS_EMAIL)=? AND (NR_CPFCNPJ IS NULL OR LTRIM(RTRIM(NR_CPFCNPJ))=''))";
        $paramsCondicoes[] = $emailReferencia;
    }

    $sqlUpd = "UPDATE _USR_CONF_SITE_CADASTROS SET $sqlCampos WHERE " . implode(' OR ', array_map(static function ($cond) {
        return '(' . $cond . ')';
    }, $condicoes));
    $paramsUpd = array_merge($paramsBase, $paramsCondicoes);
    $upd = $pdo->prepare($sqlUpd);
    $upd->execute($paramsUpd);

    if ($hashSenha) {
        require_once $baseDir . '/TokensGeradores/TokensDataSenha.php';
        set_password_timestamp($novoEmail, time(), $nome);
    }

    if (is_file($arquivo)) {
        unlink($arquivo);
    }

    $opcoesLimpeza = [
        'preservarDispositivos' => true,
        'preservarSenha' => true,
    ];
    limpar_tokens_usuario($emailAtual, $opcoesLimpeza);
    if (strtolower($novoEmail) !== strtolower($emailAtual)) {
        limpar_tokens_usuario($novoEmail, $opcoesLimpeza);
    }
    
    $codigo = 0;
    $grupo = 'BRONZE';
    try {
        $codStmt = $pdo->prepare(
            "SELECT TOP 1 CD_PESSOA FROM MBAD_PESSOA WHERE NR_CPFCNPJ = ?"
        );
        $codStmt->execute([preg_replace('/\\D/', '', $cpfcnpj)]);
        $codigo = $codStmt->fetchColumn() ?: 0;

        if ($codigo) {
            $permStmt = $pdo->prepare(
                "SELECT MAX(TIPO.DS_TIPO) AS PERMISSAO\n" .
                "FROM MBAD_PESSOACONTATO AS CONTATO\n" .
                "INNER JOIN MBAD_PESSOACONTATOTIPO AS TIPO ON CONTATO.CD_TIPO = TIPO.CD_TIPO\n" .
                "WHERE CONTATO.CD_PESSOA = ? AND CONTATO.CD_FUNCAO = 'SITE' AND LOWER(CONTATO.DS_EMAIL) = ?"
            );
            $permStmt->execute([$codigo, strtolower(trim($novoEmail))]);
            $permissao = $permStmt->fetchColumn();
            $grupo = $permissao ? $permissao : 'PRATA';
        }
    } catch (PDOException $e) {
    }
    
    $tokenCookie = trim((string)($_COOKIE['auth_token'] ?? ''));
    if ($tokenCookie !== '') {
        $segredo = getenv('JWT_SECRET');
        if ($segredo === false) {
            $segredo = trim(file_get_contents($baseDir . '/Configuracoes/Segredo.jwt'));
        }

        $payloadAtual = JWTHelper::decode($tokenCookie, $segredo);
        if (is_array($payloadAtual)) {
            $agora = time();
            $maxExpAtual = isset($payloadAtual['maxExp']) ? (int)$payloadAtual['maxExp'] : ($agora + 2592000);
            if ($maxExpAtual <= $agora) {
                $maxExpAtual = $agora + 2592000;
            }

            $ttlPadrao = getenv('JWT_TTL');
            $ttlPadrao = $ttlPadrao === false ? 604800 : (int)$ttlPadrao;
            if ($ttlPadrao <= 0) {
                $ttlPadrao = 604800;
            }

            $novaExpiracao = min($agora + min($ttlPadrao, 2592000), $maxExpAtual);
            if ($novaExpiracao <= $agora) {
                $novaExpiracao = $agora + 3600;
            }

            $payloadAtual['email'] = $novoEmail;
            $payloadAtual['nome'] = $nome;
            $payloadAtual['cpfcnpj'] = $cpfcnpj;
            $payloadAtual['novidades'] = $novidades;
            $payloadAtual['exp'] = $novaExpiracao;
            if (empty($payloadAtual['empresaDocumento']) || $payloadAtual['empresaDocumento'] === $cpfcnpjAtual) {
                $payloadAtual['empresaDocumento'] = $cpfcnpj;
            }
            if (empty($payloadAtual['empresaNome']) || $payloadAtual['empresaNome'] === ($payloadAtual['empresa'] ?? '')) {
                $payloadAtual['empresaNome'] = $empresa;
            }
            if (!isset($payloadAtual['empresa']) || trim((string)$payloadAtual['empresa']) === '') {
                $payloadAtual['empresa'] = $empresa;
            }

            $novoAuthToken = JWTHelper::encode($payloadAtual, $segredo);

            $hostAtual = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
            $hostAtual = preg_replace('/:\\d+$/', '', $hostAtual);
            $cookieDomain = strcasecmp($hostAtual, 'configurador.redutoresibr.com.br') === 0 ? 'configurador.redutoresibr.com.br' : '';
            $cookieSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
            $cookieAuth = [
                'expires' => $payloadAtual['exp'],
                'path' => '/',
                'secure' => $cookieSecure,
                'httponly' => true,
                'samesite' => 'Lax'
            ];
            set_cookie_multi_domain('auth_token', $novoAuthToken, $cookieAuth, $cookieDomain);
            $_COOKIE['auth_token'] = $novoAuthToken;
        }
    }

    @file_put_contents($arquivoTokenAplicado, json_encode([
        'token' => $token,
        'aplicadoEm' => time(),
        'email' => $novoEmail,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    try {
        disparar_sincronizacao_cliente_background();
    } catch (Throwable $t) {
        log_event('EditarPerfil - falha ao disparar sincronizacao do cliente: ' . $t->getMessage());
    }

    header('Location: /AreaCliente/Sessao?atualizacao=sucesso');
    exit;
} catch (PDOException $e) {
    header('Location: /AreaCliente/Sessao?erro=erro');
    exit;
}
