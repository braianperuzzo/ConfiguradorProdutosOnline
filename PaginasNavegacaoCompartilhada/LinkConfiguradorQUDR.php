<?php

function obter_variavel_configurador_qudr(array $variaveis, string $chave): string
{
    return trim((string) ($variaveis[$chave] ?? ''));
}

function variavel_em_lista_qudr(string $valor, array $lista): bool
{
    return in_array($valor, $lista, true);
}

function variavel_contem_qudr(string $valor, string $parte): bool
{
    if ($parte === '') {
        return false;
    }
    return mb_stripos($valor, $parte, 0, 'UTF-8') !== false;
}

function ajustar_lista_configurador_qudr(string $valor): string
{
    $valor = str_replace('NÃO', 'NÃO', $valor);
    return str_replace(', ', '%2C', $valor);
}

function montar_query_configurador_qudr(array $params): string
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

function montar_link_configurador_qudr(array $variaveis): string
{
    $linkSite = 'https://configurador.redutoresibr.com.br/Configurador';
    $quln = obter_variavel_configurador_qudr($variaveis, 'QULN');
    $linharedutor = str_replace('1.', 'IBR', $quln);

    $qubr = obter_variavel_configurador_qudr($variaveis, 'QUBR');
    $quvz = obter_variavel_configurador_qudr($variaveis, 'QUVZ');
    $quaf = obter_variavel_configurador_qudr($variaveis, 'QUAF');
    $quafp = obter_variavel_configurador_qudr($variaveis, 'QUAFP');
    $qubt = obter_variavel_configurador_qudr($variaveis, 'QUBT');
    $quas = obter_variavel_configurador_qudr($variaveis, 'QUAS');
    $quasp = obter_variavel_configurador_qudr($variaveis, 'QUASP');
    $qucl = obter_variavel_configurador_qudr($variaveis, 'QUCL');
    $quclp = obter_variavel_configurador_qudr($variaveis, 'QUCLP');

    $paramsRedutor = [
        'QULN' => $quln,
        'QUBR' => $qubr,
        'QUEX' => obter_variavel_configurador_qudr($variaveis, 'QUEX'),
        'QURD' => obter_variavel_configurador_qudr($variaveis, 'QURD'),
        'QURD1' => obter_variavel_configurador_qudr($variaveis, 'QURD1'),
        'QURD2' => obter_variavel_configurador_qudr($variaveis, 'QURD2'),
        'QUCM' => obter_variavel_configurador_qudr($variaveis, 'QUCM'),
        'QUBU' => obter_variavel_configurador_qudr($variaveis, 'QUBU'),
        'QUCM2' => obter_variavel_configurador_qudr($variaveis, 'QUCM2'),
        'QUBU2' => obter_variavel_configurador_qudr($variaveis, 'QUBU2'),
        'QUCP' => obter_variavel_configurador_qudr($variaveis, 'QUCP'),
        'QUAF' => $quaf,
        'QUAS' => $quas,
        'QUCL' => $qucl,
    ];

    if (variavel_em_lista_qudr($qubr, ['375', '475', '575', '754', '755'])) {
        $paramsRedutor['QUVZ'] = $quvz;
    }
    if ($quln === '1.QP') {
        $paramsRedutor['QUUN'] = obter_variavel_configurador_qudr($variaveis, 'QUUN');
    }
    if ($quaf !== 'N') {
        $paramsRedutor['QUAFP'] = $quafp;
    }
    if ($quaf === 'BT') {
        $paramsRedutor['QUBT'] = $qubt;
    }
    if ($quas !== '' && !variavel_em_lista_qudr($quas, ['N', 'ED', 'ED30'])) {
        $paramsRedutor['QUASP'] = $quasp;
    }
    if ($quln === '1.QDR') {
        $paramsRedutor['QUPOC'] = obter_variavel_configurador_qudr($variaveis, 'QUPOC');
        $paramsRedutor['QUPOL'] = obter_variavel_configurador_qudr($variaveis, 'QUPOL');
    }
    if ($qucl !== 'N') {
        $paramsRedutor['QUCLP'] = $quclp;
    }

    $opcionaisRedutor = ajustar_lista_configurador_qudr(obter_variavel_configurador_qudr($variaveis, 'QUOPC'));
    if ($opcionaisRedutor !== '') {
        $paramsRedutor['QUOPC'] = $opcionaisRedutor;
    }

    $qumo = obter_variavel_configurador_qudr($variaveis, 'QUMO');
    if ($qumo === 'MS') {
        return '';
    }

    $paramsMotor = ['QUMO' => $qumo];
    if ($qumo === 'S') {
        $moln = obter_variavel_configurador_qudr($variaveis, 'MOLN');
        $mocp = obter_variavel_configurador_qudr($variaveis, 'MOCP');
        $mocpp = obter_variavel_configurador_qudr($variaveis, 'MOCPP');
        $paramsMotor += [
            'MOLN' => $moln,
            'MOTP' => obter_variavel_configurador_qudr($variaveis, 'MOTP'),
            'MOTT' => obter_variavel_configurador_qudr($variaveis, 'MOTT'),
            'MOFQ' => obter_variavel_configurador_qudr($variaveis, 'MOFQ'),
            'MOPT' => obter_variavel_configurador_qudr($variaveis, 'MOPT'),
            'MOPL' => obter_variavel_configurador_qudr($variaveis, 'MOPL'),
            'MOCP' => $mocp,
            'MOPC' => obter_variavel_configurador_qudr($variaveis, 'MOPC'),
            'MOPP' => obter_variavel_configurador_qudr($variaveis, 'MOPP'),
        ];
        if ($moln !== '3.W' && variavel_em_lista_qudr($mocp, ['B3', 'B34', 'B35']) && $mocpp !== 'T') {
            $paramsMotor['MOCPP'] = $mocpp;
        }

        $opcionaisMotorOriginal = obter_variavel_configurador_qudr($variaveis, 'MOOPC');
        $opcionaisMotor = ajustar_lista_configurador_qudr($opcionaisMotorOriginal);
        if ($opcionaisMotor !== '') {
            $paramsMotor['MOOPC'] = $opcionaisMotor;
        }
        if (variavel_contem_qudr($opcionaisMotorOriginal, 'CR')) {
            $paramsMotor['MOCR'] = obter_variavel_configurador_qudr($variaveis, 'MOCR');
            $paramsMotor['QUCR'] = obter_variavel_configurador_qudr($variaveis, 'QUCR');
        }
    }

    $paramsVariador = ['QUVA' => obter_variavel_configurador_qudr($variaveis, 'QUVA')];
    if ($paramsVariador['QUVA'] === 'S') {
        $paramsVariador += [
            'VALN' => obter_variavel_configurador_qudr($variaveis, 'VALN'),
            'VAPT' => obter_variavel_configurador_qudr($variaveis, 'VAPT'),
            'VAECP' => obter_variavel_configurador_qudr($variaveis, 'VAECP'),
            'VASCP' => obter_variavel_configurador_qudr($variaveis, 'VASCP'),
            'VAPM' => obter_variavel_configurador_qudr($variaveis, 'VAPM'),
        ];
    }

    $paramsAdaptacao = ['QUPL' => obter_variavel_configurador_qudr($variaveis, 'QUPL')];
    if ($paramsAdaptacao['QUPL'] === 'S') {
        $paramsAdaptacao += [
            'PLDE' => obter_variavel_configurador_qudr($variaveis, 'PLDE'),
            'PLBU' => obter_variavel_configurador_qudr($variaveis, 'PLBU'),
            'PLGU' => obter_variavel_configurador_qudr($variaveis, 'PLGU'),
            'PLFU' => obter_variavel_configurador_qudr($variaveis, 'PLFU'),
        ];
    }

    $params = array_merge($paramsRedutor, $paramsMotor, $paramsVariador, $paramsAdaptacao);
    $query = montar_query_configurador_qudr($params);

    return $query !== '' ? "{$linkSite}{$linharedutor}?{$query}" : '';
}
