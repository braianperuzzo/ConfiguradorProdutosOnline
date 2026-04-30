<?php
header('Content-Type: application/json; charset=UTF-8');
$baseDir = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
if ($baseDir === '') {
    $baseDir = dirname(__DIR__);
}

require $baseDir . '/Seguranca/MetodosSegurancao.php';
require_once $baseDir . '/TokensGeradores/TokenPadraoJson.php';
require_once $baseDir . '/LogsErros/Logs.php';
require $baseDir . '/AcessosConsultas/CredenciaisBancoDados.php';

$token = $_COOKIE['auth_token'] ?? '';
if (!$token) {
    http_response_code(401);
    echo json_encode(['erro' => '⚠️ Usuário não autenticado.']);
    exit;
}

$segredo = getenv('JWT_SECRET');
if ($segredo === false) {
    $segredo = trim(file_get_contents($baseDir . '/Configuracoes/Segredo.jwt'));
}

$dados = JWTHelper::decode($token, $segredo);
if (!$dados) {
    http_response_code(403);
    echo json_encode(['erro' => '⚠️ Token inválido ou expirado.']);
    exit;
}

$codigoToken = preg_replace('/\D/', '', (string) ($dados['codigo'] ?? ''));
$codigoRequest = preg_replace('/\D/', '', (string) (filter_input(INPUT_GET, 'codigo', FILTER_UNSAFE_RAW) ?? ''));
$codigo = $codigoToken !== '' ? $codigoToken : $codigoRequest;

if ($codigoRequest !== '' && $codigoToken !== '' && $codigoRequest !== $codigoToken) {
    http_response_code(403);
    echo json_encode(['erro' => '⚠️ Código inválido.']);
    exit;
}

if ($codigo === '') {
    echo json_encode([
        'sucesso' => true,
        'nomeVendedor' => '',
        'numeroCelular' => '',
        'numeroTelefone' => '',
        'emailVendedor' => ''
    ]);
    exit;
}

$sql = "WITH FORMATA AS (\n" .
    "SELECT NOMEVENDEDOR.NM_PESSOA AS NomeVendedor,\n" .
    "NOMEVENDEDOR.DS_EMAIL AS EmailVendedor,\n" .
    "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(NOMEVENDEDOR.NR_CELULAR,'(',''),')',''),'-',''),' ',''),'+',''),'.',''),'/','') AS CelularFormatado,\n" .
    "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(NOMEVENDEDOR.NR_TELEFONE,'(',''),')',''),'-',''),' ',''),'+',''),'.',''),'/','') AS TelefoneFormatado\n" .
    "FROM _USR_AD_PESSOA AS TABELAPESSOA\n" .
    "INNER JOIN MBAD_PESSOA AS NOMEVENDEDOR ON TABELAPESSOA.CD_VENDEDOR = NOMEVENDEDOR.CD_PESSOA\n" .
    "WHERE TABELAPESSOA.CD_PESSOA = ?),\n" .
    "VALIDA AS (\n" .
    "SELECT NomeVendedor,\n" .
    "EmailVendedor,\n" .
    "RIGHT(CelularFormatado, CASE WHEN LEN(CelularFormatado) >= 11 THEN 11 ELSE 10 END) AS ValidaCelular,\n" .
    "RIGHT(TelefoneFormatado, CASE WHEN LEN(TelefoneFormatado) >= 11 THEN 11 ELSE 10 END) AS ValidaTelefone\n" .
    "FROM FORMATA)\n" .
    "SELECT NomeVendedor,\n" .
    "EmailVendedor,\n" .
    "(CASE WHEN LEN(ValidaCelular) = 11 THEN CONCAT('(', SUBSTRING(ValidaCelular,1,2), ') ', SUBSTRING(ValidaCelular,3,5), '-', SUBSTRING(ValidaCelular,8,4))\n" .
    "WHEN LEN(ValidaCelular) = 10 THEN CONCAT('(', SUBSTRING(ValidaCelular,1,2), ') ', SUBSTRING(ValidaCelular,3,4), '-', SUBSTRING(ValidaCelular,7,4))\n" .
    "ELSE NULL\n" .
    "END) AS NumeroCelular,\n" .
      "(CASE WHEN LEN(ValidaTelefone) = 11 THEN CONCAT('(', SUBSTRING(ValidaTelefone,1,2), ') ', SUBSTRING(ValidaTelefone,3,5), '-', SUBSTRING(ValidaTelefone,8,4))\n" .
    "WHEN LEN(ValidaTelefone) = 10 THEN CONCAT('(', SUBSTRING(ValidaTelefone,1,2), ') ', SUBSTRING(ValidaTelefone,3,4), '-', SUBSTRING(ValidaTelefone,7,4))\n" .
    "ELSE NULL\n" .
    "END) AS NumeroTelefone\n" .
    "FROM VALIDA";

    function formatarTelefone(string $digits): string
{
    $tamanho = strlen($digits);
    if ($tamanho === 11) {
        return sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 5), substr($digits, 7, 4));
    }

    if ($tamanho === 10) {
        return sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 4), substr($digits, 6, 4));
    }

    return '';
}

function normalizarCelular(string $celularDigits): string
{
    if (strlen($celularDigits) === 10) {
        $dddCelular = substr($celularDigits, 0, 2);
        $numeroLocal = substr($celularDigits, 2);

        return $dddCelular . '9' . $numeroLocal;
    }

    return $celularDigits;
}

try {
    $pdo = new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
    ]);

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$codigo]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $nomeVendedor = trim((string)($row['NomeVendedor'] ?? ''));
    $emailVendedor = trim((string)($row['EmailVendedor'] ?? ''));
    $numeroCelular = trim((string)($row['NumeroCelular'] ?? ''));
    $numeroTelefone = trim((string)($row['NumeroTelefone'] ?? ''));

    $celularDigits = preg_replace('/\D/', '', $numeroCelular);
    $telefoneDigits = preg_replace('/\D/', '', $numeroTelefone);

    if ($celularDigits !== '') {
        $celularDigits = normalizarCelular($celularDigits);
        $numeroCelular = formatarTelefone($celularDigits);
    }


    if ($telefoneDigits !== '') {
        $numeroTelefone = formatarTelefone($telefoneDigits);
    }

    echo json_encode([
        'sucesso' => true,
        'nomeVendedor' => $nomeVendedor,
        'numeroCelular' => $numeroCelular,
        'numeroTelefone' => $numeroTelefone,
        'emailVendedor' => $emailVendedor
    ]);
    $pdo = null;
} catch (PDOException $e) {
    log_event('BuscarVendedorResponsavel: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['erro' => '⚠️ Erro ao carregar vendedor responsável.']);
}
