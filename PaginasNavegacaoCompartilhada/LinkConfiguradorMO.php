<?php

function obter_variavel_configurador_mo(array $variaveis, string $chave): string
{
    return trim((string) ($variaveis[$chave] ?? ''));
}

function variavel_em_lista_mo(string $valor, array $lista): bool
{
    return in_array($valor, $lista, true);
}

function variavel_contem_mo(string $valor, string $parte): bool
{
    if ($parte === '') {
        return false;
    }
    return mb_stripos($valor, $parte, 0, 'UTF-8') !== false;
}

function ajustar_lista_configurador_mo(string $valor): string
{
    $valor = str_replace('NÃO', 'NÃO', $valor);
    return str_replace(', ', '%2C', $valor);
}

function montar_query_configurador_mo(array $params): string
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

function montar_link_configurador_mo(array $variaveis): string
{
    $linkSite = 'https://configurador.redutoresibr.com.br/Configurador';
    $moln = obter_variavel_configurador_mo($variaveis, 'MOLN');
    $linhamotor = str_replace(['3.', '2.'], ['IBRM', 'IBRMS'], $moln);

    if (obter_variavel_configurador_mo($variaveis, 'MORMES') === 'S'
        || obter_variavel_configurador_mo($variaveis, 'MOES') === 'S') {
        return '';
    }

    $mocp = obter_variavel_configurador_mo($variaveis, 'MOCP');
    $mocpp = obter_variavel_configurador_mo($variaveis, 'MOCPP');

    $paramsMotor = [
        'MOLN' => $moln,
        'MOTP' => obter_variavel_configurador_mo($variaveis, 'MOTP'),
        'MOTT' => obter_variavel_configurador_mo($variaveis, 'MOTT'),
        'MOFQ' => obter_variavel_configurador_mo($variaveis, 'MOFQ'),
        'MOPT' => obter_variavel_configurador_mo($variaveis, 'MOPT'),
        'MOPL' => obter_variavel_configurador_mo($variaveis, 'MOPL'),
        'MOCM' => obter_variavel_configurador_mo($variaveis, 'MOCM'),
        'MOCP' => $mocp,
        'MOPP' => obter_variavel_configurador_mo($variaveis, 'MOPP'),
    ];

    if ($moln !== '3.W' && variavel_em_lista_mo($mocp, ['B3', 'B34', 'B35']) && $mocpp !== 'T') {
        $paramsMotor['MOCPP'] = $mocpp;
    }

    $opcionaisMotorOriginal = obter_variavel_configurador_mo($variaveis, 'MOOPC');
    $opcionaisMotor = ajustar_lista_configurador_mo($opcionaisMotorOriginal);
    if ($opcionaisMotor !== '') {
        $paramsMotor['MOOPC'] = $opcionaisMotor;
    }
    if (variavel_contem_mo($opcionaisMotorOriginal, 'CR')) {
        $paramsMotor['MOCR'] = obter_variavel_configurador_mo($variaveis, 'MOCR');
    }

    $query = montar_query_configurador_mo($paramsMotor);
    return $query !== '' ? "{$linkSite}{$linhamotor}?{$query}" : '';
}
