<?php

function obter_variavel_configurador_va(array $variaveis, string $chave): string
{
    return trim((string) ($variaveis[$chave] ?? ''));
}

function variavel_em_lista_va(string $valor, array $lista): bool
{
    return in_array($valor, $lista, true);
}

function variavel_contem_va(string $valor, string $parte): bool
{
    if ($parte === '') {
        return false;
    }
    return mb_stripos($valor, $parte, 0, 'UTF-8') !== false;
}

function ajustar_lista_configurador_va(string $valor): string
{
    $valor = str_replace('NÃO', 'NÃO', $valor);
    return str_replace(', ', '%2C', $valor);
}

function montar_query_configurador_va(array $params): string
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

function montar_link_configurador_va(array $variaveis): string
{
    $linkSite = 'https://configurador.redutoresibr.com.br/Configurador';
    $valn = obter_variavel_configurador_va($variaveis, 'VALN');
    $linhavariador = str_replace('1.', 'IBR', $valn);

    $vamo = obter_variavel_configurador_va($variaveis, 'VAMO');
    if ($vamo === 'MS') {
        return '';
    }

    $paramsVariador = [
        'VALN' => $valn,
        'VAPT' => obter_variavel_configurador_va($variaveis, 'VAPT'),
        'VACM' => obter_variavel_configurador_va($variaveis, 'VACM'),
        'VAECP' => obter_variavel_configurador_va($variaveis, 'VAECP'),
        'VASCP' => obter_variavel_configurador_va($variaveis, 'VASCP'),
        'VAPM' => obter_variavel_configurador_va($variaveis, 'VAPM'),
    ];

    $paramsMotor = ['VAMO' => $vamo];
    if ($vamo === 'S') {
        $moln = obter_variavel_configurador_va($variaveis, 'MOLN');
        $mocp = obter_variavel_configurador_va($variaveis, 'MOCP');
        $mocpp = obter_variavel_configurador_va($variaveis, 'MOCPP');

        $paramsMotor += [
            'MOLN' => $moln,
            'MOTP' => obter_variavel_configurador_va($variaveis, 'MOTP'),
            'MOTT' => obter_variavel_configurador_va($variaveis, 'MOTT'),
            'MOFQ' => obter_variavel_configurador_va($variaveis, 'MOFQ'),
            'MOPT' => obter_variavel_configurador_va($variaveis, 'MOPT'),
            'MOPL' => obter_variavel_configurador_va($variaveis, 'MOPL'),
            'MOCP' => $mocp,
            'MOPC' => obter_variavel_configurador_va($variaveis, 'MOPC'),
            'MOPP' => obter_variavel_configurador_va($variaveis, 'MOPP'),
        ];

        if ($moln !== '3.W' && variavel_em_lista_va($mocp, ['B3', 'B34', 'B35']) && $mocpp !== 'T') {
            $paramsMotor['MOCPP'] = $mocpp;
        }

        $opcionaisMotorOriginal = obter_variavel_configurador_va($variaveis, 'MOOPC');
        $opcionaisMotor = ajustar_lista_configurador_va($opcionaisMotorOriginal);
        if ($opcionaisMotor !== '') {
            $paramsMotor['MOOPC'] = $opcionaisMotor;
        }

        if (variavel_contem_va($opcionaisMotorOriginal, 'CR')) {
            $paramsMotor['MOCR'] = obter_variavel_configurador_va($variaveis, 'MOCR');
            $paramsMotor['VACR'] = obter_variavel_configurador_va($variaveis, 'VACR');
        }
    }

    $params = array_merge($paramsVariador, $paramsMotor);
    $query = montar_query_configurador_va($params);

    return $query !== '' ? "{$linkSite}{$linhavariador}?{$query}" : '';
}
