<?php
ini_set('display_errors', 0);
error_reporting(0);
$baseDir = $_SERVER['DOCUMENT_ROOT'] ?: dirname(__DIR__);
require $baseDir . '/Seguranca/MetodosSegurancao.php';
require_once $baseDir . '/LogsErros/Logs.php';
require_once $baseDir . '/TokensGeradores/TokenPadraoJson.php';
require_once $baseDir . '/TokensGeradores/TokenInvalidacao.php';
require_once $baseDir . '/TokensGeradores/TokensLimiteSolicitacoes.php';
require_once $baseDir . '/Seguranca/CSRF.php';
require_once $baseDir . '/AcessosConsultas/BuscarNomeEmpresaReceitaCache.php';
require_once $baseDir . '/PaginasAreaClienteAcessoCadastro/CadastroPlenusAPI.php';
require_once $baseDir . '/AcessosConsultas/CredenciaisPiperun.php';
require_once $baseDir . '/ProcessamentoFilaJobs/CriaFilaProcessamento.php';
registrar_verificacao_periodica_fila_jobs($baseDir);

header('Content-Type: application/json; charset=UTF-8');
require_valid_csrf_token();

$formatarDocumento = static function (?string $valor): string {
    $digitos = preg_replace('/[^0-9A-Za-z]/', '', (string) $valor);

    if (strlen($digitos) === 14) {
        return vsprintf('%s%s.%s%s%s.%s%s%s/%s%s%s%s-%s%s', str_split($digitos));
    }

    if (strlen($digitos) === 11) {
        return vsprintf('%s%s%s.%s%s%s.%s%s%s-%s%s', str_split($digitos));
    }

    return trim((string) $valor);
};

$token = $_COOKIE['auth_token'] ?? '';
if (!$token) {
    http_response_code(401);
    echo json_encode(['erro' => '⚠️ Usuário não autenticado.']);
    exit;
}

$segredo = getenv('JWT_SECRET');
if ($segredo === false) {
    $segredo = trim(file_get_contents($baseDir . '/Configuracoes/Segredo.jwt'));
}

$dadosToken = JWTHelper::decode($token, $segredo);
if (!$dadosToken || is_token_blacklisted($token)) {
    http_response_code(403);
    echo json_encode(['erro' => '⚠️ Credenciais inválidas.']);
    exit;
}

$emailUsuario = strtolower(trim($dadosToken['email'] ?? ''));
$nomeUsuario = mb_strtoupper(trim($dadosToken['nome'] ?? ''), 'UTF-8');
if ($emailUsuario === '') {
    http_response_code(400);
    echo json_encode(['erro' => '⚠️ Não foi possível identificar o usuário.']);
    exit;
}

if (!check_rate_limit('adicionar_empresa', 5, 60, $emailUsuario)) {
    http_response_code(429);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Quantidade de solicitações máximas atingidas. Tente novamente em 1 hora.']);
    exit;
}

$cnpjNovo = preg_replace('/[^0-9A-Za-z]/', '', filter_input(INPUT_POST, 'cnpj', FILTER_UNSAFE_RAW) ?? '');
$nomeEmpresaInformado = mb_strtoupper(trim(filter_input(INPUT_POST, 'empresa', FILTER_UNSAFE_RAW) ?? ''), 'UTF-8');
if (strlen($cnpjNovo) !== 14) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Informe um CNPJ válido.']);
    exit;
}

if ($nomeEmpresaInformado === '') {
    $nomeEmpresaInformado = obter_nome_empresa($cnpjNovo, $baseDir);
}

require $baseDir . '/AcessosConsultas/CredenciaisBancoDados.php';

try {
    $pdo = new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
    ]);

    $stmt = $pdo->prepare("SELECT DS_NOME, DS_SENHA, NR_CPFCNPJ, DT_DATAEXPIRACAO FROM _USR_CONF_SITE_CADASTROS WHERE LOWER(DS_EMAIL) = ?");
    $stmt->execute([$emailUsuario]);
    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$registros) {
        http_response_code(404);
        echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Não encontramos um cadastro ativo para este usuário.']);
        exit;
    }

    $senhaHash = '';
    $nomeCadastro = '';
    $dataExpiracao = null;
    $documentosExistentes = [];
    foreach ($registros as $linha) {
        if (!$senhaHash && !empty($linha['DS_SENHA'])) {
            $senhaHash = $linha['DS_SENHA'];
        }
        if (!$nomeCadastro && !empty($linha['DS_NOME'])) {
            $nomeCadastro = mb_strtoupper(trim($linha['DS_NOME']), 'UTF-8');
        }
        if ($dataExpiracao === null && !empty($linha['DT_DATAEXPIRACAO'])) {
            $dataExpiracao = $linha['DT_DATAEXPIRACAO'];
        }
        $docLinha = preg_replace('/[^0-9A-Za-z]/', '', (string)($linha['NR_CPFCNPJ'] ?? ''));
        if ($docLinha !== '') {
            $documentosExistentes[$docLinha] = true;
        }
    }

    if (isset($documentosExistentes[$cnpjNovo])) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => '⚠️ Esta empresa já está vinculada à sua conta.',
            'status' => 409
        ]);
        exit;
    }

    if ($senhaHash === '') {
        http_response_code(400);
        echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Não foi possível reutilizar suas credenciais.']);
        exit;
    }

    $nomeParaSalvar = $nomeCadastro !== '' ? $nomeCadastro : ($nomeUsuario !== '' ? $nomeUsuario : $emailUsuario);

    if ($dataExpiracao !== null) {
        $ins = $pdo->prepare(
            "INSERT INTO _USR_CONF_SITE_CADASTROS (DS_NOME, DS_EMAIL, NR_CPFCNPJ, DS_SENHA, DT_DATAEXPIRACAO) VALUES (?, ?, ?, ?, ?)"
        );
        $ins->execute([$nomeParaSalvar, $emailUsuario, $cnpjNovo, $senhaHash, $dataExpiracao]);
    } else {
        $ins = $pdo->prepare(
            "INSERT INTO _USR_CONF_SITE_CADASTROS (DS_NOME, DS_EMAIL, NR_CPFCNPJ, DS_SENHA) VALUES (?, ?, ?, ?)"
        );
        $ins->execute([$nomeParaSalvar, $emailUsuario, $cnpjNovo, $senhaHash]);
    }

     $codigoPlenus = null;
    $contatoSiteExiste = false;
    $novoCadastroPlenus = false;

    try {
        $codStmt = $pdo->prepare(
            "SELECT TOP 1 CD_PESSOA\n"
            . "  FROM MBAD_PESSOA\n"
            . " WHERE REPLACE(REPLACE(REPLACE(REPLACE(NR_CPFCNPJ, '.', ''), '-', ''), '/', ''), ' ', '') = ?"
        );
        $codStmt->execute([$cnpjNovo]);
        $codigoRetornado = $codStmt->fetchColumn();
        if ($codigoRetornado !== false && $codigoRetornado !== null) {
            $codigoPlenus = trim((string) $codigoRetornado);
        }

        if ($codigoPlenus !== null && $codigoPlenus !== '') {
            $contatoSiteExiste = contato_site_existe_plenus($pdo, $codigoPlenus, $emailUsuario);
        } else {
            $codigoPlenus = null;
        }
    } catch (PDOException $e) {
        log_event('AdicionarEmpresa - erro ao consultar cadastro Plenus: ' . $e->getMessage());
    }

    if (strlen($cnpjNovo) === 14) {
        try {
            $sincronizacao = sincronizar_cadastro_plenus($pdo, $cnpjNovo, $nomeParaSalvar, $emailUsuario, $baseDir, $formatarDocumento);
            if ($sincronizacao !== null) {
                $codigoPlenus = $sincronizacao['codigo'];
                if (!empty($sincronizacao['criado'])) {
                    $contatoSiteExiste = contato_site_existe_plenus($pdo, $codigoPlenus, $emailUsuario);
                    $novoCadastroPlenus = true;
                }
            }
        } catch (Throwable $t) {
            log_event('AdicionarEmpresa - erro ao sincronizar cadastro com o Plenus: ' . $t->getMessage());
        }

        if ($codigoPlenus !== null && !$contatoSiteExiste) {
            try {
                $atualizado = atualizar_contato_cliente_plenus($pdo, $codigoPlenus, $nomeParaSalvar, $emailUsuario, $baseDir);
                if ($atualizado) {
                    $contatoSiteExiste = true;
                }
            } catch (Throwable $t) {
                log_event('AdicionarEmpresa - erro ao atualizar contato no Plenus: ' . $t->getMessage());
            }
        }
    }

    $pdo = null;

    $dadosJobPlenus = [
        'cnpj' => $cnpjNovo,
        'nome' => $nomeParaSalvar,
        'email' => $emailUsuario,
    ];

    try {
        registrar_job_fila('plenus_sincronizar_contato', $dadosJobPlenus, $baseDir);
    } catch (Throwable $t) {
        log_event('AdicionarEmpresa - falha ao enfileirar sincronizacao Plenus: ' . $t->getMessage());
    }

    $linhasExtrasJob = [
        'Empresa vinculada pelo botão Adicionar Empresa.'
    ];

    $dadosJobPipe = [
        'tituloPrefixo' => 'Inclusão de Nova Empresa no Configurador',
        'pipelineId'    => 78334,
        'stageId'       => 483644,
        'linhasExtras'  => $linhasExtrasJob,
        'nome'          => $nomeParaSalvar,
        'cpfcnpj'       => $formatarDocumento($cnpjNovo),
        'empresa'       => $nomeEmpresaInformado,
        'email'         => $emailUsuario,
    ];

    try {
        registrar_job_fila('piperun_criar_oportunidade', $dadosJobPipe, $baseDir);
    } catch (Throwable $t) {
        log_event('AdicionarEmpresa - falha ao enfileirar criacao de oportunidade: ' . $t->getMessage());
    }

    try {
        disparar_sincronizacao_cliente_background((string)($_COOKIE['auth_token'] ?? ''));
    } catch (Throwable $t) {
        log_event('AdicionarEmpresa - falha ao disparar sincronizacao em background: ' . $t->getMessage());
    }

    echo json_encode([
        'sucesso' => true,
        'mensagem' => '✅ Empresa adicionada com sucesso.',
        'empresa' => [
            'documento' => $cnpjNovo,
            'nome' => $nomeEmpresaInformado
        ],
        'plenus' => [
            'codigo' => $codigoPlenus,
            'novoCadastro' => $novoCadastroPlenus,
            'contatoAtualizado' => $contatoSiteExiste
        ]
    ]);
} catch (PDOException $e) {
    log_event('AdicionarEmpresa: ' . $e->getMessage());
    if ((int)$e->getCode() === 23000) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => '⚠️ Este CNPJ já está vinculado a outro cadastro.',
            'status' => 409
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Erro ao adicionar a empresa.']);
    }
}
