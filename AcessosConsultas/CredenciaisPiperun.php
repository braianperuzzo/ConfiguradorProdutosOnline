<?php

declare(strict_types=1);

function load_pipe_token_from_file(): ?string
{
    static $cached = false;
    static $token = null;

    if ($cached) {
        return $token;
    }

    $cached = true;

    $configPath = getenv('PIPE_TOKEN_FILE');
    if ($configPath === false || trim($configPath) === '') {
        $configPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Configuracoes' . DIRECTORY_SEPARATOR . 'TokenPiperun.ini';
    }

    if (!is_file($configPath) || !is_readable($configPath)) {
        return null;
    }

    $config = parse_ini_file($configPath, false, INI_SCANNER_RAW);
    if ($config === false) {
        throw new RuntimeException(sprintf('Não foi possível ler o arquivo de token da PipeRun em %s.', $configPath));
    }

    $value = $config['PIPE_TOKEN'] ?? null;
    if ($value === null) {
        return null;
    }

    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    $token = $value;
    return $token;
}

function require_pipe_token(): string
{
    static $token = null;

    if ($token !== null) {
        return $token;
    }

    $envValue = getenv('PIPE_TOKEN');
    if ($envValue !== false) {
        $envValue = trim($envValue);
        if ($envValue !== '') {
            $token = $envValue;
            return $token;
        }
    }

    $fileValue = load_pipe_token_from_file();
    if ($fileValue !== null && $fileValue !== '') {
        $token = $fileValue;
        return $token;
    }

    throw new RuntimeException('Token da API PipeRun indisponível. Defina a variável PIPE_TOKEN ou forneça TokenPipe.ini.');
}