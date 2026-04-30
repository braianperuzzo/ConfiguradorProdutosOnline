<?php

function obter_variavel_configurador_in(array $variaveis, string $chave): string
{
    return trim((string) ($variaveis[$chave] ?? ''));
}

function montar_query_configurador_in(array $params): string
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

function montar_link_configurador_in(array $variaveis): string
{
    $linkSite = 'https://configurador.redutoresibr.com.br/Configurador';
    $inln = obter_variavel_configurador_in($variaveis, 'INLN');
    $linhainversor = str_replace('4.', 'IBR', $inln);

    $paramsInversor = [
        'INLN' => $inln,
        'INTP' => obter_variavel_configurador_in($variaveis, 'INTP'),
        'INTT' => obter_variavel_configurador_in($variaveis, 'INTT'),
        'INPT' => obter_variavel_configurador_in($variaveis, 'INPT'),
        'INFE' => obter_variavel_configurador_in($variaveis, 'INFE'),
        'INPC' => obter_variavel_configurador_in($variaveis, 'INPC'),
        'INAP' => obter_variavel_configurador_in($variaveis, 'INAP'),
        'INCM' => obter_variavel_configurador_in($variaveis, 'INCM'),
        'INCP' => obter_variavel_configurador_in($variaveis, 'INCP'),
        'INCO' => obter_variavel_configurador_in($variaveis, 'INCO'),
        'INOPCS' => obter_variavel_configurador_in($variaveis, 'INOPCS'),
    ];

    $paramsMotor = [];
    if (($paramsInversor['INOPCS'] ?? '') === 'NM') {
        $paramsMotor = [
            'MOLN' => obter_variavel_configurador_in($variaveis, 'MOLN'),
            'MOCM' => obter_variavel_configurador_in($variaveis, 'MOCM'),
        ];
    }

    $params = array_merge($paramsInversor, $paramsMotor);
    $query = montar_query_configurador_in($params);

    return $query !== '' ? "{$linkSite}{$linhainversor}?{$query}" : '';
}
