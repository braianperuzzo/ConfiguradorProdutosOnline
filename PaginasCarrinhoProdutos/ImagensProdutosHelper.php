<?php
function carregar_manifesto_imagens_produtos(string $baseDir): ?array
{
    static $manifesto = null;
    static $carregado = false;
    if ($carregado) {
        return $manifesto;
    }
    $carregado = true;
    $arquivo = rtrim($baseDir, '/\\') . '/ImagensProdutos/manifest.json';
    if (!is_file($arquivo)) {
        return null;
    }
    $conteudo = file_get_contents($arquivo);
    if ($conteudo === false) {
        return null;
    }
    $dados = json_decode($conteudo, true);
    if (!is_array($dados)) {
        return null;
    }
    $manifesto = $dados;
    return $manifesto;
}

function resolver_imagens_manifesto_produtos(string $folder, ?array $manifesto): array
{
    if (!$folder || !is_array($manifesto)) {
        return ['matched' => false, 'files' => [], 'basePath' => ''];
    }
    $entries = $manifesto['products'] ?? $manifesto['folders'] ?? [];
    if (!is_array($entries) || !isset($entries[$folder])) {
        return ['matched' => false, 'files' => [], 'basePath' => ''];
    }
    $entry = $entries[$folder];
    if (!is_array($entry)) {
        return ['matched' => true, 'files' => [], 'basePath' => ''];
    }
    $basePath = $entry['basePath'] ?? ('/ImagensProdutos/' . $folder . '/');
    $files = [];
    if (isset($entry['files']) && is_array($entry['files'])) {
        foreach ($entry['files'] as $file) {
            if (is_array($file)) {
                $file = $file['url'] ?? $file['path'] ?? $file['file'] ?? '';
            }
            if (!$file) {
                continue;
            }
            if (preg_match('/^https?:\\/\\//i', $file)) {
                $files[] = $file;
                continue;
            }
            if (strpos($file, '/') === 0) {
                $files[] = $file;
                continue;
            }
            $files[] = $basePath . ltrim((string) $file, '/');
        }
    }
    $files = array_values(array_unique(array_filter($files)));
    return ['matched' => true, 'files' => $files, 'basePath' => $basePath];
}

function normalizar_linha_galeria_produto(?string $valor): string
{
    if (!$valor) {
        return '';
    }
    $mapaLinhas = [
        '3APM' => 'IBRAPM',
        '1C' => 'IBRC',
        '1FR' => 'IBRCFR',
        '3GR' => 'IBRGR',
        '3GS' => 'IBRGS',
        '1H' => 'IBRH',
        '1I' => 'IBRI',
        '4K' => 'IBRK',
        '1M' => 'IBRM',
        '2I' => 'IBRMSML',
        '1P' => 'IBRP',
        '3PB' => 'IBRPB',
        '3PBL' => 'IBRPBL',
        '1FFA' => 'IBRPFFA',
        '1Q' => 'IBRQ',
        '1QDR' => 'IBRQDR',
        '1QP' => 'IBRQP',
        '1R' => 'IBRR',
        '3RIC' => 'IBRRIC',
        '3SA' => 'IBRSA',
        '3SB' => 'IBRSB',
        '3SBL' => 'IBRSBL',
        '3SD' => 'IBRSD',
        '3SPM' => 'IBRSPM',
        '3I' => 'IBRT3AT3C',
        '1V' => 'IBRV',
        '1VFN' => 'IBRVFN',
        '1X' => 'IBRX',
        '1FKA' => 'IBRXFKA',
        '3W' => 'WEGT3A',
        '1Z' => 'IBRZ',
    ];
    $aliases = [
        'APM' => 'IBRAPM',
        'C' => 'IBRC',
        'FR' => 'IBRCFR',
        'GR' => 'IBRGR',
        'GS' => 'IBRGS',
        'H' => 'IBRH',
        'I' => 'IBRI',
        'K' => 'IBRK',
        'M' => 'IBRM',
        'MSML' => 'IBRMSML',
        'P' => 'IBRP',
        'PB' => 'IBRPB',
        'PBL' => 'IBRPBL',
        'PFFA' => 'IBRPFFA',
        'Q' => 'IBRQ',
        'QDR' => 'IBRQDR',
        'QP' => 'IBRQP',
        'R' => 'IBRR',
        'RIC' => 'IBRRIC',
        'SA' => 'IBRSA',
        'SB' => 'IBRSB',
        'SBL' => 'IBRSBL',
        'SD' => 'IBRSD',
        'SPM' => 'IBRSPM',
        'V' => 'IBRV',
        'VFN' => 'IBRVFN',
        'X' => 'IBRX',
        'FKA' => 'IBRXFKA',
        'W' => 'WEGT3A',
        'Z' => 'IBRZ',
    ];

    $texto = strtoupper(trim($valor));
    if ($texto === '') {
        return '';
    }

    $mapearLinha = function (string $candidato) use ($mapaLinhas, $aliases): string {
        $cleaned = preg_replace('/[^A-Z0-9]/', '', $candidato);
        if ($cleaned === '') {
            return '';
        }
        if (strpos($cleaned, 'IBR') === 0) {
            $sufixo = substr($cleaned, 3);
            if (isset($mapaLinhas[$sufixo])) {
                return $mapaLinhas[$sufixo];
            }
            if (isset($aliases[$sufixo])) {
                return $aliases[$sufixo];
            }
            return $sufixo ? 'IBR' . $sufixo : $cleaned;
        }
        if (strpos($cleaned, 'WEG') === 0) {
            return $cleaned;
        }
        if (isset($mapaLinhas[$cleaned])) {
            return $mapaLinhas[$cleaned];
        }
        if (isset($aliases[$cleaned])) {
            return $aliases[$cleaned];
        }
        if (strlen($cleaned) <= 4) {
            return 'IBR' . $cleaned;
        }
        if (strpos($cleaned, 'IBR') === 0 || strpos($cleaned, 'WEG') === 0) {
            return $cleaned;
        }
        return 'IBR' . $cleaned;
    };

    if (preg_match('/IBR\\s*([A-Z0-9.\\/-]{1,8})(?:\\s*[-\\/]\\s*([A-Z0-9.\\/-]{1,8}))?/i', $texto, $match)) {
        $candidato = 'IBR' . $match[1] . ($match[2] ?? '');
        $normalizado = $mapearLinha($candidato);
        if ($normalizado) {
            return $normalizado;
        }
    }

    if (preg_match('/WEG\\s*([A-Z0-9.\\/-]{1,8})/i', $texto, $match)) {
        $normalizado = $mapearLinha('WEG' . $match[1]);
        if ($normalizado) {
            return $normalizado;
        }
    }

    $texto = preg_replace('/\\s+/', '', $texto);
    if (strlen($texto) >= 2 && substr($texto, -2) === 'LN') {
        $texto = substr($texto, 0, -2);
    }
    return $mapearLinha($texto);
}

function obter_folder_galeria_item(array $item): string
{
    $candidatosLinha = [
        $item['produtoPai'] ?? null,
        $item['produto_pai'] ?? null,
        $item['produtoPaiNome'] ?? null,
        $item['produtoPaiDescricao'] ?? null,
        $item['linha'] ?? null,
        $item['linhaProduto'] ?? null,
        $item['linha_produto'] ?? null,
        $item['linhaPai'] ?? null,
        $item['linha_pai'] ?? null,
        $item['familia'] ?? null,
        $item['familiaProduto'] ?? null,
        $item['familia_produto'] ?? null,
    ];
    foreach ($candidatosLinha as $candidato) {
        $normalizado = normalizar_linha_galeria_produto(is_string($candidato) ? $candidato : null);
        if ($normalizado) {
            return preg_replace('/[^A-Z0-9]/', '', strtoupper($normalizado));
        }
    }

    $link = $item['link'] ?? $item['linkProduto'] ?? $item['urlProduto'] ?? $item['url'] ?? '';
    if (is_string($link) && $link !== '') {
        $url = parse_url($link);
        if ($url && isset($url['query'])) {
            parse_str($url['query'], $params);
            foreach ($params as $chave => $valor) {
                $key = strtoupper((string) $chave);
                $val = strtoupper((string) $valor);
                if (strlen($key) >= 2 && substr($key, -2) === 'LN') {
                    $normalizado = normalizar_linha_galeria_produto($val);
                    if ($normalizado) {
                        return preg_replace('/[^A-Z0-9]/', '', strtoupper($normalizado));
                    }
                }
                if (strlen($val) >= 2 && substr($val, -2) === 'LN') {
                    $normalizado = normalizar_linha_galeria_produto(preg_replace('/LN$/i', '', $val));
                    if ($normalizado) {
                        return preg_replace('/[^A-Z0-9]/', '', strtoupper($normalizado));
                    }
                }
            }
        }
        if ($url && isset($url['path'])) {
            $path = strtoupper((string) $url['path']);
            if (preg_match('/CONFIGURADOR(?!ES)([A-Z0-9]{1,6})/', $path, $match)) {
                $normalizado = normalizar_linha_galeria_produto($match[1] ?? '');
                if ($normalizado) {
                    return preg_replace('/[^A-Z0-9]/', '', strtoupper($normalizado));
                }
            }
        }
    }

    $candidatos = [
        $item['codigo'] ?? null,
        $item['referencia'] ?? null,
        $item['descricao'] ?? null,
    ];
    foreach ($candidatos as $candidato) {
        $normalizado = normalizar_linha_galeria_produto(is_string($candidato) ? $candidato : null);
        if ($normalizado) {
            return preg_replace('/[^A-Z0-9]/', '', strtoupper($normalizado));
        }
    }

    return '';
}

function enriquecer_itens_com_galeria(array $items, string $baseDir): array
{
    $manifesto = carregar_manifesto_imagens_produtos($baseDir);
    foreach ($items as $index => $item) {
        if (!is_array($item)) {
            continue;
        }
        if (isset($item['galeria']) && is_array($item['galeria'])) {
            $items[$index] = $item;
            continue;
        }
        $folder = obter_folder_galeria_item($item);
        if (!$folder) {
            $items[$index] = $item;
            continue;
        }
        $galeria = ['folder' => $folder];
        $resolvido = resolver_imagens_manifesto_produtos($folder, $manifesto);
        if ($resolvido['matched']) {
            $galeria['basePath'] = $resolvido['basePath'];
            $galeria['files'] = $resolvido['files'];
            $galeria['count'] = count($resolvido['files']);
        }
        $item['galeria'] = $galeria;
        $items[$index] = $item;
    }
    return $items;
}
