<?php
ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json; charset=UTF-8');

$baseDir = $_SERVER['DOCUMENT_ROOT'] ?: dirname(__DIR__);
require $baseDir . '/Seguranca/MetodosSegurancao.php';
require_once $baseDir . '/LogsErros/Logs.php';
require_once $baseDir . '/TokensGeradores/TokenPadraoJson.php';
require_once $baseDir . '/TokensGeradores/TokenInvalidacao.php';
require_once $baseDir . '/TokensGeradores/TokensLimiteSolicitacoes.php';
require_once $baseDir . '/Seguranca/CSRF.php';
require_once $baseDir . '/ProcessamentoFilaJobs/CriaFilaProcessamento.php';

registrar_verificacao_periodica_fila_jobs($baseDir);
require_valid_csrf_token();

$token = $_COOKIE['auth_token'] ?? '';
if ($token === '') {
    http_response_code(401);
    echo json_encode(['sucesso' => false, 'erro' => '⚠️ Usuário não autenticado.']);
    exit;
}

$segredo = getenv('JWT_SECRET');
if ($segredo === false) {
    $segredo = trim(file_get_contents($baseDir . '/Configuracoes/Segredo.jwt'));
}

$dadosToken = JWTHelper::decode($token, $segredo);
if (!$dadosToken || is_token_blacklisted($token)) {
    http_response_code(403);
    echo json_encode(['sucesso' => false, 'erro' => '⚠️ Credenciais inválidas.']);
    exit;
}

$emailSolicitanteToken = strtolower(trim((string) ($dadosToken['email'] ?? '')));
if ($emailSolicitanteToken === '' || !check_rate_limit('solicitacao_permissao', 30, 3600, $emailSolicitanteToken)) {
    http_response_code(429);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Limite de solicitações atingido. Tente novamente em 1 hora.']);
    exit;
}

$entradaBruta = file_get_contents('php://input') ?: '';
$entrada = json_decode($entradaBruta, true);

$solicitante = is_array($entrada['solicitante'] ?? null) ? $entrada['solicitante'] : [];
$solicitacoes = is_array($entrada['solicitacoes'] ?? null) ? $entrada['solicitacoes'] : [];

if ($solicitacoes === []) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Nenhuma solicitação informada.']);
    exit;
}

$nomeSolicitante = trim((string) ($solicitante['nome'] ?? ''));
$emailSolicitante = strtolower(trim((string) ($solicitante['email'] ?? '')));
if ($emailSolicitante === '') {
    $emailSolicitante = $emailSolicitanteToken;
}
if ($nomeSolicitante === '') {
    $nomeSolicitante = 'Usuário da Área do Cliente';
}
$cnpjSolicitante = preg_replace('/[^0-9A-Za-z]/', '', (string) ($solicitante['cnpj'] ?? ''));
$codigoSolicitante = trim((string) ($solicitante['codigoCliente'] ?? ''));
$empresaSolicitante = trim((string) ($solicitante['empresa'] ?? ''));

if (!filter_var($emailSolicitante, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ E-mail do solicitante inválido ou não informado.']);
    exit;
}

$cnpjSolicitanteValido = strlen($cnpjSolicitante) === 14 ? $cnpjSolicitante : '';

if ($emailSolicitanteToken !== '' && $emailSolicitante !== $emailSolicitanteToken) {
    $emailSolicitante = $emailSolicitanteToken;
}

$dataTitulo = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('d/m/Y H:i');
$jobsCriados = 0;

foreach ($solicitacoes as $item) {
    if (!is_array($item)) {
        continue;
    }

    $emailDestinoOriginal = strtolower(trim((string) ($item['email'] ?? '')));
    $emailDestino = filter_var($emailDestinoOriginal, FILTER_VALIDATE_EMAIL) ? $emailDestinoOriginal : $emailSolicitante;
    $area = trim((string) ($item['area'] ?? ''));
    if ($area === '') {
        $area = 'NÃO INFORMADA';
    }
    $liberacoes = is_array($item['liberacoes'] ?? null) ? $item['liberacoes'] : [];
    $observacoes = trim((string) ($item['observacoes'] ?? ''));
    $permissoesAtuais = trim((string) ($item['permissoesAtuais'] ?? ''));
    $grupoRecomendado = trim((string) ($item['grupoRecomendado'] ?? ''));
    $nivelRecomendado = trim((string) ($item['nivelRecomendado'] ?? ''));
    $codigoDestino = trim((string) ($item['codigoCliente'] ?? $codigoSolicitante));

    $liberacoes = array_values(array_filter(array_map(static function ($valor): string {
        return trim((string) $valor);
    }, $liberacoes), static fn(string $valor): bool => $valor !== ''));

    if (!filter_var($emailDestino, FILTER_VALIDATE_EMAIL)) {
        continue;
    }

    $titulo = sprintf('Permissões - %s - %s - %s', $emailDestino, $empresaSolicitante !== '' ? $empresaSolicitante : 'Empresa não informada', $dataTitulo);

    $linhasNota = [
        'Para quem foi Solicitado:',
        'Email: ' . $emailDestino,
        'CNPJ: ' . ($cnpjSolicitanteValido !== '' ? $cnpjSolicitanteValido : 'NÃO INFORMADO'),
        'Código do Cliente no Plenus: ' . ($codigoDestino !== '' ? $codigoDestino : 'NÃO INFORMADO'),
        'Permissões Atuais : ' . ($permissoesAtuais !== '' ? $permissoesAtuais : 'NÃO INFORMADO'),
        '',
        '--',
        '',
        'Liberações Solicitadas:',
        'Área: ' . $area,
        'Desejada: ' . ($liberacoes !== [] ? implode(', ', $liberacoes) : 'Sem liberações adicionais'),
        '',
        '--',
        '',
        'Grupo Recomendado para Liberação: ' . ($grupoRecomendado !== '' ? $grupoRecomendado : 'NÃO IDENTIFICADO'),
        'Nível Recomendado para Liberação: ' . ($nivelRecomendado !== '' ? $nivelRecomendado : 'NÃO IDENTIFICADO'),
    ];

    if ($observacoes !== '') {
        $linhasNota[] = 'Observações: ' . $observacoes;
    }

    $linhasNota = array_merge($linhasNota, [
        '',
        '--',
        '',
        'Solicitado por:',
        'Nome: ' . $nomeSolicitante,
        'Email: ' . $emailSolicitante,
        'CNPJ: ' . ($cnpjSolicitanteValido !== '' ? $cnpjSolicitanteValido : 'NÃO INFORMADO'),
        'Código do Cliente no Plenus: ' . ($codigoSolicitante !== '' ? $codigoSolicitante : 'NÃO INFORMADO'),
        '',
        '<em>Leu e concordo com a Política de Privacidade, os Termos de Uso e de Serviço, a Política de Coleta de Dados e a Política de Cookies. Também, se responsabilizou pelas liberações realizadas em seu nome.</em>',
    ]);

    $contatos = [
        ['nome' => $nomeSolicitante, 'email' => $emailSolicitante],
    ];
    if ($emailDestino !== $emailSolicitante) {
        $contatos[] = ['nome' => $emailDestino, 'email' => $emailDestino];
    }

    registrar_job_fila('piperun_solicitacao', [
        'deal' => [
            'pipeline_id' => 97062,
            'stage_id' => 620622,
            'title' => $titulo,
            'tags' => [['id' => 359705], ['id' => 375008], ['id' => 376067]],
            'company_cnpj' => $cnpjSolicitanteValido,
        ],
        'linhasNota' => $linhasNota,
        'contatos' => $contatos,
    ], $baseDir);

    $jobsCriados++;
}

if ($jobsCriados === 0) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => '⚠️ Nenhuma solicitação válida para envio.']);
    exit;
}

echo json_encode([
    'sucesso' => true,
    'mensagem' => '✅ Solicitação enviada! Em breve nossos Consultores Comerciais entrarão em contato.',
    'enviadas' => $jobsCriados,
]);
