<?php

function obter_variavel_configurador_fx(array $variaveis, string $chave): string
{
    return trim((string) ($variaveis[$chave] ?? ''));
}

function variavel_em_lista_fx(string $valor, array $lista): bool
{
    return in_array($valor, $lista, true);
}

function variavel_contem_fx(string $valor, string $parte): bool
{
    if ($parte === '') {
        return false;
    }
    return mb_stripos($valor, $parte, 0, 'UTF-8') !== false;
}

function ajustar_lista_configurador_fx(string $valor): string
{
    $valor = str_replace('NÃO', 'NÃO', $valor);
    return str_replace(', ', '%2C', $valor);
}

function montar_query_configurador_fx(array $params): string
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

function montar_link_configurador_fx(array $variaveis): string
{
    $linkSite = 'https://configurador.redutoresibr.com.br/Configurador';
    $fxln = obter_variavel_configurador_fx($variaveis, 'FXLN');
    $linharedutor = str_replace('1.', 'IBR', $fxln);

    $fxmo = obter_variavel_configurador_fx($variaveis, 'FXMO');
    if ($fxmo === 'MS') {
        return '';
    }

    $fxcm = obter_variavel_configurador_fx($variaveis, 'FXCM');
    $fxaf = obter_variavel_configurador_fx($variaveis, 'FXAF');
    $fxas = obter_variavel_configurador_fx($variaveis, 'FXAS');

    $paramsRedutor = [
        'FXLN' => $fxln,
        'FXBR' => obter_variavel_configurador_fx($variaveis, 'FXBR'),
        'FXRD' => obter_variavel_configurador_fx($variaveis, 'FXRD'),
        'FXET' => obter_variavel_configurador_fx($variaveis, 'FXET'),
        'FXCM' => $fxcm,
        'FXAF' => $fxaf,
        'FXAS' => $fxas,
        'FXPM' => obter_variavel_configurador_fx($variaveis, 'FXPM'),
    ];

    if (!variavel_contem_fx($fxcm, 'EE')) {
        $paramsRedutor['FXBU'] = obter_variavel_configurador_fx($variaveis, 'FXBU');
        $paramsRedutor['FXCP'] = obter_variavel_configurador_fx($variaveis, 'FXCP');
    }

    if (!variavel_em_lista_fx($fxln, ['1.FFA', '1.FR']) && !variavel_em_lista_fx($fxaf, ['N', 'PE'])) {
        $paramsRedutor['FXAFP'] = obter_variavel_configurador_fx($variaveis, 'FXAFP');
    }

    if ($fxaf === 'BT' && $fxln === '1.FR') {
        $paramsRedutor['FXBT'] = obter_variavel_configurador_fx($variaveis, 'FXBT');
    }

    if ($fxaf === 'PE') {
        $paramsRedutor['FXPE'] = obter_variavel_configurador_fx($variaveis, 'FXPE');
    }

    if (!variavel_em_lista_fx($fxas, ['N', 'ED', 'SX']) && $fxln !== '1.FFA') {
        $paramsRedutor['FXASP'] = obter_variavel_configurador_fx($variaveis, 'FXASP');
    }

    $opcionaisRedutor = ajustar_lista_configurador_fx(obter_variavel_configurador_fx($variaveis, 'FXOPC'));
    if ($opcionaisRedutor !== '') {
        $paramsRedutor['FXOPC'] = $opcionaisRedutor;
    }

    $paramsMotor = ['FXMO' => $fxmo];
    if ($fxmo === 'S') {
        $moln = obter_variavel_configurador_fx($variaveis, 'MOLN');
        $mocp = obter_variavel_configurador_fx($variaveis, 'MOCP');
        $mocpp = obter_variavel_configurador_fx($variaveis, 'MOCPP');

        $paramsMotor += [
            'MOLN' => $moln,
            'MOTP' => obter_variavel_configurador_fx($variaveis, 'MOTP'),
            'MOTT' => obter_variavel_configurador_fx($variaveis, 'MOTT'),
            'MOFQ' => obter_variavel_configurador_fx($variaveis, 'MOFQ'),
            'MOPT' => obter_variavel_configurador_fx($variaveis, 'MOPT'),
            'MOPL' => obter_variavel_configurador_fx($variaveis, 'MOPL'),
            'MOCP' => $mocp,
            'MOPC' => obter_variavel_configurador_fx($variaveis, 'MOPC'),
            'MOPP' => obter_variavel_configurador_fx($variaveis, 'MOPP'),
        ];

        if ($moln !== '3.W' && variavel_em_lista_fx($mocp, ['B3', 'B34', 'B35']) && $mocpp !== 'T') {
            $paramsMotor['MOCPP'] = $mocpp;
        }

        $opcionaisMotorOriginal = obter_variavel_configurador_fx($variaveis, 'MOOPC');
        $opcionaisMotor = ajustar_lista_configurador_fx($opcionaisMotorOriginal);
        if ($opcionaisMotor !== '') {
            $paramsMotor['MOOPC'] = $opcionaisMotor;
        }

        if (variavel_contem_fx($opcionaisMotorOriginal, 'CR')) {
            $paramsMotor['MOCR'] = obter_variavel_configurador_fx($variaveis, 'MOCR');
            $paramsMotor['FXCR'] = obter_variavel_configurador_fx($variaveis, 'FXCR');
        }
    }

    $paramsVariador = ['FXVA' => obter_variavel_configurador_fx($variaveis, 'FXVA')];
    if ($paramsVariador['FXVA'] === 'S') {
        $paramsVariador += [
            'VALN' => obter_variavel_configurador_fx($variaveis, 'VALN'),
            'VAPT' => obter_variavel_configurador_fx($variaveis, 'VAPT'),
            'VAECP' => obter_variavel_configurador_fx($variaveis, 'VAECP'),
            'VASCP' => obter_variavel_configurador_fx($variaveis, 'VASCP'),
            'VAPM' => obter_variavel_configurador_fx($variaveis, 'VAPM'),
        ];
    }

    $paramsAdaptacao = ['FXPL' => obter_variavel_configurador_fx($variaveis, 'FXPL')];
    if ($paramsAdaptacao['FXPL'] === 'S') {
        $paramsAdaptacao += [
            'PLDE' => obter_variavel_configurador_fx($variaveis, 'PLDE'),
            'PLBU' => obter_variavel_configurador_fx($variaveis, 'PLBU'),
            'PLGU' => obter_variavel_configurador_fx($variaveis, 'PLGU'),
            'PLFU' => obter_variavel_configurador_fx($variaveis, 'PLFU'),
        ];
    }

    $params = array_merge($paramsRedutor, $paramsMotor, $paramsVariador, $paramsAdaptacao);
    $query = montar_query_configurador_fx($params);

    return $query !== '' ? "{$linkSite}{$linharedutor}?{$query}" : '';
}
