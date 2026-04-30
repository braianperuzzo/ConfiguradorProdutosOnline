<?php
function obter_nome_empresa(string $cpfcnpj, string $baseDir): string {
    $cpfcnpj = strtoupper(preg_replace('/[^0-9A-Z]/', '', $cpfcnpj));
    if (strlen($cpfcnpj) !== 14) {
        return '';
    }

    $cacheDir = $baseDir . '/Tokens/CacheEmpresa';
    $legacyCacheDir = $baseDir . '/Tokens/CacheEmpresaReceita';
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0700, true);
        if (!file_exists($cacheDir . '/web.config')) {
            file_put_contents($cacheDir . '/web.config', "<?xml version=\"1.0\" encoding=\"UTF-8\"?><configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>");
        }
    }
    $cacheFile = $cacheDir . '/' . $cpfcnpj . '.json';
    $legacyCacheFile = $legacyCacheDir . '/' . $cpfcnpj . '.json';

    if (!file_exists($cacheFile) && file_exists($legacyCacheFile)) {
        @rename($legacyCacheFile, $cacheFile);
    }

    if (file_exists($cacheFile)) {
        $dados = json_decode(file_get_contents($cacheFile), true);
        if (is_array($dados) && !empty($dados['nome'])) {
            return $dados['nome'];
        }
    }

    $ctx = stream_context_create([
        'http' => [
            'timeout' => 10,
            'user_agent' => 'ConfiguradorIBR'
        ]
    ]);
    $resp = @file_get_contents('https://www.receitaws.com.br/v1/cnpj/' . $cpfcnpj, false, $ctx);
    $info = $resp ? json_decode($resp, true) : null;
    $nome = $info['nome'] ?? ($info['nome_fantasia'] ?? ($info['fantasia'] ?? ''));
    if ($nome) {
        $nome = strtoupper($nome);
        file_put_contents($cacheFile, json_encode(['nome' => $nome]));
        chmod($cacheFile, 0600);
        return $nome;
    }
    return '';
}
?>
