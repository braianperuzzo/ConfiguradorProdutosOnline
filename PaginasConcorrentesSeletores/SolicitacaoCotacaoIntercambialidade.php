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

$nome       = strtoupper(trim(filter_input(INPUT_POST, 'nome', FILTER_UNSAFE_RAW) ?? ''));
$empresa    = strtoupper(trim(filter_input(INPUT_POST, 'empresa', FILTER_UNSAFE_RAW) ?? ''));
$emailInput = filter_input(INPUT_POST, 'email', FILTER_UNSAFE_RAW) ?? '';
$emailParts = array_filter(array_map('trim', preg_split('/[;\s,]+/', $emailInput)));
$email      = strtoupper(implode(';', $emailParts));
$telefone   = trim(filter_input(INPUT_POST, 'telefone', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '');
$quantidade = trim(filter_input(INPUT_POST, 'quantidade', FILTER_SANITIZE_NUMBER_INT) ?? '1');
$observacao = strtoupper(trim(filter_input(INPUT_POST, 'observacao', FILTER_UNSAFE_RAW) ?? ''));
$cnpj       = preg_replace('/[^0-9A-Za-z]/', '', filter_input(INPUT_POST, 'cnpj', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '');
$link       = trim(filter_input(INPUT_POST, 'link', FILTER_SANITIZE_URL) ?? '');
$itemSugerido = trim(filter_input(INPUT_POST, 'item_sugerido', FILTER_UNSAFE_RAW) ?? '');
$itemConcorrente = trim(filter_input(INPUT_POST, 'item_concorrente', FILTER_UNSAFE_RAW) ?? '');
$sugestoesProduto = trim(filter_input(INPUT_POST, 'sugestoes_produto', FILTER_UNSAFE_RAW) ?? '');
$codigoItem = strtoupper(trim(filter_input(INPUT_POST, 'codigo_item', FILTER_UNSAFE_RAW) ?? ''));
$codigoItem = preg_replace('/\s+/', '', $codigoItem);
$codigoItemPartes = array_values(array_filter(array_map(static function ($parte) {
    $valor = trim((string)$parte);
    if ($valor === '' || $valor === '--' || $valor === '?') {
        return '';
    }
    return $valor;
}, explode('.', $codigoItem)), static fn($valor) => $valor !== ''));
$codigoItem = implode('.', $codigoItemPartes);

$itemSugeridoJson = json_decode($itemSugerido, true);
if (!is_array($itemSugeridoJson)) {
    $itemSugeridoJson = [];
}

$itemConcorrenteJson = json_decode($itemConcorrente, true);
if (!is_array($itemConcorrenteJson)) {
    $itemConcorrenteJson = [];
}

$sugestoesProdutoJson = json_decode($sugestoesProduto, true);
if (!is_array($sugestoesProdutoJson)) {
    $sugestoesProdutoJson = [];
}

function valor_nota_intercambiavel($valor, string $padrao = '--'): string
{
    $texto = trim((string)$valor);
    return $texto !== '' ? $texto : $padrao;
}

function quebrar_campos_em_linhas(string $texto): array
{
    $partes = preg_split('/\s*\|\s*/', trim($texto));
    if (!is_array($partes)) {
        return [];
    }

    $linhas = [];
    foreach ($partes as $parte) {
        $linha = trim((string)$parte);
        if ($linha !== '') {
            $linhas[] = $linha;
        }
    }

    return $linhas;
}

if ($quantidade === '' || (int)$quantidade <= 0) {
    $quantidade = '1';
}

$cdProduto = $codigoItem !== '' ? $codigoItem : 'INTERCAMBIALIDADE';
$referencia = $cdProduto;

if (!check_rate_limit('solicitar_cotacao_intercambialidade', 10, 3600, $email)) {
    http_response_code(429);
    echo '⚠️ Limite Máximo de Solicitações Excedido. Tente novamente em 1 hora.';
    exit;
}

if (!$nome || !$email || !$cnpj) {
    echo '⚠️ Dados Incompletos.';
    exit;
}

$agora = new DateTime('now');
$created = $agora->format('Y-m-d H:i:s');
$dataTitulo = $agora->format('d/m/Y H:i');

$nomeEmpresaOuPessoa = strlen($cnpj) === 11 ? $nome : $empresa;
$titulo = "Solicitação de Cotação - $cnpj - $nomeEmpresaOuPessoa - $dataTitulo";

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
    "Origem: Guia de Intercambialidade IBR",
    "Nome: $nome",
    (strlen($cnpj) === 11 ? 'CPF' : 'CNPJ') . ": $cnpj"
];
if (strlen($cnpj) === 14) $linhasNota[] = "Empresa: $empresa";
$linhasNota[] = "Email: $email";
if ($telefone) $linhasNota[] = "Telefone: $telefone";
if ($observacao) $linhasNota[] = "Observação: $observacao";

$linhasNota[] = '--';
$linhasNota[] = 'Item Sugerido: ';
if (!empty($itemSugeridoJson)) {
    $linhasNota[] = 'Linha: ' . valor_nota_intercambiavel($itemSugeridoJson['linha'] ?? '--');
    $linhasNota[] = 'Tamanho: ' . valor_nota_intercambiavel($itemSugeridoJson['tamanho'] ?? '--');
    $linhasNota[] = 'Redução: ' . valor_nota_intercambiavel($itemSugeridoJson['reducao'] ?? '--');
    $linhasNota[] = 'Diâmetro do Eixo de Saída: ' . valor_nota_intercambiavel($itemSugeridoJson['diametro_saida'] ?? '--');
} elseif ($itemSugerido !== '') {
    $linhasItemSugerido = quebrar_campos_em_linhas($itemSugerido);
    if (!empty($linhasItemSugerido)) {
        $linhasNota = array_merge($linhasNota, $linhasItemSugerido);
    } else {
        $linhasNota[] = $itemSugerido;
    }
}

$linhasNota[] = '--';
$linhasNota[] = 'Item Concorrente: ';
if (!empty($itemConcorrenteJson)) {
    $linhasNota[] = 'Marca: ' . valor_nota_intercambiavel($itemConcorrenteJson['marca'] ?? '--');
    $linhasNota[] = 'Linha: ' . valor_nota_intercambiavel($itemConcorrenteJson['linha'] ?? '--');
    $linhasNota[] = 'Tamanho: ' . valor_nota_intercambiavel($itemConcorrenteJson['tamanho'] ?? '--');
    $linhasNota[] = 'Redução: ' . valor_nota_intercambiavel($itemConcorrenteJson['reducao'] ?? '--');
    $linhasNota[] = 'Torque: ' . valor_nota_intercambiavel($itemConcorrenteJson['torque'] ?? '--');
    $saida = trim((string)($itemConcorrenteJson['saida'] ?? ''));
    if ($saida !== '' && $saida !== '--' && $saida !== '⏳') {
        $linhasNota[] = "Saída: $saida";
    }
    $linhasNota[] = 'Critério: ' . valor_nota_intercambiavel($itemConcorrenteJson['criterios'] ?? '--');
} elseif ($itemConcorrente !== '') {
    $linhasItemConcorrente = quebrar_campos_em_linhas($itemConcorrente);
    if (!empty($linhasItemConcorrente)) {
        foreach ($linhasItemConcorrente as $linhaConcorrente) {
            if (stripos($linhaConcorrente, 'Saída:') === 0) {
                $valorSaidaTexto = trim(substr($linhaConcorrente, strlen('Saída:')));
                if ($valorSaidaTexto === '' || $valorSaidaTexto === '--' || $valorSaidaTexto === '⏳') {
                    continue;
                }
            }

            $linhasNota[] = str_ireplace('Critérios de configuração avançada:', 'Critério:', $linhaConcorrente);
        }
    } else {
        $linhasNota[] = $itemConcorrente;
    }
}
$linhasNota[] = "Link da Página: $link";

$linhasNota[] = '--';
$linhasNota[] = 'Sugestões de Produto: ';
if (!empty($sugestoesProdutoJson)) {
    foreach (array_slice($sugestoesProdutoJson, 0, 5) as $indice => $item) {
        if (!is_array($item)) {
            continue;
        }
        $numero = $indice + 1;
        $codigo = trim((string)($item['codigo'] ?? '--'));
        $referenciaItem = trim((string)($item['referencia'] ?? '--'));
        $descricao = trim((string)($item['descricao'] ?? '--'));
        $linhasNota[] = "{$numero}) Código: $codigo";
        $linhasNota[] = "Referência: " . ($referenciaItem !== '' ? $referenciaItem : '--');
        $linhasNota[] = "Descrição: " . ($descricao !== '' ? $descricao : '--');
        if ($indice < min(count($sugestoesProdutoJson), 5) - 1) {
            $linhasNota[] = '';
        }
    }
} elseif ($sugestoesProduto !== '') {
    if (stripos($sugestoesProduto, '[object Object]') === false) {
        $linhasNota[] = $sugestoesProduto;
    } else {
        $linhasNota[] = '--';
    }
}

$textoClassificacao = trim("cotacao intercambialidade orcamento preco $observacao referencia $referencia");
$classificacao = classificar_categoria_suporte($textoClassificacao, 'cobranca');
$categoriaSugerida = $classificacao['categoria'];
$filaSugerida = obter_fila_suporte($categoriaSugerida);
$rotuloCategoria = obter_rotulo_categoria_suporte($categoriaSugerida);

$contatos = array_map(function ($mail) use ($nome) {
    return ['nome' => $nome, 'email' => strtolower($mail)];
}, $emailParts);

$historicos = [[
    'tipo'       => 'cotacao_intercambialidade',
    'host'       => $_SERVER['HTTP_HOST'] ?? '',
    'referencia' => $referencia,
    'emails'     => array_map('strtolower', $emailParts),
    'cnpj'       => $cnpj,
    'link'       => $link,
]];

$historicoId = registrar_historico_solicitacao_suporte($baseDir, [
    'tipo' => 'cotacao_intercambialidade',
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
    'origem' => 'SolicitacaoCotacaoIntercambialidade.php',
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
