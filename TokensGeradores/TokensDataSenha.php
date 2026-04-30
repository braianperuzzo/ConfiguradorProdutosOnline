<?php
function obter_base_dir(): string {
    $baseDir = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
    if ($baseDir === '') {
        $baseDir = dirname(__DIR__);
    }
    return $baseDir;
}

function criar_pdo_tokens_data_senha(): ?PDO
{
    $baseDir = obter_base_dir();
    $credenciaisArquivo = $baseDir . '/AcessosConsultas/CredenciaisBancoDados.php';
    if (!file_exists($credenciaisArquivo)) {
        return null;
    }

    require $credenciaisArquivo;
    if (!isset($dbhost, $db, $user, $password)) {
        return null;
    }

    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
    if (defined('PDO::SQLSRV_ATTR_ENCODING') && defined('PDO::SQLSRV_ENCODING_UTF8')) {
        $options[PDO::SQLSRV_ATTR_ENCODING] = PDO::SQLSRV_ENCODING_UTF8;
    }

    try {
        return new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, $options);
    } catch (Throwable $e) {
        return null;
    }
}

function get_password_expiration_timestamp(string $email): int
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return 0;
    }

    $pdo = criar_pdo_tokens_data_senha();
    if (!$pdo) {
        return 0;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT TOP 1
                COALESCE(
                    TRY_CONVERT(date, DT_DATAEXPIRACAO, 110),
                    TRY_CONVERT(date, DT_DATAEXPIRACAO, 120),
                    TRY_CONVERT(date, DT_DATAEXPIRACAO)
                ) AS DT_DATAEXPIRACAO_NORMALIZADA
            FROM _USR_CONF_SITE_CADASTROS
            WHERE LOWER(DS_EMAIL) = ?
              AND DT_DATAEXPIRACAO IS NOT NULL
            ORDER BY COALESCE(
                TRY_CONVERT(date, DT_DATAEXPIRACAO, 110),
                TRY_CONVERT(date, DT_DATAEXPIRACAO, 120),
                TRY_CONVERT(date, DT_DATAEXPIRACAO)
            ) DESC"
        );
        $stmt->execute([$email]);
        $valor = $stmt->fetchColumn();
        if (!$valor) {
            return 0;
        }

        return parse_password_expiration_timestamp($valor);
    } catch (Throwable $e) {
        return 0;
    }
}

function parse_password_expiration_timestamp($valor): int
{
    if ($valor instanceof DateTimeInterface) {
        return (int) $valor->setTime(23, 59, 59)->getTimestamp();
    }

    $data = trim((string) $valor);
    if ($data === '') {
        return 0;
    }

    $formatos = [
        'Y-m-d',
        'm-d-Y',
        'm/d/Y',
        'd-m-Y',
        'd/m/Y',
        'Y/m/d',
        'm-d-Y H:i:s',
        'm/d/Y H:i:s',
        'Y-m-d H:i:s',
        'Y-m-d H:i:s.u',
        'Y-m-d\TH:i:s.u',
        'Y-m-d\TH:i:s',
    ];

    foreach ($formatos as $formato) {
        $date = DateTimeImmutable::createFromFormat($formato, $data);
        if (!$date instanceof DateTimeImmutable) {
            continue;
        }

        $errors = DateTimeImmutable::getLastErrors();
        $semErros = !is_array($errors) || (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0);
        if (!$semErros) {
            continue;
        }

        return (int) $date->setTime(23, 59, 59)->getTimestamp();
    }

    $timestamp = strtotime($data . ' 23:59:59');
    return $timestamp !== false ? $timestamp : 0;
}

function get_password_timestamp($email) {
    $expiraEm = get_password_expiration_timestamp((string) $email);
    if ($expiraEm > 0) {
        return (int) ($expiraEm - (180 * 86400));
    }

    return 0;
}

function set_password_timestamp($email, $timestamp = null, $nome = '') {
    $timestamp = $timestamp ?? time();
    $email = strtolower(trim((string) $email));
    if ($email === '') {
        return;
    }

    $pdo = criar_pdo_tokens_data_senha();
    if (!$pdo) {
        return;
    }

    $dataExpiracao = date('Y-m-d', ((int) $timestamp) + (180 * 86400));

    try {
        $stmt = $pdo->prepare("UPDATE _USR_CONF_SITE_CADASTROS SET DT_DATAEXPIRACAO = ? WHERE LOWER(DS_EMAIL) = ?");
        $stmt->execute([$dataExpiracao, $email]);
    } catch (Throwable $e) {
        return;
    }
}

function password_expired($email, $dias = 180) {
    $expiraEm = get_password_expiration_timestamp((string) $email);
    if ($expiraEm > 0) {
        return time() > $expiraEm;
    }

    return true;
}
?>
