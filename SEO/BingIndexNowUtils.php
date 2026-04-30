<?php

function bingIndexNowResponderJson(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function bingIndexNowBaseDir(): string
{
    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
        return rtrim((string) $_SERVER['DOCUMENT_ROOT'], '/\\');
    }

    return rtrim(dirname(__DIR__), '/\\');
}

function bingIndexNowCarregarConfig(): array
{
    $baseDir = bingIndexNowBaseDir();
    $configPath = $baseDir . '/Configuracoes/BingIndexNow.ini';

    if (!is_readable($configPath)) {
        bingIndexNowResponderJson(500, [
            'status' => 'erro',
            'mensagem' => 'Arquivo de configuração do IndexNow não encontrado.',
            'configPath' => $configPath,
        ]);
    }

    $config = parse_ini_file($configPath, true, INI_SCANNER_TYPED);
    if ($config === false || !isset($config['IndexNow'])) {
        bingIndexNowResponderJson(500, [
            'status' => 'erro',
            'mensagem' => 'Falha ao ler o arquivo de configuração do IndexNow.',
            'configPath' => $configPath,
        ]);
    }

    $indexNow = $config['IndexNow'];
    $key = trim((string) ($indexNow['key'] ?? ''));
    $host = trim((string) ($indexNow['host'] ?? ''));
    $keyFilePath = trim((string) ($indexNow['key_file_path'] ?? ''));

    if ($key === '') {
        bingIndexNowResponderJson(500, [
            'status' => 'erro',
            'mensagem' => 'A chave do IndexNow não foi configurada.',
            'configPath' => $configPath,
        ]);
    }

    if ($keyFilePath === '') {
        $keyFilePath = '/' . $key . '.txt';
    }

    if ($keyFilePath[0] !== '/') {
        $keyFilePath = '/' . $keyFilePath;
    }

    $keyFilePath = preg_replace('#/+#', '/', $keyFilePath);

    $scheme = 'https';
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $scheme = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_PROTO'])[0];
    } elseif (!empty($_SERVER['REQUEST_SCHEME'])) {
        $scheme = (string) $_SERVER['REQUEST_SCHEME'];
    } elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $scheme = 'https';
    } elseif (!empty($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 80) {
        $scheme = 'http';
    }

    $scheme = strtolower(trim($scheme)) === 'http' ? 'http' : 'https';

    if ($host === '') {
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
    }

    if ($host === '') {
        bingIndexNowResponderJson(500, [
            'status' => 'erro',
            'mensagem' => 'Não foi possível determinar o host para o envio ao IndexNow.',
            'configPath' => $configPath,
        ]);
    }

    $keyLocation = $scheme . '://' . $host . $keyFilePath;
    $keyFileAbsolutePath = $baseDir . $keyFilePath;

    if (!is_readable($keyFileAbsolutePath)) {
        bingIndexNowResponderJson(500, [
            'status' => 'erro',
            'mensagem' => 'Arquivo de chave do IndexNow não encontrado no caminho configurado.',
            'key_file_path' => $keyFilePath,
            'key_file_absolute_path' => $keyFileAbsolutePath,
        ]);
    }

    $fileContent = trim((string) file_get_contents($keyFileAbsolutePath));
    if ($fileContent !== $key) {
        bingIndexNowResponderJson(500, [
            'status' => 'erro',
            'mensagem' => 'O conteúdo do arquivo de chave não corresponde à chave configurada.',
            'key_file_path' => $keyFilePath,
        ]);
    }

    return [
        'key' => $key,
        'host' => $host,
        'keyLocation' => $keyLocation,
        'keyFilePath' => $keyFilePath,
        'baseDir' => $baseDir,
    ];
}

function bingIndexNowNormalizarUrls(array $urls, string $host): array
{
    $normalizadas = [];

    foreach ($urls as $url) {
        if (!is_string($url)) {
            continue;
        }

        $url = trim($url);
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            continue;
        }

        $parsed = parse_url($url);
        if ($parsed === false) {
            continue;
        }

        $urlHost = strtolower((string) ($parsed['host'] ?? ''));
        if ($urlHost !== strtolower($host)) {
            continue;
        }

        $normalizadas[$url] = true;
    }

    return array_slice(array_keys($normalizadas), 0, 10000);
}

function bingIndexNowEnviar(array $config, array $urls): array
{
    $payload = [
        'host' => $config['host'],
        'key' => $config['key'],
        'keyLocation' => $config['keyLocation'],
        'urlList' => array_values($urls),
    ];

    $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ch = curl_init('https://api.indexnow.org/indexnow');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json; charset=utf-8',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS => $jsonPayload,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $responseBody = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'httpCode' => $httpCode,
        'responseBody' => $responseBody,
        'curlError' => $curlError,
        'payload' => $payload,
    ];
}
