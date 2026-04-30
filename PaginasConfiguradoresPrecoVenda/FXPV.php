<?php
header('Content-Type: application/json; charset=UTF-8');
$baseDir = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
if ($baseDir === '') {
    $baseDir = dirname(__DIR__);
}
require $baseDir . '/Seguranca/MetodosSegurancao.php';
require $baseDir . '/AcessosConsultas/CredenciaisBancoDados.php';

require_once $baseDir . '/TokensGeradores/TokenPadraoJson.php';
require_once $baseDir . '/TokensGeradores/TokenInvalidacao.php';
require_once $baseDir . '/LogsErros/Logs.php';

$token = $_COOKIE['auth_token'] ?? '';
$segredo = getenv('JWT_SECRET');
if ($segredo === false) {
    $segredo = trim(file_get_contents($baseDir . '/Configuracoes/Segredo.jwt'));
}
$dadosToken = $token ? JWTHelper::decode($token, $segredo) : null;
if (!$dadosToken || is_token_blacklisted($token)) {
    $dadosToken = null;
}
$pessoa = trim($_POST['pessoa'] ?? '');
if ($pessoa === '') {
    $pessoa = $dadosToken['codigo'] ?? '';
}
if ($pessoa === '') {
    echo '';
    return;
}

$pdo = new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
]);

try {
    $stmtPessoa = $pdo->prepare("SELECT TOP 1 CD_CATEGORIA, CD_ESTADO FROM MBAD_PESSOA WHERE CD_PESSOA = ?");
    $stmtPessoa->execute([$pessoa]);
    $rowPessoa = $stmtPessoa->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $rowPessoa = [];
}

$fxmo  = $_POST['FXMO']  ?? 'N';
$fxaf  = $_POST['FXAF']  ?? 'N';
$fxas  = $_POST['FXAS']  ?? 'N';
$fxbu  = $_POST['FXBU']  ?? 'N';
$fxopc = $_POST['FXOPC'] ?? '';
$moopc = $_POST['MOOPC'] ?? '';
$fxva  = $_POST['FXVA']  ?? 'N';
$fxpl  = $_POST['FXPL']  ?? 'N';

$categoria = (($rowPessoa['CD_CATEGORIA'] ?? '') === '9') ? 'PR' : 'PN';
$codigoEstado = $rowPessoa['CD_ESTADO'] ?? '';

function procvm(PDO $pdo, $tabela, array $campos, $coluna, array $valores)
{
    if (!preg_match('/^[A-Z0-9_]+$/i', $coluna)) {
        return 0;
    }

    $attempt = 0;
    while (true) {
        $where = [];
        foreach ($campos as $c) {
            $where[] = "[$c] = ?";
        }
        $sql = "SELECT [$coluna] AS VALOR FROM $tabela WHERE " . implode(' AND ', $where);
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($valores);
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            if ($attempt === 0 && strpos($msg, 'Invalid column name') !== false &&
                preg_match("/'([^']+)'/", $msg, $m)) {
                $invalid = strtoupper($m[1]);
                $idx = array_search($invalid, $campos);
                if ($idx !== false) {
                    array_splice($campos, $idx, 1);
                    array_splice($valores, $idx, 1);
                    $attempt++;
                    continue;
                }
            }
            return 0;
        }
        $v = $stmt->fetchColumn();
        if ($v === false) {
            return 0;
        }
        $v = str_replace(['.', ','], ['', '.'], $v);
        return (float)$v;
    }
}

function aplicar_descontos(PDO $pdo, string $produto, string $pessoa, float $preco): float {
    if (!$produto || !$pessoa || $preco <= 0) return $preco;
    try {
        $stmtProd = $pdo->prepare('SELECT TOP 1 CD_FAMILIA, CD_GRUPO, CD_NCM, CD_MARCA FROM MMPR_PRODUTO WHERE CD_PRODUTO = ?');
        $stmtProd->execute([$produto]);
        $rowProd = $stmtProd->fetch(PDO::FETCH_ASSOC);
        if (!$rowProd) return $preco;

        $stmtPes = $pdo->prepare('SELECT DS_ATRIBUTO7, CD_CATEGORIA FROM MBAD_PESSOA WHERE CD_PESSOA = ?');
        $stmtPes->execute([$pessoa]);
        $rowPes = $stmtPes->fetch(PDO::FETCH_ASSOC) ?: [];
        $tabela = trim($rowPes['DS_ATRIBUTO7'] ?? '');
        $categoria = trim($rowPes['CD_CATEGORIA'] ?? '');
        if (!$tabela || !preg_match('/^[A-Z0-9_]+$/i', $tabela)) return $preco;

        $sql = "SELECT PC_DCTO1, PC_DCTO2, PC_DCTO3, PC_DCTO4 FROM $tabela WHERE CD_FAMILIA = ? AND CD_GRUPO = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$rowProd['CD_FAMILIA'], $rowProd['CD_GRUPO']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $novo = $preco;
        foreach (['PC_DCTO1','PC_DCTO2','PC_DCTO3','PC_DCTO4'] as $c) {
            $v = str_replace(',', '.', $row[$c] ?? '');
            if ($v === '' || !is_numeric($v)) continue;
            $v = (float)$v;
            if ($v > 0) {
                $novo -= $novo * ($v / 100);
            } elseif ($v < 0) {
                $novo += $novo * (abs($v) / 100);
            }
        }

        $ncm = trim($rowProd['CD_NCM'] ?? '');
        $marca = strtoupper(trim($rowProd['CD_MARCA'] ?? ''));

        if (in_array($categoria, ['3','8'], true) && $ncm === '84834010') {
            $novo += $novo * 0.04;
        }
        if (in_array($categoria, ['2','5'], true) && $ncm === '84834010') {
            $novo += $novo * 0.05;
        }
        if (in_array($categoria, ['2','5','111'], true) && in_array($marca, ['I4','I44','I61'], true)) {
            $novo += $novo * 0.06;
        }

        return $novo;
    } catch (PDOException $e) {
        return $preco;
    }
}

function calcular_preco(PDO $pdo, array $rowPessoa, array $post, string $companyCode, string $categoria, string $codigoEstado)
{
    $fxmo  = $post['FXMO']  ?? 'N';
    $fxaf  = $post['FXAF']  ?? 'N';
    $fxas  = $post['FXAS']  ?? 'N';
    $fxbu  = $post['FXBU']  ?? 'N';
    $fxopc = $post['FXOPC'] ?? '';
    $moopc = $post['MOOPC'] ?? '';
    $fxva  = $post['FXVA']  ?? 'N';
    $fxpl  = $post['FXPL']  ?? 'N';

    $companyCode = str_pad(trim($companyCode), 3, '0', STR_PAD_LEFT);
    $estado = '';
    if ($fxmo === 'S') {
        if ($companyCode === '001') {
            $estado = ($codigoEstado === '43') ? 'DE' : 'FE';
        } elseif ($companyCode === '003') {
            $estado = ($codigoEstado === '35') ? 'DE' : 'FE';
        }
    }

    $colunaBase = "FX{$categoria}BR";
    $colunaEixoEntrada = "FX{$categoria}EE";
    $colunaAcessorioFixacao = "FX{$categoria}{$fxaf}";
    $colunaAcessoriosSaida = "FX{$categoria}{$fxas}";
    $colunaKitReducao = "FX{$categoria}{$fxbu}";
    $colunaPintura = null;
    if (strpos($fxopc, 'PINTCZ') !== false) {
        $colunaPintura = "FX{$categoria}PINTCZ";
    } elseif (strpos($fxopc, 'ACOR') !== false) {
        $colunaPintura = "FX{$categoria}ACOR";
    }

    $lookupCampos = ['FXLN', 'FXBR', 'FXET', 'FXCM'];
    $valoresLookup = [
        $post['FXLN'] ?? '',
        $post['FXBR'] ?? '',
        $post['FXET'] ?? '',
        $post['FXCM'] ?? ''
    ];

    $precoRedutor = procvm($pdo, '_USR_CONF_FXPV', $lookupCampos, $colunaBase, $valoresLookup);
    $precoEixoEntrada = (strpos($post['FXCM'] ?? '', 'EE') !== false)
        ? procvm($pdo, '_USR_CONF_FXPV', $lookupCampos, $colunaEixoEntrada, $valoresLookup)
        : 0;
    $precoAcessorioFixacao = $fxaf !== 'N'
        ? procvm($pdo, '_USR_CONF_FXPV', $lookupCampos, $colunaAcessorioFixacao, $valoresLookup)
        : 0;
    $precoAcessoriosSaida = $fxas !== 'N'
        ? procvm($pdo, '_USR_CONF_FXPV', $lookupCampos, $colunaAcessoriosSaida, $valoresLookup)
        : 0;
    $precoKitReducao = $fxbu !== 'N'
        ? procvm(
            $pdo,
            '_USR_CONF_FXBUPV',
            ['FXLN', 'FXBR', 'FXCM', 'FXBU'],
            $colunaKitReducao,
            [$post['FXLN'] ?? '', $post['FXBR'] ?? '', $post['FXCM'] ?? '', $fxbu]
        )
        : 0;
    $precoPintura = $colunaPintura
        ? procvm($pdo, '_USR_CONF_FXPV', $lookupCampos, $colunaPintura, $valoresLookup)
        : 0;

    if ((strpos($post['FXCM'] ?? '', 'EE') !== false && !$precoEixoEntrada) ||
        ($fxaf !== 'N' && !$precoAcessorioFixacao) ||
        (($fxas !== 'N' && $fxas !== 'SX') && !$precoAcessoriosSaida) ||
        ($fxbu !== 'N' && !$precoKitReducao) ||
        ((strpos($fxopc, 'PINTCZ') !== false || strpos($fxopc, 'ACOR') !== false) && !$precoPintura)) {
        $precoVendaRedutor = 0;
    } else {
        $precoVendaRedutor = $precoRedutor + $precoEixoEntrada + $precoAcessorioFixacao +
            $precoAcessoriosSaida + $precoKitReducao + $precoPintura;
    }

    $tabelaPrecoMotor = $fxmo === 'S' ? "_USR_CONF_MOPV_{$estado}" : '';
    $tabelaPrecoOpcMotor = $fxmo === 'S' ? "_USR_CONF_MOOPCPV_{$estado}" : '';

    $colunaMotor = $fxmo === 'S' ? "MO{$categoria}" . ($post['MOCP'] ?? '') : '';
    $colunaOpcMotor = null;
    if ($fxmo === 'S') {
        if (strpos($moopc, 'VF') !== false) {
            $colunaOpcMotor = "MO{$categoria}VF";
        } elseif (strpos($moopc, 'CR') !== false) {
            $colunaOpcMotor = "MO{$categoria}CR";
        }
    }
    $colunaPinturaMotor = null;
    if ($fxmo === 'S') {
        if (strpos($moopc, 'PINTCZ') !== false) {
            $colunaPinturaMotor = "MO{$categoria}PINTCZ";
        } elseif (strpos($moopc, 'ACOR') !== false) {
            $colunaPinturaMotor = "MO{$categoria}ACOR";
        }
    }

    $carcaca100112 = $post['FXCM'] ?? '';
    if ($fxmo === 'S' && ($post['FXCM'] ?? '') === '100-112') {
        $carcaca100112 = procvm(
            $pdo,
            '_USR_CONF_MOBR',
            ['MOLN', 'MOTP', 'MOTT', 'MOFQ', 'MOPT', 'MOPL'],
            'MOCM',
            [
                $post['MOLN'] ?? '',
                $post['MOTP'] ?? '',
                $post['MOTT'] ?? '',
                $post['MOFQ'] ?? '',
                $post['MOPT'] ?? '',
                $post['MOPL'] ?? ''
            ]
        );
    }

    $lookupMotor = ['MOLN', 'MOTP', 'MOTT', 'MOFQ', 'MOPT', 'MOPL', 'MOCM'];
    $valoresMotor = [
        $post['MOLN'] ?? '',
        $post['MOTP'] ?? '',
        $post['MOTT'] ?? '',
        $post['MOFQ'] ?? '',
        $post['MOPT'] ?? '',
        $post['MOPL'] ?? '',
        (($post['FXCM'] ?? '') === '100-112') ? $carcaca100112 : ($post['FXCM'] ?? '')
    ];

    $precoMotor = ($fxmo === 'S')
        ? procvm($pdo, $tabelaPrecoMotor, $lookupMotor, $colunaMotor, $valoresMotor)
        : 0;

    if ($fxmo === 'S' && $colunaOpcMotor) {
        if (strpos($moopc, 'VF') !== false) {
            $precoOpcionalMotor = procvm($pdo, $tabelaPrecoMotor, $lookupMotor, $colunaOpcMotor, $valoresMotor);
        } elseif (strpos($moopc, 'CR') !== false) {
            $precoOpcionalMotor = procvm(
                $pdo,
                $tabelaPrecoOpcMotor,
                ['MOLN', 'MOCM', 'MOCP'],
                $colunaOpcMotor,
                [
                    $post['MOLN'] ?? '',
                    (($post['FXCM'] ?? '') === '100-112') ? $carcaca100112 : ($post['FXCM'] ?? ''),
                    $post['MOCP'] ?? ''
                ]
            );
        } else {
            $precoOpcionalMotor = 0;
        }
    } else {
        $precoOpcionalMotor = 0;
    }

    $precoPinturaMotor = ($fxmo === 'S' && $colunaPinturaMotor)
        ? procvm(
            $pdo,
            $tabelaPrecoMotor,
            ['MOLN', 'MOTP', 'MOTT', 'MOFQ', 'MOPT', 'MOPL', 'MOCM'],
            $colunaPinturaMotor,
            [
                $post['MOLN'] ?? '',
                $post['MOTP'] ?? '',
                $post['MOTT'] ?? '',
                $post['MOFQ'] ?? '',
                $post['MOPT'] ?? '',
                $post['MOPL'] ?? '',
                $post['FXCM'] ?? ''
            ]
        )
        : 0;

    if ($fxmo === 'N' || !$precoMotor ||
        ((strpos($moopc, 'VF') !== false || strpos($moopc, 'CR') !== false) && !$precoOpcionalMotor) ||
        ((strpos($moopc, 'PINTCZ') !== false || strpos($moopc, 'ACOR') !== false) && !$precoPinturaMotor)) {
        $precoVendaMotor = 0;
    } else {
        $precoVendaMotor = $precoMotor + $precoOpcionalMotor + $precoPinturaMotor;
    }

    $precoVendaVariador = ($fxva === 'S')
        ? procvm(
            $pdo,
            '_USR_CONF_VAPV',
            ['VALN', 'VAPT', 'VACM', 'VASCP'],
            "VA{$categoria}",
            [$post['VALN'] ?? '', $post['VAPT'] ?? '', $post['FXCM'] ?? '', $post['VASCP'] ?? '']
        )
        : 0;

    $colunaAdaptacao = $fxpl === 'S' ? "PL{$categoria}" : '';
    $diametro = $fxpl === 'S'
        ? (($post['PLBU'] ?? 'N') === 'N' ? ($post['PLDE'] ?? '') : ($post['PLBU'] ?? ''))
        : '';
    $precoVendaAdaptacao = ($fxpl === 'S')
        ? procvm($pdo, '_USR_CONF_PLASPV', ['PLCM', 'PLDE'], $colunaAdaptacao, [$post['FXCM'] ?? '', $diametro])
        : 0;

    if ($precoVendaRedutor == 0 ||
        ($fxmo === 'S' && $precoVendaMotor == 0) ||
        $fxmo === 'MS' ||
        ($fxva === 'S' && $precoVendaVariador == 0) ||
        ($fxpl === 'S' && $precoVendaAdaptacao == 0) ||
        ($fxmo !== 'N' && $categoria === 'PR')) {
        $precoTotal = 0;
    } else {
        $precoTotal = ($fxmo === 'S' ? ($precoVendaRedutor * 1.1111) : $precoVendaRedutor) +
            $precoVendaMotor + $precoVendaVariador + $precoVendaAdaptacao;
        $precoTotal = round($precoTotal, 2);
    }

    return $precoTotal;
}

$postData = $_POST;
$precoRS = calcular_preco($pdo, $rowPessoa, $postData, '001', $categoria, $codigoEstado);
$precoSP = calcular_preco($pdo, $rowPessoa, $postData, '003', $categoria, $codigoEstado);

$codigoProduto = $_POST['produto'] ?? '';
if ($codigoProduto) {
    $precoRS = aplicar_descontos($pdo, $codigoProduto, $pessoa, $precoRS);
    $precoSP = aplicar_descontos($pdo, $codigoProduto, $pessoa, $precoSP);
}

echo json_encode([
    '001' => number_format($precoRS, 2, ',', '.'),
    '003' => number_format($precoSP, 2, ',', '.')
]);

$pdo = null;