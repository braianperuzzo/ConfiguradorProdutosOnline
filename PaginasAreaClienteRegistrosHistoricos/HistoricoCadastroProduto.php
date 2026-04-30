<?php
header('Content-Type: application/json; charset=UTF-8');
$baseDir = $_SERVER['DOCUMENT_ROOT'] ?: dirname(__DIR__);
require_once $baseDir . '/Seguranca/CSRF.php';
require_valid_csrf_token();
require $baseDir . '/Seguranca/MetodosSegurancao.php';
require_once $baseDir . '/LogsErros/Logs.php';
require $baseDir . '/AcessosConsultas/CredenciaisBancoDados.php';

$emailInput = filter_input(INPUT_POST, 'email', FILTER_UNSAFE_RAW) ?? '';
$emailParts = array_filter(array_map('trim', preg_split('/[;\s,]+/', $emailInput)));
$produto = strtoupper(trim(filter_input(INPUT_POST, 'produto', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? ''));
$oportunidade = trim(filter_input(INPUT_POST, 'oportunidade', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '');
$oportunidade = $oportunidade === '' ? null : $oportunidade;
$documento = preg_replace('/[^0-9A-Za-z]/', '', (string) (filter_input(INPUT_POST, 'documento', FILTER_UNSAFE_RAW) ?? ''));

if (!$emailParts || !$produto || $documento === '') {
    echo json_encode(['erro' => 'Dados incompletos']);
    exit;
}

try {
    $pdo = new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
    ]);

    $sql = "INSERT INTO _USR_CONF_SITE_HISTORICO_CADASTROS (DS_EMAIL, NR_CPFCNPJ, DS_REFERENCIA, CD_OPORTUNIDADE, DT_DATA)
            SELECT ?, ?, ?, ?, CONVERT(VARCHAR(19), GETDATE(), 120)
             WHERE NOT EXISTS (
                 SELECT 1 FROM _USR_CONF_SITE_HISTORICO_CADASTROS
                  WHERE DS_EMAIL = ?
                    AND NR_CPFCNPJ = ?
                    AND DS_REFERENCIA = ?
                    AND ((CD_OPORTUNIDADE = ? AND ? IS NOT NULL) OR (CD_OPORTUNIDADE IS NULL AND ? IS NULL))
             )";
    $stmt = $pdo->prepare($sql);
    foreach ($emailParts as $email) {
        $email = strtolower($email);
        $stmt->execute([
            $email,
            $documento,
            $produto,
            $oportunidade,
            $email,
            $documento,
            $produto,
            $oportunidade,
            $oportunidade,
            $oportunidade
        ]);
    }

    echo json_encode(['sucesso' => true]);
    $pdo = null;
} catch (PDOException $e) {
    log_event('RegistrarCadastro: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['erro' => 'Erro ao registrar cadastro']);
}
?>