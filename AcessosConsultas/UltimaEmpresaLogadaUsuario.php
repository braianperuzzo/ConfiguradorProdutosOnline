<?php

declare(strict_types=1);

function preferencias_usuario_base_dir(): string
{
    static $baseDir = null;

    if ($baseDir !== null) {
        return $baseDir;
    }

    $globalBaseDir = isset($GLOBALS['baseDir']) ? trim((string)$GLOBALS['baseDir']) : '';
    if ($globalBaseDir !== '') {
        $baseDir = $globalBaseDir;
    } else {
        $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string)$_SERVER['DOCUMENT_ROOT']) : '';
            $baseDir = realpath(__DIR__ . '/..') ?: __DIR__;
    }

    return $baseDir;
}

function preferencias_usuario_directory(): string
{
    $dir = preferencias_usuario_base_dir() . '/Tokens/PreferenciasUsuarios';
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
        file_put_contents(
            $dir . '/web.config',
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?><configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>"
        );
    }
    return $dir;
}

function definir_ultima_empresa_usuario(string $email, string $documento, bool $habilitado = false, array $informacoes = []): void
{
    if (!$habilitado) {
        return;
    }

    $emailLimpo = strtolower(trim($email));
    $documentoLimpo = preg_replace('/[^0-9A-Za-z]/', '', $documento);

    if ($emailLimpo === '' || $documentoLimpo === '') {
        return;
    }

 $dir = preferencias_usuario_directory();
    $arquivo = $dir . '/' . hash('sha256', $emailLimpo) . '.json';

    $dados = [
        'ultimaEmpresa' => $documentoLimpo,
        'atualizadoEm' => time(),
    ];

    file_put_contents($arquivo, json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    chmod($arquivo, 0600);
}

function obter_ultima_empresa_usuario(string $email, bool $habilitado = false): string
{
    if (!$habilitado) {
        return '';
    }

    $emailLimpo = strtolower(trim($email));
    if ($emailLimpo === '') {
        return '';
    }

    $arquivo = preferencias_usuario_directory() . '/' . hash('sha256', $emailLimpo) . '.json';
    if (!is_file($arquivo) || !is_readable($arquivo)) {
        return '';
    }

    $conteudo = @file_get_contents($arquivo);
    if ($conteudo === false || $conteudo === '') {
        return '';
    }

    $dados = json_decode($conteudo, true);
    if (!is_array($dados)) {
        return '';
    }

    $documento = isset($dados['ultimaEmpresa']) ? (string)$dados['ultimaEmpresa'] : '';
    return preg_replace('/[^0-9A-Za-z]/', '', $documento);
}

function remover_preferencia_usuario_por_email(string $email): void
{
    $emailLimpo = strtolower(trim($email));
    if ($emailLimpo === '') {
        return;
    }

    $arquivo = preferencias_usuario_directory() . '/' . hash('sha256', $emailLimpo) . '.json';
    if (is_file($arquivo)) {
        @unlink($arquivo);
    }
}

function remover_preferencia_usuario_por_documento(string $email, string $documento): void
{
    $emailLimpo = strtolower(trim($email));
    $documentoLimpo = preg_replace('/[^0-9A-Za-z]/', '', $documento);

    if ($emailLimpo === '' || $documentoLimpo === '') {
        return;
    }

    $arquivo = preferencias_usuario_directory() . '/' . hash('sha256', $emailLimpo) . '.json';
    if (!is_file($arquivo) || !is_readable($arquivo)) {
        return;
    }

    $conteudo = @file_get_contents($arquivo);
    if ($conteudo === false || $conteudo === '') {
        return;
    }

    $dados = json_decode($conteudo, true);
    if (!is_array($dados)) {
        return;
    }

    $documentoRegistrado = preg_replace('/[^0-9A-Za-z]/', '', (string)($dados['ultimaEmpresa'] ?? ''));
    if ($documentoRegistrado === $documentoLimpo) {
        @unlink($arquivo);
    }
}