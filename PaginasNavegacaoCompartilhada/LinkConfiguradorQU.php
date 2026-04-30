<?php

function obter_variavel_configurador_qu(array $variaveis, string $chave): string
{
    return trim((string) ($variaveis[$chave] ?? ''));
}

function variavel_em_lista_qu(string $valor, array $lista): bool
{
    return in_array($valor, $lista, true);
}

function variavel_contem_qu(string $valor, string $parte): bool
{
    if ($parte === '') {
        return false;
    }
    return mb_stripos($valor, $parte, 0, 'UTF-8') !== false;
}

function ajustar_lista_configurador_qu(string $valor): string
{
    $valor = str_replace('NÃO', 'NÃO', $valor);
    return str_replace(', ', '%2C', $valor);
}

function montar_query_configurador_qu(array $params): string
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

function montar_link_configurador_qu(array $variaveis): string
{
    $linkSite = 'https://configurador.redutoresibr.com.br/Configurador';
    $quln = obter_variavel_configurador_qu($variaveis, 'QULN');
    $linharedutor = str_replace('1.', 'IBR', $quln);
    $qubr = obter_variavel_configurador_qu($variaveis, 'QUBR');
    $quvz = obter_variavel_configurador_qu($variaveis, 'QUVZ');
    $quaf = obter_variavel_configurador_qu($variaveis, 'QUAF');
    $quafp = obter_variavel_configurador_qu($variaveis, 'QUAFP');
    $qubt = obter_variavel_configurador_qu($variaveis, 'QUBT');
    $quas = obter_variavel_configurador_qu($variaveis, 'QUAS');
    $quasp = obter_variavel_configurador_qu($variaveis, 'QUASP');
    $qucl = obter_variavel_configurador_qu($variaveis, 'QUCL');
    $quclp = obter_variavel_configurador_qu($variaveis, 'QUCLP');

    $paramsRedutor = [
        'QULN' => $quln,
        'QUBR' => $qubr,
        'QUEX' => obter_variavel_configurador_qu($variaveis, 'QUEX'),
        'QURD' => obter_variavel_configurador_qu($variaveis, 'QURD'),
        'QUCM' => obter_variavel_configurador_qu($variaveis, 'QUCM'),
        'QUBU' => obter_variavel_configurador_qu($variaveis, 'QUBU'),
        'QUCP' => obter_variavel_configurador_qu($variaveis, 'QUCP'),
        'QUAF' => $quaf,
        'QUAS' => $quas,
        'QUCL' => $qucl,
    ];

    if ($qubr === '075') {
        $paramsRedutor['QUVZ'] = $quvz;
    }
    if ($quaf !== 'N') {
        $paramsRedutor['QUAFP'] = $quafp;
    }
    if ($quaf === 'BT') {
        $paramsRedutor['QUBT'] = $qubt;
    }
    if ($quas !== '' && !variavel_em_lista_qu($quas, ['N', 'ED', 'ED30'])) {
        $paramsRedutor['QUASP'] = $quasp;
    }
    if ($qucl !== 'N') {
        $paramsRedutor['QUCLP'] = $quclp;
    }

    $opcionaisRedutor = ajustar_lista_configurador_qu(obter_variavel_configurador_qu($variaveis, 'QUOPC'));
    if ($opcionaisRedutor !== '') {
        $paramsRedutor['QUOPC'] = $opcionaisRedutor;
    }

    $qumo = obter_variavel_configurador_qu($variaveis, 'QUMO');
    if ($qumo === 'MS') {
        return '';
    }

    $paramsMotor = ['QUMO' => $qumo];
    if ($qumo === 'S') {
        $moln = obter_variavel_configurador_qu($variaveis, 'MOLN');
        $mocp = obter_variavel_configurador_qu($variaveis, 'MOCP');
        $mocpp = obter_variavel_configurador_qu($variaveis, 'MOCPP');
        $paramsMotor += [
            'MOLN' => $moln,
            'MOTP' => obter_variavel_configurador_qu($variaveis, 'MOTP'),
            'MOTT' => obter_variavel_configurador_qu($variaveis, 'MOTT'),
            'MOFQ' => obter_variavel_configurador_qu($variaveis, 'MOFQ'),
            'MOPT' => obter_variavel_configurador_qu($variaveis, 'MOPT'),
            'MOPL' => obter_variavel_configurador_qu($variaveis, 'MOPL'),
            'MOCP' => $mocp,
            'MOPC' => obter_variavel_configurador_qu($variaveis, 'MOPC'),
            'MOPP' => obter_variavel_configurador_qu($variaveis, 'MOPP'),
        ];
        if ($moln !== '3.W' && variavel_em_lista_qu($mocp, ['B3', 'B34', 'B35']) && $mocpp !== 'T') {
            $paramsMotor['MOCPP'] = $mocpp;
        }

        $opcionaisMotorOriginal = obter_variavel_configurador_qu($variaveis, 'MOOPC');
        $opcionaisMotor = ajustar_lista_configurador_qu($opcionaisMotorOriginal);
        if ($opcionaisMotor !== '') {
            $paramsMotor['MOOPC'] = $opcionaisMotor;
        }
        if (variavel_contem_qu($opcionaisMotorOriginal, 'CR')) {
            $paramsMotor['MOCR'] = obter_variavel_configurador_qu($variaveis, 'MOCR');
            $paramsMotor['QUCR'] = obter_variavel_configurador_qu($variaveis, 'QUCR');
        }
    }

    $paramsVariador = ['QUVA' => obter_variavel_configurador_qu($variaveis, 'QUVA')];
    if ($paramsVariador['QUVA'] === 'S') {
        $paramsVariador += [
            'VALN' => obter_variavel_configurador_qu($variaveis, 'VALN'),
            'VAPT' => obter_variavel_configurador_qu($variaveis, 'VAPT'),
            'VAECP' => obter_variavel_configurador_qu($variaveis, 'VAECP'),
            'VASCP' => obter_variavel_configurador_qu($variaveis, 'VASCP'),
            'VAPM' => obter_variavel_configurador_qu($variaveis, 'VAPM'),
        ];
    }

    $paramsAdaptacao = ['QUPL' => obter_variavel_configurador_qu($variaveis, 'QUPL')];
    if ($paramsAdaptacao['QUPL'] === 'S') {
        $paramsAdaptacao += [
            'PLDE' => obter_variavel_configurador_qu($variaveis, 'PLDE'),
            'PLBU' => obter_variavel_configurador_qu($variaveis, 'PLBU'),
            'PLGU' => obter_variavel_configurador_qu($variaveis, 'PLGU'),
            'PLFU' => obter_variavel_configurador_qu($variaveis, 'PLFU'),
        ];
    }

    $paramsHidraulico = ['QUMH' => obter_variavel_configurador_qu($variaveis, 'QUMH')];
    if ($paramsHidraulico['QUMH'] === 'S') {
        $paramsHidraulico['MHDE'] = obter_variavel_configurador_qu($variaveis, 'MHDE');
    }

    $params = array_merge($paramsRedutor, $paramsMotor, $paramsVariador, $paramsAdaptacao, $paramsHidraulico);
    $query = montar_query_configurador_qu($params);

    return $query !== '' ? "{$linkSite}{$linharedutor}?{$query}" : '';
}
