<?php

function token_blacklist_base_dir(): string
{
    $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
    if ($documentRoot !== '') {
        return rtrim($documentRoot, DIRECTORY_SEPARATOR);
    }
}

function token_blacklist_directory(): string
{
    return token_blacklist_base_dir() . '/Tokens/TokensInvalidos';
}

function blacklist_token(string $token): void {
    $dir = token_blacklist_directory();
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
        if (!file_exists($dir . '/web.config')) {
            file_put_contents($dir . '/web.config', "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>");
        }
    }
    $file = $dir . '/' . hash('sha256', $token) . '.blk';
    file_put_contents($file, 'revogado');
    chmod($file, 0600);
}

function is_token_blacklisted(string $token): bool {
    $dir = token_blacklist_directory();
    $file = $dir . '/' . hash('sha256', $token) . '.blk';
    return file_exists($file);
}
?>