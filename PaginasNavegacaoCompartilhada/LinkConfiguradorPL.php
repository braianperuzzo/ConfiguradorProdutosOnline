<?php

function obter_variavel_configurador_pl(array $variaveis, string $chave): string
{
    return trim((string) ($variaveis[$chave] ?? ''));
}

function montar_query_configurador_pl(array $params): string
{
    $filtrados = [];
    foreach ($params as $chave => $valor) {
        $valorLimpo = trim((string) $valor);
        if ($valorLimpo === '') {
            continue;
        }
        $filtrados[$chave] = $valorLimpo;
    }
    return http_build_query($filtrados, '', '&', PHP_QUERY_RFC3986);
}

function montar_link_configurador_pl(array $variaveis): string
{
    $linkSite = 'https://configurador.redutoresibr.com.br/Configurador';
    $plln = obter_variavel_configurador_pl($variaveis, 'PLLN');
    $linharedutor = str_replace('3.', 'IBR', $plln);

    $paramsRedutor = [
        'PLLN' => $plln,
        'PLBR' => obter_variavel_configurador_pl($variaveis, 'PLBR'),
        'PLET' => obter_variavel_configurador_pl($variaveis, 'PLET'),
        'PLTP' => obter_variavel_configurador_pl($variaveis, 'PLTP'),
        'PLRD' => obter_variavel_configurador_pl($variaveis, 'PLRD'),
        'PLDE' => obter_variavel_configurador_pl($variaveis, 'PLDE'),
        'PLBU' => obter_variavel_configurador_pl($variaveis, 'PLBU'),
        'PLBUD' => obter_variavel_configurador_pl($variaveis, 'PLBUD'),
        'PLBL' => obter_variavel_configurador_pl($variaveis, 'PLBL'),
        'PLGU' => obter_variavel_configurador_pl($variaveis, 'PLGU'),
        'PLFU' => obter_variavel_configurador_pl($variaveis, 'PLFU'),
    ];

    $query = montar_query_configurador_pl($paramsRedutor);
    return $query !== '' ? "{$linkSite}{$linharedutor}?{$query}" : '';
}

