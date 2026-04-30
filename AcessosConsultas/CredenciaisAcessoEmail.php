<?php

declare(strict_types=1);

function load_smtp_secrets(): void
{
    static $loaded = false;

    if ($loaded) {
        return;
    }

    $loaded = true;

    $defaultConfigPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Configuracoes' . DIRECTORY_SEPARATOR . 'AcessoEmail.ini';
    $configPath = getenv('SMTP_CONFIG_FILE');

    if ($configPath !== false) {
        $configPath = trim($configPath);
    }

    if ($configPath === false || $configPath === '') {
        $configPath = $defaultConfigPath;
    } elseif (!is_file($configPath) && is_file($defaultConfigPath)) {
        $configPath = $defaultConfigPath;
    }

    if (!is_file($configPath)) {
        throw new RuntimeException(sprintf('Arquivo de credenciais SMTP não encontrado em %s.', $configPath));
    }

    $config = parse_ini_file($configPath, false, INI_SCANNER_RAW);

    if ($config === false) {
        throw new RuntimeException(sprintf('Não foi possível ler o arquivo de credenciais SMTP em %s.', $configPath));
    }

    foreach (['SMTP_HOST', 'SMTP_USER', 'SMTP_PASS', 'SMTP_SECURE', 'SMTP_PORT'] as $key) {
        if (!array_key_exists($key, $config)) {
            continue;
        }

        $value = (string) $config[$key];

        if ($value === '') {
            continue;
        }

        $current = getenv($key);

        if ($current === false || $current === '') {
            putenv(sprintf('%s=%s', $key, $value));
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

function get_smtp_env_value(string $name): ?string
{
    $value = getenv($name);

    if ($value !== false && $value !== '') {
        return $value;
    }

    if (isset($_SERVER[$name]) && $_SERVER[$name] !== '') {
        return (string) $_SERVER[$name];
    }

    if (isset($_ENV[$name]) && $_ENV[$name] !== '') {
        return (string) $_ENV[$name];
    }

    return null;
}

function require_smtp_env(string $name): string
{
    load_smtp_secrets();

    $value = get_smtp_env_value($name);

    if ($value === null) {
        throw new RuntimeException(sprintf('A variável de ambiente %s deve ser definida para configurar o SMTP.', $name));
    }

    return $value;
}

$smtpHost = require_smtp_env('SMTP_HOST');
$smtpUser = require_smtp_env('SMTP_USER');
$smtpPass = require_smtp_env('SMTP_PASS');
$smtpSecure = require_smtp_env('SMTP_SECURE');

$smtpPortValue = require_smtp_env('SMTP_PORT');

if (!ctype_digit($smtpPortValue)) {
    throw new RuntimeException('A variável de ambiente SMTP_PORT deve conter apenas dígitos.');
}

$smtpPort = (int) $smtpPortValue;