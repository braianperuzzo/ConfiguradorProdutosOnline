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
require_once $baseDir . '/TokensGeradores/TokenPadraoJson.php';
require_once $baseDir . '/AcessosConsultas/CredenciaisBancoDados.php';
require_once $baseDir . '/ProcessamentoFilaJobs/CriaFilaProcessamento.php';
registrar_verificacao_periodica_fila_jobs($baseDir);

$nome       = strtoupper(trim(filter_input(INPUT_POST, 'nome', FILTER_UNSAFE_RAW) ?? ''));
$empresa    = strtoupper(trim(filter_input(INPUT_POST, 'empresa', FILTER_UNSAFE_RAW) ?? ''));
$emailInput = filter_input(INPUT_POST, 'email', FILTER_UNSAFE_RAW) ?? '';
$emailParts = array_filter(array_map('trim', preg_split('/[;\s,]+/', $emailInput)));
$emailsUnicos = [];
$emailsMap = [];
foreach ($emailParts as $parte) {
    if ($parte === '') continue;
    $chave = strtolower($parte);
    if (isset($emailsMap[$chave])) continue;
    $emailsMap[$chave] = true;
    $emailsUnicos[] = $parte;
}
$emailParts = $emailsUnicos;
$email      = strtoupper(implode(';', $emailParts));
$telefone   = trim(filter_input(INPUT_POST, 'telefone', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '');
$observacao = strtoupper(trim(filter_input(INPUT_POST, 'observacao', FILTER_UNSAFE_RAW) ?? ''));
$cnpj       = preg_replace('/[^0-9A-Za-z]/', '', filter_input(INPUT_POST, 'cnpj', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '');
$itensJson  = $_POST['itens'] ?? '[]';
$itens      = json_decode($itensJson, true);

if (!check_rate_limit('solicitar_cotacao', 10, 3600, $email)) {
    http_response_code(429);
    echo '⚠️ Limite Máximo de Solicitações Excedido. Tente novamente em 1 hora.';
    exit;
}

if (!$nome || !$email || !$cnpj || !is_array($itens) || !count($itens)) {
    echo '⚠️ Dados Incompletos.';
    exit;
}

$agora = new DateTime('now');
$created = $agora->format('Y-m-d H:i:s');
$dataTitulo = $agora->format('d/m/Y H:i');

$nomeEmpresaOuPessoa = strlen($cnpj) === 11 ? $nome : $empresa;
$titulo = "Solicitação de Cotação Configurador - $cnpj - $nomeEmpresaOuPessoa - $dataTitulo";

$stageId = 574348;
$tokenJwt = $_COOKIE['auth_token'] ?? '';
if ($tokenJwt) {
    $segredo = getenv('JWT_SECRET');
    if ($segredo === false) {
        $arquivoSegredo = $baseDir . '/Configuracoes/Segredo.jwt';
        if (is_readable($arquivoSegredo)) {
            $segredo = trim(file_get_contents($arquivoSegredo));
        } else {
            $segredo = '';
        }
    }
    if ($segredo !== '') {
        $dados = JWTHelper::decode($tokenJwt, $segredo);
        if ($dados) {
            $grupo = strtoupper($dados['grupo'] ?? '');
            if ($grupo === 'PRATA') $stageId = 611115;
            elseif ($grupo === 'OURO' || $grupo === 'DIAMANTE') $stageId = 611116;
        }
    }
}

try {
    $linhasNota = [];
    foreach ($itens as $it) {
        $ref = strtoupper(trim($it['referencia'] ?? ''));
        $cod = strtoupper(trim($it['codigo'] ?? ''));
        $qtd = trim((string)($it['quantidade'] ?? ''));
        $identificador = $ref !== '' ? $ref : $cod;
        if ($ref !== '') {
            $linhasNota[] = "Referência: $ref";
        }
        if ($cod) $linhasNota[] = "Código: $cod";
        if ($qtd !== '') $linhasNota[] = "Quantidade: $qtd";
        if ($identificador !== '') {
            $linhasNota[] = '';
        }
    }
    $linhasNota[] = "Nome: $nome";
    $linhasNota[] = (strlen($cnpj) === 11 ? 'CPF' : 'CNPJ') . ": $cnpj";
    if (strlen($cnpj) === 14) $linhasNota[] = "Empresa: $empresa";
    $linhasNota[] = "Email: $email";
    if ($telefone) $linhasNota[] = "Telefone: $telefone";
    if ($observacao) $linhasNota[] = "Observação: $observacao";


    $contatos = array_map(function ($mail) use ($nome) {
        return ['nome' => $nome, 'email' => strtolower($mail)];
    }, $emailParts);
    $referencias = [];
    $linksPorReferencia = [];
        $hostAtual = isset($_SERVER['HTTP_HOST']) ? trim((string) $_SERVER['HTTP_HOST']) : '';
    foreach ($itens as $it) {
        $ref = strtoupper(trim($it['referencia'] ?? ''));
        $cod = strtoupper(trim($it['codigo'] ?? ''));
        $identificador = $ref !== '' ? $ref : $cod;
        if ($identificador !== '') {
            $referencias[] = $identificador;
        }
        $linkItem = trim((string)($it['link'] ?? ''));
        if ($identificador !== '' && $linkItem !== '') {
            $linksPorReferencia[$identificador] = $linkItem;
        }
    }

    registrar_job_fila('piperun_solicitacao', [
        'deal' => [
            'pipeline_id'  => 90783,
            'stage_id'     => $stageId,
            'title'        => $titulo,
            'created_at'   => $created,
            'tags'         => [['id' => 359705], ['id' => 362614]],
            'company_cnpj' => $cnpj,
        ],
        'linhasNota' => $linhasNota,
        'contatos'   => $contatos,
        'historicos' => [
            [
                'tipo'        => 'cotacao_carrinho',
                'cnpj'        => $cnpj,
                'emails'      => array_map('strtolower', $emailParts),
                'referencias' => $referencias,
                'links'       => $linksPorReferencia,
                'host'        => $hostAtual,
            ],
        ],
    ], $baseDir);

    echo '✅ Solicitação enviada! Em breve nossos Consultores Comerciais entrarão em contato com a proposta.';

} catch (Exception $e) {
    log_event('Erro em SolicitacaoCotacaoCarrinho: ' . $e->getMessage());
    http_response_code(500);
    echo '⚠️ Erro ao enviar solicitação.';
}
?>