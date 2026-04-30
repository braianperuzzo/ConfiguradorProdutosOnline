<?php
header('Content-Type: text/html; charset=UTF-8');
$baseDir = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
if ($baseDir === '') {
    $baseDir = dirname(__DIR__);
}
require_once $baseDir . '/Seguranca/CSRF.php';
require_valid_csrf_token();
require $baseDir . '/Seguranca/MetodosSegurancao.php';
require_once $baseDir . '/LogsErros/Logs.php';
require_once $baseDir . '/TokensGeradores/TokensLimiteSolicitacoes.php';
require $baseDir . '/AcessosConsultas/CredenciaisBancoDados.php';
require_once $baseDir . '/ProcessamentoFilaJobs/CriaFilaProcessamento.php';
require_once $baseDir . '/PaginasSolicitacoes/SuporteCategoria.php';
registrar_verificacao_periodica_fila_jobs($baseDir);

$formatoRaw = strip_tags(filter_input(INPUT_POST, 'formato', FILTER_UNSAFE_RAW) ?? '');
$formatoDecodificado = html_entity_decode((string)$formatoRaw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$formato   = mb_strtoupper(trim($formatoDecodificado), 'UTF-8');
$cdProduto = strtoupper(trim(strip_tags(filter_input(INPUT_POST, 'cd_produto', FILTER_UNSAFE_RAW) ?? '')));
$empresa   = strtoupper(trim(strip_tags(filter_input(INPUT_POST, 'cd_empresa', FILTER_UNSAFE_RAW) ?? '001')));
$nome    = mb_strtoupper(trim(strip_tags(filter_input(INPUT_POST, 'nome', FILTER_UNSAFE_RAW) ?? '')), 'UTF-8');
$empresacliente = mb_strtoupper(trim(strip_tags(filter_input(INPUT_POST, 'empresa', FILTER_UNSAFE_RAW) ?? '')), 'UTF-8');
$emailInput = filter_input(INPUT_POST, 'email', FILTER_UNSAFE_RAW) ?? '';
$emailParts = array_filter(array_map('trim', preg_split('/[;\s,]+/', $emailInput)));
$email   = strtoupper(implode(';', $emailParts));
$cpfcnpj = preg_replace('/[^0-9A-Za-z]/', '', filter_input(INPUT_POST, 'cpfcnpj', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '');
$linkDesenho = trim(filter_input(INPUT_POST, 'link', FILTER_SANITIZE_URL) ?? '');

if ($linkDesenho && (!filter_var($linkDesenho, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $linkDesenho))) {
    http_response_code(400);
    echo '⚠️ Link invalido.';
    exit;
}

if (!$formato || !$cdProduto || $cdProduto === '---' || !preg_match('/^[A-Z]{2,4}\.[0-9]{8}$/', $cdProduto)) {
    http_response_code(400);
    echo '⚠️ Código de produto inválido. Reconfigure o produto e tente novamente';
    exit;
}

if (!check_rate_limit('solicitar_desenho', 10, 3600, $email)) {
    http_response_code(429);
    echo '⚠️ Limite Máximo de Solicitações Excedido. Tente novamente em 1 hora.';
    exit;
}

try {
    $pdo = new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
       PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
    ]);

    $nrCompl       = 'RDS';
$cdResponsavel = $empresa;
$cdTipo        = 1;
$idStatus      = 0;
$usuaCriacao   = 'REDUTORES IBR';
$complorigem   = 'PRODUTO';
$tipoorigem    = 'Avant.BO.Materiais.Produto';
$atributo1     = '001';
$atributo2     = '001';
$atributo3     = $empresa . ',' . $cdProduto;
$atributo4     = $empresa;
$drvw_project = explode('.', $cdProduto)[0];

    $produtoRef = $cdProduto;
    $isCodigo = preg_match('/^[A-Z]{2,4}\.[0-9]{8}$/', $cdProduto);
    if ($isCodigo) {
        $sqlRef = "SELECT TOP 1 DS_REFERENCIA FROM MMPR_PRODUTO WHERE CD_PRODUTO = ? AND ID_STATUS = 0 AND CD_PRODCONFIG IS NOT NULL";
        try {
            $stmRef = $pdo->prepare($sqlRef);
            $stmRef->execute([$cdProduto]);
            $rowRef = $stmRef->fetch(PDO::FETCH_ASSOC);
            $produtoRef = $rowRef['DS_REFERENCIA'] ?? $cdProduto;
        } catch (PDOException $e) {
        }
    }


$dataFormatada = date('m-d-Y');
$dataFormatadaInicio = date('d/m/Y H:i:s');

$sqlDoc = "SELECT ISNULL(MAX(CAST(CD_DOCUMENTO AS INT)), 0) + 1 AS PROXIMO
           FROM PMPR_PROJETO
           WHERE NR_COMPL = ?";
$stm = $pdo->prepare($sqlDoc);
$stm->execute([$nrCompl]);
$cdDocumento = $stm->fetch(PDO::FETCH_ASSOC)['PROXIMO'] ?? 1;

$drvw_idfield = $empresa . '-' . $cdDocumento . '-' . $nrCompl;

$sql = "INSERT INTO PMPR_PROJETO (
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

$insert = $pdo->prepare($sql);
$insert->execute([
    $empresa, $cdDocumento, $nrCompl,
    $dataFormatada, $dataFormatada, $dataFormatada, $dataFormatada,
    $cdResponsavel, $formato, $cdTipo, $idStatus,
    $complorigem, $tipoorigem, $cdProduto,
    $atributo1, $atributo2, $atributo3,
    $usuaCriacao, $usuaCriacao
]);

$sql2 = "INSERT INTO _USR_PMPR_PROJETO (
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

$insert2 = $pdo->prepare($sql2);
$insert2->execute([
    $empresa, $cdDocumento, $nrCompl,
    $dataFormatadaInicio,
    $nome, $cpfcnpj, $email
]);

$sql3 = "INSERT INTO _USR_PMPR_PROJETO_DRIVEWORKS (
    CD_EMPRESA, CD_DOCUMENTO, NR_COMPL,
    DRVW_IDFIELD, DRVW_TRANSITION, DRVW_PROJECT, DRVW_STATE
) VALUES (
    ?, ?, ?,
    ?, 'Release', ?, 'Novo' 
)";

$insert3 = $pdo->prepare($sql3);
$insert3->execute([
    $empresa, $cdDocumento, $nrCompl,
    $drvw_idfield, $drvw_project
]);

$textoClassificacao = trim("desenho tecnico formato $formato produto $cdProduto");
$classificacao = classificar_categoria_suporte($textoClassificacao, 'tecnico');
$categoriaSugerida = $classificacao['categoria'];
$filaSugerida = obter_fila_suporte($categoriaSugerida);

$emailHistorico = strtolower($emailParts[0] ?? '');

if ($emailHistorico !== '' && $cpfcnpj !== '') {
        $sqlAtualizaHistorico = "UPDATE _USR_CONF_SITE_HISTORICO_DESENHO
               SET DS_LINK = ?
             WHERE DS_EMAIL = ? AND NR_CPFCNPJ = ? AND DS_REFERENCIA = ? AND DS_FORMATO = ? AND DRVW_IDFIELD = ?
               AND (DS_LINK IS NULL OR LTRIM(RTRIM(CONVERT(VARCHAR(MAX), DS_LINK))) = '')";

    $stmHistoricoUpdate = $pdo->prepare($sqlAtualizaHistorico);
    $stmHistoricoUpdate->execute([
        $linkDesenho,
        $emailHistorico,
        $cpfcnpj,
        $produtoRef,
        $formato,
        $drvw_idfield,
    ]);

$sqlHistoricoDesenho = "INSERT INTO _USR_CONF_SITE_HISTORICO_DESENHO (DS_EMAIL, NR_CPFCNPJ, DS_REFERENCIA, DS_FORMATO, DRVW_IDFIELD, DS_LINK, DT_DATA)
            SELECT ?, ?, ?, ?, ?, ?, CONVERT(VARCHAR(19), GETDATE(), 120)
             WHERE NOT EXISTS (
                 SELECT 1 FROM _USR_CONF_SITE_HISTORICO_DESENHO
                  WHERE DS_EMAIL = ? AND NR_CPFCNPJ = ? AND DS_REFERENCIA = ? AND DS_FORMATO = ? AND DRVW_IDFIELD = ?
             )";

    $stmHistorico = $pdo->prepare($sqlHistoricoDesenho);
    $stmHistorico->execute([
        $emailHistorico,
        $cpfcnpj,
        $produtoRef,
        $formato,
        $drvw_idfield,
        $linkDesenho,
        $emailHistorico,
        $cpfcnpj,
        $produtoRef,
        $formato,
        $drvw_idfield,
    ]);
}

registrar_historico_solicitacao_suporte($baseDir, [
    'tipo' => 'desenho',
    'categoriaSugerida' => $categoriaSugerida,
    'filaSugerida' => $filaSugerida,
    'confianca' => $classificacao['confianca'],
    'solicitante' => [
        'nome' => $nome,
        'email' => $email,
        'documento' => $cpfcnpj,
    ],
    'referencia' => $produtoRef,
    'codigo' => $cdProduto,
    'formato' => $formato,
    'link' => $linkDesenho,
    'origem' => 'SolicitacaoDesenho.php',
]);
    echo $drvw_idfield;

$pdo = null;
} catch (PDOException $e) {
    log_event('Erro em SolicitacaoDesenho: ' . $e->getMessage());
    http_response_code(500);
    echo '⚠️ Erro ao Salvar Dados.';
}
?>
