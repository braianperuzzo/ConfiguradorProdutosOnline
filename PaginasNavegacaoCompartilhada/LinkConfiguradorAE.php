<?php

function obter_variavel_configurador_ae(array $variaveis, string $chave): string
{
    return trim((string) ($variaveis[$chave] ?? ''));
}

function montar_query_configurador_ae(array $params): string
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

function montar_link_configurador_ae(array $variaveis): string
{
    $linkSite = 'https://configurador.redutoresibr.com.br/Configurador';
    $aeln = obter_variavel_configurador_ae($variaveis, 'AELN');
    $linhaAcoplamento = str_replace('3.', 'IBR', $aeln);

    $params = [
        'AELN' => $aeln,
        'AEBR' => obter_variavel_configurador_ae($variaveis, 'AEBR'),
        'AETP' => obter_variavel_configurador_ae($variaveis, 'AETP'),
        'AEEL' => obter_variavel_configurador_ae($variaveis, 'AEEL'),
        'AEEE' => obter_variavel_configurador_ae($variaveis, 'AEEE'),
        'AEEE2' => obter_variavel_configurador_ae($variaveis, 'AEEE2'),
    ];

    $query = montar_query_configurador_ae($params);
    return $query !== '' ? "{$linkSite}{$linhaAcoplamento}?{$query}" : '';
}
