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

function calcular_preco(PDO $pdo, array $post, string $companyCode, string $categoria, string $codigoEstado, string $pessoa): float
{
    $vamo  = $post['VAMO'] ?? 'N';
    $moopc = $post['MOOPC'] ?? '';
    $vacm  = $post['VACM'] ?? '';

    $companyCode = str_pad(trim($companyCode), 3, '0', STR_PAD_LEFT);
    $estado = '';
    if ($vamo === 'S') {
        if ($companyCode === '001') {
            $estado = ($codigoEstado === '43') ? 'DE' : 'FE';
        } elseif ($companyCode === '003') {
            $estado = ($codigoEstado === '35') ? 'DE' : 'FE';
        }
    }

    $tabelaPrecoMotor = $vamo === 'S' ? "_USR_CONF_MOPV_{$estado}" : '';
    $tabelaPrecoOpcMotor = $vamo === 'S' ? "_USR_CONF_MOOPCPV_{$estado}" : '';

    $colunaVariador = "VA{$categoria}";
    $precoVariador = procvm(
        $pdo,
        '_USR_CONF_VAPV',
        ['VALN', 'VAPT', 'VACM', 'VASCP'],
        $colunaVariador,
        [
            $post['VALN'] ?? '',
            $post['VAPT'] ?? '',
            $vacm,
            $post['VASCP'] ?? ''
        ]
    );

    $colunaBaseMotor = "MO{$categoria}" . ($post['MOCP'] ?? '');
    $colunaOpcMotor = null;
    if (strpos($moopc, 'VF') !== false) {
        $colunaOpcMotor = "MO{$categoria}VF";
    } elseif (strpos($moopc, 'CR') !== false) {
        $colunaOpcMotor = "MO{$categoria}CR";
    }

    $colunaPinturaMotor = null;
    if (strpos($moopc, 'PINTCZ') !== false) {
        $colunaPinturaMotor = "MO{$categoria}PINTCZ";
    } elseif (strpos($moopc, 'ACOR') !== false) {
        $colunaPinturaMotor = "MO{$categoria}ACOR";
    }

    $carcaca100112 = '';
    if ($vamo === 'S') {
        $carcaca100112 = $vacm;
        if ($vacm === '100-112') {
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
    }

    $lookupMotor = ['MOLN', 'MOTP', 'MOTT', 'MOFQ', 'MOPT', 'MOPL', 'MOCM'];
    $valoresMotor = [
        $post['MOLN'] ?? '',
        $post['MOTP'] ?? '',
        $post['MOTT'] ?? '',
        $post['MOFQ'] ?? '',
        $post['MOPT'] ?? '',
        $post['MOPL'] ?? '',
        ($vacm === '100-112' ? $carcaca100112 : $vacm)
    ];

    $precoMotor = $vamo === 'S'
        ? procvm($pdo, $tabelaPrecoMotor, $lookupMotor, $colunaBaseMotor, $valoresMotor)
        : 0;

    if ($vamo === 'S' && $colunaOpcMotor) {
        if (strpos($moopc, 'VF') !== false) {
            $precoOpcMotor = procvm($pdo, $tabelaPrecoMotor, $lookupMotor, $colunaOpcMotor, $valoresMotor);
        } elseif (strpos($moopc, 'CR') !== false) {
            $precoOpcMotor = procvm(
                $pdo,
                $tabelaPrecoOpcMotor,
                ['MOLN', 'MOCM', 'MOCP'],
                $colunaOpcMotor,
                [
                    $post['MOLN'] ?? '',
                    $carcaca100112,
                    $post['MOCP'] ?? ''
                ]
            );
        } else {
            $precoOpcMotor = 0;
        }
    } else {
        $precoOpcMotor = 0;
    }

    $precoPinturaMotor = ($vamo === 'S' && $colunaPinturaMotor)
        ? procvm(
            $pdo,
            $tabelaPrecoMotor,
            $lookupMotor,
            $colunaPinturaMotor,
            [
                $post['MOLN'] ?? '',
                $post['MOTP'] ?? '',
                $post['MOTT'] ?? '',
                $post['MOFQ'] ?? '',
                $post['MOPT'] ?? '',
                $post['MOPL'] ?? '',
                ($vacm === '100-112' ? $carcaca100112 : $vacm)
            ]
        )
        : 0;

    if ($vamo === 'N' || !$precoMotor ||
        ((strpos($moopc, 'VF') !== false || strpos($moopc, 'CR') !== false) && !$precoOpcMotor) ||
        ((strpos($moopc, 'PINTCZ') !== false || strpos($moopc, 'ACOR') !== false) && !$precoPinturaMotor)) {
        $precoVendaMotor = 0;
    } else {
        $precoVendaMotor = $precoMotor + $precoOpcMotor + $precoPinturaMotor;
    }

    if (!$precoVariador ||
        ($vamo === 'S' && !$precoVendaMotor) ||
        $vamo === 'MS' ||
        ($vamo !== 'N' && $categoria === 'PR') ||
        !$pessoa) {
        return 0.0;
    }

    return round($precoVariador + $precoVendaMotor, 2);
}

$postData = $_POST;
$preco001 = calcular_preco($pdo, $postData, '001', $categoria, $codigoEstado, $pessoa);
$preco003 = calcular_preco($pdo, $postData, '003', $categoria, $codigoEstado, $pessoa);

$codigoProduto = $_POST['produto'] ?? '';
if ($codigoProduto) {
    $preco001 = aplicar_descontos($pdo, $codigoProduto, $pessoa, $preco001);
    $preco003 = aplicar_descontos($pdo, $codigoProduto, $pessoa, $preco003);
}

echo json_encode([
    '001' => number_format($preco001, 2, ',', '.'),
    '003' => number_format($preco003, 2, ',', '.')
]);

$pdo = null;