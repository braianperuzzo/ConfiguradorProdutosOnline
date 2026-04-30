<?php
header('Content-Type: application/json; charset=UTF-8');
$baseDir = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
if ($baseDir === '') {
    $baseDir = dirname(__DIR__, 2);
}

require $baseDir . '/Seguranca/MetodosSegurancao.php';
require $baseDir . '/AcessosConsultas/CredenciaisBancoDados.php';
require_once $baseDir . '/LogsErros/Logs.php';

$grupo = strtoupper(trim((string) ($_GET['IBCN'] ?? '')));
$linha = strtoupper(trim((string) ($_GET['IBLN'] ?? '')));
$reducaoBaseRaw = trim((string) ($_GET['CORD'] ?? ''));
$torqueBaseRaw = trim((string) ($_GET['COTR'] ?? ''));
$criterioReducaoMin = (float) ($_GET['criterioReducaoMin'] ?? -10);
$criterioReducaoMax = (float) ($_GET['criterioReducaoMax'] ?? 10);
$criterioTorqueMin = (float) ($_GET['criterioTorqueMin'] ?? -5);
$criterioTorqueMax = (float) ($_GET['criterioTorqueMax'] ?? 30);
$diametroSaida = trim((string) ($_GET['COVZ'] ?? ''));

$extrairPrimeiroNumero = static function (string $valor): ?float {
    $texto = trim($valor);
    if ($texto === '') {
        return null;
    }

    if (preg_match('/[-+]?\d+(?:[\.,]\d+)?/', $texto, $matches)) {
        return (float) str_replace(',', '.', $matches[0]);
    }

    return null;
};

$reducaoBaseNum = $extrairPrimeiroNumero($reducaoBaseRaw);
$torqueBaseNum = $extrairPrimeiroNumero($torqueBaseRaw);

if ($linha === '' || $reducaoBaseNum === null || $torqueBaseNum === null) {
    echo json_encode(['tamanhos' => '', 'reducoes' => '', 'diametros' => ''], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($criterioReducaoMin > $criterioReducaoMax) {
    [$criterioReducaoMin, $criterioReducaoMax] = [$criterioReducaoMax, $criterioReducaoMin];
}
if ($criterioTorqueMin > $criterioTorqueMax) {
    [$criterioTorqueMin, $criterioTorqueMax] = [$criterioTorqueMax, $criterioTorqueMin];
}
if ($grupo === '' || !preg_match('/^[A-Z0-9]+$/', $grupo)) {
    echo json_encode(['tamanhos' => '', 'reducoes' => '', 'diametros' => ''], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$colLinha = $grupo . 'LN';
$colTamanho = $grupo . 'BR';
$colReducao = $grupo . 'RD';
$colSaida = $grupo . 'OS';
$colTorque = $grupo . 'OTO';

$tabelaBase = '_USR_CONF_' . $grupo . 'BR';
$tabelaSaida = '_USR_CONF_' . $grupo . 'OCS';
$tabelaTorque = '_USR_CONF_' . $grupo . 'OTORE';

$reducaoMin = $reducaoBaseNum * (1 + ($criterioReducaoMin / 100));
$reducaoMax = $reducaoBaseNum * (1 + ($criterioReducaoMax / 100));
$torqueMin = $torqueBaseNum * (1 + ($criterioTorqueMin / 100));
$torqueMax = $torqueBaseNum * (1 + ($criterioTorqueMax / 100));

if ($reducaoMin > $reducaoMax) {
    [$reducaoMin, $reducaoMax] = [$reducaoMax, $reducaoMin];
}
if ($torqueMin > $torqueMax) {
    [$torqueMin, $torqueMax] = [$torqueMax, $torqueMin];
}

$sql = "
    SELECT DISTINCT
        BASELINHA.$colTamanho AS TAMANHO,
        BASELINHA.$colReducao AS REDUCAO,
        SAIDA.$colSaida AS DIAMETRO
    FROM $tabelaBase AS BASELINHA
    INNER JOIN $tabelaSaida AS SAIDA
        ON BASELINHA.$colLinha = SAIDA.$colLinha
       AND BASELINHA.$colTamanho = SAIDA.$colTamanho
    INNER JOIN $tabelaTorque AS TORQUE
        ON BASELINHA.$colLinha = TORQUE.$colLinha
       AND BASELINHA.$colTamanho = TORQUE.$colTamanho
       AND BASELINHA.$colReducao = TORQUE.$colReducao
    WHERE BASELINHA.$colLinha = ?
      AND TRY_CONVERT(decimal(18,4), REPLACE(BASELINHA.$colReducao, ',', '.')) BETWEEN ? AND ?
      AND TRY_CONVERT(decimal(18,4), REPLACE(TORQUE.$colTorque, ',', '.')) BETWEEN ? AND ?
      AND (? = '' OR SAIDA.$colSaida = ?)
      AND UPPER(LTRIM(RTRIM(BASELINHA.$colTamanho))) <> 'BR'
      AND UPPER(LTRIM(RTRIM(BASELINHA.$colReducao))) <> 'RD'
      AND UPPER(LTRIM(RTRIM(SAIDA.$colSaida))) <> 'OS'
";

$montarSqlDetalhes = static function () use ($sql): string {
    return $sql . "\n ORDER BY TAMANHO, REDUCAO, DIAMETRO";
};

$montarParamsDetalhes = static function () use ($linha, $reducaoMin, $reducaoMax, $torqueMin, $torqueMax, $diametroSaida): array {
    return [$linha, $reducaoMin, $reducaoMax, $torqueMin, $torqueMax, $diametroSaida, $diametroSaida];
};

try {
    $pdo = new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
    ]);

    $carregarValores = static function (PDOStatement $stmt) {
        $tamanhos = [];
        $reducoes = [];
        $diametros = [];
        $reducoesPorTamanho = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $tamanhoValor = trim((string) ($row['TAMANHO'] ?? ''));
            $reducaoValor = trim((string) ($row['REDUCAO'] ?? ''));
            $diametroValor = trim((string) ($row['DIAMETRO'] ?? ''));

            if ($tamanhoValor !== '') {
                $tamanhos[$tamanhoValor] = true;
            }
            if ($reducaoValor !== '') {
                $reducoes[$reducaoValor] = true;
            }
            if ($diametroValor !== '') {
                $diametros[$diametroValor] = true;
            }
            if ($tamanhoValor !== '' && $reducaoValor !== '') {
                if (!isset($reducoesPorTamanho[$tamanhoValor])) {
                    $reducoesPorTamanho[$tamanhoValor] = [];
                }
                $reducoesPorTamanho[$tamanhoValor][$reducaoValor] = true;
            }
        }

        foreach ($reducoesPorTamanho as $tamanho => $listaReducao) {
            $reducoesPorTamanho[$tamanho] = array_keys($listaReducao);
        }

        return [$tamanhos, $reducoes, $diametros, $reducoesPorTamanho];
    };

    $stmt = $pdo->prepare($montarSqlDetalhes());
    $stmt->execute($montarParamsDetalhes());
    [$tamanhos, $reducoes, $diametros, $reducoesPorTamanho] = $carregarValores($stmt);

    echo json_encode([
        'tamanhos' => implode(' / ', array_keys($tamanhos)),
        'reducoes' => implode(' / ', array_keys($reducoes)),
        'diametros' => implode(' / ', array_keys($diametros)),
        'reducoesPorTamanho' => $reducoesPorTamanho
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $erro) {
    if (function_exists('log_event')) {
        log_event('Falha ao consultar detalhes de equivalência IBR: ' . $erro->getMessage());
    }
    http_response_code(500);
    echo json_encode(['tamanhos' => '', 'reducoes' => '', 'diametros' => ''], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
