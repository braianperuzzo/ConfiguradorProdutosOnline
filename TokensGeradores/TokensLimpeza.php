<?php
if (!function_exists('converter_minusculas')) {
    function converter_minusculas(string $valor): string
    {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($valor, 'UTF-8');
        }
        return strtolower($valor);
    }
}

if (!function_exists('buscar_email_em_array')) {
    function buscar_email_em_array($dados, array $variacoes): bool
    {
        if (is_array($dados)) {
            foreach ($dados as $valor) {
                if (buscar_email_em_array($valor, $variacoes)) {
                    return true;
                }
            }
            return false;
        }

        if (is_string($dados)) {
            $texto = converter_minusculas($dados);
            foreach ($variacoes as $variacao) {
                if ($variacao !== '' && strpos($texto, $variacao) !== false) {
                    return true;
                }
            }
        }

        return false;
    }
}

if (!function_exists('arquivo_relacionado_ao_email')) {
    function arquivo_relacionado_ao_email(string $arquivo, string $email, array $variacoes): bool
    {
        $nomeArquivo = strtolower(basename($arquivo));
        foreach ($variacoes as $variacao) {
            if ($variacao !== '' && strpos($nomeArquivo, $variacao) !== false) {
                return true;
            }
        }

        $conteudo = @file_get_contents($arquivo);
        if ($conteudo === false || $conteudo === '') {
            return false;
        }

        $conteudoMinusculo = converter_minusculas($conteudo);
        foreach ($variacoes as $variacao) {
            if ($variacao !== '' && strpos($conteudoMinusculo, $variacao) !== false) {
                return true;
            }
        }

        $json = json_decode($conteudo, true);
        if (json_last_error() === JSON_ERROR_NONE && $json !== null) {
            if (buscar_email_em_array($json, $variacoes)) {
                return true;
            }
        }

        return false;
    }
}

function obter_base_dir_tokens(): string
{
    $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
    if ($documentRoot !== '') {
        return rtrim($documentRoot, DIRECTORY_SEPARATOR);
    }

    return dirname(__DIR__);
}

function obter_diretorio_logs(): string
{
    $baseDir = obter_base_dir_tokens();
    $logDir = $baseDir . '/LogsErros';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0700, true);
    }
    return $logDir;
}

function limpar_tokens_semanal(): void {
    $baseDir = obter_base_dir_tokens();
    $logDir = obter_diretorio_logs();
    $flag = $baseDir . '/Tokens/Tokens/.tokens_limpeza';
    $intervalo = 7 * 24 * 60 * 60; // uma semana

    if (file_exists($flag) && (time() - filemtime($flag)) < $intervalo) {
        return;
    }

    $pastas = [
        // HistoricoCarrinhos intencionalmente fora desta lista para manter os carrinhos armazenados.
        $baseDir . '/Tokens/TokensCadastro',
        $baseDir . '/Tokens/TokensEmail',
        $baseDir . '/Tokens/TokensExclusao',
        $baseDir . '/Tokens/TokensInvalidos',
        $baseDir . '/Tokens/TokensRecuperacao',
        $baseDir . '/Tokens/PreferenciasUsuarios',
    ];

    $arquivos = [
        $logDir . '/php_errors.log',
        $logDir . '/site.log',
    ];

    foreach ($pastas as $pasta) {
        if (is_dir($pasta)) {
            foreach (glob($pasta . '/*.json') as $arquivo) {
                @unlink($arquivo);
            }
            foreach (glob($pasta . '/*.log') as $arquivo) {
                @unlink($arquivo);
            }
        }
    }

    foreach ($arquivos as $arquivo) {
        if (is_file($arquivo)) {
            @unlink($arquivo);
        }
    }

  $diretorioFlag = dirname($flag);
    if (!is_dir($diretorioFlag)) {
        @mkdir($diretorioFlag, 0700, true);
    }

    @touch($flag);
    @chmod($flag, 0600);
    if (function_exists('log_event')) {
        log_event('Limpeza semanal de tokens executada.', [
            'nivel' => 'info',
            'componente' => 'tokens',
        ]);
    }
}

function limpar_tokens_usuario(string $email, array $opcoes = []): void {
    $baseDir = obter_base_dir_tokens();
    $logDir = obter_diretorio_logs();
    $email = strtolower(trim($email));
    if ($email === '') {
        return;
    }

    $preservarDispositivos = !empty($opcoes['preservarDispositivos']);
    $preservarSenha = !empty($opcoes['preservarSenha']);

    $variacoes = array_values(array_filter(array_unique([
        $email,
        str_replace(['@', '.', '+'], '_', $email),
        str_replace(['@', '.', '+'], '-', $email),
        preg_replace('/[^a-z0-9]/i', '', $email),
        preg_replace('/[^a-z0-9]/i', '_', $email),
        preg_replace('/[^a-z0-9]/i', '-', $email),
        hash('sha1', $email),
        hash('sha256', $email),
    ]), static function ($valor) {
        return is_string($valor) && strlen($valor) >= 3;
    }));

    $pastasPadrao = [
        $baseDir . '/Tokens/TokensDispositivos',
        $baseDir . '/Tokens/TokensRecuperacao',
        $baseDir . '/Tokens/TokensEmail',
        $baseDir . '/Tokens/TokensCadastro',
        $baseDir . '/Tokens/TokensExclusao',
        $baseDir . '/Tokens/TokensDataSenha',
        $baseDir . '/Tokens/TokensInvalidos',
        $baseDir . '/Tokens/PreferenciasUsuarios',
        $logDir,
    ];
    
  if ($preservarDispositivos) {
        $pastasPadrao = array_filter($pastasPadrao, static function ($pasta) use ($baseDir) {
            return $pasta !== $baseDir . '/Tokens/TokensDispositivos';
        });
    }

    if ($preservarSenha) {
        $pastasPadrao = array_filter($pastasPadrao, static function ($pasta) use ($baseDir) {
            return $pasta !== $baseDir . '/Tokens/TokensDataSenha';
        });
    }

    $pastasDinamicas = glob($baseDir . '/Tokens/Tokens*', GLOB_ONLYDIR) ?: [];
    if ($preservarDispositivos || $preservarSenha) {
        $pastasDinamicas = array_filter($pastasDinamicas, static function ($pasta) use ($baseDir, $preservarDispositivos, $preservarSenha) {
            if ($preservarDispositivos && $pasta === $baseDir . '/Tokens/TokensDispositivos') {
                return false;
            }
            if ($preservarSenha && $pasta === $baseDir . '/Tokens/TokensDataSenha') {
                return false;
            }
            return true;
        });
    }
    $pastas = array_unique(array_merge($pastasPadrao, $pastasDinamicas));

    foreach ($pastas as $pasta) {
        if (!is_dir($pasta)) {
            continue;
        }
        foreach (glob($pasta . '/*', GLOB_NOSORT) as $arquivo) {
            if (!is_file($arquivo)) {
                continue;
            }
            if (arquivo_relacionado_ao_email($arquivo, $email, $variacoes)) {
                @unlink($arquivo);
            }
        }
    }

    if (function_exists('log_event')) {
    }
}
?>
