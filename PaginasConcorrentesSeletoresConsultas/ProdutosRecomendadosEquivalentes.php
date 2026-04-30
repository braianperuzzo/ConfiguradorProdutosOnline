<?php
header('Content-Type: application/json; charset=UTF-8');
$baseDir = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
if ($baseDir === '') {
    $baseDir = dirname(__DIR__, 2);
}

require $baseDir . '/Seguranca/MetodosSegurancao.php';
require $baseDir . '/AcessosConsultas/CredenciaisBancoDados.php';
require_once $baseDir . '/LogsErros/Logs.php';

$grupo = isset($_GET['grupo']) ? strtoupper(trim((string) $_GET['grupo'])) : '';
$linha = isset($_GET['linha']) ? strtoupper(trim((string) $_GET['linha'])) : '';
$base = isset($_GET['base']) ? strtoupper(trim((string) $_GET['base'])) : '';
$reducao = isset($_GET['reducao']) ? strtoupper(trim((string) $_GET['reducao'])) : '';
$grupo = preg_replace('/[^A-Z0-9]/', '', $grupo);
$linha = preg_replace('/[^A-Z0-9\.\-]/', '', $linha);
$normalizarListaValores = static function (string $valor): array {
    $itens = preg_split('/\s*[,\/]\s*/', strtoupper($valor)) ?: [];
    $itens = array_map('trim', $itens);
    $itens = array_filter($itens, static function (string $item): bool {
        return $item !== '';
    });

    $itensSanitizados = [];
    foreach ($itens as $item) {
        $itemSanitizado = preg_replace('/[^A-Z0-9\.\-]/', '', $item);
        if ($itemSanitizado !== '') {
            $itensSanitizados[] = $itemSanitizado;
        }
    }

    return array_values(array_unique($itensSanitizados));
};

$bases = $normalizarListaValores($base);
$reducoes = $normalizarListaValores($reducao);

$controle = [
    'entrada' => [
        'grupo' => $grupo,
        'linha' => $linha,
        'base' => $base,
        'reducao' => $reducao,
    ],
    'filtrosNormalizados' => [
        'bases' => $bases,
        'reducoes' => $reducoes,
    ],
    'status' => 'inicio',
];

if ($grupo === '' || $linha === '' || empty($bases) || empty($reducoes)) {
    $controle['status'] = 'sem_parametros_obrigatorios';
    $controle['motivo'] = 'Parâmetros obrigatórios ausentes para consulta (grupo, linha, base e redução).';
    echo json_encode([
        'resultados' => [],
        'controle' => $deveRetornarControle ? $controle : null
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$nmVariavelLinha = $grupo . 'LN';
$nmVariavelBase = $grupo . 'BR';
$nmVariavelReducao = $grupo . 'RD';

try {
    $pdo = new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
    ]);

    $basePlaceholders = implode(', ', array_fill(0, count($bases), '?'));
    $reducaoPlaceholders = implode(', ', array_fill(0, count($reducoes), '?'));

    $sql = "SELECT DISTINCT PRODUTO.CD_PRODUTO, PRODUTO.DS_PRODUTO, PRODUTO.DS_REFERENCIA
FROM MMPR_PRODUTO AS PRODUTO
WHERE PRODUTO.CD_PRODCONFIG = ?
  AND PRODUTO.ID_STATUS = 0
  AND PRODUTO.DS_REFERENCIA NOT LIKE '%2.WS%'
  AND PRODUTO.DS_REFERENCIA NOT LIKE 'MS.%'

  AND EXISTS (SELECT 1
  FROM MMPR_PRODUTOESTRUTURA ESTRUTURA
      WHERE ESTRUTURA.CD_PRODUTO = PRODUTO.CD_PRODUTO
        AND ESTRUTURA.CD_EMPRESA = PRODUTO.CD_EMPRESA
        AND ESTRUTURA.NM_VARIAVEL = ?
        AND ESTRUTURA.DS_VARIAVEL.value('(/VariavelOpcaoSimples/Valor)[1]', 'varchar(4000)') = ?)

  AND EXISTS (SELECT 1
      FROM MMPR_PRODUTOESTRUTURA ESTRUTURA
      WHERE ESTRUTURA.CD_PRODUTO = PRODUTO.CD_PRODUTO
        AND ESTRUTURA.CD_EMPRESA = PRODUTO.CD_EMPRESA
        AND ESTRUTURA.NM_VARIAVEL = ?
        AND ESTRUTURA.DS_VARIAVEL.value('(/VariavelOpcaoSimples/Valor)[1]', 'varchar(4000)') IN ($basePlaceholders))

  AND EXISTS (SELECT 1
      FROM MMPR_PRODUTOESTRUTURA ESTRUTURA
      WHERE ESTRUTURA.CD_PRODUTO = PRODUTO.CD_PRODUTO
        AND ESTRUTURA.CD_EMPRESA = PRODUTO.CD_EMPRESA
        AND ESTRUTURA.NM_VARIAVEL = ?
        AND ESTRUTURA.DS_VARIAVEL.value('(/VariavelOpcaoSimples/Valor)[1]', 'varchar(4000)') IN ($reducaoPlaceholders))
ORDER BY PRODUTO.DS_PRODUTO;";

    $stmt = $pdo->prepare($sql);
    $parametros = array_merge([$grupo, $nmVariavelLinha, $linha, $nmVariavelBase], $bases, [$nmVariavelReducao], $reducoes);
    $stmt->execute($parametros);

    $resultados = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $resultados[] = [
            'CD_PRODUTO' => (string) ($row['CD_PRODUTO'] ?? ''),
            'DS_PRODUTO' => (string) ($row['DS_PRODUTO'] ?? ''),
            'DS_REFERENCIA' => (string) ($row['DS_REFERENCIA'] ?? '')
        ];
    }

    echo json_encode(['resultados' => $resultados], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $erro) {
    if (function_exists('log_event')) {
        log_event('Falha ao consultar produtos equivalentes recomendados: ' . $erro->getMessage());
    }
    http_response_code(500);
    echo json_encode(['resultados' => [], 'erro' => 'Falha ao carregar recomendações.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
