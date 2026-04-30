<?php

declare(strict_types=1);

if (!function_exists('mb_strtolower')) {
    function mb_strtolower($string, $encoding = null)
    {
        return strtolower((string) $string);
    }
}

function obter_categorias_suporte(): array
{
    return [
        'cobranca' => [
            'rotulo' => 'Cobrança',
            'fila' => 'Financeiro',
            'exemplos' => [
                'segunda via boleto cobrança vencimento atraso pagamento',
                'nota fiscal faturamento duplicata prazo financeiro',
                'condição de pagamento juros multa parcela',
                'cobrança de pedido desconto alteração de valor',
                'fatura pendente confirmação de pagamento',
            ],
        ],
        'tecnico' => [
            'rotulo' => 'Técnico',
            'fila' => 'Engenharia',
            'exemplos' => [
                'desenho técnico especificação motor redutor torque',
                'ajuste dimensional aplicação projeto engenharia',
                'problema técnico vibração ruído manutenção',
                'compatibilidade eixo flange manual desenho',
                'suporte técnico configuração produto',
            ],
        ],
        'cadastro' => [
            'rotulo' => 'Cadastro',
            'fila' => 'Cadastros',
            'exemplos' => [
                'solicitação de cadastro de produto inclusão no sistema',
                'cadastro de referência novo produto atualização',
                'incluir item no configurador liberar cadastro',
                'dados cadastrais atualizar cadastro',
                'criar referência para produto novo',
            ],
        ],
    ];
}

function normalizar_texto_suporte(string $texto): string
{
    $texto = trim($texto);
    if ($texto === '') {
        return '';
    }
    $texto = mb_strtolower($texto, 'UTF-8');
    $texto = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $texto);
    $texto = preg_replace('/\s+/', ' ', $texto);
    $textoTranslit = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);
    if ($textoTranslit !== false) {
        $texto = $textoTranslit;
    }
    $texto = preg_replace('/[^a-z0-9\s]+/i', ' ', (string) $texto);
    $texto = preg_replace('/\s+/', ' ', (string) $texto);
    return trim((string) $texto);
}

function tokenizar_texto_suporte(string $texto): array
{
    $texto = normalizar_texto_suporte($texto);
    if ($texto === '') {
        return [];
    }
    $tokens = preg_split('/\s+/', $texto);
    $tokensFiltrados = [];
    foreach ($tokens as $token) {
        $token = trim((string) $token);
        if ($token === '' || strlen($token) < 3) {
            continue;
        }
        $tokensFiltrados[] = $token;
    }
    return $tokensFiltrados;
}

function treinar_classificador_suporte(): array
{
    $categorias = obter_categorias_suporte();
    $modelo = [
        'categorias' => [],
        'vocabulario' => [],
    ];

    foreach ($categorias as $chave => $dados) {
        $palavras = [];
        $total = 0;
        foreach ($dados['exemplos'] as $exemplo) {
            $tokens = tokenizar_texto_suporte($exemplo);
            foreach ($tokens as $token) {
                if (!isset($palavras[$token])) {
                    $palavras[$token] = 0;
                }
                $palavras[$token]++;
                $total++;
                $modelo['vocabulario'][$token] = true;
            }
        }
        $modelo['categorias'][$chave] = [
            'total' => $total,
            'palavras' => $palavras,
        ];
    }

    return $modelo;
}

function classificar_categoria_suporte(string $texto, string $fallback = 'cadastro'): array
{
    $tokens = tokenizar_texto_suporte($texto);
    $modelo = treinar_classificador_suporte();
    $vocabulario = $modelo['vocabulario'];
    $categorias = $modelo['categorias'];

    if (!$tokens) {
        return [
            'categoria' => $fallback,
            'confianca' => 0.34,
            'scores' => [],
        ];
    }

    $totalCategorias = count($categorias);
    $scoresLog = [];
    $alpha = 1.0;
    foreach ($categorias as $categoria => $info) {
        $totalPalavras = (int) $info['total'];
        $palavras = $info['palavras'];
        $vocabTamanho = count($vocabulario);
        $logProb = log(1 / max(1, $totalCategorias));

        foreach ($tokens as $token) {
            $contagem = $palavras[$token] ?? 0;
            $probToken = ($contagem + $alpha) / ($totalPalavras + $alpha * $vocabTamanho);
            $logProb += log($probToken);
        }
        $scoresLog[$categoria] = $logProb;
    }

    $maxLog = max($scoresLog);
    $expScores = [];
    $soma = 0.0;
    foreach ($scoresLog as $categoria => $logScore) {
        $exp = exp($logScore - $maxLog);
        $expScores[$categoria] = $exp;
        $soma += $exp;
    }

    $scores = [];
    foreach ($expScores as $categoria => $exp) {
        $scores[$categoria] = $soma > 0 ? $exp / $soma : 0.0;
    }

    arsort($scores);
    $categoriaSugerida = key($scores) ?: $fallback;
    $confianca = $scores[$categoriaSugerida] ?? 0.0;

    return [
        'categoria' => $categoriaSugerida,
        'confianca' => $confianca,
        'scores' => $scores,
    ];
}

function obter_fila_suporte(string $categoria): string
{
    $categorias = obter_categorias_suporte();
    if (isset($categorias[$categoria]['fila'])) {
        return (string) $categorias[$categoria]['fila'];
    }
    return 'Triagem';
}

function obter_rotulo_categoria_suporte(string $categoria): string
{
    $categorias = obter_categorias_suporte();
    if (isset($categorias[$categoria]['rotulo'])) {
        return (string) $categorias[$categoria]['rotulo'];
    }
    return ucfirst($categoria);
}

function obter_caminho_historico_suporte(string $baseDir): string
{
    $dir = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'Tokens' . DIRECTORY_SEPARATOR . 'HistoricoSolicitacoes';
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    return $dir . DIRECTORY_SEPARATOR . 'solicitacoes.json';
}

function ler_historico_suporte(string $baseDir): array
{
    $arquivo = obter_caminho_historico_suporte($baseDir);
    if (!is_file($arquivo)) {
        return [];
    }
    $json = file_get_contents($arquivo);
    $dados = json_decode((string) $json, true);
    return is_array($dados) ? $dados : [];
}

function salvar_historico_suporte(string $baseDir, array $historico): void
{
    $arquivo = obter_caminho_historico_suporte($baseDir);
    $json = json_encode($historico, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    file_put_contents($arquivo, $json, LOCK_EX);
    chmod($arquivo, 0600);
}

function registrar_historico_solicitacao_suporte(string $baseDir, array $entrada): string
{
    $historico = ler_historico_suporte($baseDir);
    $id = uniqid('suporte_', true);
    $entrada['id'] = $id;
    $entrada['criadoEm'] = $entrada['criadoEm'] ?? date(DATE_ATOM);
    $entrada['status'] = $entrada['status'] ?? 'pendente';
    $historico[] = $entrada;
    if (count($historico) > 500) {
        $historico = array_slice($historico, -500);
    }
    salvar_historico_suporte($baseDir, $historico);
    return $id;
}

function atualizar_historico_suporte(string $baseDir, string $id, array $alteracoes): bool
{
    $historico = ler_historico_suporte($baseDir);
    $alterado = false;
    foreach ($historico as &$item) {
        if (($item['id'] ?? '') !== $id) {
            continue;
        }
        foreach ($alteracoes as $chave => $valor) {
            $item[$chave] = $valor;
        }
        $alterado = true;
        break;
    }
    unset($item);
    if ($alterado) {
        salvar_historico_suporte($baseDir, $historico);
    }
    return $alterado;
}

function aplicar_configuracao_fila_suporte(array $deal, string $categoria, string $baseDir): array
{
    $arquivoConfig = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'Configuracoes' . DIRECTORY_SEPARATOR . 'SuporteSolicitacoes.json';
    if (!is_file($arquivoConfig)) {
        return $deal;
    }
    $config = json_decode((string) file_get_contents($arquivoConfig), true);
    if (!is_array($config)) {
        return $deal;
    }
    $categorias = $config['categorias'] ?? [];
    if (!isset($categorias[$categoria]) || !is_array($categorias[$categoria])) {
        return $deal;
    }
    $mapa = $categorias[$categoria];
    if (!empty($mapa['pipeline_id'])) {
        $deal['pipeline_id'] = (int) $mapa['pipeline_id'];
    }
    if (!empty($mapa['stage_id'])) {
        $deal['stage_id'] = (int) $mapa['stage_id'];
    }
    if (!empty($mapa['tags']) && is_array($mapa['tags'])) {
        $deal['tags'] = array_values(array_merge($deal['tags'] ?? [], $mapa['tags']));
    }
    if (!empty($mapa['titulo_prefixo'])) {
        $prefixo = (string) $mapa['titulo_prefixo'];
        $deal['title'] = $prefixo . ($deal['title'] ?? '');
    }
    return $deal;
}
