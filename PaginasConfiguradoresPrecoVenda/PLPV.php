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
    echo json_encode(['001' => '0,00', '003' => '0,00']);
    return;
}

$pdo = new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
]);

try {
    $stmtPessoa = $pdo->prepare("SELECT TOP 1 CD_CATEGORIA FROM MBAD_PESSOA WHERE CD_PESSOA = ?");
    $stmtPessoa->execute([$pessoa]);
    $rowPessoa = $stmtPessoa->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $rowPessoa = [];
}

$categoria = (($rowPessoa['CD_CATEGORIA'] ?? '') === '9') ? 'PR' : 'PN';
$coluna = 'PL' . $categoria;

$plln = $_POST['PLLN'] ?? '';
$plbr = $_POST['PLBR'] ?? '';
$plet = $_POST['PLET'] ?? '';
$pltp = $_POST['PLTP'] ?? '';

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
        $cat = trim($rowPes['CD_CATEGORIA'] ?? '');
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

        if (in_array($cat, ['3','8'], true) && $ncm === '84834010') {
            $novo += $novo * 0.04;
        }

        if (in_array($cat, ['2','5'], true) && $ncm === '84834010') {
            $novo += $novo * 0.05;
        }

        if (in_array($cat, ['2','5','111'], true) && in_array($marca, ['I4','I44','I61'], true)) {
            $novo += $novo * 0.06;
        }

        return $novo;
    } catch (PDOException $e) {
        return $preco;
    }
}

$preco = procvm($pdo, '_USR_CONF_PLPV', ['PLLN','PLBR','PLET','PLTP'], $coluna, [$plln,$plbr,$plet,$pltp]);
if ($preco <= 0) {
    $preco = 0.0;
}

$codigoProduto = $_POST['produto'] ?? '';
if ($codigoProduto) {
    $preco = aplicar_descontos($pdo, $codigoProduto, $pessoa, $preco);
}

$preco = round($preco, 2);

echo json_encode([
    '001' => number_format($preco, 2, ',', '.'),
    '003' => number_format($preco, 2, ',', '.')
]);

$pdo = null;