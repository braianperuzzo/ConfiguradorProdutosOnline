<?php
if (!function_exists('obter_diretorio_carrinhos_compartilhados')) {
    function obter_diretorio_carrinhos_compartilhados(?string $baseDir = null): string
    {
        if ($baseDir === null || $baseDir === '') {
            $baseDir = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
if ($baseDir === '') {
    $baseDir = dirname(__DIR__);
}
        }
        return rtrim($baseDir, DIRECTORY_SEPARATOR) . '/Tokens/CarrinhosCompartilhados';
    }
}

if (!function_exists('extrair_id_carrinho_compartilhado')) {
    function extrair_id_carrinho_compartilhado(?string $link): string
    {
        if (!is_string($link) || $link === '') {
            return '';
        }

        $parsed = @parse_url($link);
        if (is_array($parsed)) {
            if (!empty($parsed['query'])) {
                parse_str($parsed['query'], $params);
                if (!empty($params['i']) && preg_match('/^[a-zA-Z0-9]{4,32}$/', (string) $params['i'])) {
                    return (string) $params['i'];
                }
            }

            if (!empty($parsed['path']) && preg_match('/([a-zA-Z0-9]{4,32})(?:\\.json)?$/', $parsed['path'], $match)) {
                if (!empty($match[1]) && preg_match('/^[a-zA-Z0-9]{4,32}$/', $match[1])) {
                    return $match[1];
                }
            }
        }

        if (preg_match('/([a-zA-Z0-9]{4,32})/', $link, $match)) {
            return $match[1];
        }

        return '';
    }
}

if (!function_exists('excluir_carrinho_compartilhado_por_id')) {
    function excluir_carrinho_compartilhado_por_id(?string $id, ?string $baseDir = null): void
    {
        if (!is_string($id) || $id === '') {
            return;
        }

        $idLimpo = preg_replace('/[^a-zA-Z0-9]/', '', $id);
        if ($idLimpo === '') {
            return;
        }

        $dir = obter_diretorio_carrinhos_compartilhados($baseDir);
        $arquivo = $dir . '/' . $idLimpo . '.json';
        if (is_file($arquivo)) {
            @unlink($arquivo);
        }
    }
}

if (!function_exists('excluir_carrinhos_compartilhados_por_links')) {
    function excluir_carrinhos_compartilhados_por_links(array $links, ?string $baseDir = null): void
    {
        if (empty($links)) {
            return;
        }

        $ids = [];
        foreach ($links as $link) {
            $id = extrair_id_carrinho_compartilhado(is_string($link) ? $link : '');
            if ($id !== '') {
                $ids[$id] = true;
            }
        }

        if (empty($ids)) {
            return;
        }

        foreach (array_keys($ids) as $id) {
            excluir_carrinho_compartilhado_por_id($id, $baseDir);
        }
    }
}