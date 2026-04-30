<?php
ini_set('display_errors', 0);
error_reporting(0);
$baseDir = $_SERVER['DOCUMENT_ROOT'] ?: dirname(__DIR__);
require $baseDir . '/Seguranca/MetodosSegurancao.php';
require_once $baseDir . '/LogsErros/Logs.php';
require_once $baseDir . '/TokensGeradores/TokenPadraoJson.php';
require_once $baseDir . '/TokensGeradores/TokenInvalidacao.php';
require $baseDir . '/AcessosConsultas/CredenciaisBancoDados.php';
require_once $baseDir . '/TokensGeradores/TokensCarrinhosCompartilhados.php';
require_once $baseDir . '/TokensGeradores/TokensLimpeza.php';
require_once $baseDir . '/AcessosConsultas/UltimaEmpresaLogadaUsuario.php';
require_once $baseDir . '/AcessosConsultas/CredenciaisPiperun.php';
require_once $baseDir . '/ProcessamentoFilaJobs/CriaFilaProcessamento.php';
require_once $baseDir . '/AcessosConsultas/BuscarNomeEmpresaReceitaCache.php';
require_once $baseDir . '/Seguranca/CSRF.php';
registrar_verificacao_periodica_fila_jobs($baseDir);

$tokenCookie = isset($_COOKIE['auth_token']) ? trim((string)$_COOKIE['auth_token']) : '';
$dominioCookie = 'configurador.redutoresibr.com.br';

function limpar_cookie_autenticacao(string $dominioPadrao): void
{
    $conexaoSegura = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && stripos((string) $_SERVER['HTTP_X_FORWARDED_PROTO'], 'https') === 0);

    $opcoesBase = [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => $conexaoSegura,
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

        if (!$conexaoSegura) {
            set_cookie_compat('auth_token', '', array_merge($opcoesBase, [
                'domain' => $dominio,
                'secure' => true,
            ]));
        }
    }

    if (!$conexaoSegura) {
        set_cookie_compat('auth_token', '', array_merge($opcoesBase, ['secure' => true]));
    }

    set_cookie_compat('auth_token', '', $opcoesBase);
}
function formatar_documento_nacional(?string $valor): string
{
    $numeros = preg_replace('/[^0-9A-Za-z]/', '', (string)$valor);
    if (strlen($numeros) === 14) {
        return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $numeros) ?: $numeros;
    }
    if (strlen($numeros) === 11) {
        return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $numeros) ?: $numeros;
    }
    return $numeros;
}

$tokenEmail = filter_input(INPUT_GET, 'token', FILTER_UNSAFE_RAW) ?? '';
if (!preg_match('/^[A-Fa-f0-9]{64}$/', $tokenEmail)) {
    header('Location: /AreaCliente?exclusao=token_invalido');
    exit;
}

$arquivo = $baseDir . '/Tokens/TokensExclusao/' . $tokenEmail . '.json';
if (!file_exists($arquivo)) {
    header('Location: /AreaCliente?exclusao=token_invalido');
    exit;
}

$dados = json_decode(file_get_contents($arquivo), true);
$criado = $dados['criadoEm'] ?? 0;
if (time() - $criado > 24*60*60) {
    if (file_exists($arquivo)) {
        unlink($arquivo);
    }
    header('Location: /AreaCliente?exclusao=token_expirado');
    exit;
}

$email = strtolower(trim($dados['email'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: /AreaCliente?exclusao=token_invalido');
    exit;
}
$escopo = strtolower(trim($dados['escopo'] ?? 'todas'));
$documentoSelecionado = preg_replace('/[^0-9A-Za-z]/', '', (string)($dados['documento'] ?? ''));
$exclusaoParcial = ($escopo === 'empresa' && strlen($documentoSelecionado) === 14);
if (!$exclusaoParcial) {
    $documentoSelecionado = '';
}
if ($escopo === 'empresa' && !$exclusaoParcial) {
    header('Location: /AreaCliente?exclusao=token_invalido');
    exit;
}

$nome = mb_strtoupper(trim((string)($dados['nome'] ?? '')), 'UTF-8');
$cpfcnpj = preg_replace('/[^0-9A-Za-z]/', '', (string)($dados['cpfcnpj'] ?? ''));
$grupo = trim((string)($dados['grupo'] ?? ''));
if ($grupo === '') {
    $grupo = 'PRATA';
}
$codigo = trim((string)($dados['codigo'] ?? ''));

try {
    $linksCompartilhados = [];
    $pdo = new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
    ]);

    $stmtUsuario = $pdo->prepare('SELECT DS_NOME, NR_CPFCNPJ FROM _USR_CONF_SITE_CADASTROS WHERE LOWER(DS_EMAIL) = ?');
    $stmtUsuario->execute([$email]);
    $usuariosEncontrados = $stmtUsuario->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $nomeBruto = '';
    foreach ($usuariosEncontrados as $usuarioLinha) {
        if ($nomeBruto === '' && !empty($usuarioLinha['DS_NOME'])) {
            $nomeBruto = (string) $usuarioLinha['DS_NOME'];
        }
        $docLinha = preg_replace('/[^0-9A-Za-z]/', '', (string)($usuarioLinha['NR_CPFCNPJ'] ?? ''));
        if ($docLinha === '') {
            continue;
        }
        if ($exclusaoParcial) {
            if ($docLinha === $documentoSelecionado) {
                $cpfcnpj = $docLinha;
                break;
            }
        } elseif ($cpfcnpj === '') {
            $cpfcnpj = $docLinha;
        }
    }
    if ($nomeBruto !== '') {
        $nome = mb_strtoupper(trim($nomeBruto), 'UTF-8');
    }

    if ($cpfcnpj === '' && $exclusaoParcial) {
        $cpfcnpj = $documentoSelecionado;
    }
    $documentoConsulta = $cpfcnpj !== '' ? $cpfcnpj : ($exclusaoParcial ? $documentoSelecionado : '');
    $grupoAtual = $grupo !== '' ? $grupo : 'PRATA';
    $codigoAtual = $codigo;
    if ($documentoConsulta !== '' && strlen($documentoConsulta) === 14) {
        try {
            $codStmt = $pdo->prepare('SELECT TOP 1 CD_PESSOA FROM MBAD_PESSOA WHERE NR_CPFCNPJ = ?');
            $codStmt->execute([$documentoConsulta]);
            $codigoRetornado = $codStmt->fetchColumn();
            if ($codigoRetornado !== false && $codigoRetornado !== null) {
                $codigoAtual = trim((string) $codigoRetornado);
                if ($codigoAtual !== '') {
                    $permStmt = $pdo->prepare(
                        "SELECT MAX(TIPO.DS_TIPO) AS PERMISSAO\n" .
                        "FROM MBAD_PESSOACONTATO AS CONTATO\n" .
                        "INNER JOIN MBAD_PESSOACONTATOTIPO AS TIPO ON CONTATO.CD_TIPO = TIPO.CD_TIPO\n" .
                        "WHERE CONTATO.CD_PESSOA = ? AND CONTATO.CD_FUNCAO = 'SITE' AND LOWER(CONTATO.DS_EMAIL) = ?"
                    );
                    $permStmt->execute([$codigoAtual, strtolower($email)]);
                    $permissao = $permStmt->fetchColumn();
                    if ($permissao) {
                        $grupoAtual = $permissao;
                    }
                }
            }
        } catch (PDOException $e) {
        }
    }

    $grupo = $grupoAtual;
    $codigo = $codigoAtual;

    if ($exclusaoParcial) {
        $stmtLinks = $pdo->prepare('SELECT DS_LINKCARRINHO FROM _USR_CONF_SITE_HISTORICO_CARRFAV WHERE LOWER(DS_EMAIL) = ? AND NR_CPFCNPJ = ?');
        $stmtLinks->execute([$email, $documentoSelecionado]);
    } else {
        $stmtLinks = $pdo->prepare('SELECT DS_LINKCARRINHO FROM _USR_CONF_SITE_HISTORICO_CARRFAV WHERE LOWER(DS_EMAIL) = ?');
        $stmtLinks->execute([$email]);
    }
    $linksCompartilhados = $stmtLinks->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $linksCompartilhados = array_map('strval', $linksCompartilhados);

    $tabelas = [
        '_USR_CONF_SITE_HISTORICO_PRODUTO',
        '_USR_CONF_SITE_HISTORICO_DESENHO',
        '_USR_CONF_SITE_HISTORICO_COTACAO',
        '_USR_CONF_SITE_HISTORICO_CADASTROS',
        '_USR_CONF_SITE_HISTORICO_PRODFAV',
        '_USR_CONF_SITE_HISTORICO_CARRFAV',
        '_USR_CONF_SITE_CADASTROS'
    ];

    foreach ($tabelas as $tbl) {
        $sql = "DELETE FROM $tbl WHERE LOWER(DS_EMAIL) = ?";
        $params = [$email];
        if ($exclusaoParcial) {
            $sql .= ' AND NR_CPFCNPJ = ?';
            $params[] = $documentoSelecionado;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $removidos = (int)$stmt->rowCount();
        if ($removidos > 0) {
            $escopoLog = $exclusaoParcial ? 'parcial' : 'completa';
            $documentoLog = $exclusaoParcial ? ' / CNPJ ' . $documentoSelecionado : '';
        }
    }

    $baseDirLimpeza = $_SERVER['DOCUMENT_ROOT'] ?: dirname(__DIR__);
    if (!empty($linksCompartilhados)) {
        excluir_carrinhos_compartilhados_por_links($linksCompartilhados, $baseDirLimpeza);
    }

    if (!$exclusaoParcial) {
        $historicoDir = rtrim($baseDirLimpeza, DIRECTORY_SEPARATOR) . '/Tokens/HistoricoCarrinhos';
        $emailSanitizado = preg_replace('/[^a-zA-Z0-9_@.\-]/', '', $email);
        if ($emailSanitizado && is_dir($historicoDir)) {
            $padrao = $historicoDir . '/' . $emailSanitizado . '*.json';
            foreach (glob($padrao) ?: [] as $arquivoHistorico) {
                if (is_file($arquivoHistorico)) {
                    @unlink($arquivoHistorico);
                }
            }
        }

        limpar_tokens_usuario($email);

        if ($tokenCookie) {
            blacklist_token($tokenCookie);
        }
        limpar_cookie_autenticacao($dominioCookie);
    }

    if ($exclusaoParcial) {
        remover_preferencia_usuario_por_documento($email, $documentoSelecionado);
    } else {
        remover_preferencia_usuario_por_email($email);
    }

    $documentosEmpresas = [];
    if ($exclusaoParcial) {
        if ($documentoSelecionado !== '' && strlen($documentoSelecionado) === 14) {
            $documentosEmpresas[] = $documentoSelecionado;
        }
    } else {
        foreach ($usuariosEncontrados as $usuarioLinha) {
            $docLinha = preg_replace('/[^0-9A-Za-z]/', '', (string)($usuarioLinha['NR_CPFCNPJ'] ?? ''));
            if (strlen($docLinha) === 14) {
                $documentosEmpresas[] = $docLinha;
            }
        }

        if (strlen($cpfcnpj) === 14) {
            $documentosEmpresas[] = $cpfcnpj;
        }
    }

    $documentosEmpresas = array_values(array_unique(array_filter($documentosEmpresas)));

    if (!empty($documentosEmpresas)) {
        $placeholdersEmpresas = implode(',', array_fill(0, count($documentosEmpresas), '?'));
        $sqlEmpresas = "SELECT CD_PESSOA, NR_CPFCNPJ FROM MBAD_PESSOA WHERE REPLACE(REPLACE(REPLACE(NR_CPFCNPJ, '.', ''), '-', ''), '/', '') IN ($placeholdersEmpresas)";
        $stmtEmpresas = $pdo->prepare($sqlEmpresas);
        $stmtEmpresas->execute($documentosEmpresas);

        $codigosEmpresas = [];
        while ($row = $stmtEmpresas->fetch(PDO::FETCH_ASSOC)) {
            $docBanco = preg_replace('/[^0-9A-Za-z]/', '', (string)($row['NR_CPFCNPJ'] ?? ''));
            $codigoPessoa = trim((string)($row['CD_PESSOA'] ?? ''));

            if (strlen($docBanco) === 14 && $codigoPessoa !== '') {
                $codigosEmpresas[] = $codigoPessoa;
            }
        }

        if (!empty($codigosEmpresas)) {
            $stmtExcluirContatos = $pdo->prepare(
                "DELETE FROM MBAD_PESSOACONTATO WHERE CD_PESSOA = ? AND LOWER(DS_EMAIL) = ? AND CD_FUNCAO = 'SITE'"
            );

            foreach ($codigosEmpresas as $codigoEmpresa) {
                $stmtExcluirContatos->execute([$codigoEmpresa, $email]);
            }
        }
    }

    if (file_exists($arquivo)) {
        unlink($arquivo);
    }

$nomeEmpresaSelecionada = '';
    if ($exclusaoParcial) {
        $nomeEmpresaSelecionada = obter_nome_empresa($documentoSelecionado, $baseDir) ?: '';
    }

    try {
        $linhasExtrasJob = [
            'Grupo: ' . $grupo,
            'Código interno: ' . ($codigo !== '' ? $codigo : 'Não informado'),
            'Escopo da exclusão: ' . ($exclusaoParcial ? 'Remoção de empresa' : 'Conta completa'),
        ];

        $dadosPipeRun = [
            'tituloPrefixo' => $exclusaoParcial ? 'Remoção de Empresa no Configurador' : 'Exclusão de Conta no Configurador',
            'pipelineId'    => 78334,
            'stageId'       => 483644,
            'linhasExtras'  => $linhasExtrasJob,
            'nome'          => $nome ?: 'Não informado',
            'cpfcnpj'       => $cpfcnpj ? formatar_documento_nacional($cpfcnpj) : '',
            'empresa'       => $nomeEmpresaSelecionada,
            'email'         => $email,
        ];

        registrar_job_fila('piperun_criar_oportunidade', $dadosPipeRun, $baseDir);
    } catch (Throwable $t) {
        log_event('Erro ao enfileirar registro de exclusao: ' . $t->getMessage());
    }
    try {
        disparar_sincronizacao_cliente_background((string)($_COOKIE['auth_token'] ?? ''));
    } catch (Throwable $t) {
        log_event('ExcluirConta - falha ao disparar sincronizacao do cliente: ' . $t->getMessage());
    }

    if ($exclusaoParcial) {
        $query = http_build_query([
            'empresaRemovida' => 1,
            'doc' => $documentoSelecionado,
        ]);
        header('Location: /AreaCliente/Sessao?' . $query);
    } else {
        $retornoPosLogout = '/AreaCliente?exclusao=sucesso';
        $hostAtual = isset($_SERVER['HTTP_HOST']) ? trim((string) $_SERVER['HTTP_HOST']) : '';
        $esquemaSeguro = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && stripos((string) $_SERVER['HTTP_X_FORWARDED_PROTO'], 'https') === 0);

        $baseLogout = $hostAtual !== ''
            ? (($esquemaSeguro ? 'https://' : 'http://') . $hostAtual)
            : 'https://configurador.redutoresibr.com.br';

 $parametrosLogout = [
            'retorno'  => $retornoPosLogout,
            'exclusao' => 'sucesso',
        ];

        $tokenCsrf = csrf_token();
        $destinoLogout = rtrim($baseLogout, '/')
            . '/PaginasAreaClienteSessaoPerfil/Sair.php';
        $parametrosLogout['csrf_token'] = $tokenCsrf;

        $formInputs = '';
        foreach ($parametrosLogout as $chave => $valor) {
            $formInputs .= '<input type="hidden" name="'
                . htmlspecialchars($chave, ENT_QUOTES, 'UTF-8')
                . '" value="'
                . htmlspecialchars($valor, ENT_QUOTES, 'UTF-8')
                . '" />';
        }

        echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8" />';
        echo '<title>Exclusão de Conta | Configurador de Produtos IBR</title></head><body>';
        echo '<form id="logoutForm" method="POST" action="'
            . htmlspecialchars($destinoLogout, ENT_QUOTES, 'UTF-8') . '">';
        echo $formInputs;
        echo '<noscript><button type="submit">Continuar</button></noscript>';
        echo '</form>';
        echo '<script ' . csp_nonce_attr() . '>document.getElementById("logoutForm").submit();</script>';
        echo '</body></html>';
    }
    exit;
} catch (PDOException $e) {
    header('Location: /AreaCliente?exclusao=erro');
    exit;
}
?>
