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

$emailInput = filter_input(INPUT_POST, 'email', FILTER_UNSAFE_RAW) ?? '';
$emailParts = array_filter(array_map('trim', preg_split('/[;\s,]+/', $emailInput)));
$produto = strtoupper(trim(filter_input(INPUT_POST, 'produto', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? ''));
$formatoRaw = filter_input(INPUT_POST, 'formato', FILTER_UNSAFE_RAW) ?? '';
$formatoDecodificado = html_entity_decode((string)$formatoRaw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$formato = strtoupper(trim($formatoDecodificado));
$drvwIdField = trim(filter_input(INPUT_POST, 'drvw_idfield', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '');
$documento = preg_replace('/[^0-9A-Za-z]/', '', (string) (filter_input(INPUT_POST, 'documento', FILTER_UNSAFE_RAW) ?? ''));

        $isCodigo = preg_match('/^[A-Z]{2,4}\.[0-9]{8}$/', $produto);
    if ($isCodigo) {
        $sqlRef = "SELECT TOP 1 DS_REFERENCIA FROM MMPR_PRODUTO WHERE CD_PRODUTO = ? AND ID_STATUS = 0 AND CD_PRODCONFIG IS NOT NULL";
        try {
            $stmRef = $pdo->prepare($sqlRef);
            $stmRef->execute([$produto]);
            $rowRef = $stmRef->fetch(PDO::FETCH_ASSOC);
            $produto = $rowRef['DS_REFERENCIA'] ?? $produto;
        } catch (PDOException $e) {
        }
    }

    if (!$emailParts || !$produto || !$formato || $documento === '') {
        echo '⚠️ Dados Incompletos.';
        exit;
    }
    
    $sql = "INSERT INTO _USR_CONF_SITE_HISTORICO_DESENHO (DS_EMAIL, NR_CPFCNPJ, DS_REFERENCIA, DS_FORMATO, DRVW_IDFIELD, DS_LINK, DT_DATA)
            SELECT ?, ?, ?, ?, ?, '', CONVERT(VARCHAR(19), GETDATE(), 120)
             WHERE NOT EXISTS (
                 SELECT 1 FROM _USR_CONF_SITE_HISTORICO_DESENHO
                  WHERE DS_EMAIL = ? AND NR_CPFCNPJ = ? AND DS_REFERENCIA = ? AND DS_FORMATO = ? AND DRVW_IDFIELD = ?
             )";
    $stm = $pdo->prepare($sql);
    foreach ($emailParts as $email) {
        $email = strtolower($email);
        $stm->execute([
            $email,
            $documento,
            $produto,
            $formato,
            $drvwIdField,
            $email,
            $documento,
            $produto,
            $formato,
            $drvwIdField,
            ''
        ]);
    }

    echo '✅ Histórico Salvo.';
    $pdo = null;
} catch (PDOException $e) {
    log_event('HistoricoDesenho: ' . $e->getMessage());
    http_response_code(500);
    echo '⚠️ Erro ao Salvar Dados.';
}
?>