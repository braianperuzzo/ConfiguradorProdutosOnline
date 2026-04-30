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
require_once $baseDir . '/ProcessamentoFilaJobs/CriaFilaProcessamento.php';
require_once $baseDir . '/PaginasSolicitacoes/SuporteCategoria.php';
registrar_verificacao_periodica_fila_jobs($baseDir);

$nome      = strtoupper(trim(filter_input(INPUT_POST, 'nome', FILTER_UNSAFE_RAW) ?? ''));
$empresa   = strtoupper(trim(filter_input(INPUT_POST, 'empresa', FILTER_UNSAFE_RAW) ?? ''));
$emailInput = filter_input(INPUT_POST, 'email', FILTER_UNSAFE_RAW) ?? '';
$emailParts = array_filter(array_map('trim', preg_split('/[;\s,]+/', $emailInput)));
$email     = strtoupper(implode(';', $emailParts));
$telefone  = trim(filter_input(INPUT_POST, 'telefone', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '');
$quantidade= trim(filter_input(INPUT_POST, 'quantidade', FILTER_SANITIZE_NUMBER_INT) ?? '');
$observacao= strtoupper(trim(filter_input(INPUT_POST, 'observacao', FILTER_UNSAFE_RAW) ?? ''));
$cnpj      = preg_replace('/[^0-9A-Za-z]/', '', filter_input(INPUT_POST, 'cnpj', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '');
$cdProduto = strtoupper(trim(filter_input(INPUT_POST, 'cd_produto', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? ''));
$referencia= strtoupper(trim(filter_input(INPUT_POST, 'referencia_produto', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? ''));
$link      = trim(filter_input(INPUT_POST, 'link', FILTER_SANITIZE_URL) ?? '');

if (!check_rate_limit('solicitar_cotacao', 10, 3600, $email)) {
    http_response_code(429);
    echo '⚠️ Limite Máximo de Solicitações Excedido. Tente novamente em 1 hora.';
    exit;
}

if (!$nome || !$email || !$cnpj || !$quantidade) {
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

$linhasNota = [
    "Referência: $referencia",
    "Código: " . ($cdProduto ?: ''),
    "Quantidade: $quantidade",
    "Nome: $nome",
    (strlen($cnpj) === 11 ? 'CPF' : 'CNPJ') . ": $cnpj"
];
if (strlen($cnpj) === 14) $linhasNota[] = "Empresa: $empresa";
$linhasNota[] = "Email: $email";
if ($telefone) $linhasNota[] = "Telefone: $telefone";
if ($observacao) $linhasNota[] = "Observação: $observacao";

$textoClassificacao = trim("cotacao orcamento preco $observacao referencia $referencia");
$classificacao = classificar_categoria_suporte($textoClassificacao, 'cobranca');
$categoriaSugerida = $classificacao['categoria'];
$filaSugerida = obter_fila_suporte($categoriaSugerida);
$rotuloCategoria = obter_rotulo_categoria_suporte($categoriaSugerida);
$linhasNota[] = "Categoria sugerida: {$rotuloCategoria} (Fila: {$filaSugerida})";

$contatos = array_map(function ($mail) use ($nome) {
    return ['nome' => $nome, 'email' => strtolower($mail)];
}, $emailParts);

$historicos = [
    [
        'tipo'       => 'cotacao_produto',
        'host'       => $_SERVER['HTTP_HOST'] ?? '',
        'referencia' => $referencia,
        'emails'     => array_map('strtolower', $emailParts),
        'cnpj'       => $cnpj,
        'link'       => $link,
    ],
];

$historicoId = registrar_historico_solicitacao_suporte($baseDir, [
    'tipo' => 'cotacao_produto',
    'categoriaSugerida' => $categoriaSugerida,
    'filaSugerida' => $filaSugerida,
    'confianca' => $classificacao['confianca'],
    'solicitante' => [
        'nome' => $nome,
        'email' => $email,
        'documento' => $cnpj,
    ],
    'referencia' => $referencia,
    'codigo' => $cdProduto,
    'observacao' => $observacao,
    'origem' => 'SolicitacaoCotacao.php',
]);

$deal = [
    'pipeline_id'  => 90783,
    'stage_id'     => $stageId,
    'title'        => $titulo,
    'created_at'   => $created,
    'tags'         => [['id' => 359705], ['id' => 362614]],
    'company_cnpj' => $cnpj,
];
$deal = aplicar_configuracao_fila_suporte($deal, $categoriaSugerida, $baseDir);

registrar_job_fila('piperun_solicitacao', [
    'deal' => $deal,
    'linhasNota' => $linhasNota,
    'contatos'   => $contatos,
    'historicos' => $historicos,
    'categoria_suporte' => [
        'sugerida' => $categoriaSugerida,
        'fila' => $filaSugerida,
        'confianca' => $classificacao['confianca'],
        'rotulo' => $rotuloCategoria,
    ],
    'historico_suporte_id' => $historicoId,
], $baseDir);

echo '✅ Solicitação enviada! Em breve nossos Consultores Comerciais entrarão em contato com a proposta.';
?>
