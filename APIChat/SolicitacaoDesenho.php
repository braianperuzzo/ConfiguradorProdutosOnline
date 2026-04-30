<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

$baseDir = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
if ($baseDir === '') {
    $baseDir = dirname(__DIR__);
}

require_once __DIR__ . '/NucleoApiChat.php';
require_once $baseDir . '/TokensGeradores/TokensLimiteSolicitacoes.php';
require_once $baseDir . '/ProcessamentoFilaJobs/CriaFilaProcessamento.php';
require_once $baseDir . '/PaginasSolicitacoes/SuporteCategoria.php';
require_once $baseDir . '/AcessosConsultas/CredenciaisBancoDados.php';
require_once $baseDir . '/AcessosConsultas/BuscarNomeEmpresaReceitaCache.php';

const API_CHAT_SOLICITACAO_DESENHO_ENDPOINT = '/APIChat/SolicitacaoDesenho.php';

api_chat_middleware_init($baseDir, API_CHAT_SOLICITACAO_DESENHO_ENDPOINT);
auth_api_chat_validar_ou_responder($baseDir, 'responder_solicitacao_desenho_api_chat');

const API_CHAT_FORMATOS_DESENHO_RETORNO_EXATO = "1 - 3D (PARASOLID)\n2 - 3D (PARASOLID BINÁRIO)\n3 - PDF\n4 - STEP\n5 - DWG\n6 - IGES\n7 - DXF\n8 - SAT";

const API_CHAT_FORMATOS_DESENHO_MAPA = [
    1 => '3D (PARASOLID)',
    2 => '3D (PARASOLID BINÁRIO)',
    3 => 'PDF',
    4 => 'STEP',
    5 => 'DWG',
    6 => 'IGES',
    7 => 'DXF',
    8 => 'SAT',
];

const API_CHAT_LINHAS_DESENHO_PERMITIDAS = ['QU', 'QUDR', 'MO', 'PL', 'VA', 'HY'];
const API_CHAT_HYLN_PERMITIDOS = ['1.P', '1.M'];
const API_CHAT_MENSAGEM_CLIENTE_SUCESSO = 'Solicitação realizada com sucesso. Em até 10 minutos, o desenho será encaminhado para o(s) e-mail(s) informado(s).';
const API_CHAT_EMPRESA_CODIGO_PADRAO = '001';

function responder_solicitacao_desenho_api_chat(int $status, array $payload): void
{
    $idempotencyKey = api_chat_middleware_idempotency_header_key();
    if ($idempotencyKey !== '') {
        api_chat_middleware_idempotency_finalizar(API_CHAT_SOLICITACAO_DESENHO_ENDPOINT, $idempotencyKey, $status, $payload);
    }
    api_chat_middleware_responder($status, $payload);
}

function api_chat_solicitacao_desenho_log(string $baseDir, string $tipo, array $dados): void
{
    $arquivo = rtrim($baseDir, '/\\') . '/APIChat/Logs/solicitacao-desenho-' . date('Y-m-d') . '.jsonl';
    $registro = [
        'timestamp' => gmdate('c'),
        'tipo' => $tipo,
        'requestId' => api_chat_middleware_request_id_atual(),
        'dados' => $dados,
    ];

    api_chat_append_jsonl($arquivo, $registro);
}

function api_chat_solicitacao_desenho_responder_erro(string $baseDir, int $status, string $codigoErro, string $mensagem, array $extra = []): void
{
    api_chat_solicitacao_desenho_log($baseDir, 'erro_validacao', [
        'status' => $status,
        'codigoErro' => $codigoErro,
        'mensagem' => $mensagem,
        'extra' => $extra,
    ]);

    responder_solicitacao_desenho_api_chat($status, array_merge([
        'ok' => false,
        'erro' => $mensagem,
        'codigoErro' => $codigoErro,
    ], $extra));
}


function api_chat_solicitacao_desenho_tipo_documento(string $documento): string
{
    $len = strlen($documento);
    if ($len === 11) {
        return 'CPF';
    }

    if ($len === 14) {
        return 'CNPJ';
    }

    return '';
}

function api_chat_solicitacao_desenho_validar_nome_completo(string $nome): bool
{
    return api_chat_validar_nome_completo_padrao($nome);
}

function api_chat_solicitacao_desenho_normalizar_emails(string $emailsRaw): array
{
    $entrada = trim($emailsRaw);
    if ($entrada === '') {
        return [];
    }

    $colecao = preg_split('/\s*;\s*/', $entrada) ?: [];
    $saida = [];
    $map = [];

    foreach ($colecao as $email) {
        $atual = strtolower(trim((string) $email));
        if ($atual === '' || !filter_var($atual, FILTER_VALIDATE_EMAIL)) {
            return [];
        }

        if (isset($map[$atual])) {
            continue;
        }

        $map[$atual] = true;
        $saida[] = $atual;
    }

    return $saida;
}

function api_chat_solicitacao_desenho_normalizar_formatos($formatoEntrada): array
{
    $tokens = [];

    if (is_array($formatoEntrada)) {
        $tokens = $formatoEntrada;
    } else {
        $texto = trim((string) $formatoEntrada);
        if ($texto !== '') {
            $tokens = preg_split('/\s*[;,\|]\s*/', $texto) ?: [];
        }
    }

    $saida = [];
    $map = [];

    foreach ($tokens as $token) {
        $atual = strtoupper(trim((string) $token));
        if ($atual === '') {
            continue;
        }

        $indice = null;
        if (preg_match('/^\d+$/', $atual) === 1) {
            $indice = (int) $atual;
        } else {
            if (preg_match('/\b([1-8])\b/u', $atual, $codigoCapturado) === 1) {
                $indice = (int) $codigoCapturado[1];
            }

            if ($indice === null) {
                $apelidos = [
                    1 => ['PARASOLID', '3D PARASOLID', '3D'],
                    2 => ['PARASOLID BINARIO', 'PARASOLID BINÁRIO', '3D BINARIO', '3D BINÁRIO'],
                    3 => ['PDF'],
                    4 => ['STEP', 'STP'],
                    5 => ['DWG'],
                    6 => ['IGES', 'IGS'],
                    7 => ['DXF'],
                    8 => ['SAT'],
                ];

                foreach ($apelidos as $codigo => $termos) {
                    foreach ($termos as $termo) {
                        if (mb_stripos($atual, $termo, 0, 'UTF-8') !== false) {
                            $indice = $codigo;
                            break 2;
                        }
                    }
                }
            }

            foreach (API_CHAT_FORMATOS_DESENHO_MAPA as $codigo => $descricao) {
                if ($atual === strtoupper($descricao)) {
                    $indice = $codigo;
                    break;
                }
            }
        }

        if ($indice === null || !isset(API_CHAT_FORMATOS_DESENHO_MAPA[$indice])) {
            return [];
        }

        if (isset($map[$indice])) {
            continue;
        }

        $map[$indice] = true;
        $saida[] = API_CHAT_FORMATOS_DESENHO_MAPA[$indice];
    }

    return $saida;
}

function api_chat_solicitacao_desenho_validar_produto_ativo(PDO $pdo, string $codigoProduto): bool
{
    $sql = "SELECT TOP 1 CD_PRODUTO FROM MMPR_PRODUTO WHERE ID_STATUS = 0 AND CD_PRODUTO = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$codigoProduto]);

    return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
}



function api_chat_solicitacao_desenho_buscar_linha_produto(PDO $pdo, string $codigoProduto): string
{
    $sql = "SELECT TOP 1 CD_PRODCONFIG FROM MMPR_PRODUTO WHERE ID_STATUS = 0 AND CD_PRODUTO = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$codigoProduto]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return strtoupper(trim((string) ($row['CD_PRODCONFIG'] ?? '')));
}

function api_chat_solicitacao_desenho_buscar_hyln(PDO $pdo, string $codigoProduto): string
{
    $sql = "SELECT TOP 1 DS_VARIAVEL.value('(/VariavelOpcaoSimples/Valor)[1]', 'varchar(4000)') as RESPOSTA_SELETOR
"
        . "FROM MMPR_PRODUTOESTRUTURA
"
        . "WHERE CD_PRODUTO = ?
"
        . "AND NM_VARIAVEL = 'HYLN'";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$codigoProduto]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return strtoupper(trim((string) ($row['RESPOSTA_SELETOR'] ?? '')));
}

function api_chat_solicitacao_desenho_buscar_referencia_produto(PDO $pdo, string $codigoProduto): string
{
    $referencia = $codigoProduto;
    $sqlRef = "SELECT TOP 1 DS_REFERENCIA FROM MMPR_PRODUTO WHERE CD_PRODUTO = ? AND ID_STATUS = 0 AND CD_PRODCONFIG IS NOT NULL";

    try {
        $stmRef = $pdo->prepare($sqlRef);
        $stmRef->execute([$codigoProduto]);
        $rowRef = $stmRef->fetch(PDO::FETCH_ASSOC);
        $referenciaBanco = trim((string) ($rowRef['DS_REFERENCIA'] ?? ''));
        if ($referenciaBanco !== '') {
            $referencia = strtoupper($referenciaBanco);
        }
    } catch (Throwable $e) {
        // Sem bloqueio do fluxo principal caso referência não seja encontrada.
    }

    return $referencia;
}


function api_chat_solicitacao_desenho_criar_projeto(
    PDO $pdo,
    string $nome,
    string $documento,
    array $emails,
    string $codigoProduto,
    array $formatos
): array {
    $empresaCodigo = API_CHAT_EMPRESA_CODIGO_PADRAO;
    $nrCompl = 'RDS';
    $cdResponsavel = $empresaCodigo;
    $cdTipo = 1;
    $idStatus = 0;
    $usuaCriacao = 'REDUTORES IBR';
    $complorigem = 'PRODUTO';
    $tipoorigem = 'Avant.BO.Materiais.Produto';
    $atributo1 = '001';
    $atributo2 = '001';
    $atributo3 = $empresaCodigo . ',' . $codigoProduto;
    $drvwProject = explode('.', $codigoProduto)[0] ?? $codigoProduto;
    $formatoTexto = strtoupper(implode(';', $formatos));
    $emailTexto = strtoupper(implode(';', $emails));

    $dataFormatada = date('m-d-Y');
    $dataFormatadaInicio = date('d/m/Y H:i:s');

    $sqlDoc = "SELECT ISNULL(MAX(CAST(CD_DOCUMENTO AS INT)), 0) + 1 AS PROXIMO
               FROM PMPR_PROJETO
               WHERE NR_COMPL = ?";
    $stm = $pdo->prepare($sqlDoc);
    $stm->execute([$nrCompl]);
    $cdDocumento = (int) ($stm->fetch(PDO::FETCH_ASSOC)['PROXIMO'] ?? 1);

    $drvwIdField = $empresaCodigo . '-' . $cdDocumento . '-' . $nrCompl;

    $sqlProjeto = "INSERT INTO PMPR_PROJETO (
        CD_EMPRESA, CD_DOCUMENTO, NR_COMPL,
        DT_EMISSAO, DATA_CRIACAO, DATA_MODIFIC, DT_ALTERSTATUS,
        CD_RESPONSAVEL, DS_NOME, CD_TIPO, ID_STATUS,
        PC_DESCTO1, PC_DESCTO2, PC_DESCTO3, PC_DESCTO4, PC_DESCTO5,
        PC_DESCTO6, PC_DESCTO7, PC_DESCTO8, PC_DESCTO9, PC_DESCTO10,
        PC_ACRESCIMO, PC_TOTALDESCONTO, PC_TOTALACRESCIMO,
        VL_DESCTOADIC, VL_ACRESADIC, VL_DURACAO, VL_TRABALHO, VL_CUSTO,
        VL_TOTALATENDER, VL_TOTALFINANCEIRO,
        CD_DOCDESTINO, CD_DOCORIGEM,
        NR_COMPLORIGEM, DS_TIPOORIGEM, DS_ATRIBUTO5,
        DS_ATRIBUTO1, DS_ATRIBUTO2, DS_ATRIBUTO3,
        USUA_CRIACAO, USUA_MODIFIC
    ) VALUES (
        ?, ?, ?,
        ?, ?, ?, ?,
        ?, ?, ?, ?,
        0, 0, 0, 0, 0,
        0, 0, 0, 0, 0,
        0, 0, 0,
        0, 0, 0, 0, 0,
        0, 0,
        0, 0,
        ?, ?, ?,
        ?, ?, ?,
        ?, ?
    )";

    $stmProjeto = $pdo->prepare($sqlProjeto);
    $stmProjeto->execute([
        $empresaCodigo, $cdDocumento, $nrCompl,
        $dataFormatada, $dataFormatada, $dataFormatada, $dataFormatada,
        $cdResponsavel, $formatoTexto, $cdTipo, $idStatus,
        $complorigem, $tipoorigem, $codigoProduto,
        $atributo1, $atributo2, $atributo3,
        $usuaCriacao, $usuaCriacao,
    ]);

    $sqlUsrProjeto = "INSERT INTO _USR_PMPR_PROJETO (
        CD_EMPRESA, CD_DOCUMENTO, NR_COMPL,
        DT_SOLICITACAO, DS_PRIORITARIO,
        CD_NOMECLIENTE, NR_CPFCNPJ, CD_EMAILCLIENTE,
        DS_SITE
    ) VALUES (
        ?, ?, ?,
        ?, 'False',
        ?, ?, ?,
        'True'
    )";

    $stmUsrProjeto = $pdo->prepare($sqlUsrProjeto);
    $stmUsrProjeto->execute([
        $empresaCodigo, $cdDocumento, $nrCompl,
        $dataFormatadaInicio,
        $nome, $documento, $emailTexto,
    ]);

    $sqlDriveworks = "INSERT INTO _USR_PMPR_PROJETO_DRIVEWORKS (
        CD_EMPRESA, CD_DOCUMENTO, NR_COMPL,
        DRVW_IDFIELD, DRVW_TRANSITION, DRVW_PROJECT, DRVW_STATE
    ) VALUES (
        ?, ?, ?,
        ?, 'Release', ?, 'Novo'
    )";

    $stmDriveworks = $pdo->prepare($sqlDriveworks);
    $stmDriveworks->execute([
        $empresaCodigo, $cdDocumento, $nrCompl,
        $drvwIdField, $drvwProject,
    ]);

    return [
        'empresaCodigo' => $empresaCodigo,
        'cdDocumento' => $cdDocumento,
        'nrCompl' => $nrCompl,
        'drvwIdField' => $drvwIdField,
    ];
}

function api_chat_solicitacao_desenho_registrar_historico_tabela(
    PDO $pdo,
    array $emails,
    string $documento,
    string $referenciaProduto,
    array $formatos,
    string $drvwIdField,
    string $linkDesenho = ''
): void {
    if ($emails === [] || $documento === '' || $referenciaProduto === '' || $formatos === [] || $drvwIdField === '') {
        return;
    }

    $sqlAtualizaHistorico = "UPDATE _USR_CONF_SITE_HISTORICO_DESENHO
           SET DS_LINK = ?
         WHERE DS_EMAIL = ? AND NR_CPFCNPJ = ? AND DS_REFERENCIA = ? AND DS_FORMATO = ? AND DRVW_IDFIELD = ?
           AND (DS_LINK IS NULL OR LTRIM(RTRIM(CONVERT(VARCHAR(MAX), DS_LINK))) = '')";

    $sqlHistoricoDesenho = "INSERT INTO _USR_CONF_SITE_HISTORICO_DESENHO (DS_EMAIL, NR_CPFCNPJ, DS_REFERENCIA, DS_FORMATO, DRVW_IDFIELD, DS_LINK, DT_DATA)
        SELECT ?, ?, ?, ?, ?, ?, CONVERT(VARCHAR(19), GETDATE(), 120)
         WHERE NOT EXISTS (
             SELECT 1 FROM _USR_CONF_SITE_HISTORICO_DESENHO
              WHERE DS_EMAIL = ? AND NR_CPFCNPJ = ? AND DS_REFERENCIA = ? AND DS_FORMATO = ? AND DRVW_IDFIELD = ?
         )";

    $stmHistoricoUpdate = $pdo->prepare($sqlAtualizaHistorico);
    $stmHistorico = $pdo->prepare($sqlHistoricoDesenho);

    foreach ($emails as $email) {
        $emailHistorico = strtolower(trim((string) $email));
        if ($emailHistorico === '') {
            continue;
        }

        foreach ($formatos as $formato) {
            $formatoHistorico = strtoupper(trim((string) $formato));
            if ($formatoHistorico === '') {
                continue;
            }

            $stmHistoricoUpdate->execute([
                $linkDesenho,
                $emailHistorico,
                $documento,
                $referenciaProduto,
                $formatoHistorico,
                $drvwIdField,
            ]);

            $stmHistorico->execute([
                $emailHistorico,
                $documento,
                $referenciaProduto,
                $formatoHistorico,
                $drvwIdField,
                $linkDesenho,
                $emailHistorico,
                $documento,
                $referenciaProduto,
                $formatoHistorico,
                $drvwIdField,
            ]);
        }
    }
}


if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    api_chat_solicitacao_desenho_responder_erro($baseDir, 405, 'METODO_NAO_PERMITIDO', 'Use POST para abrir solicitação de desenho.');
}

$input = api_chat_payload_json_or_error();
if (!is_array($input)) {
    $input = [];
}

$idempotencyKey = api_chat_middleware_idempotency_header_key();
$idempotencyState = api_chat_middleware_idempotency_iniciar(API_CHAT_SOLICITACAO_DESENHO_ENDPOINT, $idempotencyKey, $input);
if (($idempotencyState['status'] ?? '') === 'replay') {
    $resposta = is_array($idempotencyState['response']['body'] ?? null) ? $idempotencyState['response']['body'] : [];
    responder_solicitacao_desenho_api_chat((int) ($idempotencyState['response']['status'] ?? 201), $resposta);
}
if (($idempotencyState['status'] ?? '') === 'conflict') {
    responder_solicitacao_desenho_api_chat(409, ['ok' => false, 'erro' => 'Idempotency-Key já foi usado com payload diferente.', 'codigoErro' => 'IDEMPOTENCY_CONFLICT']);
}
if (($idempotencyState['status'] ?? '') === 'in_progress') {
    responder_solicitacao_desenho_api_chat(409, ['ok' => false, 'erro' => 'Requisição idempotente ainda em processamento.', 'codigoErro' => 'IDEMPOTENCY_IN_PROGRESS']);
}

$nome = strtoupper(trim((string) ($input['nome'] ?? '')));
$documento = api_chat_documento_somente_digitos((string) ($input['documento'] ?? $input['cpfCnpj'] ?? $input['cnpj'] ?? ''));
$emailsRaw = trim((string) ($input['email'] ?? ''));
$emails = api_chat_solicitacao_desenho_normalizar_emails($emailsRaw);
$formatoEntrada = $input['formatoDesenho'] ?? $input['formatosDesenho'] ?? $input['formato'] ?? '';
$formatos = api_chat_solicitacao_desenho_normalizar_formatos($formatoEntrada);
$codigoProduto = strtoupper(trim((string) ($input['codigoProduto'] ?? $input['codigo'] ?? '')));
$origem = trim((string) ($input['origem'] ?? 'gpt_api_chat'));
$conversaChatGPT = trim((string) ($input['conversaChatGPT'] ?? $input['conversa'] ?? ''));
$conversaChatGPT = api_chat_privacy_redact_text($conversaChatGPT);
$linkDesenho = trim((string) ($input['link'] ?? ''));
$tipoDocumento = api_chat_solicitacao_desenho_tipo_documento($documento);

if ($nome === '' || $documento === '' || $emails === [] || $formatos === [] || $codigoProduto === '') {
    api_chat_solicitacao_desenho_responder_erro($baseDir, 400, 'DADOS_INCOMPLETOS', 'Campos obrigatórios ausentes: nome completo, CPF/CNPJ válido, email no formato xx@yy.com.br;xx@yy.com.br, formato(s) do desenho e código do produto.');
}

if (isset($input['emails'])) {
    api_chat_solicitacao_desenho_responder_erro($baseDir, 400, 'FORMATO_EMAIL_INVALIDO', 'Envie os emails somente no campo "email", separados por ponto e vírgula (;).');
}

if ($emailsRaw !== '' && $emails === []) {
    api_chat_solicitacao_desenho_responder_erro($baseDir, 400, 'FORMATO_EMAIL_INVALIDO', 'Email inválido. Use o formato xx@yy.com.br;xx@yy.com.br.');
}

if (!api_chat_solicitacao_desenho_validar_nome_completo($nome)) {
    api_chat_solicitacao_desenho_responder_erro($baseDir, 400, 'NOME_COMPLETO_INVALIDO', 'Informe o nome completo (nome e sobrenome).');
}

if (!api_chat_documento_validar_generico($documento)) {
    api_chat_solicitacao_desenho_responder_erro($baseDir, 400, 'DOCUMENTO_INVALIDO', 'Documento inválido. Envie CPF (11 dígitos) ou CNPJ (14 dígitos) válido.');
}

if ($formatos === []) {
    api_chat_solicitacao_desenho_responder_erro(
        $baseDir,
        400,
        'FORMATO_DESENHO_INVALIDO',
        'Formato de desenho inválido. Envie os códigos (1..8) ou descrições permitidas.',
        [
            'formatosPermitidos' => array_values(API_CHAT_FORMATOS_DESENHO_MAPA),
            'formatosPermitidosTexto' => API_CHAT_FORMATOS_DESENHO_RETORNO_EXATO,
        ]
    );
}

if ($linkDesenho !== '' && (!filter_var($linkDesenho, FILTER_VALIDATE_URL) || preg_match('/^https?:\/\//i', $linkDesenho) !== 1)) {
    api_chat_solicitacao_desenho_responder_erro($baseDir, 400, 'LINK_INVALIDO', 'Link inválido. Use URL iniciando com http:// ou https://.');
}

if (!preg_match('/^[A-Z]{2,4}\.[0-9]{8}$/', $codigoProduto)) {
    api_chat_solicitacao_desenho_responder_erro($baseDir, 400, 'CODIGO_PRODUTO_INVALIDO', 'Código do produto inválido. Formato esperado: XXXX.00000000.');
}

if (!check_rate_limit('solicitar_desenho_api_chat', 20, 3600, $emails[0])) {
    api_chat_solicitacao_desenho_responder_erro($baseDir, 429, 'RATE_LIMIT_EXCEDIDO', 'Limite de solicitações excedido para este contato. Tente novamente em 1 hora.');
}

try {
    $pdo = new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8,
        PDO::SQLSRV_ATTR_QUERY_TIMEOUT => 15,
    ]);

    if (!api_chat_solicitacao_desenho_validar_produto_ativo($pdo, $codigoProduto)) {
        api_chat_solicitacao_desenho_responder_erro($baseDir, 400, 'PRODUTO_NAO_ENCONTRADO', 'Código do produto não encontrado ou inativo para solicitação de desenho.');
    }

    $linhaProduto = api_chat_solicitacao_desenho_buscar_linha_produto($pdo, $codigoProduto);
    if ($linhaProduto === '' || !in_array($linhaProduto, API_CHAT_LINHAS_DESENHO_PERMITIDAS, true)) {
        api_chat_solicitacao_desenho_responder_erro(
            $baseDir,
            400,
            'LINHA_EM_DESENVOLVIMENTO',
            'A linha deste produto ainda está em desenvolvimento para envio automático de desenho.',
            [
                'linhaProduto' => $linhaProduto,
                'linhasPermitidas' => API_CHAT_LINHAS_DESENHO_PERMITIDAS,
            ]
        );
    }

    $hyln = '';
    if ($linhaProduto === 'HY') {
        $hyln = api_chat_solicitacao_desenho_buscar_hyln($pdo, $codigoProduto);
        if ($hyln === '' || !in_array($hyln, API_CHAT_HYLN_PERMITIDOS, true)) {
            api_chat_solicitacao_desenho_responder_erro(
                $baseDir,
                400,
                'HYLN_NAO_PERMITIDO',
                'Para a linha HY, somente HYLN 1.P ou 1.M está habilitado para envio automático de desenho.',
                [
                    'hyln' => $hyln,
                    'hylnPermitidos' => API_CHAT_HYLN_PERMITIDOS,
                ]
            );
        }
    }

    $empresa = '';
    if ($tipoDocumento === 'CNPJ') {
        $empresa = trim((string) obter_nome_empresa($documento, $baseDir));
        if ($empresa !== '') {
            $empresa = strtoupper($empresa);
        }
    }

    $referenciaProduto = api_chat_solicitacao_desenho_buscar_referencia_produto($pdo, $codigoProduto);

    try {
        $projeto = api_chat_solicitacao_desenho_criar_projeto($pdo, $nome, $documento, $emails, $codigoProduto, $formatos);
    } catch (Throwable $e) {
        api_chat_solicitacao_desenho_responder_erro(
            $baseDir,
            500,
            'ERRO_INSERCAO_SOLICITACAO',
            'Erro ao registrar solicitação de desenho na base de projetos.',
            ['detalhe' => $e->getMessage()]
        );
    }

    $drvwIdField = (string) ($projeto['drvwIdField'] ?? '');
    $cdDocumentoSolicitacao = (string) ($projeto['cdDocumento'] ?? '');

    api_chat_solicitacao_desenho_registrar_historico_tabela(
        $pdo,
        $emails,
        $documento,
        $referenciaProduto,
        $formatos,
        $drvwIdField,
        $linkDesenho
    );

    $historicoId = registrar_historico_solicitacao_suporte($baseDir, [
        'tipo' => 'desenho_gpt_api_chat',
        'categoriaSugerida' => 'desenho_tecnico',
        'filaSugerida' => 'atendimento_tecnico',
        'confianca' => 1,
        'solicitante' => [
            'nome' => $nome,
            'email' => $emails[0],
            'documento' => $documento,
            'documentoTipo' => $tipoDocumento,
            'empresa' => $empresa,
        ],
        'codigo' => $codigoProduto,
        'linhaProduto' => $linhaProduto,
        'hyln' => $hyln,
        'formatos' => $formatos,
        'referencia' => $referenciaProduto,
        'drvwIdField' => $drvwIdField,
        'numeroSolicitacao' => $cdDocumentoSolicitacao,
        'origem' => 'APIChat/SolicitacaoDesenho.php',
        'conversaChatGPT' => $conversaChatGPT,
        'link' => $linkDesenho,
    ]);

    responder_solicitacao_desenho_api_chat(201, [
        'ok' => true,
        'solicitacaoId' => $cdDocumentoSolicitacao !== '' ? $cdDocumentoSolicitacao : $drvwIdField,
        'redactionsApplied' => true,
        'mensagem' => 'Solicitação de desenho registrada com sucesso.',
        'mensagemCliente' => API_CHAT_MENSAGEM_CLIENTE_SUCESSO,
        'historicoId' => $historicoId,
        'formatosPermitidos' => array_values(API_CHAT_FORMATOS_DESENHO_MAPA),
        'formatosPermitidosTexto' => API_CHAT_FORMATOS_DESENHO_RETORNO_EXATO,
        'dadosRecebidos' => [
            'nome' => $nome,
            'documento' => $documento,
            'documentoTipo' => $tipoDocumento,
            'emailsQuantidade' => count($emails),
            'empresa' => $empresa,
            'codigo' => $codigoProduto,
            'formatos' => $formatos,
            'referencia' => $referenciaProduto,
            'numeroSolicitacao' => $cdDocumentoSolicitacao,
            'drvwIdField' => $drvwIdField,
            'origem' => $origem,
            'statusSolicitacao' => 'registrada',
            'linhaProduto' => $linhaProduto,
            'hyln' => $hyln,
        ],
        'orientacaoGPT' => [
            'primeiraRespostaImediata' => API_CHAT_MENSAGEM_CLIENTE_SUCESSO,
            'incluirLinkConfigurador' => true,
        ],
    ]);
} catch (Throwable $e) {
    api_chat_solicitacao_desenho_log($baseDir, 'erro', [
        'codigoErro' => 'ERRO_INTERNO_SOLICITACAO_DESENHO',
        'mensagem' => $e->getMessage(),
        'arquivo' => $e->getFile(),
        'linha' => $e->getLine(),
    ]);

    responder_solicitacao_desenho_api_chat(500, [
        'ok' => false,
        'erro' => 'Erro interno ao enviar solicitação de desenho.',
        'codigoErro' => 'ERRO_INTERNO_SOLICITACAO_DESENHO',
    ]);
}
