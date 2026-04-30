<?php
header('Content-Type: application/json; charset=UTF-8');
$baseDir = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
if ($baseDir === '') {
    $baseDir = dirname(__DIR__);
}
require $baseDir . '/Seguranca/MetodosSegurancao.php';
require $baseDir . '/AcessosConsultas/CredenciaisBancoDados.php';

$cdProduto = trim($_GET['cd_produto'] ?? '');
$cdProdconfigParam = trim($_GET['cd_prodconfig'] ?? '');
$link = trim($_GET['link'] ?? '');

if ($link === '') {
    http_response_code(400);
    echo json_encode(['erro' => 'Informe link com parâmetros.']);
    exit;
}

function extrair_cd_prodconfig_link(string $link, string $baseDir): string
{
    $link = trim($link);
    if ($link === '') {
        return '';
    }
    $parsed = parse_url($link);
    $path = $parsed['path'] ?? '';
    $arquivo = $path !== '' ? basename($path) : '';
    if ($arquivo !== '') {
        if (preg_match('/SeletoresConfigurador([A-Za-z0-9]+)(?:\.html?)?$/i', $arquivo, $match)) {
            return strtoupper(trim((string) $match[1]));
        }
        if (preg_match('/Configurador([A-Za-z0-9]+)(?:\.html?)?$/i', $arquivo, $match)) {
            return strtoupper(trim((string) $match[1]));
        }
    }
    parse_str($parsed['query'] ?? '', $params);
    foreach ($params as $chave => $valor) {
        if (strtolower((string) $chave) === 'cd_prodconfig') {
            return strtoupper(trim((string) $valor));
        }
    }

    $dirSeletorBase = rtrim($baseDir, '/') . '/PaginasConfiguradoresSeletoresConsultas';
    if (!is_dir($dirSeletorBase)) {
        return '';
    }

    $chaves = [];
    foreach ($params as $chave => $valor) {
        $upper = strtoupper(trim((string) $chave));
        if ($upper === '' || !preg_match('/^[A-Z0-9]{2,}$/', $upper)) {
            continue;
        }
        $chaves[] = $upper;
    }

    foreach ($chaves as $chave) {
        if (strlen($chave) < 4) {
            continue;
        }
        $prefixo4 = substr($chave, 0, 4);
        if (is_dir($dirSeletorBase . '/' . $prefixo4)) {
            return $prefixo4;
        }
    }

    foreach ($chaves as $chave) {
        if (strlen($chave) < 2) {
            continue;
        }
        $prefixo2 = substr($chave, 0, 2);
        if (is_dir($dirSeletorBase . '/' . $prefixo2)) {
            return $prefixo2;
        }
    }
    return '';
}

$cdProdconfig = $cdProdconfigParam !== '' ? strtoupper($cdProdconfigParam) : '';
if ($cdProduto === '') {
    $cdProduto = extrair_cd_prodconfig_link($link, $baseDir);
}

if ($cdProduto === '' && $cdProdconfig === '') {
    http_response_code(400);
    echo json_encode(['erro' => 'Informe cd_produto, cd_prodconfig ou link com parâmetros válidos.']);
    exit;
}

try {
    $pdo = new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    log_event('Erro ao conectar no banco.', [
        'erro' => $e->getMessage(),
        'origem' => 'BuscarRecomendacoesAFAS',
    ]);
    echo json_encode(['erro' => 'Erro ao conectar no banco.']);
    exit;
}

if ($cdProdconfig === '' && $cdProduto !== '') {
    try {
        $stmt = $pdo->prepare('SELECT DISTINCT CD_PRODCONFIG FROM MMPR_PRODUTO WHERE CD_PRODUTO = ?');
        $stmt->execute([$cdProduto]);
        $cdProdconfig = trim((string) ($stmt->fetchColumn() ?: ''));
    } catch (PDOException $e) {
        http_response_code(500);
        log_event('Erro ao buscar CD_PRODCONFIG.', [
            'erro' => $e->getMessage(),
            'cd_produto' => $cdProduto,
        ]);
        echo json_encode(['erro' => 'Erro ao buscar CD_PRODCONFIG.']);
        exit;
    }
}

if ($cdProdconfig === '') {
    $cdProdconfig = extrair_cd_prodconfig_link($link, $baseDir);
}

if ($cdProdconfig === '' && $cdProduto !== '' && preg_match('/^[A-Za-z0-9]{2,6}$/', $cdProduto)) {
    $cdProdconfig = strtoupper($cdProduto);
}

if ($cdProdconfig === '') {
    http_response_code(404);
    echo json_encode(['erro' => 'CD_PRODCONFIG não encontrado para o produto informado.']);
    exit;
}

$parsedUrl = parse_url($link);
parse_str($parsedUrl['query'] ?? '', $rawParams);
$parametros = [];
$parametrosMocp = [];
foreach ($rawParams as $chave => $valor) {
    $upper = strtoupper((string) $chave);
    if ($upper !== $chave) {
        $parametrosMocp[$upper] = $valor;
    }
    $parametrosMocp[$chave] = $valor;
    if (str_starts_with($upper, strtoupper($cdProdconfig))) {
        $parametros[$upper] = $valor;
    }
}

function ler_opcoes_seletor(string $filePath, array $params): array
{
    if (!is_file($filePath)) {
        return [];
    }
    $originalGet = $_GET;
    $_GET = $params;
    ob_start();
    include $filePath;
    $html = ob_get_clean();
    $_GET = $originalGet;

    if ($html === '') {
        return [];
    }
    if (function_exists('mb_check_encoding')) {
        if (!mb_check_encoding($html, 'UTF-8')) {
            $html = mb_convert_encoding($html, 'UTF-8', 'ISO-8859-1');
        }
    } else {
        $html = utf8_encode($html);
    }

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><select>' . $html . '</select>');
    libxml_clear_errors();

    $opcoes = [];
    foreach ($doc->getElementsByTagName('option') as $option) {
        $valor = trim((string) $option->getAttribute('value'));
        $descricao = trim((string) $option->textContent);
        if ($valor === '') {
            continue;
        }
        $opcoes[] = [
            'valor' => $valor,
            'descricao' => $descricao
        ];
    }
    return $opcoes;
}

$dirSeletor = $baseDir . '/PaginasConfiguradoresSeletoresConsultas/' . $cdProdconfig;
$arquivoAf = $dirSeletor . '/' . $cdProdconfig . 'AF.php';
$arquivoAs = $dirSeletor . '/' . $cdProdconfig . 'AS.php';
$arquivoMocp = $dirSeletor . '/MOCP.php';
if (!is_file($arquivoMocp)) {
    $arquivoMocp = $dirSeletor . '/' . $cdProdconfig . 'MOCP.php';
}

$opcoesAf = ler_opcoes_seletor($arquivoAf, $parametros);
$opcoesAs = ler_opcoes_seletor($arquivoAs, $parametros);
$opcoesMocp = ler_opcoes_seletor($arquivoMocp, $parametrosMocp);

$opcoesMocpFiltradas = array_values(array_filter($opcoesMocp, function ($opcao) {
    $valor = strtoupper((string) ($opcao['valor'] ?? ''));
    return in_array($valor, ['B34', 'B35'], true);
}));

$filtrarOpcoes = function (array $opcoes, string $tipo): array {
    $limpas = [];
    foreach ($opcoes as $opcao) {
        $valor = strtoupper((string) ($opcao['valor'] ?? ''));
        if ($valor === 'N') {
            continue;
        }
        $limpas[] = [
            'tipo' => $tipo,
            'valor' => $opcao['valor'] ?? '',
            'descricao' => $opcao['descricao'] ?? ''
        ];
    }
    return $limpas;
};

$sugestoes = array_merge(
    $filtrarOpcoes($opcoesAf, 'AF'),
    $filtrarOpcoes($opcoesAs, 'AS')
);
$sugestoes = array_slice($sugestoes, 0, 5);

$response = [
    'cd_produto' => $cdProduto,
    'cd_prodconfig' => $cdProdconfig,
    'parametros' => $parametros,
    'sugestoes' => $sugestoes
];

if (is_file($arquivoAf)) {
    $response['opcoes_af'] = $opcoesAf;
}
if (is_file($arquivoAs)) {
    $response['opcoes_as'] = $opcoesAs;
}
if (is_file($arquivoMocp) && $opcoesMocpFiltradas !== []) {
    $response['opcoes_mocp'] = $opcoesMocpFiltradas;
}

echo json_encode($response);
?>
