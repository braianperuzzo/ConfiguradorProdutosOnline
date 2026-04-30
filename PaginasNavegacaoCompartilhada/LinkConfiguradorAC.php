<?php

function obter_variavel_configurador_ac(array $variaveis, string $chave): string
{
    return trim((string) ($variaveis[$chave] ?? ''));
}

function variavel_em_lista_ac(string $valor, array $lista): bool
{
    return in_array($valor, $lista, true);
}

function variavel_contem_ac(string $valor, string $parte): bool
{
    if ($parte === '') {
        return false;
    }
    return mb_stripos($valor, $parte, 0, 'UTF-8') !== false;
}

function ajustar_lista_configurador_ac(string $valor): string
{
    $valor = str_replace('NÃO', 'NÃO', $valor);
    return str_replace(', ', '%2C', $valor);
}

function montar_query_configurador_ac(array $params): string
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

function montar_link_configurador_ac(array $variaveis): string
{
    $linkSite = 'https://configurador.redutoresibr.com.br/Configurador';
    $acln = obter_variavel_configurador_ac($variaveis, 'ACLN');
    $linharedutor = str_replace('1.', 'IBR', $acln);

    $acmo = obter_variavel_configurador_ac($variaveis, 'ACMO');
    if ($acmo === 'MS') {
        return '';
    }

    $acaf = obter_variavel_configurador_ac($variaveis, 'ACAF');
    $acas = obter_variavel_configurador_ac($variaveis, 'ACAS');

    $paramsRedutor = [
        'ACLN' => $acln,
        'ACBR' => obter_variavel_configurador_ac($variaveis, 'ACBR'),
        'ACRD' => obter_variavel_configurador_ac($variaveis, 'ACRD'),
        'ACCM' => obter_variavel_configurador_ac($variaveis, 'ACCM'),
        'ACCP' => obter_variavel_configurador_ac($variaveis, 'ACCP'),
        'ACAF' => $acaf,
        'ACAS' => $acas,
        'ACPM' => obter_variavel_configurador_ac($variaveis, 'ACPM'),
    ];

    if (!variavel_em_lista_ac($acaf, ['N', 'PE'])) {
        $paramsRedutor['ACAFP'] = obter_variavel_configurador_ac($variaveis, 'ACAFP');
    }

    if ($acaf === 'BT') {
        $paramsRedutor['ACBT'] = obter_variavel_configurador_ac($variaveis, 'ACBT');
    }

    if ($acaf === 'PE') {
        $paramsRedutor['ACPE'] = obter_variavel_configurador_ac($variaveis, 'ACPE');
    }

    if (!variavel_em_lista_ac($acas, ['N', 'ED'])) {
        $paramsRedutor['ACASP'] = obter_variavel_configurador_ac($variaveis, 'ACASP');
    }

    $opcionaisRedutor = ajustar_lista_configurador_ac(obter_variavel_configurador_ac($variaveis, 'ACOPC'));
    if ($opcionaisRedutor !== '') {
        $paramsRedutor['ACOPC'] = $opcionaisRedutor;
    }

    $paramsMotor = ['ACMO' => $acmo];
    if ($acmo === 'S') {
        $moln = obter_variavel_configurador_ac($variaveis, 'MOLN');
        $mocp = obter_variavel_configurador_ac($variaveis, 'MOCP');
        $mocpp = obter_variavel_configurador_ac($variaveis, 'MOCPP');

        $paramsMotor += [
            'MOLN' => $moln,
            'MOTP' => obter_variavel_configurador_ac($variaveis, 'MOTP'),
            'MOTT' => obter_variavel_configurador_ac($variaveis, 'MOTT'),
            'MOFQ' => obter_variavel_configurador_ac($variaveis, 'MOFQ'),
            'MOPT' => obter_variavel_configurador_ac($variaveis, 'MOPT'),
            'MOPL' => obter_variavel_configurador_ac($variaveis, 'MOPL'),
            'MOCP' => $mocp,
            'MOPC' => obter_variavel_configurador_ac($variaveis, 'MOPC'),
            'MOPP' => obter_variavel_configurador_ac($variaveis, 'MOPP'),
        ];

        if ($moln !== '3.W' && variavel_em_lista_ac($mocp, ['B3', 'B34', 'B35']) && $mocpp !== 'T') {
            $paramsMotor['MOCPP'] = $mocpp;
        }

        $opcionaisMotorOriginal = obter_variavel_configurador_ac($variaveis, 'MOOPC');
        $opcionaisMotor = ajustar_lista_configurador_ac($opcionaisMotorOriginal);
        if ($opcionaisMotor !== '') {
            $paramsMotor['MOOPC'] = $opcionaisMotor;
        }

        if (variavel_contem_ac($opcionaisMotorOriginal, 'CR')) {
            $paramsMotor['MOCR'] = obter_variavel_configurador_ac($variaveis, 'MOCR');
            $paramsMotor['ACCR'] = obter_variavel_configurador_ac($variaveis, 'ACCR');
        }
    }

    $params = array_merge($paramsRedutor, $paramsMotor);
    $query = montar_query_configurador_ac($params);

    return $query !== '' ? "{$linkSite}{$linharedutor}?{$query}" : '';
}
