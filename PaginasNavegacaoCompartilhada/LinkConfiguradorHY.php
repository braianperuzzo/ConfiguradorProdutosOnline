<?php

function obter_variavel_configurador_hy(array $variaveis, string $chave): string
{
    return trim((string) ($variaveis[$chave] ?? ''));
}

function variavel_em_lista_hy(string $valor, array $lista): bool
{
    return in_array($valor, $lista, true);
}

function variavel_contem_hy(string $valor, string $parte): bool
{
    if ($parte === '') {
        return false;
    }
    return mb_stripos($valor, $parte, 0, 'UTF-8') !== false;
}

function ajustar_lista_configurador_hy(string $valor): string
{
    $valor = str_replace('NÃO', 'NÃO', $valor);
    return str_replace(', ', '%2C', $valor);
}

function montar_query_configurador_hy(array $params): string
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

function montar_link_configurador_hy(array $variaveis): string
{
    $linkSite = 'https://configurador.redutoresibr.com.br/Configurador';
    $hyln = obter_variavel_configurador_hy($variaveis, 'HYLN');
    $linharedutor = str_replace('1.', 'IBR', $hyln);

    $hymo = obter_variavel_configurador_hy($variaveis, 'HYMO');
    if ($hymo === 'MS') {
        return '';
    }

    $hycm = obter_variavel_configurador_hy($variaveis, 'HYCM');
    $hyaf = obter_variavel_configurador_hy($variaveis, 'HYAF');
    $hybr = obter_variavel_configurador_hy($variaveis, 'HYBR');
    $hyas = obter_variavel_configurador_hy($variaveis, 'HYAS');

    $paramsRedutor = [
        'HYLN' => $hyln,
        'HYBR' => $hybr,
        'HYRD' => obter_variavel_configurador_hy($variaveis, 'HYRD'),
        'HYCM' => $hycm,
        'HYAF' => $hyaf,
        'HYAS' => $hyas,
    ];

    if ($hyln !== '1.R' && !variavel_contem_hy($hycm, 'EE')) {
        $paramsRedutor['HYBU'] = obter_variavel_configurador_hy($variaveis, 'HYBU');
    }

    if (!variavel_contem_hy($hycm, 'EE')) {
        $paramsRedutor['HYCP'] = obter_variavel_configurador_hy($variaveis, 'HYCP');
    }

    if (variavel_em_lista_hy($hyln, ['1.R', '1.X']) && !variavel_em_lista_hy($hyaf, ['N', 'PE'])) {
        $paramsRedutor['HYAFP'] = obter_variavel_configurador_hy($variaveis, 'HYAFP');
    }

    $hybtValido = $hyaf === 'BT' && (
        $hyln === '1.R'
        || ($hyln === '1.X' && variavel_em_lista_hy($hybr, ['22S', '32S', '33S', '42A', '43A', '52A', '53A', '62A', '63A', '93C', '94C']))
    );
    if ($hybtValido) {
        $paramsRedutor['HYBT'] = obter_variavel_configurador_hy($variaveis, 'HYBT');
    }

    if ($hyaf === 'PE') {
        $paramsRedutor['HYPE'] = obter_variavel_configurador_hy($variaveis, 'HYPE');
    }

    if (!variavel_em_lista_hy($hyas, ['N', 'ED']) && variavel_em_lista_hy($hyln, ['1.R', '1.X'])) {
        $paramsRedutor['HYASP'] = obter_variavel_configurador_hy($variaveis, 'HYASP');
    }

    if (!variavel_em_lista_hy($hyln, ['1.M', '1.R'])) {
        $paramsRedutor['HYPM'] = obter_variavel_configurador_hy($variaveis, 'HYPM');
    }

    $opcionaisRedutor = ajustar_lista_configurador_hy(obter_variavel_configurador_hy($variaveis, 'HYOPC'));
    if ($opcionaisRedutor !== '') {
        $paramsRedutor['HYOPC'] = $opcionaisRedutor;
    }

    $paramsMotor = ['HYMO' => $hymo];
    if ($hymo === 'S') {
        $moln = obter_variavel_configurador_hy($variaveis, 'MOLN');
        $mocp = obter_variavel_configurador_hy($variaveis, 'MOCP');
        $mocpp = obter_variavel_configurador_hy($variaveis, 'MOCPP');

        $paramsMotor += [
            'MOLN' => $moln,
            'MOTP' => obter_variavel_configurador_hy($variaveis, 'MOTP'),
            'MOTT' => obter_variavel_configurador_hy($variaveis, 'MOTT'),
            'MOFQ' => obter_variavel_configurador_hy($variaveis, 'MOFQ'),
            'MOPT' => obter_variavel_configurador_hy($variaveis, 'MOPT'),
            'MOPL' => obter_variavel_configurador_hy($variaveis, 'MOPL'),
            'MOCP' => $mocp,
            'MOPC' => obter_variavel_configurador_hy($variaveis, 'MOPC'),
            'MOPP' => obter_variavel_configurador_hy($variaveis, 'MOPP'),
        ];

        if ($moln !== '3.W' && variavel_em_lista_hy($mocp, ['B3', 'B34', 'B35']) && $mocpp !== 'T') {
            $paramsMotor['MOCPP'] = $mocpp;
        }

        $opcionaisMotorOriginal = obter_variavel_configurador_hy($variaveis, 'MOOPC');
        $opcionaisMotor = ajustar_lista_configurador_hy($opcionaisMotorOriginal);
        if ($opcionaisMotor !== '') {
            $paramsMotor['MOOPC'] = $opcionaisMotor;
        }

        if (variavel_contem_hy($opcionaisMotorOriginal, 'CR')) {
            $paramsMotor['MOCR'] = obter_variavel_configurador_hy($variaveis, 'MOCR');
            $paramsMotor['HYCR'] = obter_variavel_configurador_hy($variaveis, 'HYCR');
        }
    }

    $paramsVariador = ['HYVA' => obter_variavel_configurador_hy($variaveis, 'HYVA')];
    if ($paramsVariador['HYVA'] === 'S') {
        $paramsVariador += [
            'VALN' => obter_variavel_configurador_hy($variaveis, 'VALN'),
            'VAPT' => obter_variavel_configurador_hy($variaveis, 'VAPT'),
            'VAECP' => obter_variavel_configurador_hy($variaveis, 'VAECP'),
            'VASCP' => obter_variavel_configurador_hy($variaveis, 'VASCP'),
            'VAPM' => obter_variavel_configurador_hy($variaveis, 'VAPM'),
        ];
    }

    $paramsAdaptacao = ['HYPL' => obter_variavel_configurador_hy($variaveis, 'HYPL')];
    if ($paramsAdaptacao['HYPL'] === 'S') {
        $paramsAdaptacao += [
            'PLDE' => obter_variavel_configurador_hy($variaveis, 'PLDE'),
            'PLBU' => obter_variavel_configurador_hy($variaveis, 'PLBU'),
            'PLGU' => obter_variavel_configurador_hy($variaveis, 'PLGU'),
            'PLFU' => obter_variavel_configurador_hy($variaveis, 'PLFU'),
        ];
    }

    $params = array_merge($paramsRedutor, $paramsMotor, $paramsVariador, $paramsAdaptacao);
    $query = montar_query_configurador_hy($params);

    return $query !== '' ? "{$linkSite}{$linharedutor}?{$query}" : '';
}
