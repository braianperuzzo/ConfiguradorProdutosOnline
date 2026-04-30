<?php
/**
 * Retorna a versão atual da aplicação definida em Versionamento/Versao.json.
 * O valor é armazenado em cache em memória para evitar leituras repetidas em uma mesma requisição.
 * Caso o arquivo não esteja acessível ou não contenha uma versão válida,
 * retorna o fallback "versao.desconhecida".
 */
function obterVersaoAplicacao()
{
    static $versaoCache = null;

    if ($versaoCache !== null) {
        return $versaoCache;
    }

    $configPath = __DIR__ . '/Versao.json';

    if (is_readable($configPath)) {
        $conteudo = file_get_contents($configPath);

        if ($conteudo !== false) {
            $dados = json_decode($conteudo, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                $candidata = null;

                if (isset($dados['version']) && is_string($dados['version'])) {
                    $candidata = $dados['version'];
                } elseif (isset($dados['appVersion']) && is_string($dados['appVersion'])) {
                    $candidata = $dados['appVersion'];
                }

                if ($candidata !== null) {
                    $candidata = trim($candidata);

                    if ($candidata !== '') {
                        $versaoCache = $candidata;
                        return $versaoCache;
                    }
                }
            }
        }
    }

    $versaoCache = 'versao.desconhecida';
    return $versaoCache;
}