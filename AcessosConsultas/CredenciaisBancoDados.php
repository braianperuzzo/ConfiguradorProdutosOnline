<?php

if (!function_exists('load_database_secrets')) {
    function load_database_secrets(): void
    {
        static $loaded = false;

        if ($loaded) {
            return;
        }

        $loaded = true;

        $configPath = getenv('DB_CONFIG_FILE');

        if ($configPath === false || trim($configPath) === '') {
            $configPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Configuracoes' . DIRECTORY_SEPARATOR . 'BancoDados.ini';
        }

        if (!is_file($configPath)) {
            return;
        }

        $config = parse_ini_file($configPath, false, INI_SCANNER_RAW);

        if ($config === false) {
            throw new RuntimeException(sprintf('Não foi possível ler o arquivo de credenciais do banco em %s.', $configPath));
        }

        foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $key) {
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
}

if (!function_exists('get_env_value')) {
    function get_env_value(string $name): ?string
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
}

if (!function_exists('require_env')) {
    function require_env(string $name): string
    {
        load_database_secrets();

        $value = get_env_value($name);

        if ($value === null) {
            throw new RuntimeException(sprintf('A variável de ambiente %s deve ser definida.', $name));
        }

        return $value;
    }
}

$dbhost = require_env('DB_HOST');
$db = require_env('DB_NAME');
$user = require_env('DB_USER');
$password = require_env('DB_PASS');