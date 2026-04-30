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
require_once $baseDir . '/AcessosConsultas/CredenciaisBancoDados.php';
require_once $baseDir . '/ProcessamentoFilaJobs/CriaFilaProcessamento.php';
require_once $baseDir . '/PaginasSolicitacoes/SuporteCategoria.php';
registrar_verificacao_periodica_fila_jobs($baseDir);

$nome       = strtoupper(trim(filter_input(INPUT_POST, 'nome', FILTER_UNSAFE_RAW) ?? ''));
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
$referencia = strtoupper(trim(filter_input(INPUT_POST, 'referencia_produto', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? ''));
$link       = trim(filter_input(INPUT_POST, 'link', FILTER_SANITIZE_URL) ?? '');
$documento  = preg_replace('/[^0-9A-Za-z]/', '', (string) (filter_input(INPUT_POST, 'documento', FILTER_UNSAFE_RAW) ?? ''));
$documento  = $documento === '' ? null : $documento;

if (!check_rate_limit('solicitar_cadastro', 10, 3600, $email)) {
    http_response_code(429);
    echo '⚠️ Limite Máximo de Solicitações Excedido. Tente novamente em 1 hora.';
    exit;
}

if ($link && (!filter_var($link, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $link))) {
    echo '⚠️ Link invalido.';
    exit;
}

if (!$nome || empty($emailParts) || !$referencia) {
    echo '⚠️ Dados Incompletos.';
    exit;
}

$agora      = new DateTime('now');
$created    = $agora->format('Y-m-d H:i:s');
$dataTitulo = $agora->format('d/m/Y H:i');

try {
    $pdo = new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
    ]);

        $contatos = array_map(function ($mail) use ($nome) {
        return ['nome' => $nome, 'email' => strtolower($mail)];
    }, $emailParts);

    $textoClassificacao = trim("cadastro produto referencia $referencia link $link");
    $classificacao = classificar_categoria_suporte($textoClassificacao, 'cadastro');
    $categoriaSugerida = $classificacao['categoria'];
    $filaSugerida = obter_fila_suporte($categoriaSugerida);
    $rotuloCategoria = obter_rotulo_categoria_suporte($categoriaSugerida);
    $historicoId = registrar_historico_solicitacao_suporte($baseDir, [
        'tipo' => 'cadastro_produto',
        'categoriaSugerida' => $categoriaSugerida,
        'filaSugerida' => $filaSugerida,
        'confianca' => $classificacao['confianca'],
        'solicitante' => [
            'nome' => $nome,
            'email' => $email,
            'documento' => $documento,
        ],
        'referencia' => $referencia,
        'link' => $link,
        'origem' => 'SolicitacaoCadastroProduto.php',
    ]);

    $deal = [
        'pipeline_id'   => 96591,
        'stage_id'      => 617200,
        'title'         => "Solicitação de Produto Configurador - Cadastro - $referencia - $email - $nome - $dataTitulo",
        'tags'          => [['id' => 359705], ['id' => 344438], ['id' => 362615]],
        'created_at'    => $created,
        'custom_fields' => [
            ['id' => 259977, 'value' => $link],
            ['id' => 259975, 'value' => $nome],
            ['id' => 259976, 'value' => $referencia]
        ],
    ];
    $deal = aplicar_configuracao_fila_suporte($deal, $categoriaSugerida, $baseDir);

    registrar_job_fila('piperun_solicitacao', [
        'deal' => $deal,
        'linhasNota' => [
            "Nome: $nome",
            "Email: $email",
            "Referência: $referencia",
            "Categoria sugerida: {$rotuloCategoria} (Fila: {$filaSugerida})",
        ],
        'contatos'   => $contatos,
        'historicos' => [
            [
                'tipo'       => 'cadastro_produto',
                'documento'  => $documento,
                'referencia' => $referencia,
                'emails'     => $emailParts,
                'link'       => $link,
            ],
        ],
        'categoria_suporte' => [
            'sugerida' => $categoriaSugerida,
            'fila' => $filaSugerida,
            'confianca' => $classificacao['confianca'],
            'rotulo' => $rotuloCategoria,
        ],
        'historico_suporte_id' => $historicoId,
    ], $baseDir);

    echo '✅ Solicitação enviada! Em breve nossos Consultores Comerciais entrarão em contato.';
    $pdo = null;


} catch (Exception $e) {
    log_event('Erro em SolicitacaoCadastroProduto: ' . $e->getMessage());
    http_response_code(500);
    echo '⚠️ Erro ao enviar solicitação.';
}
?>
