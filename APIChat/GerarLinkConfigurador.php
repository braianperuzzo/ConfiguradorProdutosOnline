<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/NucleoApiChat.php';

const API_CHAT_ACAO_ENDPOINT = '/APIChat/GerarLinkConfigurador.php';

function responder_api_chat(int $status, array $payload): void
{
    $idempotencyKey = trim((string) ($GLOBALS['api_chat_idempotency_key'] ?? ''));
    if ($idempotencyKey !== '') {
        api_chat_middleware_idempotency_finalizar(API_CHAT_ACAO_ENDPOINT, $idempotencyKey, $status, $payload);
    }

    api_chat_middleware_responder($status, $payload);
}

function apontar_erro_schema_api_chat(string $codigoErro, string $mensagem, string $path): array
{
    return [
        'codigo' => $codigoErro,
        'mensagem' => $mensagem,
        'path' => $path,
    ];
}

function caminho_json_api_chat(string $base, $segmento): string
{
    if (is_int($segmento)) {
        return $base . '[' . $segmento . ']';
    }
    if ($base === '$') {
        return '$.' . $segmento;
    }
    return $base . '.' . $segmento;
}

function validar_json_schema_api_chat(array $schema, $valor, string $path = '$'): array
{
    if (isset($schema['type'])) {
        $tipo = $schema['type'];
        $okTipo = true;
        switch ($tipo) {
            case 'object':
                $okTipo = is_array($valor) && array_keys($valor) !== range(0, count($valor) - 1);
                break;
            case 'array':
                $okTipo = is_array($valor) && array_keys($valor) === range(0, count($valor) - 1);
                break;
            case 'string':
                $okTipo = is_string($valor);
                break;
            case 'number':
                $okTipo = is_int($valor) || is_float($valor);
                break;
            case 'integer':
                $okTipo = is_int($valor);
                break;
            case 'boolean':
                $okTipo = is_bool($valor);
                break;
            case 'null':
                $okTipo = $valor === null;
                break;
        }

        if (!$okTipo) {
            return apontar_erro_schema_api_chat('SCHEMA_TYPE_MISMATCH', 'Tipo inválido para o campo.', $path);
        }
    }

    if (array_key_exists('const', $schema) && $valor !== $schema['const']) {
        return apontar_erro_schema_api_chat('SCHEMA_CONST_MISMATCH', 'Valor diferente do esperado em const.', $path);
    }

    if (isset($schema['enum']) && is_array($schema['enum']) && !in_array($valor, $schema['enum'], true)) {
        return apontar_erro_schema_api_chat('SCHEMA_ENUM_MISMATCH', 'Valor fora do enum permitido.', $path);
    }

    if (is_string($valor)) {
        $tam = api_chat_strlen($valor);
        if (isset($schema['minLength']) && is_numeric($schema['minLength']) && $tam < (int) $schema['minLength']) {
            return apontar_erro_schema_api_chat('SCHEMA_MIN_LENGTH', 'Texto menor que o mínimo permitido.', $path);
        }
        if (isset($schema['maxLength']) && is_numeric($schema['maxLength']) && $tam > (int) $schema['maxLength']) {
            return apontar_erro_schema_api_chat('SCHEMA_MAX_LENGTH', 'Texto maior que o máximo permitido.', $path);
        }
        if (isset($schema['pattern']) && is_string($schema['pattern']) && @preg_match('/' . $schema['pattern'] . '/u', $valor) !== 1) {
            return apontar_erro_schema_api_chat('SCHEMA_PATTERN_MISMATCH', 'Texto não segue o padrão esperado.', $path);
        }
    }

    if ((is_int($valor) || is_float($valor)) && isset($schema['minimum']) && is_numeric($schema['minimum']) && $valor < $schema['minimum']) {
        return apontar_erro_schema_api_chat('SCHEMA_MINIMUM', 'Número abaixo do mínimo permitido.', $path);
    }
    if ((is_int($valor) || is_float($valor)) && isset($schema['maximum']) && is_numeric($schema['maximum']) && $valor > $schema['maximum']) {
        return apontar_erro_schema_api_chat('SCHEMA_MAXIMUM', 'Número acima do máximo permitido.', $path);
    }

    if (is_array($valor) && array_keys($valor) === range(0, count($valor) - 1)) {
        if (isset($schema['minItems']) && is_numeric($schema['minItems']) && count($valor) < (int) $schema['minItems']) {
            return apontar_erro_schema_api_chat('SCHEMA_MIN_ITEMS', 'Quantidade de itens abaixo do mínimo.', $path);
        }
        if (isset($schema['maxItems']) && is_numeric($schema['maxItems']) && count($valor) > (int) $schema['maxItems']) {
            return apontar_erro_schema_api_chat('SCHEMA_MAX_ITEMS', 'Quantidade de itens acima do máximo.', $path);
        }
        if (isset($schema['items']) && is_array($schema['items'])) {
            foreach ($valor as $idx => $item) {
                $erroItem = validar_json_schema_api_chat($schema['items'], $item, caminho_json_api_chat($path, $idx));
                if ($erroItem !== null) {
                    return $erroItem;
                }
            }
        }
    }

    if (is_array($valor) && array_keys($valor) !== range(0, count($valor) - 1)) {
        $props = isset($schema['properties']) && is_array($schema['properties']) ? $schema['properties'] : [];
        $required = isset($schema['required']) && is_array($schema['required']) ? $schema['required'] : [];

        foreach ($required as $nomeCampo) {
            if (!array_key_exists((string) $nomeCampo, $valor)) {
                return apontar_erro_schema_api_chat('SCHEMA_REQUIRED_MISSING', 'Campo obrigatório ausente.', caminho_json_api_chat($path, (string) $nomeCampo));
            }
        }

        foreach ($valor as $nomeCampo => $campoValor) {
            if (isset($props[$nomeCampo]) && is_array($props[$nomeCampo])) {
                $erroCampo = validar_json_schema_api_chat($props[$nomeCampo], $campoValor, caminho_json_api_chat($path, (string) $nomeCampo));
                if ($erroCampo !== null) {
                    return $erroCampo;
                }
                continue;
            }

            if (array_key_exists('additionalProperties', $schema) && $schema['additionalProperties'] === false) {
                return apontar_erro_schema_api_chat('SCHEMA_ADDITIONAL_PROPERTY', 'Campo não permitido pelo schema.', caminho_json_api_chat($path, (string) $nomeCampo));
            }
        }
    }

    return null;
}

function reparar_json_schema_api_chat(array $schema, $valor)
{
    if (isset($schema['type'])) {
        switch ($schema['type']) {
            case 'object':
                if (!is_array($valor) || array_keys($valor) === range(0, count($valor) - 1)) {
                    return [];
                }
                break;
            case 'array':
                if (!is_array($valor) || array_keys($valor) !== range(0, count($valor) - 1)) {
                    return [];
                }
                break;
            case 'string':
                $valor = is_scalar($valor) || $valor === null ? (string) $valor : '';
                break;
            case 'number':
                $valor = is_numeric($valor) ? (float) $valor : 0.0;
                break;
            case 'integer':
                $valor = is_numeric($valor) ? (int) $valor : 0;
                break;
            case 'boolean':
                $valor = (bool) $valor;
                break;
            case 'null':
                return null;
        }
    }

    if (is_array($valor) && array_keys($valor) === range(0, count($valor) - 1) && isset($schema['items']) && is_array($schema['items'])) {
        foreach ($valor as $idx => $item) {
            $valor[$idx] = reparar_json_schema_api_chat($schema['items'], $item);
        }
    }

    if (is_array($valor) && array_keys($valor) !== range(0, count($valor) - 1)) {
        $props = isset($schema['properties']) && is_array($schema['properties']) ? $schema['properties'] : [];
        $required = isset($schema['required']) && is_array($schema['required']) ? $schema['required'] : [];
        $allowAdditional = !array_key_exists('additionalProperties', $schema) || $schema['additionalProperties'] !== false;

        foreach ($required as $campo) {
            $campo = (string) $campo;
            if (!array_key_exists($campo, $valor)) {
                if (isset($props[$campo]['default'])) {
                    $valor[$campo] = $props[$campo]['default'];
                } else {
                    $valor[$campo] = null;
                }
            }
        }

        foreach ($valor as $campo => $campoValor) {
            if (isset($props[$campo]) && is_array($props[$campo])) {
                $valor[$campo] = reparar_json_schema_api_chat($props[$campo], $campoValor);
                continue;
            }

            if (!$allowAdditional) {
                unset($valor[$campo]);
            }
        }
    }

    return $valor;
}

function registrar_metrica_schema_api_chat(string $baseDir, string $modelo, string $schemaHash, string $policy, bool $conforme): void
{
    $logDir = $baseDir . '/APIChat/Logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0775, true);
    }

    $arquivo = $logDir . '/response-schema-metricas-' . date('Y-m-d') . '.jsonl';
    $linha = json_encode([
        'timestamp' => date('c'),
        'modelo' => $modelo,
        'schemaHash' => $schemaHash,
        'policy' => $policy,
        'conforme' => $conforme,
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    file_put_contents($arquivo, $linha, FILE_APPEND | LOCK_EX);
}

function obter_payload_api_chat(): array
{
    $raw = api_chat_middleware_raw_input();
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $json = json_decode($raw, true);
    return is_array($json) ? $json : [];
}

function normalizar_variaveis_api_chat(array $variaveis): array
{
    $saida = [];

    foreach ($variaveis as $chave => $valor) {
        $nome = strtoupper(trim((string) $chave));
        if ($nome === '') {
            continue;
        }

        if (is_scalar($valor) || $valor === null) {
            $saida[$nome] = normalizar_valor_variavel_api_chat($nome, (string) $valor);
        }
    }

    return $saida;
}

function normalizar_valor_variavel_api_chat(string $chave, string $valor): string
{
    $valorLimpo = trim($valor);
    if (preg_match('/OPC$/', $chave) === 1 || $chave === 'ITENS_OPCIONAIS') {
        return $valorLimpo;
    }
    if ($valorLimpo === '') {
        return '';
    }

    $maiusculo = strtoupper($valorLimpo);
    $normalizadoSemAcento = strtr($maiusculo, [
        'Á' => 'A',
        'À' => 'A',
        'Â' => 'A',
        'Ã' => 'A',
        'É' => 'E',
        'Ê' => 'E',
        'Í' => 'I',
        'Ó' => 'O',
        'Ô' => 'O',
        'Õ' => 'O',
        'Ú' => 'U',
        'Ç' => 'C',
    ]);

    $mapaBinario = [
        'SIM' => 'S',
        'S' => 'S',
        'NAO' => 'N',
        'NÃO' => 'N',
        'N' => 'N',
    ];

    if (isset($mapaBinario[$normalizadoSemAcento])) {
        return $mapaBinario[$normalizadoSemAcento];
    }
    if (isset($mapaBinario[$maiusculo])) {
        return $mapaBinario[$maiusculo];
    }

    return $valorLimpo;
}

function extrair_variaveis_texto_api_chat(string $texto): array
{
    $extraidas = [];
    $textoLimpo = trim($texto);
    if ($textoLimpo === '') {
        return $extraidas;
    }

    $queryDaMensagem = parse_url($textoLimpo, PHP_URL_QUERY);
    if (is_string($queryDaMensagem) && $queryDaMensagem !== '') {
        parse_str($queryDaMensagem, $paramsQuery);
        if (is_array($paramsQuery)) {
            foreach ($paramsQuery as $chave => $valor) {
                if (!is_scalar($valor) && $valor !== null) {
                    continue;
                }
                $extraidas[(string) $chave] = (string) $valor;
            }
        }
    }

    preg_match_all('/\b([A-Za-z_]{2,32})\s*(?:=|:)\s*([^\s,;|]+)/u', $textoLimpo, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        if (!isset($match[1], $match[2])) {
            continue;
        }
        $extraidas[(string) $match[1]] = rawurldecode((string) $match[2]);
    }

    if (!isset($extraidas['LINHA']) && !isset($extraidas['QULN'])) {
        if (preg_match('/\blinha(?:\s+do|\s+da)?(?:\s+[a-zçãõáéíóúâêô]+)?\s*:\s*([0-9]\.[A-Za-z0-9]+|[A-Za-z0-9]+)/iu', $textoLimpo, $matchLinha) === 1) {
            $extraidas['LINHA'] = rawurldecode((string) ($matchLinha[1] ?? ''));
        }
    }

    return normalizar_variaveis_api_chat($extraidas);
}


function normalizar_linha_configurador_api_chat(string $linha): string
{
    $linhaNormalizada = strtoupper(trim($linha));
    if ($linhaNormalizada === '') {
        return '';
    }

    if (preg_match('/^[0-9]\.[A-Z0-9]+$/', $linhaNormalizada) === 1) {
        return $linhaNormalizada;
    }

    if (preg_match('/^[A-Z]$/', $linhaNormalizada) === 1) {
        return '1.' . $linhaNormalizada;
    }

    return $linhaNormalizada;
}

function extrair_entrada_produto_api_chat(array $payload, string $falaSolicitante): string
{
    $candidatos = [
        (string) ($payload['codigo'] ?? ''),
        (string) ($payload['codigoProduto'] ?? ''),
        (string) ($payload['referencia'] ?? ''),
        (string) ($payload['referenciaEstruturada'] ?? ''),
        (string) ($payload['codigoOuReferencia'] ?? ''),
        (string) ($payload['DS_REFERENCIA'] ?? ''),
        (string) ($payload['descricao'] ?? ''),
        (string) ($payload['descricaoProduto'] ?? ''),
        (string) ($payload['DS_PRODUTO'] ?? ''),
        (string) ($payload['etiqueta'] ?? ''),
        (string) ($payload['nrEtiqueta'] ?? ''),
        (string) ($payload['NR_ETIQUETA'] ?? ''),
        $falaSolicitante,
    ];

    $regexCodigo = '/\b[A-Z]{2,4}\.[0-9]{8}\b/u';
    $regexReferencia = '/\b[0-9A-Z]+(?:\.[0-9A-Z]+){2,}\b/u';

    foreach ($candidatos as $texto) {
        $valor = strtoupper(trim($texto));
        if ($valor === '') {
            continue;
        }

        if (preg_match($regexCodigo, $valor, $match) === 1) {
            return trim((string) ($match[0] ?? ''));
        }

        if (preg_match($regexReferencia, $valor, $match) === 1) {
            return trim((string) ($match[0] ?? ''));
        }

        return trim((string) $texto);
    }

    return '';
}

function resolver_entrada_produto_api_chat(string $baseDir, string $entrada): array
{
    $entradaBruta = trim($entrada);
    $entradaNormalizada = strtoupper($entradaBruta);
    if ($entradaNormalizada === '') {
        return [];
    }

    $isCodigo = preg_match('/^[A-Z]{2,4}\.[0-9]{8}$/', $entradaNormalizada) === 1;
    $isReferencia = preg_match('/^(?=.{1,250}$)[0-9A-Z]+(\.[0-9A-Z]+){2,}$/', $entradaNormalizada) === 1;
    if ($isCodigo || $isReferencia) {
        return [
            'valor' => $entradaNormalizada,
            'fonte' => $isCodigo ? 'codigo' : 'referencia',
        ];
    }

    require $baseDir . '/AcessosConsultas/CredenciaisBancoDados.php';

    try {
        $pdo = new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8,
        ]);

        $entradaSemEspacos = str_replace(' ', '', $entradaNormalizada);
        $likeDescricao = '%' . $entradaBruta . '%';
        $sql = "SELECT TOP 1
                    PRODUTO.CD_PRODUTO,
                    PRODUTO.DS_REFERENCIA,
                    PRODUTO.DS_PRODUTO,
                    COALESCE(CAST(PRODUTO.NR_ETIQUETA AS NVARCHAR(MAX)), '') AS NR_ETIQUETA,
                    CASE
                        WHEN UPPER(PRODUTO.DS_REFERENCIA) = ? THEN 1
                        WHEN UPPER(PRODUTO.CD_PRODUTO) = ? THEN 1
                        WHEN REPLACE(UPPER(COALESCE(CAST(PRODUTO.NR_ETIQUETA AS NVARCHAR(MAX)), '')), ' ', '') = ? THEN 1
                        WHEN UPPER(COALESCE(CAST(PRODUTO.DS_PRODUTO AS NVARCHAR(MAX)), '')) = ? THEN 2
                        WHEN COALESCE(CAST(PRODUTO.DS_PRODUTO AS NVARCHAR(MAX)), '') LIKE ? THEN 3
                        ELSE 9
                    END AS ORDEM_MATCH
                FROM MMPR_PRODUTO AS PRODUTO
                WHERE PRODUTO.ID_STATUS = 0
                  AND PRODUTO.CD_PRODUTO LIKE '%.%'
                  AND PRODUTO.DS_REFERENCIA NOT LIKE '%2.WS%'
                  AND PRODUTO.DS_REFERENCIA NOT LIKE 'MS.%'
                  AND (
                    UPPER(PRODUTO.DS_REFERENCIA) = ?
                    OR UPPER(PRODUTO.CD_PRODUTO) = ?
                    OR REPLACE(UPPER(COALESCE(CAST(PRODUTO.NR_ETIQUETA AS NVARCHAR(MAX)), '')), ' ', '') = ?
                    OR UPPER(COALESCE(CAST(PRODUTO.DS_PRODUTO AS NVARCHAR(MAX)), '')) = ?
                    OR COALESCE(CAST(PRODUTO.DS_PRODUTO AS NVARCHAR(MAX)), '') LIKE ?
                  )
                ORDER BY ORDEM_MATCH ASC, PRODUTO.CD_PRODUTO ASC";

        $parametros = [
            $entradaNormalizada,
            $entradaNormalizada,
            $entradaSemEspacos,
            $entradaNormalizada,
            $likeDescricao,
            $entradaNormalizada,
            $entradaNormalizada,
            $entradaSemEspacos,
            $entradaNormalizada,
            $likeDescricao,
        ];

        $query = $pdo->prepare($sql);
        $query->execute($parametros);
        $registro = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($registro)) {
            return [];
        }

        $codigo = strtoupper(trim((string) ($registro['CD_PRODUTO'] ?? '')));
        if ($codigo === '') {
            return [];
        }

        $referencia = strtoupper(trim((string) ($registro['DS_REFERENCIA'] ?? '')));
        $descricao = strtoupper(trim((string) ($registro['DS_PRODUTO'] ?? '')));
        $etiqueta = strtoupper(str_replace(' ', '', trim((string) ($registro['NR_ETIQUETA'] ?? ''))));

        $fonte = 'descricao';
        if ($referencia !== '' && $referencia === $entradaNormalizada) {
            $fonte = 'referencia';
        } elseif ($codigo === $entradaNormalizada) {
            $fonte = 'codigo';
        } elseif ($etiqueta !== '' && $etiqueta === $entradaSemEspacos) {
            $fonte = 'etiqueta';
        } elseif ($descricao !== '' && $descricao === $entradaNormalizada) {
            $fonte = 'descricao_exata';
        }

        return [
            'valor' => $codigo,
            'fonte' => $fonte,
            'codigo' => $codigo,
            'referencia' => $referencia,
        ];
    } catch (Throwable $e) {
        return [];
    }
}

function buscar_estrutura_produto_api_chat(string $baseDir, string $entrada): array
{
    $entradaNormalizada = strtoupper(trim($entrada));
    if ($entradaNormalizada === '') {
        return [];
    }

    $isCodigo = preg_match('/^[A-Z]{2,4}\.[0-9]{8}$/', $entradaNormalizada) === 1;
    $isReferencia = preg_match('/^(?=.{1,250}$)[0-9A-Z]+(\.[0-9A-Z]+){2,}$/', $entradaNormalizada) === 1;
    if (!$isCodigo && !$isReferencia) {
        return [];
    }

    require $baseDir . '/AcessosConsultas/CredenciaisBancoDados.php';

    try {
        $pdo = new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8,
        ]);

        $sql = "SELECT
                PRODUTO.CD_PRODCONFIG,
                PRODUTO.DS_REFERENCIA,
                ESTRUTURA.NM_VARIAVEL,
                ESTRUTURA.CD_ITEM,
                MIN(ESTRUTURA.DS_VARIAVEL.value('(/VariavelOpcaoSimples/Valor)[1]', 'varchar(4000)')) AS RESPOSTA_SELETOR,
                MIN(ESTRUTURA.DS_VARIAVEL.value('(/VariavelOpcaoMultiplas/Valor)[1]', 'varchar(4000)')) AS RESPOSTA_SELETORMULTIPLO
            FROM MMPR_PRODUTOESTRUTURA AS ESTRUTURA
            INNER JOIN MMPR_PRODUTO AS PRODUTO
                ON ESTRUTURA.CD_EMPRESA = PRODUTO.CD_EMPRESA
                AND ESTRUTURA.CD_PRODUTO = PRODUTO.CD_PRODUTO
            WHERE " . ($isCodigo ? "PRODUTO.CD_PRODUTO = ?" : "PRODUTO.DS_REFERENCIA = ?") . "
              AND (
                LTRIM(RTRIM(ESTRUTURA.DS_VARIAVEL.value('(/VariavelOpcaoSimples/Valor)[1]', 'varchar(4000)'))) IS NOT NULL AND
                LTRIM(RTRIM(ESTRUTURA.DS_VARIAVEL.value('(/VariavelOpcaoSimples/Valor)[1]', 'varchar(4000)'))) <> ''
             OR
                LTRIM(RTRIM(ESTRUTURA.DS_VARIAVEL.value('(/VariavelOpcaoMultiplas/Valor)[1]', 'varchar(4000)'))) IS NOT NULL AND
                LTRIM(RTRIM(ESTRUTURA.DS_VARIAVEL.value('(/VariavelOpcaoMultiplas/Valor)[1]', 'varchar(4000)'))) <> ''
             )
                AND PRODUTO.ID_STATUS = 0
            GROUP BY
                PRODUTO.CD_PRODCONFIG,
                PRODUTO.DS_REFERENCIA,
                ESTRUTURA.NM_VARIAVEL,
                ESTRUTURA.CD_ITEM
            ORDER BY ESTRUTURA.CD_ITEM";

        $query = $pdo->prepare($sql);
        $query->execute([$entradaNormalizada]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {
            return [];
        }

        $variaveis = [];
        $tipo = strtoupper(trim((string) ($rows[0]['CD_PRODCONFIG'] ?? '')));
        $referencia = strtoupper(trim((string) ($rows[0]['DS_REFERENCIA'] ?? $entradaNormalizada)));

        foreach ($rows as $row) {
            $chave = strtoupper(trim((string) ($row['NM_VARIAVEL'] ?? '')));
            $chave = strtoupper((string) preg_replace('/[\s_-]+(PL|MO|VA|MH)$/i', '', $chave));
            $valorSimples = trim((string) ($row['RESPOSTA_SELETOR'] ?? ''));
            $valorMultiplo = trim((string) ($row['RESPOSTA_SELETORMULTIPLO'] ?? ''));
            $valorFinal = $valorMultiplo !== '' ? $valorMultiplo : $valorSimples;

            if ($chave !== '' && $valorFinal !== '') {
                $variaveis[$chave] = $valorFinal;
            }
        }

        if (isset($variaveis['MOLN'])) {
            unset($variaveis['MORMES'], $variaveis['MOES']);
        }

        $link = montar_link_por_tipo_api_chat($tipo, $variaveis);
        if ($link === '') {
            $link = montar_link_manual_api_chat($tipo, $variaveis);
        }

        return [
            'configurador' => $tipo,
            'variaveis' => $variaveis,
            'link' => $link,
            'referencia' => $referencia,
            'fonte' => $isCodigo ? 'codigo' : 'referencia',
        ];
    } catch (Throwable $e) {
        return [];
    }
}

function detectar_configurador_por_linha_codigo_api_chat(string $linha): string
{
    $linhaBruta = strtoupper(trim($linha));
    if ($linhaBruta === '') {
        return '';
    }

    $mapaDiretoConfigurador = [
        'AC' => 'AC',
        'AE' => 'AE',
        'FX' => 'FX',
        'HY' => 'HY',
        'IN' => 'IN',
        'MO' => 'MO',
        'PL' => 'PL',
        'QU' => 'QU',
        'QUDR' => 'QUDR',
        'VA' => 'VA',
    ];
    if (isset($mapaDiretoConfigurador[$linhaBruta])) {
        return $mapaDiretoConfigurador[$linhaBruta];
    }

    $linhaNormalizada = normalizar_linha_configurador_api_chat($linhaBruta);
    if ($linhaNormalizada === '') {
        return '';
    }

    $mapaLinhaConfigurador = [
        '1.C' => 'HY',
        '1.FFA' => 'FX',
        '1.FKA' => 'FX',
        '1.FR' => 'FX',
        '1.H' => 'HY',
        '1.M' => 'HY',
        '1.P' => 'HY',
        '1.Q' => 'QU',
        '1.QDR' => 'QUDR',
        '1.QP' => 'QUDR',
        '1.R' => 'HY',
        '1.V' => 'VA',
        '1.VFN' => 'AC',
        '1.X' => 'HY',
        '1.Z' => 'AC',
        '2.I' => 'MO',
        '3.APM' => 'MO',
        '3.GR' => 'AE',
        '3.GS' => 'AE',
        '3.I' => 'MO',
        '3.PB' => 'PL',
        '3.PBL' => 'PL',
        '3.RIC' => 'AE',
        '3.SA' => 'PL',
        '3.SB' => 'PL',
        '3.SBL' => 'PL',
        '3.SD' => 'PL',
        '3.SPM' => 'MO',
        '3.W' => 'MO',
        '4.K' => 'IN',
    ];

    if (isset($mapaLinhaConfigurador[$linhaNormalizada])) {
        return $mapaLinhaConfigurador[$linhaNormalizada];
    }

    $mapaRotaAmigavelConfigurador = [
        'IBRP' => 'HY',
        'IBRH' => 'HY',
        'IBRC' => 'HY',
        'IBRM' => 'HY',
        'IBRX' => 'HY',
        'IBRZ' => 'AC',
        'IBRVFN' => 'AC',
        'IBRGR' => 'AE',
        'IBRGS' => 'AE',
        'IBRRIC' => 'AE',
        'IBRFFA' => 'FX',
        'IBRFKA' => 'FX',
        'IBRFR' => 'FX',
        'IBRK' => 'IN',
        'IBRMSI' => 'MO',
        'IBRMS' => 'MO',
        'IBRAPM' => 'MO',
        'IBRSPM' => 'MO',
        'IBRW' => 'MO',
        'IBRPB' => 'PL',
        'IBRPBL' => 'PL',
        'IBRSA' => 'PL',
        'IBRSB' => 'PL',
        'IBRSBL' => 'PL',
        'IBRSD' => 'PL',
        'IBRQ' => 'QU',
        'IBRQDR' => 'QUDR',
        'IBRQP' => 'QUDR',
        'IBRV' => 'VA',
    ];
    if (isset($mapaRotaAmigavelConfigurador[$linhaBruta])) {
        return $mapaRotaAmigavelConfigurador[$linhaBruta];
    }

    if (preg_match('/^([0-9])\.([A-Z0-9]+)$/', $linhaNormalizada, $partes) === 1) {
        $compacta = $partes[1] . '.' . ltrim($partes[2], '.');
        if (isset($mapaLinhaConfigurador[$compacta])) {
            return $mapaLinhaConfigurador[$compacta];
        }
    }

    if (preg_match('/^[A-Z0-9]+$/', $linhaNormalizada) === 1) {
        foreach (['1.', '2.', '3.', '4.'] as $prefixo) {
            $candidato = $prefixo . $linhaNormalizada;
            if (isset($mapaLinhaConfigurador[$candidato])) {
                return $mapaLinhaConfigurador[$candidato];
            }
        }
    }

    return '';
}

function detectar_configurador_por_variaveis_api_chat(array $variaveis): string
{
    $chavesLinha = ['QULN', 'HYLN', 'MOLN', 'AELN', 'PLLN', 'FXLN', 'INLN', 'VALN', 'ACLN', 'LINHA'];
    foreach ($chavesLinha as $chaveLinha) {
        $valorLinha = isset($variaveis[$chaveLinha]) ? (string) $variaveis[$chaveLinha] : '';
        $tipoPorLinha = detectar_configurador_por_linha_codigo_api_chat($valorLinha);
        if ($tipoPorLinha !== '') {
            return $tipoPorLinha;
        }
    }

    foreach ($variaveis as $chave => $valor) {
        $chaveNormalizada = strtoupper((string) $chave);
        if (preg_match('/LN$/', $chaveNormalizada) !== 1) {
            continue;
        }
        $tipoPorLinha = detectar_configurador_por_linha_codigo_api_chat((string) $valor);
        if ($tipoPorLinha !== '') {
            return $tipoPorLinha;
        }
    }

    if (isset($variaveis['QULN'])) {
        $linhaQu = strtoupper((string) $variaveis['QULN']);
        if ($linhaQu === '1.QDR' || $linhaQu === '1.QP') {
            return 'QUDR';
        }

        return 'QU';
    }

    $mapaPrefixo = [
        'AC' => 'AC',
        'AE' => 'AE',
        'FX' => 'FX',
        'HY' => 'HY',
        'IN' => 'IN',
        'MO' => 'MO',
        'PL' => 'PL',
        'VA' => 'VA',
    ];

    $chavesGenericasIgnoradas = [
        'MOTOR' => true,
        'MOTOREDUTOR' => true,
        'REDUTOR' => true,
        'PAI' => true,
    ];

    foreach ($variaveis as $chave => $_valor) {
        $chaveNormalizada = strtoupper((string) $chave);
        if (isset($chavesGenericasIgnoradas[$chaveNormalizada])) {
            continue;
        }
        $prefixo = substr((string) $chave, 0, 2);
        if (isset($mapaPrefixo[$prefixo])) {
            return $mapaPrefixo[$prefixo];
        }
    }

    return '';
}


function api_chat_tool_runtime_state_payload(array $extra = []): array
{
    $ctx = api_chat_middleware_contexto();
    $runtime = is_array($ctx['toolRuntime'] ?? null) ? $ctx['toolRuntime'] : [];

    return [
        'tool' => $runtime['toolName'] ?? 'gerarLinkConfigurador',
        'idempotencyKey' => $runtime['idempotencyKey'] ?? null,
        'steps' => $runtime['steps'] ?? [],
        'requiresConfirmation' => (bool) ($runtime['requiresConfirmation'] ?? false),
        'confirmed' => (bool) ($runtime['confirmed'] ?? false),
        'replayed' => (bool) ($runtime['replayed'] ?? false),
    ] + $extra;
}

function normalizar_link_amigavel_api_chat(string $link, string $tipo): string
{
    $hostPadrao = 'https://configurador.redutoresibr.com.br';
    $rotasPadraoPorTipo = [
        'QU' => '/ConfiguradorIBRQ',
        'QUDR' => '/ConfiguradorIBRQDR',
    ];

    $linkLimpo = trim($link);
    if ($linkLimpo !== '' && preg_match('/^https?:\/\//i', $linkLimpo) === 1) {
        return $linkLimpo;
    }

    $caminho = parse_url($linkLimpo, PHP_URL_PATH);
    if (!is_string($caminho) || trim($caminho) === '') {
        $rotaPadrao = $rotasPadraoPorTipo[$tipo] ?? ('/Configurador' . $tipo);
        return $hostPadrao . $rotaPadrao;
    }

    $query = parse_url($linkLimpo, PHP_URL_QUERY);
    $fragmento = parse_url($linkLimpo, PHP_URL_FRAGMENT);
    $sufixo = '';
    if (is_string($query) && $query !== '') {
        $sufixo .= '?' . $query;
    }
    if (is_string($fragmento) && $fragmento !== '') {
        $sufixo .= '#' . $fragmento;
    }

    return $hostPadrao . $caminho . $sufixo;
}

function aplicar_alias_variaveis_por_configurador_api_chat(string $tipo, array $variaveis): array
{
    if ($tipo !== 'QU') {
        return $variaveis;
    }

    $mapaAliases = [
        'LINHA' => 'QULN',
        'TAMANHO' => 'QUBR',
        'EIXO_ESTENDIDO' => 'QUEX',
        'REDUCAO' => 'QURD',
        'CARCACA' => 'QUCM',
        'BUCHA_REDUCAO' => 'QUBU',
        'FORMA_CONSTRUTIVA' => 'QUCP',
        'EIXO_ENTRADA' => 'QUCP',
        'ACESSORIO_FIXACAO' => 'QUAF',
        'ACESSORIO_SAIDA' => 'QUAS',
        'CAPA_PROTECAO_LATERAL' => 'QUCL',
        'ITENS_OPCIONAIS' => 'QUOPC',
        'COM_MOTOR' => 'QUMO',
        'COM_VARIADOR' => 'QUVA',
        'ADAPTACAO_SERVOMOTOR' => 'QUPL',
        'ADAPTACAO_MOTOR_HIDRAULICO' => 'QUMH',
    ];

    foreach ($mapaAliases as $alias => $chaveOficial) {
        if (!isset($variaveis[$alias]) || isset($variaveis[$chaveOficial])) {
            continue;
        }

        $variaveis[$chaveOficial] = $variaveis[$alias];
    }

    return $variaveis;
}

function montar_link_por_tipo_api_chat(string $tipo, array $variaveis): string
{
    $montadoresPorTipo = [
        'AC' => 'montar_link_configurador_ac',
        'AE' => 'montar_link_configurador_ae',
        'FX' => 'montar_link_configurador_fx',
        'HY' => 'montar_link_configurador_hy',
        'IN' => 'montar_link_configurador_in',
        'MO' => 'montar_link_configurador_mo',
        'PL' => 'montar_link_configurador_pl',
        'QU' => 'montar_link_configurador_qu',
        'QUDR' => 'montar_link_configurador_qudr',
        'VA' => 'montar_link_configurador_va',
    ];

    $montador = $montadoresPorTipo[$tipo] ?? null;
    if (!is_string($montador) || !function_exists($montador)) {
        return '';
    }

    $link = $montador($variaveis);
    return is_string($link) ? trim($link) : '';
}

function obter_linha_configurador_api_chat(string $tipo, array $variaveis): string
{
    $chavesLinhaPorTipo = [
        'AC' => ['ACLN'],
        'AE' => ['AELN'],
        'FX' => ['FXLN'],
        'HY' => ['HYLN'],
        'IN' => ['INLN'],
        'MO' => ['MOLN'],
        'PL' => ['PLLN'],
        'QU' => ['QULN'],
        'QUDR' => ['QULN'],
        'VA' => ['VALN'],
    ];

    $chaves = $chavesLinhaPorTipo[$tipo] ?? [];
    $chaves[] = 'LINHA';

    foreach ($chaves as $chave) {
        $valor = trim((string) ($variaveis[$chave] ?? ''));
        if ($valor !== '') {
            return $valor;
        }
    }

    return '';
}

function obter_rota_base_configurador_api_chat(string $tipo, array $variaveis): string
{
    $linha = obter_linha_configurador_api_chat($tipo, $variaveis);
    if ($linha === '') {
        $rotasPadraoPorTipo = [
            'QU' => 'IBRQ',
            'QUDR' => 'IBRQDR',
        ];
        return $rotasPadraoPorTipo[$tipo] ?? $tipo;
    }

    switch ($tipo) {
        case 'MO':
            return str_replace(['3.', '2.'], ['IBRM', 'IBRMS'], $linha);
        case 'AE':
        case 'PL':
            return str_replace('3.', 'IBR', $linha);
        case 'IN':
            return str_replace('4.', 'IBR', $linha);
        default:
            return str_replace('1.', 'IBR', $linha);
    }
}

function montar_query_variaveis_api_chat(array $variaveis): string
{
    $queryVars = [];
    foreach ($variaveis as $chave => $valor) {
        $nome = strtoupper(trim((string) $chave));
        $valorLimpo = trim((string) $valor);
        if ($nome === '' || $valorLimpo === '') {
            continue;
        }

        $queryVars[$nome] = $valorLimpo;
    }

    return http_build_query($queryVars, '', '&', PHP_QUERY_RFC3986);
}

function montar_link_manual_api_chat(string $tipo, array $variaveis): string
{
    $hostPadrao = 'https://configurador.redutoresibr.com.br';
    $rota = '/Configurador' . obter_rota_base_configurador_api_chat($tipo, $variaveis);
    $query = montar_query_variaveis_api_chat($variaveis);

    if ($query === '') {
        return $hostPadrao . $rota;
    }

    return $hostPadrao . $rota . '?' . $query;
}

function garantir_querystring_no_link_api_chat(string $link, array $variaveis): string
{
    $link = trim($link);
    if ($link === '') {
        return '';
    }

    $query = montar_query_variaveis_api_chat($variaveis);
    if ($query === '') {
        return $link;
    }

    $partes = parse_url($link);
    if (!is_array($partes)) {
        return $link;
    }

    $queryExistente = trim((string) ($partes['query'] ?? ''));
    if ($queryExistente !== '') {
        return $link;
    }

    return $link . (str_contains($link, '?') ? '&' : '?') . $query;
}

function executar_ferramenta_montagem_link_api_chat(array $args): array
{
    $tipo = strtoupper(trim((string) ($args['configurador'] ?? '')));
    $variaveis = is_array($args['variaveis'] ?? null) ? $args['variaveis'] : [];
    $link = montar_link_por_tipo_api_chat($tipo, $variaveis);
    if ($link === '') {
        $link = montar_link_manual_api_chat($tipo, $variaveis);
    } else {
        $link = garantir_querystring_no_link_api_chat($link, $variaveis);
    }

    return [
        'ok' => true,
        'data' => [
            'configurador' => $tipo,
            'link' => $link,
            'variaveisIdentificadas' => $variaveis,
        ],
    ];
}

$baseDir = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
if ($baseDir === '') {
    $baseDir = dirname(__DIR__);
}

api_chat_middleware_init($baseDir, API_CHAT_ACAO_ENDPOINT);

$metodo = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($metodo !== 'POST') {
    responder_api_chat(405, [
        'ok' => false,
        'erro' => 'Use POST para montar links.',
    ]);
}

auth_api_chat_validar_ou_responder($baseDir, 'responder_api_chat');

require_once $baseDir . '/PaginasNavegacaoCompartilhada/LinkConfiguradorAC.php';
require_once $baseDir . '/PaginasNavegacaoCompartilhada/LinkConfiguradorAE.php';
require_once $baseDir . '/PaginasNavegacaoCompartilhada/LinkConfiguradorFX.php';
require_once $baseDir . '/PaginasNavegacaoCompartilhada/LinkConfiguradorHY.php';
require_once $baseDir . '/PaginasNavegacaoCompartilhada/LinkConfiguradorIN.php';
require_once $baseDir . '/PaginasNavegacaoCompartilhada/LinkConfiguradorMO.php';
require_once $baseDir . '/PaginasNavegacaoCompartilhada/LinkConfiguradorPL.php';
require_once $baseDir . '/PaginasNavegacaoCompartilhada/LinkConfiguradorQU.php';
require_once $baseDir . '/PaginasNavegacaoCompartilhada/LinkConfiguradorQUDR.php';
require_once $baseDir . '/PaginasNavegacaoCompartilhada/LinkConfiguradorVA.php';

$payload = obter_payload_api_chat();
$requiresConfirmation = (bool) ($payload['requires_confirmation'] ?? false);
$confirmed = (bool) ($payload['confirmed'] ?? false);
$idempotencyKeyHeader = api_chat_middleware_idempotency_header_key();
$idempotencyKey = $idempotencyKeyHeader !== ''
    ? $idempotencyKeyHeader
    : trim((string) ($payload['idempotency_key'] ?? ''));

$idempotencyEstado = api_chat_middleware_idempotency_iniciar(API_CHAT_ACAO_ENDPOINT, $idempotencyKey, $payload);
if (($idempotencyEstado['status'] ?? '') === 'replay') {
    $resposta = is_array($idempotencyEstado['response']['body'] ?? null) ? $idempotencyEstado['response']['body'] : ['ok' => true];
    responder_api_chat((int) ($idempotencyEstado['response']['status'] ?? 200), $resposta);
}
if (($idempotencyEstado['status'] ?? '') === 'conflict') {
    responder_api_chat(409, ['ok' => false, 'erro' => 'Idempotency-Key já foi usado com payload diferente.', 'codigoErro' => 'IDEMPOTENCY_CONFLICT']);
}
if (($idempotencyEstado['status'] ?? '') === 'in_progress') {
    responder_api_chat(409, ['ok' => false, 'erro' => 'Requisição idempotente ainda em processamento.', 'codigoErro' => 'IDEMPOTENCY_IN_PROGRESS']);
}
$GLOBALS['api_chat_idempotency_key'] = $idempotencyKey;
$timeoutMs = max(100, (int) ($payload['timeout_ms'] ?? 10000));

$runtimeInicio = api_chat_tool_runtime_iniciar($baseDir, 'gerarLinkConfigurador', $payload, [
    'requires_confirmation' => $requiresConfirmation,
    'confirmed' => $confirmed,
    'idempotency_key' => $idempotencyKey,
    'timeout_ms' => $timeoutMs,
]);

if (($runtimeInicio['status'] ?? '') === 'replayed') {
    $resposta = is_array($runtimeInicio['response'] ?? null) ? $runtimeInicio['response'] : ['ok' => true];
    $resposta['runtime'] = api_chat_tool_runtime_state_payload(['status' => 'replayed']);
    responder_api_chat(200, $resposta);
}

if (($runtimeInicio['status'] ?? '') === 'awaiting_confirmation') {
    api_chat_tool_runtime_etapa('plan', 'completed', ['action' => 'await_confirmation']);
    api_chat_tool_runtime_etapa('finalize', 'completed', ['status' => 'requires_confirmation']);
    $resposta = [
        'ok' => false,
        'erro' => 'Operação sensível requer confirmação explícita.',
        'codigoErro' => 'REQUIRES_CONFIRMATION',
        'runtime' => api_chat_tool_runtime_state_payload(['status' => 'awaiting_confirmation']),
    ];
    api_chat_tool_runtime_finalizar($baseDir, false, [], ['reason' => 'requires_confirmation']);
    responder_api_chat(409, $resposta);
}

api_chat_tool_runtime_etapa('plan', 'in_progress');
api_chat_tool_runtime_registrar_decisao('planejamento_iniciado', ['tool' => 'geradorLinkConfigurador']);
$tipoSolicitado = (string) ($payload['configurador'] ?? $payload['cdProdConfig'] ?? $payload['CD_PRODCONFIG'] ?? '');
$tipo = detectar_configurador_por_linha_codigo_api_chat($tipoSolicitado);
if ($tipo === '') {
    $tipo = strtoupper(trim($tipoSolicitado));
}
$variaveisBrutas = $payload['variaveis'] ?? [];
$falaSolicitante = trim((string) ($payload['falaSolicitante'] ?? $payload['solicitacao'] ?? ''));
$responseSchema = $payload['response_schema'] ?? null;
$responseSchemaPolicy = strtolower(trim((string) ($payload['response_schema_policy'] ?? 'strict_fail')));
$policiesPermitidas = ['strict_fail', 'auto_repair', 'best_effort'];
if (!in_array($responseSchemaPolicy, $policiesPermitidas, true)) {
    responder_api_chat(400, [
        'ok' => false,
        'erro' => 'Campo "response_schema_policy" inválido.',
        'codigoErro' => 'RESPONSE_SCHEMA_POLICY_INVALIDA',
        'runtime' => api_chat_tool_runtime_state_payload(['status' => 'failed']),
    ]);
}
if ($responseSchema !== null && !is_array($responseSchema)) {
    responder_api_chat(400, [
        'ok' => false,
        'erro' => 'Campo "response_schema" deve ser um objeto JSON Schema válido.',
        'codigoErro' => 'RESPONSE_SCHEMA_INVALIDO',
        'runtime' => api_chat_tool_runtime_state_payload(['status' => 'failed']),
    ]);
}

if (!is_array($variaveisBrutas)) {
    responder_api_chat(400, [
        'ok' => false,
        'erro' => 'Campo "variaveis" deve ser um objeto JSON.',
    ]);
}

if (api_chat_tool_runtime_timeout_excedido()) {
    api_chat_tool_runtime_etapa('plan', 'failed', ['reason' => 'timeout']);
    api_chat_tool_runtime_etapa('finalize', 'completed', ['status' => 'timeout']);
    api_chat_tool_runtime_finalizar($baseDir, false, [], ['reason' => 'timeout_plan']);
    responder_api_chat(504, [
        'ok' => false,
        'erro' => 'Tempo limite excedido durante planejamento.',
        'codigoErro' => 'TOOL_TIMEOUT',
        'runtime' => api_chat_tool_runtime_state_payload(['status' => 'timeout']),
    ]);
}

$variaveis = normalizar_variaveis_api_chat($variaveisBrutas);
$variaveisExtraidasFala = extrair_variaveis_texto_api_chat($falaSolicitante);
if ($variaveisExtraidasFala !== []) {
    $variaveis = array_merge($variaveisExtraidasFala, $variaveis);
}

$entradaProduto = extrair_entrada_produto_api_chat($payload, $falaSolicitante);
$produtoResolvido = $entradaProduto !== '' ? resolver_entrada_produto_api_chat($baseDir, $entradaProduto) : [];
$codigoOuReferencia = (string) ($produtoResolvido['valor'] ?? $entradaProduto);
$dadosEstrutura = $codigoOuReferencia !== '' ? buscar_estrutura_produto_api_chat($baseDir, $codigoOuReferencia) : [];
if ($dadosEstrutura !== []) {
    $tipoEstrutura = strtoupper(trim((string) ($dadosEstrutura['configurador'] ?? '')));
    $variaveisEstrutura = is_array($dadosEstrutura['variaveis'] ?? null) ? normalizar_variaveis_api_chat($dadosEstrutura['variaveis']) : [];

    if ($tipoEstrutura !== '') {
        $tipo = $tipoEstrutura;
    }
    if ($variaveisEstrutura !== []) {
        $variaveis = array_merge($variaveisEstrutura, $variaveis);
    }

    api_chat_tool_runtime_registrar_decisao('estrutura_produto_encontrada', [
        'entrada' => $entradaProduto,
        'entradaResolvida' => $codigoOuReferencia,
        'fonte' => (string) ($produtoResolvido['fonte'] ?? $dadosEstrutura['fonte'] ?? 'desconhecida'),
        'configuradorDetectado' => $tipo,
    ]);
}

if ($tipo === '') {
    $tipo = detectar_configurador_por_variaveis_api_chat($variaveis);
} else {
    $tipoDetectadoPelasVariaveis = detectar_configurador_por_variaveis_api_chat($variaveis);
    if ($tipoDetectadoPelasVariaveis !== '' && $tipoDetectadoPelasVariaveis !== $tipo) {
        $tipo = $tipoDetectadoPelasVariaveis;
        api_chat_tool_runtime_registrar_decisao('configurador_ajustado_por_variaveis', [
            'configuradorSolicitado' => strtoupper(trim($tipoSolicitado)),
            'configuradorAjustado' => $tipo,
        ]);
    }
}

$variaveis = aplicar_alias_variaveis_por_configurador_api_chat($tipo, $variaveis);

if ($tipo === '') {
    api_chat_tool_runtime_registrar_decisao('planejamento_parcial_sem_configurador', [
        'variaveisExtraidas' => count($variaveis),
        'possuiFalaSolicitante' => $falaSolicitante !== '',
        'possuiCodigoOuReferencia' => $codigoOuReferencia !== '',
    ]);

    api_chat_tool_runtime_etapa('plan', 'completed', ['configurador' => null, 'status' => 'partial']);
    api_chat_tool_runtime_etapa('execute', 'skipped', ['reason' => 'configurador_not_identified']);
    api_chat_tool_runtime_etapa('verify', 'skipped', ['reason' => 'configurador_not_identified']);
    api_chat_tool_runtime_etapa('finalize', 'in_progress');

    $respostaParcial = [
        'ok' => true,
        'status' => 'BLOQUEADO_SEM_DADOS',
        'erro' => 'Não foi possível identificar a linha automaticamente com os dados enviados.',
        'pendencia' => 'Informe o campo "configurador" ou envie uma referência/código para identificar a linha.',
        'link' => 'https://configurador.redutoresibr.com.br',
        'variaveisIdentificadas' => $variaveis,
    ];

    $respostaParcial['runtime'] = api_chat_tool_runtime_state_payload(['status' => 'completed_partial']);
    api_chat_tool_runtime_etapa('finalize', 'completed', ['status' => 'partial']);
    api_chat_tool_runtime_finalizar($baseDir, true, $respostaParcial, ['partial' => true]);
    responder_api_chat(200, $respostaParcial);
}

api_chat_tool_runtime_etapa('plan', 'completed', ['configurador' => $tipo]);
api_chat_tool_runtime_registrar_decisao('planejamento_concluido', [
    'tool' => 'geradorLinkConfigurador',
    'schemaVersion' => '2.1.0',
    'retryPolicy' => ['timeout' => 1, 'rate_limit' => 2, 'schema_mismatch' => 0],
]);

$tiposSuportados = ['AC', 'AE', 'FX', 'HY', 'IN', 'MO', 'PL', 'QU', 'QUDR', 'VA'];
if (!in_array($tipo, $tiposSuportados, true)) {
    responder_api_chat(400, [
        'ok' => false,
        'erro' => 'Configurador não suportado.',
        'tiposSuportados' => $tiposSuportados,
    ]);
}

api_chat_tool_runtime_etapa('execute', 'in_progress');
$contratoFerramenta = [
    'name' => 'geradorLinkConfigurador',
    'schemaVersion' => '2.1.0',
    'requestSchema' => [
        'type' => 'object',
        'required' => ['configurador', 'variaveis'],
        'additionalProperties' => false,
        'properties' => [
            'configurador' => ['type' => 'string', 'enum' => $tiposSuportados],
            'variaveis' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
        ],
    ],
    'responseSchema' => [
        'type' => 'object',
        'required' => ['configurador', 'link', 'variaveisIdentificadas'],
        'additionalProperties' => false,
        'properties' => [
            'configurador' => ['type' => 'string', 'enum' => $tiposSuportados],
            'link' => ['type' => 'string'],
            'variaveisIdentificadas' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
        ],
    ],
];

$resultadoTool = api_chat_tool_runtime_chamar(
    $baseDir,
    $contratoFerramenta,
    ['configurador' => $tipo, 'variaveis' => $variaveis],
    'executar_ferramenta_montagem_link_api_chat',
    ['timeout' => 1, 'rate_limit' => 2, 'schema_mismatch' => 0]
);

if (($resultadoTool['ok'] ?? false) === true) {
    api_chat_tool_runtime_registrar_compensacao('limpar_link_gerado', static function (): void {
        // Compensação lógica: marca reversão da etapa de execução sem efeitos externos persistentes.
    });
}
if (api_chat_tool_runtime_timeout_excedido()) {
    $compensacoes = api_chat_tool_runtime_compensar('timeout_execute');
    api_chat_tool_runtime_etapa('execute', 'failed', ['reason' => 'timeout']);
    api_chat_tool_runtime_etapa('finalize', 'completed', ['status' => 'timeout']);
    api_chat_tool_runtime_finalizar($baseDir, false, [], ['reason' => 'timeout_execute', 'compensations' => $compensacoes]);
    responder_api_chat(504, [
        'ok' => false,
        'erro' => 'Tempo limite excedido durante execução.',
        'codigoErro' => 'TOOL_TIMEOUT',
        'runtime' => api_chat_tool_runtime_state_payload(['status' => 'timeout', 'compensations' => $compensacoes]),
    ]);
}
api_chat_tool_runtime_etapa('execute', 'completed');

if (($resultadoTool['ok'] ?? false) !== true) {
    api_chat_tool_runtime_etapa('execute', 'failed', [
        'errorType' => $resultadoTool['errorType'] ?? 'unknown',
        'attempts' => (int) ($resultadoTool['attempts'] ?? 0),
    ]);
    responder_api_chat(422, [
        'ok' => false,
        'erro' => 'Falha ao executar ferramenta de montagem de link.',
        'codigoErro' => 'TOOL_EXECUTION_FAILED',
        'detalhes' => $resultadoTool['error'] ?? null,
        'runtime' => api_chat_tool_runtime_state_payload([
            'status' => 'failed',
            'toolContract' => [
                'name' => $contratoFerramenta['name'],
                'schemaVersion' => $contratoFerramenta['schemaVersion'],
                'attempts' => (int) ($resultadoTool['attempts'] ?? 0),
                'errorType' => $resultadoTool['errorType'] ?? 'unknown',
            ],
        ]),
    ]);
}

$toolData = is_array($resultadoTool['data'] ?? null) ? $resultadoTool['data'] : [];
$linkCalculado = trim((string) ($toolData['link'] ?? ''));
if ($linkCalculado === '') {
    $linkCalculado = montar_link_por_tipo_api_chat($tipo, $variaveis);
}
if ($linkCalculado === '') {
    $linkCalculado = montar_link_manual_api_chat($tipo, $variaveis);
}

$respostaFinal = [
    'ok' => true,
    'configurador' => $tipo,
    'link' => normalizar_link_amigavel_api_chat($linkCalculado, $tipo),
    'variaveisIdentificadas' => $variaveis,
];

api_chat_tool_runtime_etapa('verify', 'in_progress');
api_chat_tool_runtime_registrar_decisao('verificacao_consistencia', [
    'toolContract' => [
        'name' => $contratoFerramenta['name'],
        'schemaVersion' => $contratoFerramenta['schemaVersion'],
        'attempts' => (int) ($resultadoTool['attempts'] ?? 1),
    ],
]);
if (is_array($responseSchema)) {
    $schemaHash = substr(hash('sha256', json_encode($responseSchema, JSON_UNESCAPED_UNICODE)), 0, 16);
    $erroSchema = validar_json_schema_api_chat($responseSchema, $respostaFinal);

    if ($erroSchema !== null && $responseSchemaPolicy === 'auto_repair') {
        $respostaReparada = reparar_json_schema_api_chat($responseSchema, $respostaFinal);
        $erroSchema = validar_json_schema_api_chat($responseSchema, $respostaReparada);
        if ($erroSchema === null && is_array($respostaReparada)) {
            $respostaFinal = $respostaReparada;
        }
    }

    if ($erroSchema !== null && $responseSchemaPolicy === 'strict_fail') {
        registrar_metrica_schema_api_chat($baseDir, $tipo, $schemaHash, $responseSchemaPolicy, false);
        responder_api_chat(422, [
            'ok' => false,
            'erro' => 'Resposta não está em conformidade com response_schema.',
            'codigoErro' => 'RESPONSE_SCHEMA_VALIDATION_FAILED',
            'detalhes' => [
                'path' => $erroSchema['path'],
                'mensagem' => $erroSchema['mensagem'],
                'codigo' => $erroSchema['codigo'],
            ],
        ]);
    }

    $conforme = $erroSchema === null;
    registrar_metrica_schema_api_chat($baseDir, $tipo, $schemaHash, $responseSchemaPolicy, $conforme);
    if (!$conforme && $responseSchemaPolicy === 'best_effort') {
        $respostaFinal['_schemaAviso'] = [
            'codigo' => 'RESPONSE_SCHEMA_VALIDATION_WARN',
            'path' => $erroSchema['path'],
            'mensagem' => $erroSchema['mensagem'],
        ];
    }
}

api_chat_tool_runtime_etapa('verify', 'completed');
api_chat_tool_runtime_etapa('finalize', 'in_progress');
$respostaFinal['runtime'] = api_chat_tool_runtime_state_payload(['status' => 'completed']);
api_chat_tool_runtime_etapa('finalize', 'completed');
api_chat_tool_runtime_finalizar($baseDir, true, $respostaFinal);
responder_api_chat(200, $respostaFinal);
