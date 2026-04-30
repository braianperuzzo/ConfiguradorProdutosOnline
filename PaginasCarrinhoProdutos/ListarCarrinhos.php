<?php
header('Content-Type: application/json; charset=UTF-8');
$baseDir = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
if ($baseDir === '') {
    $baseDir = dirname(__DIR__);
}
require $baseDir . '/Seguranca/MetodosSegurancao.php';
require_once $baseDir . '/TokensGeradores/TokenPadraoJson.php';
require $baseDir . '/AcessosConsultas/CredenciaisBancoDados.php';
require_once $baseDir . '/LogsErros/Logs.php';
require_once $baseDir . '/ProcessamentoFilaJobs/CriaFilaProcessamento.php';

registrar_verificacao_periodica_fila_jobs($baseDir);

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
if (!$dadosToken) {
    http_response_code(403);
    echo json_encode(['erro' => '⚠️ Token inválido ou expirado.']);
    exit;
}

$documentoRequest = preg_replace('/[^0-9A-Za-z]/', '', (string) (filter_input(INPUT_GET, 'documento', FILTER_UNSAFE_RAW) ?? ''));
$documento = preg_replace(
    '/[^0-9A-Za-z]/',
    '',
    (string)($dadosToken['empresaDocumento'] ?? $_COOKIE['ibr_empresa_doc'] ?? '')
);
if ($documento === '' && $documentoRequest !== '') {
    $documento = $documentoRequest;
}
if ($documentoRequest !== '' && $documento !== '' && $documentoRequest !== $documento) {
    http_response_code(403);
    echo json_encode(['erro' => '⚠️ Empresa selecionada inválida.']);
    exit;
}
if ($documento === '') {
    http_response_code(400);
    echo json_encode(['erro' => '⚠️ CNPJ ou CPF não encontrado.']);
    exit;
}

$email = strtolower($dadosToken['email'] ?? '');
if (!$email) {
    http_response_code(400);
    echo json_encode(['erro' => '⚠️ E-mail Inválido']);
    exit;
}

function formatarData(?string $data): string {
    if (!$data) return '';
    $data = trim($data);
    if ($data === '') return '';
    if (strpos($data, '/') !== false) {
        $partes = explode(' ', $data, 2);
        $dataParte = $partes[0] ?? '';
        $horaParte = $partes[1] ?? '';
        [$dia, $mes, $ano] = array_pad(array_map('intval', explode('/', $dataParte)), 3, 0);
        [$hora, $min, $seg] = array_pad(array_map('intval', explode(':', $horaParte)), 3, 0);
        if ($dia && $mes && $ano) {
            return sprintf('%02d/%02d/%04d %02d:%02d:%02d', $dia, $mes, $ano, $hora, $min, $seg);
        }
    }
    $ts = strtotime($data);
    if ($ts) {
        return date('d/m/Y H:i:s', $ts);
    }
    return '';
}

try {
    $pdo = new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
    ]);

    $stmt = $pdo->prepare('
        SELECT DS_NOMECARRINHO AS nome,
               DS_COMENTARIO AS comentario,
               DT_DATA AS data,
               DS_LINKCARRINHO AS link
          FROM _USR_CONF_SITE_HISTORICO_CARRFAV
         WHERE DS_EMAIL = ? AND NR_CPFCNPJ = ?
         ORDER BY DT_DATA DESC
    ');
    $stmt->execute([$email, $documento]);

    $carrinhos = [];
    while ($item = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $carrinhos[] = [
            'nome' => $item['nome'] ?? '',
            'comentario' => $item['comentario'] ?? '',
            'data' => formatarData($item['data'] ?? ''),
            'link' => $item['link'] ?? ''
        ];
    }

    echo json_encode(['carrinhos' => $carrinhos]);
} catch (PDOException $e) {
    log_event('ListarCarrinhos: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['erro' => '⚠️ Erro ao listar carrinhos salvos.']);
}