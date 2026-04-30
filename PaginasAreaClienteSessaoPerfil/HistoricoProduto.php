<?php
header('Content-Type: text/html; charset=UTF-8');
$baseDir = $_SERVER['DOCUMENT_ROOT'] ?: dirname(__DIR__);
require_once $baseDir . '/Seguranca/CSRF.php';
require_valid_csrf_token();
require $baseDir . '/Seguranca/MetodosSegurancao.php';
require_once $baseDir . '/LogsErros/Logs.php';
require $baseDir . '/AcessosConsultas/CredenciaisBancoDados.php';

try {
    $pdo = new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
    ]);

    $email = trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL) ?? '');
    $produto = strtoupper(trim(filter_input(INPUT_POST, 'produto', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? ''));
    $documento = preg_replace('/[^0-9A-Za-z]/', '', (string)(filter_input(INPUT_POST, 'documento', FILTER_UNSAFE_RAW) ?? ''));
    $link = trim(filter_input(INPUT_POST, 'link', FILTER_SANITIZE_URL) ?? '');
    if ($link && (!filter_var($link, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $link))) {
        echo '⚠️ Link invalido.';
        exit;
    }

    if (!$email || !$produto || !$documento) {
        echo '⚠️ Dados Incompletos.';
        exit;
    }

    $sql = "INSERT INTO _USR_CONF_SITE_HISTORICO_PRODUTO (
                DS_EMAIL, NR_CPFCNPJ, DS_REFERENCIA, DS_LINK, DT_DATA
            ) VALUES (?, ?, ?, ?, GETDATE())";

    $stm = $pdo->prepare($sql);
    $stm->execute([$email, $documento, $produto, $link]);

    echo '✅ Histórico Salvo.';
    $pdo = null;
} catch (PDOException $e) {
    log_event('HistoricoProduto: ' . $e->getMessage());
    http_response_code(500);
    echo '⚠️ Erro ao Salvar Dados.';
}
?>