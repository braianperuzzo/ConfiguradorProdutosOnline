<?php

declare(strict_types=1);

const PLENUS_CODIGO_PAIS = '1058';

function normalizar_base_url_plenus(array $config): array
{
    $protocol = strtolower(trim((string) ($config['protocol'] ?? 'https')));
    if ($protocol === '') {
        $protocol = 'https';
    }

    $tlsHostRaw = trim((string) ($config['tlsHost'] ?? ''));
    $hostRaw = $tlsHostRaw !== '' ? $tlsHostRaw : trim((string) ($config['host'] ?? ''));
    $connectHostRaw = trim((string) ($config['connectHost'] ?? ''));

    if ($hostRaw === '') {
        return ['', '', null];
    }

    $hostRaw = trim($hostRaw, '/');
    if (preg_match('#^https?://#i', $hostRaw)) {
        $baseUrl = $hostRaw;
    } else {
        $baseUrl = sprintf('%s://%s', $protocol, $hostRaw);
    }

    $baseUrl = rtrim($baseUrl, '/');

    $hostHeader = parse_url($baseUrl, PHP_URL_HOST);
    if ($hostHeader === null || $hostHeader === '') {
        $hostHeader = $hostRaw;
    }

    $port = parse_url($baseUrl, PHP_URL_PORT);
    if ($port === null) {
        $port = $protocol === 'https' ? 443 : 80;
        $hostHeaderComPorta = $hostHeader;
    } else {
        $hostHeaderComPorta = $hostHeader . ':' . $port;
    }

    $resolveEntry = null;
    if ($connectHostRaw !== '') {
        $resolveEntry = sprintf('%s:%d:%s', $hostHeader, $port, $connectHostRaw);
    }

    return [$baseUrl, $hostHeaderComPorta, $resolveEntry];
}

function carregar_configuracao_plenus(?string $baseDir = null, bool $preferirPut = false): array
{
    static $cache = [];

    $cacheKey = ($preferirPut ? 'put' : 'default') . '|' . ($baseDir ?? '');

    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $baseDir = $baseDir ?? dirname(__DIR__);
    $configEnvVar = $preferirPut ? 'PLENUS_API_PUT_CONFIG_FILE' : 'PLENUS_API_CONFIG_FILE';
    $customPath = getenv($configEnvVar);

    if (($customPath === false || trim($customPath) === '') && $preferirPut) {
        $customPath = getenv('PLENUS_API_CONFIG_FILE');
    }

    if ($customPath !== false) {
        $customPath = trim($customPath);
    }

    $possibleFiles = $preferirPut
        ? [
            'Configuracoes' . DIRECTORY_SEPARATOR . 'PlenusAPIPut.ini',
            'Configuracoes' . DIRECTORY_SEPARATOR . 'PlenusAPIPut.in',
            'Configuracoes' . DIRECTORY_SEPARATOR . 'PlenusAPI.ini',
            'Configuracoes' . DIRECTORY_SEPARATOR . 'PlenusAPIPost.ini',
            'Configuracoes' . DIRECTORY_SEPARATOR . 'PlenusAPIPost.in',
        ]
        : [
            'Configuracoes' . DIRECTORY_SEPARATOR . 'PlenusAPI.ini',
            'Configuracoes' . DIRECTORY_SEPARATOR . 'PlenusAPIPost.ini',
            'Configuracoes' . DIRECTORY_SEPARATOR . 'PlenusAPIPost.in',
            'Configuracoes' . DIRECTORY_SEPARATOR . 'PlenusAPIPut.ini',
            'Configuracoes' . DIRECTORY_SEPARATOR . 'PlenusAPIPut.in',
        ];

    $configPath = $customPath !== false && $customPath !== '' ? $customPath : null;

    if ($configPath === null || !is_file($configPath)) {
        foreach ($possibleFiles as $relative) {
            $candidate = $baseDir . DIRECTORY_SEPARATOR . $relative;
            if (is_file($candidate)) {
                $configPath = $candidate;
                break;
            }
        }
    }

    $parsedIni = [];

    if ($configPath !== null && is_file($configPath)) {
        $parsedIni = parse_ini_file($configPath, false, INI_SCANNER_RAW) ?: [];
    }

    $mapEnv = [
        'host'           => 'HOST',
        'tlsHost'        => 'TLS_HOST',
        'connectHost'    => 'CONNECT_HOST',
        'authorization'  => 'AUTHORIZATION',
        'sessionId'      => 'SESSION_ID',
        'authentication' => 'AUTHENTICATION',
        'protocol'       => 'PROTOCOL',
        'enabled'        => 'ENABLED',
        'timeout'        => 'TIMEOUT',
        'skipTlsVerify'  => 'SKIP_TLS_VERIFY',
    ];

    $data = [
        'host'           => '',
        'tlsHost'        => '',
        'connectHost'    => '',
        'authorization'  => '',
        'sessionId'      => '',
        'authentication' => '',
        'protocol'       => 'https',
        'enabled'        => '1',
        'timeout'        => 30,
        'skipTlsVerify'  => '',
    ];

$prefixosEnv = $preferirPut
        ? ['PLENUS_API_PUT_', 'PLENUS_API_']
        : ['PLENUS_API_', 'PLENUS_API_PUT_'];

    foreach ($mapEnv as $key => $sufixo) {
        foreach ($prefixosEnv as $prefixo) {
            $envValue = getenv($prefixo . $sufixo);
            if ($envValue !== false && trim($envValue) !== '') {
                $data[$key] = trim($envValue);
                continue 2;
            }
        }
        $iniKeys = [strtoupper($key)];
        $snakeKey = strtoupper(preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $key));
        if (!in_array($snakeKey, $iniKeys, true)) {
            $iniKeys[] = $snakeKey;
        }

        $iniKeys[] = strtoupper($sufixo);

        foreach ($iniKeys as $iniKey) {
            if (isset($parsedIni[$iniKey]) && trim((string) $parsedIni[$iniKey]) !== '') {
                $data[$key] = trim((string) $parsedIni[$iniKey]);
                break;
            }
        }
    }

        $data['timeout'] = (int) ($data['timeout'] ?: 30);
    if ($data['timeout'] <= 0) {
        $data['timeout'] = 30;
    }

    $data['protocol'] = strtolower($data['protocol'] ?: 'https');

    $enabledRaw = strtolower((string) ($data['enabled'] ?? '1'));
    $data['enabled'] = !in_array($enabledRaw, ['0', 'false', 'no', 'off'], true);

    $skipTlsRaw = strtolower((string) ($data['skipTlsVerify'] ?? ''));
    $data['skipTlsVerify'] = in_array($skipTlsRaw, ['1', 'true', 'yes', 'on'], true);

    return $cache[$cacheKey] = $data;
}

function carregar_configuracao_sintegra(string $tipo, ?string $baseDir = null): array
{
    static $cache = [];

    $tipoKey = strtolower(trim($tipo));
    if (isset($cache[$tipoKey])) {
        return $cache[$tipoKey];
    }

    $baseDir = $baseDir ?? dirname(__DIR__);

    $arquivos = [
        'cnpj'    => [
            'Configuracoes' . DIRECTORY_SEPARATOR . 'SintegraAPICNPJ',
            'Configuracoes' . DIRECTORY_SEPARATOR . 'SintegraAPICNPJ.ini',
        ],
        'ie'      => [
            'Configuracoes' . DIRECTORY_SEPARATOR . 'SintegraAPIIE.ini',
            'Configuracoes' . DIRECTORY_SEPARATOR . 'SintegraAPIIE',
        ],
        'suframa' => [
            'Configuracoes' . DIRECTORY_SEPARATOR . 'SintegraAPISuframa.ini',
            'Configuracoes' . DIRECTORY_SEPARATOR . 'SintegraAPISuframa',
        ],
    ];

    $defaults = [
        'base_url' => 'https://api.sintegrapi.com.br/consultas/v2',
        'api_key'  => '',
        'cache'    => 0,
        'timeout'  => 20,
        'enabled'  => '1',
    ];

    if ($tipoKey === 'ie' || $tipoKey === 'suframa') {
        $defaults['cache'] = 0;
    }

    $configFile = null;
    foreach ($arquivos[$tipoKey] ?? [] as $arquivo) {
        $caminho = $baseDir . DIRECTORY_SEPARATOR . $arquivo;
        if (is_file($caminho)) {
            $configFile = $caminho;
            break;
        }
    }

    $parsedIni = [];
    if ($configFile !== null) {
        $parsedIni = parse_ini_file($configFile, false, INI_SCANNER_RAW) ?: [];
    }

    $prefixes = [
        'SINTEGRA_' . strtoupper($tipoKey) . '_',
        'SINTEGRAPI_' . strtoupper($tipoKey) . '_',
        'SINTEGRA_' . strtoupper($tipoKey) . '_',
    ];

    $mapEnv = [
        'base_url' => 'BASE_URL',
        'api_key'  => 'API_KEY',
        'cache'    => 'CACHE',
        'timeout'  => 'TIMEOUT',
        'enabled'  => 'ENABLED',
    ];

    $data = $defaults;

    foreach ($mapEnv as $chave => $sufixo) {
        foreach ($prefixes as $prefixo) {
            $env = getenv($prefixo . $sufixo);
            if ($env !== false && trim($env) !== '') {
                $data[$chave] = trim($env);
                continue 2;
            }
        }

        $iniKey = strtoupper($chave);
        if (isset($parsedIni[$iniKey]) && trim((string) $parsedIni[$iniKey]) !== '') {
            $data[$chave] = trim((string) $parsedIni[$iniKey]);
        }
    }

    $data['cache'] = max(0, (int) ($data['cache'] ?? 0));
    $data['timeout'] = max(1, (int) ($data['timeout'] ?? 20));
    $enabledRaw = strtolower((string) ($data['enabled'] ?? '1'));
    $data['enabled'] = !in_array($enabledRaw, ['0', 'false', 'no', 'off'], true);

    return $cache[$tipoKey] = $data;
}

function formatar_data_abertura(?string $data): ?string
{
    $dt = obter_datetime_abertura($data);

    if ($dt === null) {
        return null;
    }

    return $dt->format('Y-m-d\TH:i:sP');
}

function obter_datetime_abertura(?string $data): ?DateTimeImmutable
{
    if (!$data) {
        return null;
    }

    $data = trim($data);

    if ($data === '') {
        return null;
    }

    $tz = new DateTimeZone('America/Sao_Paulo');

    $formatos = ['d/m/Y|', 'Y-m-d|'];

    foreach ($formatos as $formato) {
        $dt = DateTimeImmutable::createFromFormat($formato, $data, $tz);
        if ($dt !== false) {
            return $dt->setTime(0, 0, 0, 0);
        }
    }

    return null;
}

function gerar_born_date_plenus(?string $data): ?array
{
    $dt = obter_datetime_abertura($data);

    if ($dt === null) {
        return null;
    }

    return [
        'bornDate'   => $dt->format('Y-m-d'),
        'bornDateTS' => $dt->format('Y-m-d\TH:i:sP'),
    ];
}

function normalizar_sigla_uf(?string $valor): ?string
{
    if ($valor === null) {
        return null;
    }

    $valor = strtoupper(trim((string) $valor));
    if ($valor === '') {
        return null;
    }

    if (preg_match('/^[A-Z]{2}$/', $valor)) {
        return $valor;
    }

    $semAcentos = remover_acentos($valor);
    $semAcentos = strtoupper(trim(preg_replace('/\s', ' ', $semAcentos)));

    $map = mapa_siglas_estados();
    foreach ($map as $sigla => $nomeEstado) {
        $nomeNormalizado = strtoupper(trim(preg_replace('/\s', ' ', remover_acentos($nomeEstado))));
        if ($semAcentos === $nomeNormalizado) {
            return $sigla;
        }
    }

    return null;
}

function normalizar_inscricoes_estaduais_sintegra(array $dados): array
{
    $colecoes = [];

    foreach (['inscricoes_estaduais', 'inscricoes', 'state_registrations', 'inscricoes_estadual'] as $chave) {
        if (isset($dados[$chave]) && is_array($dados[$chave])) {
            $colecoes = array_merge($colecoes, $dados[$chave]);
        }
    }

    $resultado = [];

    foreach ($colecoes as $registro) {
        if (!is_array($registro)) {
            continue;
        }

        $numero = normalizar_state_registration(
            $registro['inscricao']
            ?? $registro['inscricao_estadual']
            ?? $registro['ie']
            ?? $registro['numero']
            ?? null
        );

        if ($numero === null) {
            continue;
        }

        $uf = normalizar_sigla_uf($registro['uf'] ?? $registro['estado'] ?? null);
        $status = $registro['situacao']
            ?? ($registro['status']['descricao'] ?? ($registro['status'] ?? ''));

        $resultado[] = [
            'numero' => $numero,
            'uf'     => $uf,
            'status' => is_string($status) || is_numeric($status) ? (string) $status : '',
            'ativo'  => $registro['ativo'] ?? null,
        ];
    }

    return $resultado;
}

function registro_inscricao_estadual_ativo(array $registro): bool
{
    if (isset($registro['ativo'])) {
        return (bool) $registro['ativo'];
    }

    $status = strtoupper(trim((string) ($registro['status'] ?? '')));

    if ($status === '') {
        return true;
    }

    if (preg_match('/ATIV|HABIL|REGULAR/', $status)) {
        return true;
    }

    if (preg_match('/BAIXA|CANCEL|INAPT|SUSPEN|BLOQUE|EXCLU/', $status)) {
        return false;
    }

    return true;
}

function selecionar_inscricao_estadual(array $registros, ?string $ufOrigem): ?array
{
    $validos = array_values(array_filter($registros, static function (array $registro) {
        return isset($registro['numero']) && $registro['numero'] !== '';
    }));

    if (!$validos) {
        return null;
    }

    $ativos = array_values(array_filter($validos, 'registro_inscricao_estadual_ativo'));
    $candidatos = $ativos ?: $validos;

    if (count($candidatos) > 1 && $ufOrigem !== null) {
        foreach ($candidatos as $registro) {
            if (isset($registro['uf']) && $registro['uf'] !== null && strtoupper($registro['uf']) === strtoupper($ufOrigem)) {
                return $registro;
            }
        }
    }

    return $candidatos[0];
}

function normalizar_resposta_sintegra_cnpj(array $dados): ?array
{
    if (($dados['error'] ?? false) === true && ($dados['success'] ?? true) === false) {
        return null;
    }

    $payload = is_array($dados['response'] ?? null) ? $dados['response'] : $dados;

    $texto = static function ($valor): string {
        if ($valor === null) {
            return '';
        }

        if (is_string($valor) || is_numeric($valor)) {
            return trim((string) $valor);
        }

        return '';
    };

    $uf = normalizar_sigla_uf($payload['uf'] ?? null) ?? '';

    $estado = $texto($payload['estado'] ?? '');
    if ($estado === '' && $uf !== '') {
        $nomeEstado = obter_nome_estado_por_uf($uf);
        if ($nomeEstado !== null) {
            $estado = $nomeEstado;
        }
    }

    $inscricoes = normalizar_inscricoes_estaduais_sintegra($payload);
    $selecionada = selecionar_inscricao_estadual($inscricoes, $uf);

    $inscricaoEscolhida = $selecionada['numero'] ?? $texto($payload['inscricao_estadual'] ?? '');

        $cnae = '';
    if (isset($payload['atividade_economica_principal']) && is_array($payload['atividade_economica_principal'])) {
        $codigoCnae = $payload['atividade_economica_principal']['codigo'] ?? '';
        $cnaeNormalizado = preg_replace('/\D/', '', (string) $codigoCnae);
        if ($cnaeNormalizado !== '') {
            $cnae = $cnaeNormalizado;
        }
    }

    $cnaesSecundarios = [];
    if (isset($payload['atividades_economicas_secundarias']) && is_array($payload['atividades_economicas_secundarias'])) {
        foreach ($payload['atividades_economicas_secundarias'] as $atividade) {
            if (!is_array($atividade)) {
                continue;
            }

            $codigoCnae = preg_replace('/\D/', '', (string) ($atividade['codigo'] ?? ''));
            if ($codigoCnae !== '') {
                $cnaesSecundarios[] = $codigoCnae;
            }
        }

        $cnaesSecundarios = array_values(array_unique($cnaesSecundarios));
    }

    $resultado = [
        'status'                => 'OK',
        'fonte'                 => 'sintegra',
        'nome'                  => $texto($payload['nome_empresarial'] ?? ''),
        'nome_fantasia'         => $texto($payload['nome_fantasia'] ?? ''),
        'fantasia'              => $texto($payload['nome_fantasia'] ?? ''),
        'email'                 => strtolower($texto($payload['endereco_eletronico'] ?? ($payload['email'] ?? ''))),
        'telefone'              => $texto($payload['telefone'] ?? ''),
        'cep'                   => preg_replace('/\D/', '', (string) ($payload['cep'] ?? '')),
        'logradouro'            => $texto($payload['logradouro'] ?? ''),
        'numero'                => $texto($payload['numero'] ?? ''),
        'complemento'           => $texto($payload['complemento'] ?? ''),
        'bairro'                => $texto($payload['bairro'] ?? ''),
        'municipio'             => $texto($payload['municipio'] ?? ''),
        'uf'                    => $uf,
        'estado'                => $estado,
        'estado_codigo'         => $texto($payload['estado_codigo'] ?? ''),
        'cidade_codigo'         => $texto($payload['cidade_codigo'] ?? ''),
        'pais_codigo'           => $texto($payload['pais_codigo'] ?? ''),
        'abertura'              => $texto($payload['data_de_abertura'] ?? ($payload['abertura'] ?? '')),
        'cnae'                  => $cnae,
        'cnaes_secundarios'     => $cnaesSecundarios,
        'inscricao_estadual'    => $inscricaoEscolhida,
        'inscricoes_estaduais'  => $inscricoes,
    ];

    $resultado['stateId'] = $resultado['inscricao_estadual'];

    return $resultado;
}

function executar_consulta_sintegra(string $url, array $headers, int $timeout, array $contextoLog = []): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $response === '') {
        log_event('Falha ao consultar SintegraAPI: ' . ($curlError ?: 'sem resposta') . ' ' . json_encode($contextoLog));
        return null;
    }

    $dados = json_decode($response, true);
    if (!is_array($dados)) {
        log_event('Resposta inválida da SintegraAPI: ' . substr($response, 0, 200) . ' ' . json_encode($contextoLog));
        return null;
    }

    return $dados;
}

function consultar_cnpj_sintegra(string $cnpj, string $baseDir): ?array
{
    $config = carregar_configuracao_sintegra('cnpj', $baseDir);

    if (!$config['enabled']) {
        return null;
    }

    $apiKey = trim((string) ($config['api_key'] ?? ''));
    if ($apiKey === '') {
        log_event('Chave do SintegraAPI para CNPJ não configurada.');
        return null;
    }

    $cnpj = preg_replace('/[^0-9A-Za-z]/', '', $cnpj);
    if (strlen($cnpj) !== 14) {
        return null;
    }

    $baseUrl = rtrim($config['base_url'] ?: 'https://api.sintegrapi.com.br/consultas/v2', '/');
    $url = sprintf('%s/cnpj-receita-federal/%s', $baseUrl, $cnpj);

    $headers = [
        'Accept: application/json',
        'x-api-key: ' . $apiKey,
        'cache: ' . $config['cache'],
    ];

    $dados = executar_consulta_sintegra($url, $headers, (int) $config['timeout'], ['tipo' => 'cnpj', 'cnpj' => $cnpj]);
    if ($dados === null) {
        return null;
    }

    $normalizado = normalizar_resposta_sintegra_cnpj($dados);
    if (!$normalizado) {
        log_event('SintegraAPI CNPJ retornou status inválido.');
        return null;
    }

    return $normalizado;
}

function gerar_item_cnae_secundario_xml(string $codigo): string
{
    $template = <<<'XML'
<ObjetoCustomizado Id="{BCDC2-F9593-3A6C4}" Nome="Item"><Descricao /><Titulo /><Campo Nome="CodigoCnae" Tipo="System.String"><Atributo Tipo="Avant.BO.Atributos.AtributoPropriedade"><Descricao>Código CNAE Secundário</Descricao><Ortografia>False</Ortografia><AutoSequencial>False</AutoSequencial><Habilitado>True</Habilitado><TamanhoAutomatico>False</TamanhoAutomatico><TamanhoPercentual>0</TamanhoPercentual><ColunaAbsoluta>False</ColunaAbsoluta><ForcarNovaLinha>False</ForcarNovaLinha><Obrigatorio>False</Obrigatorio><MultiLine>False</MultiLine><Incremental>False</Incremental><Formato /><ToolTip /><Pesquisavel>True</Pesquisavel><Visivel>True</Visivel><Sensivel>False</Sensivel><Altura>0</Altura><Largura>0</Largura><AlturaMinima>0</AlturaMinima><LarguraMinima>150</LarguraMinima><Coluna>0</Coluna><Linha>1</Linha><ColunaSpan>0</ColunaSpan><LinhaSpan>0</LinhaSpan><TamMaximo>20</TamMaximo><TamMinimo>0</TamMinimo><Negrito>False</Negrito><Italico>False</Italico><HyperLink>False</HyperLink><Sobrescrito>False</Sobrescrito><TamFonte>0</TamFonte><NomeFonte /><ValMaximo>0</ValMaximo><ValMinimo>0</ValMinimo><Alinhamento>Padrao</Alinhamento><Apresentacao>LabelAndText</Apresentacao><Ancoragem>Nenhuma</Ancoragem><TabPageIndex>0</TabPageIndex><Senha>False</Senha><CorFundo /><CorFonte /><Percentual>False</Percentual><IgnoraValidacao>False</IgnoraValidacao><CasoTexto>Sistema</CasoTexto><ObrigatorioSe /><PropriedadesDinamicas /><ValorInicial /><Icone /></Atributo><Valor>%s</Valor></Campo><scriptInicioEdicao /><scriptFinalEdicao /><scriptValidacao /><scriptEdicao /><Copiavel>True</Copiavel><AutoFoco>False</AutoFoco><DescricaoCustomizada /></ObjetoCustomizado>
XML;

    return sprintf($template, htmlspecialchars($codigo, ENT_XML1));
}

function gerar_xml_cnaes_secundarios(array $cnaes): string
{
    $cnaesFiltrados = [];
    foreach ($cnaes as $cnae) {
        $codigo = preg_replace('/\D/', '', (string) $cnae);
        if ($codigo !== '') {
            $cnaesFiltrados[] = $codigo;
        }
    }

    $cnaesFiltrados = array_values(array_unique($cnaesFiltrados));

    $itensXml = '';
    if ($cnaesFiltrados !== []) {
        $itensXml = implode("\n", array_map('gerar_item_cnae_secundario_xml', $cnaesFiltrados));
    }

    $inicio = <<<'XML'
<ObjetoCustomizado Id="{8108B-8D4BC-C8459}" Nome="CnaesSecundarios"><Descricao>CNAEs Secundários</Descricao><Titulo>CNAEs Secundários</Titulo><Atributo Tipo="Avant.BO.Atributos.AtributoClasseTamanhoTela"><Largura>500</Largura><Altura>300</Altura><TamanhoAutomatico>False</TamanhoAutomatico></Atributo><Atributo Tipo="Avant.BO.Atributos.AtributoPropriedade"><Descricao>CNAEs Secundários</Descricao><Ortografia>False</Ortografia><AutoSequencial>False</AutoSequencial><Habilitado>True</Habilitado><TamanhoAutomatico>False</TamanhoAutomatico><TamanhoPercentual>0</TamanhoPercentual><ColunaAbsoluta>False</ColunaAbsoluta><ForcarNovaLinha>False</ForcarNovaLinha><Obrigatorio>False</Obrigatorio><MultiLine>False</MultiLine><Incremental>False</Incremental><Formato /><ToolTip /><Pesquisavel>True</Pesquisavel><Visivel>True</Visivel><Sensivel>False</Sensivel><Altura>0</Altura><Largura>0</Largura><AlturaMinima>0</AlturaMinima><LarguraMinima>0</LarguraMinima><Coluna>1</Coluna><Linha>1</Linha><ColunaSpan>0</ColunaSpan><LinhaSpan>0</LinhaSpan><TamMaximo>0</TamMaximo><TamMinimo>0</TamMinimo><Negrito>False</Negrito><Italico>False</Italico><HyperLink>False</HyperLink><Sobrescrito>False</Sobrescrito><TamFonte>0</TamFonte><NomeFonte /><ValMaximo>0</ValMaximo><ValMinimo>0</ValMinimo><Alinhamento>Padrao</Alinhamento><Apresentacao>LabelAndButton</Apresentacao><Ancoragem>Nenhuma</Ancoragem><TabPageIndex>2</TabPageIndex><Senha>False</Senha><CorFundo /><CorFonte /><Percentual>False</Percentual><IgnoraValidacao>False</IgnoraValidacao><CasoTexto>Sistema</CasoTexto><ObrigatorioSe /><PropriedadesDinamicas /><ValorInicial /><Icone /></Atributo><Atributo Tipo="Avant.BO.Atributos.AtributoRefrescabilidade" /><Campo Nome="Itens" Tipo="Coleção" TipoElementos="Item" Adicao="True" Edicao="True" Remocao="True"><Atributo Tipo="Avant.BO.Atributos.AtributoPropriedade"><Descricao>Lista de CNAEs Secundários</Descricao><Ortografia>False</Ortografia><AutoSequencial>False</AutoSequencial><Habilitado>True</Habilitado><TamanhoAutomatico>True</TamanhoAutomatico><TamanhoPercentual>100</TamanhoPercentual><ColunaAbsoluta>False</ColunaAbsoluta><ForcarNovaLinha>False</ForcarNovaLinha><Obrigatorio>False</Obrigatorio><MultiLine>False</MultiLine><Incremental>False</Incremental><Formato /><ToolTip /><Pesquisavel>True</Pesquisavel><Visivel>True</Visivel><Sensivel>False</Sensivel><Altura>0</Altura><Largura>0</Largura><AlturaMinima>200</AlturaMinima><LarguraMinima>0</LarguraMinima><Coluna>0</Coluna><Linha>-1</Linha><ColunaSpan>0</ColunaSpan><LinhaSpan>0</LinhaSpan><TamMaximo>0</TamMaximo><TamMinimo>0</TamMinimo><Negrito>False</Negrito><Italico>False</Italico><HyperLink>False</HyperLink><Sobrescrito>False</Sobrescrito><TamFonte>0</TamFonte><NomeFonte /><ValMaximo>0</ValMaximo><ValMinimo>0</ValMinimo><Alinhamento>Padrao</Alinhamento><Apresentacao>OnlyText</Apresentacao><Ancoragem>Nenhuma</Ancoragem><TabPageIndex>0</TabPageIndex><Senha>False</Senha><CorFundo /><CorFonte /><Percentual>False</Percentual><IgnoraValidacao>False</IgnoraValidacao><CasoTexto>Sistema</CasoTexto><ObrigatorioSe /><PropriedadesDinamicas /><ValorInicial /><Icone /></Atributo><Valor>
XML;

    $fim = <<<'XML'
</Valor></Campo><scriptInicioEdicao>
    return true;
    </ScriptInicioEdicao><scriptFinalEdicao>
    return true;
    </ScriptFinalEdicao><scriptValidacao>
    return true;
    </ScriptValidacao><scriptEdicao /><Copiavel>False</Copiavel><AutoFoco>True</AutoFoco><DescricaoCustomizada /><ObjetoCustomizado Id="{BCDC2-F9593-3A6C4}" Nome="Item"><Descricao /><Titulo /><Campo Nome="CodigoCnae" Tipo="System.String"><Atributo Tipo="Avant.BO.Atributos.AtributoPropriedade"><Descricao>Código CNAE Secundário</Descricao><Ortografia>False</Ortografia><AutoSequencial>False</AutoSequencial><Habilitado>True</Habilitado><TamanhoAutomatico>False</TamanhoAutomatico><TamanhoPercentual>0</TamanhoPercentual><ColunaAbsoluta>False</ColunaAbsoluta><ForcarNovaLinha>False</ForcarNovaLinha><Obrigatorio>False</Obrigatorio><MultiLine>False</MultiLine><Incremental>False</Incremental><Formato /><ToolTip /><Pesquisavel>True</Pesquisavel><Visivel>True</Visivel><Sensivel>False</Sensivel><Altura>0</Altura><Largura>0</Largura><AlturaMinima>0</AlturaMinima><LarguraMinima>150</LarguraMinima><Coluna>0</Coluna><Linha>1</Linha><ColunaSpan>0</ColunaSpan><LinhaSpan>0</LinhaSpan><TamMaximo>20</TamMaximo><TamMinimo>0</TamMinimo><Negrito>False</Negrito><Italico>False</Italico><HyperLink>False</HyperLink><Sobrescrito>False</Sobrescrito><TamFonte>0</TamFonte><NomeFonte /><ValMaximo>0</ValMaximo><ValMinimo>0</ValMinimo><Alinhamento>Padrao</Alinhamento><Apresentacao>LabelAndText</Apresentacao><Ancoragem>Nenhuma</Ancoragem><TabPageIndex>0</TabPageIndex><Senha>False</Senha><CorFundo /><CorFonte /><Percentual>False</Percentual><IgnoraValidacao>False</IgnoraValidacao><CasoTexto>Sistema</CasoTexto><ObrigatorioSe /><PropriedadesDinamicas /><ValorInicial /><Icone /></Atributo></Campo><scriptInicioEdicao /><scriptFinalEdicao /><scriptValidacao /><scriptEdicao /><Copiavel>True</Copiavel><AutoFoco>False</AutoFoco><DescricaoCustomizada /></ObjetoCustomizado></ObjetoCustomizado>
XML;

    return $inicio . $itensXml . $fim;
}

function atualizar_campo_cnaes_secundarios(PDO $pdo, string $cnpj, array $dadosCnpj): void
{
    $cnaesSecundarios = [];
    if (isset($dadosCnpj['cnaes_secundarios']) && is_array($dadosCnpj['cnaes_secundarios'])) {
        $cnaesSecundarios = $dadosCnpj['cnaes_secundarios'];
    }

    $xml = gerar_xml_cnaes_secundarios($cnaesSecundarios);
    $cnpjNumeros = preg_replace('/[^0-9A-Za-z]/', '', $cnpj);

    if ($cnpjNumeros === '') {
        return;
    }

    try {
        $stmt = $pdo->prepare(
            "UPDATE MBAD_PESSOA SET OB_CUSTOM1 = ? WHERE REPLACE(REPLACE(REPLACE(REPLACE(NR_CPFCNPJ, '.', ''), '-', ''), '/', ''), ' ', '') = ?"
        );
        $stmt->execute([$xml, $cnpjNumeros]);
    } catch (PDOException $e) {
        log_event('Erro ao atualizar CNAEs secundários no Plenus: ' . $e->getMessage());
    }
}

function atualizar_natureza_cliente_plenus(PDO $pdo, string $codigoCliente, array $dadosCnpj): void
{
    $codigoCliente = trim($codigoCliente);
    if ($codigoCliente === '') {
        return;
    }

    $uf = normalizar_sigla_uf($dadosCnpj['uf'] ?? null);
    $cidade = normalizar_para_comparacao($dadosCnpj['municipio'] ?? ($dadosCnpj['cidade'] ?? ''));
    $possuiInscricaoEstadual = strtoupper(determinar_state_id_plenus($dadosCnpj)) !== 'NC';

    $naturezasPorEmpresa = [];

    if ($uf === 'AM' && $cidade === normalizar_para_comparacao('Manaus')) {
        $naturezasPorEmpresa['001'] = '6109';
        $naturezasPorEmpresa['003'] = '6109';
    } else {
        if ($uf === 'SP') {
            $naturezasPorEmpresa['003'] = '5101';
        } elseif ($possuiInscricaoEstadual) {
            $naturezasPorEmpresa['003'] = '6101';
        }

        if ($uf === 'RS') {
            $naturezasPorEmpresa['001'] = '5101';
        } elseif ($possuiInscricaoEstadual) {
            $naturezasPorEmpresa['001'] = '6101';
        }
    }

    if ($naturezasPorEmpresa === []) {
        return;
    }

    foreach ($naturezasPorEmpresa as $empresa => $natureza) {
        try {
            $stmt = $pdo->prepare(
                "UPDATE MBAD_CLIENTE SET CD_NATUREZA = ? WHERE CD_EMPRESA = ? AND CD_CLIENTE = ?"
            );
            $stmt->execute([$natureza, $empresa, $codigoCliente]);
        } catch (PDOException $e) {
            log_event('Erro ao atualizar natureza no Plenus: ' . $e->getMessage());
        }
    }
}

function consultar_suframa_sintegra(string $cnpj, string $baseDir): ?string
{
    $config = carregar_configuracao_sintegra('suframa', $baseDir);

    if (!$config['enabled']) {
        return null;
    }

    $apiKey = trim((string) ($config['api_key'] ?? ''));
    if ($apiKey === '') {
        log_event('Chave do SintegraAPI para Suframa não configurada.');
        return null;
    }

    $cnpj = preg_replace('/[^0-9A-Za-z]/', '', $cnpj);
    if (strlen($cnpj) !== 14) {
        return null;
    }

    $baseUrl = rtrim($config['base_url'] ?: 'https://api.sintegrapi.com.br/consultas/v2', '/');
    $url = sprintf('%s/suframa/%s', $baseUrl, $cnpj);

    $headers = [
        'Accept: application/json',
        'x-api-key: ' . $apiKey,
        'cache: ' . $config['cache'],
    ];

    $dados = executar_consulta_sintegra($url, $headers, (int) $config['timeout'], ['tipo' => 'suframa', 'cnpj' => $cnpj]);
    if ($dados === null) {
        return null;
    }

    $payload = is_array($dados['response'] ?? null) ? $dados['response'] : $dados;
    $inscricao = $payload['inscricao'] ?? null;

    $inscricaoNormalizada = normalizar_state_registration(is_scalar($inscricao) ? (string) $inscricao : null);

    return $inscricaoNormalizada !== null && $inscricaoNormalizada !== '' ? $inscricaoNormalizada : null;
}

function obter_detalhes_cnpj_plenus(string $cnpj, string $baseDir): ?array
{
    $cnpj = preg_replace('/[^0-9A-Za-z]/', '', $cnpj);

    if (strlen($cnpj) !== 14) {
        return null;
    }

    $dados = consultar_cnpj_sintegra($cnpj, $baseDir);

    if (!$dados) {
        return null;
    }

    if (
        isset($dados['uf'], $dados['municipio'])
        && normalizar_sigla_uf($dados['uf']) === 'AM'
        && normalizar_para_comparacao($dados['municipio']) === normalizar_para_comparacao('Manaus')
    ) {
        $suframa = consultar_suframa_sintegra($cnpj, $baseDir);
        if ($suframa !== null) {
            $dados['suframa'] = $suframa;
        }
    }

    if (!isset($dados['inscricao_estadual']) || trim((string) $dados['inscricao_estadual']) === '') {
        $dados['inscricao_estadual'] = 'NC';
        $dados['stateId'] = 'NC';
    }

    return $dados;
}

function mapa_siglas_estados(): array
{
    return [
        'AC' => 'ACRE',
        'AL' => 'ALAGOAS',
        'AP' => 'AMAPA',
        'AM' => 'AMAZONAS',
        'BA' => 'BAHIA',
        'CE' => 'CEARA',
        'DF' => 'DISTRITO FEDERAL',
        'ES' => 'ESPIRITO SANTO',
        'GO' => 'GOIAS',
        'MA' => 'MARANHAO',
        'MT' => 'MATO GROSSO',
        'MS' => 'MATO GROSSO DO SUL',
        'MG' => 'MINAS GERAIS',
        'PA' => 'PARA',
        'PB' => 'PARAIBA',
        'PR' => 'PARANA',
        'PE' => 'PERNAMBUCO',
        'PI' => 'PIAUI',
        'RJ' => 'RIO DE JANEIRO',
        'RN' => 'RIO GRANDE DO NORTE',
        'RS' => 'RIO GRANDE DO SUL',
        'RO' => 'RONDONIA',
        'RR' => 'RORAIMA',
        'SC' => 'SANTA CATARINA',
        'SP' => 'SAO PAULO',
        'SE' => 'SERGIPE',
        'TO' => 'TOCANTINS',
    ];
}

function obter_nome_estado_por_uf(?string $uf): ?string
{
    if (!$uf) {
        return null;
    }

    $uf = strtoupper(trim($uf));
    $map = mapa_siglas_estados();

    return $map[$uf] ?? null;
}

function normalizar_codigo_plenus(?string $valor): ?string
{
    if ($valor === null) {
        return null;
    }

    $valor = preg_replace('/\D/', '', trim((string) $valor));

    return $valor !== '' ? $valor : null;
}


function registrar_log_json_plenus(string $contexto, array $dados): void
{
    $json = json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

    if ($json === false) {
        log_event($contexto . ': (falha ao serializar dados do log)');
        return;
    }

    log_event($contexto . ': ' . $json);
}

function registrar_registro_api_sintegra(PDO $pdo, string $codigoPessoa, bool $consultouSuframa): void
{
    try {
        $stmt = $pdo->query("SELECT ISNULL(MAX(CAST(NR_ORDEM AS INT)), 0) FROM _USR_REGISTROAPISINTEGRA");
        $maiorOrdem = $stmt ? (int) $stmt->fetchColumn() : 0;
        $novaOrdem = (string) ($maiorOrdem + 1);

        $nrRequisicoes = $consultouSuframa ? '3' : '2';

        $ins = $pdo->prepare(
            "INSERT INTO _USR_REGISTROAPISINTEGRA (NR_ORDEM, DS_TIPO, CD_PESSOA, CD_REQUISITANTE, NR_REQUISICOES, DT_DATA)"
            . " VALUES (?, ?, ?, ?, ?, ?)"
        );

        $ins->execute([
            $novaOrdem,
            'Cadastro',
            $codigoPessoa,
            'SITE',
            $nrRequisicoes,
            date('d/m/Y'),
        ]);
    } catch (Throwable $t) {
        log_event('Erro ao registrar consumo da API Sintegra: ' . $t->getMessage());
    }
}

function buscar_codigo_pais_por_nome(PDO $pdo, ?string $nomePais): ?string
{
    return PLENUS_CODIGO_PAIS;
}

function buscar_codigo_estado_por_sigla(PDO $pdo, string $codigoPais, string $uf): ?string
{
    try {
        $stmt = $pdo->prepare(
            "SELECT CD_ESTADO AS codigo FROM MBAD_ESTADO WHERE CD_PAIS = ? AND UPPER(LTRIM(RTRIM(DS_SIGLA))) = UPPER(LTRIM(RTRIM(?)))"
        );
        $stmt->execute([$codigoPais, $uf]);
        $resultado = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($resultado && isset($resultado['codigo'])) {
            return normalizar_codigo_plenus((string) $resultado['codigo']);
        }
    } catch (Throwable $e) {
        log_event('Erro ao buscar estado no banco: ' . $e->getMessage());
    }

    return null;
}

function buscar_codigo_estado(PDO $pdo, ?string $estadoNome, ?string $uf, ?string $codigoEstado = null, ?string $codigoPaisPreferido = null, ?string $paisNome = null): array
{
    $codigoEstado = normalizar_codigo_plenus($codigoEstado);
    $codigoPaisPreferido = $codigoPaisPreferido !== null ? normalizar_codigo_plenus($codigoPaisPreferido) : null;
    $codigoPais = $codigoPaisPreferido ?: PLENUS_CODIGO_PAIS;

    if ($codigoEstado !== null) {
        return ['estado' => $codigoEstado, 'pais' => $codigoPais];
    }

    $ufNormalizado = normalizar_sigla_uf($uf ?? '');
    if ($ufNormalizado !== null) {
        $codigo = buscar_codigo_estado_por_sigla($pdo, $codigoPais, $ufNormalizado);

        if ($codigo !== null) {
            return ['estado' => $codigo, 'pais' => $codigoPais];
        }

        log_event('Estado não encontrado no Plenus para UF: ' . $ufNormalizado);
    }

    return ['estado' => null, 'pais' => null];
}
function buscar_codigo_cidade(PDO $pdo, ?string $paisCodigo, ?string $estadoCodigo, ?string $cidadeNome, ?string $cidadeCodigo = null): ?string
{
    $cidadeCodigo = normalizar_codigo_plenus($cidadeCodigo);

    if ($paisCodigo === null) {
        $paisCodigo = PLENUS_CODIGO_PAIS;
    }

    if ($cidadeCodigo !== null && $paisCodigo && $estadoCodigo) {
        $cidadeExistente = buscar_cidade_plenus_por_codigo($pdo, $paisCodigo, $estadoCodigo, $cidadeCodigo);
        if ($cidadeExistente !== null) {
            return $cidadeExistente;
        }
    }

    if (!$cidadeNome || !$paisCodigo || !$estadoCodigo) {
        return null;
    }

    $cidadeNormalizada = normalizar_texto($cidadeNome);

    if ($cidadeNormalizada === '') {
        return null;
    }

    $variantes = [$cidadeNormalizada];
    $semAcentos = remover_acentos($cidadeNormalizada);
    if ($semAcentos !== '' && $semAcentos !== $cidadeNormalizada) {
        $variantes[] = $semAcentos;
    }

    $variantes = array_values(array_unique($variantes));
    $variantesComparacao = array_values(array_filter(array_unique(array_map('normalizar_para_comparacao', $variantes))));

    try {
        $stmt = $pdo->prepare(
            'SELECT CD_CIDADE AS codigo, NM_CIDADE AS nome FROM MBAD_CIDADE WHERE CD_PAIS = ? AND CD_ESTADO = ?'
        );
        $stmt->execute([$paisCodigo, $estadoCodigo]);

        while ($cidadeItem = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $codigo = isset($cidadeItem['codigo']) ? normalizar_codigo_plenus((string) $cidadeItem['codigo']) : null;
            $nomeCidade = normalizar_texto($cidadeItem['nome'] ?? '');
            $nomeSemAcentos = remover_acentos($nomeCidade);
            $comparacaoCidade = normalizar_para_comparacao($nomeCidade);

            if ($codigo !== null && $cidadeCodigo !== null && $codigo === $cidadeCodigo) {
                return $codigo;
            }

            if (
                in_array($nomeCidade, $variantes, true)
                || ($nomeSemAcentos !== '' && in_array($nomeSemAcentos, $variantes, true))
                || ($comparacaoCidade !== '' && in_array($comparacaoCidade, $variantesComparacao, true))
            ) {
                return $codigo;
            }
        }
    } catch (Throwable $e) {
        log_event('Erro ao buscar cidade por nome no banco: ' . $e->getMessage());
    }

    if ($cidadeCodigo !== null) {
        return $cidadeCodigo;
    }

    return null;
}

function remover_acentos(string $valor): string
{
    if ($valor === '') {
        return '';
    }

    if (class_exists('Normalizer')) {
        $normalizado = Normalizer::normalize($valor, Normalizer::FORM_D);
        if ($normalizado !== false) {
            $semMarcas = preg_replace('/\p{Mn}u', '', $normalizado);
            if (is_string($semMarcas)) {
                $valor = $semMarcas;
            }
        }
    }

    $transliterado = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $valor);
    if ($transliterado !== false && $transliterado !== '') {
        return $transliterado;
    }

    return $valor;
}

function normalizar_para_comparacao(?string $valor): string
{
    $valor = normalizar_texto($valor);
    if ($valor === '') {
        return '';
    }

    $valor = remover_acentos($valor);
    $valor = preg_replace('/[^A-Z0-9]/u', '', $valor);

    return is_string($valor) ? $valor : '';
}

function normalizar_email_plenus(?string $email): string
{
    $email = strtolower(trim((string) $email));

    if ($email === '') {
        return '';
    }

    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}

function obter_email_empresa_plenus(array $dadosCnpj, string $emailContato): string
{
    if (isset($dadosCnpj['email'])) {
        $emailNormalizado = normalizar_email_plenus($dadosCnpj['email']);
        if ($emailNormalizado !== '') {
            return $emailNormalizado;
        }
    }

    return normalizar_email_plenus($emailContato);
}


function normalizar_texto(?string $valor): string
{
    $valor = trim((string) $valor);
    if ($valor === '') {
        return '';
    }
    return mb_strtoupper($valor, 'UTF-8');
}

function limitar_texto_plenus(string $valor, int $limite): string
{
    $valor = trim($valor);

    if ($valor === '' || $limite <= 0) {
        return $valor;
    }

    if (mb_strlen($valor, 'UTF-8') <= $limite) {
        return $valor;
    }

    return mb_substr($valor, 0, $limite, 'UTF-8');
}

function aplicar_maiusculas_payload_plenus(array $payload): array
{
    foreach ($payload as $chave => $valor) {
        if (is_array($valor)) {
            $payload[$chave] = aplicar_maiusculas_payload_plenus($valor);
            continue;
        }

        if (is_string($valor) && strtolower((string) $chave) !== 'email') {
            $payload[$chave] = mb_strtoupper($valor, 'UTF-8');
        }
    }

    return $payload;
}

function aplicar_limites_payload_plenus(array $payload): array
{
    $payload['legalId'] = limitar_texto_plenus((string) ($payload['legalId'] ?? ''), 15);
    $payload['name'] = limitar_texto_plenus((string) ($payload['name'] ?? ''), 250);
    $payload['fantasyName'] = limitar_texto_plenus((string) ($payload['fantasyName'] ?? ''), 250);
    $payload['typeDescription'] = limitar_texto_plenus((string) ($payload['typeDescription'] ?? ''), 100);
    $payload['site'] = limitar_texto_plenus((string) ($payload['site'] ?? ''), 255);
    $payload['email'] = limitar_texto_plenus((string) ($payload['email'] ?? ''), 255);
    $payload['telephone'] = limitar_texto_plenus((string) ($payload['telephone'] ?? ''), 40);
    $payload['cellphone'] = limitar_texto_plenus((string) ($payload['cellphone'] ?? ''), 40);
    $payload['notes'] = limitar_texto_plenus((string) ($payload['notes'] ?? ''), 500);

    if (isset($payload['locationAddress']) && is_array($payload['locationAddress'])) {
        $location = $payload['locationAddress'];
        $location['zipCode'] = limitar_texto_plenus((string) ($location['zipCode'] ?? ''), 20);
        $location['streetName'] = limitar_texto_plenus((string) ($location['streetName'] ?? ''), 100);
        $location['streetNumber'] = limitar_texto_plenus((string) ($location['streetNumber'] ?? ''), 30);
        $location['complement'] = limitar_texto_plenus((string) ($location['complement'] ?? ''), 50);
        $location['district'] = limitar_texto_plenus((string) ($location['district'] ?? ''), 60);
        $location['referencePoint'] = limitar_texto_plenus((string) ($location['referencePoint'] ?? ''), 60);
        $location['country'] = limitar_texto_plenus((string) ($location['country'] ?? ''), 10);
        $location['state'] = limitar_texto_plenus((string) ($location['state'] ?? ''), 10);
        $location['city'] = limitar_texto_plenus((string) ($location['city'] ?? ''), 10);
        $payload['locationAddress'] = $location;
    }

    if (isset($payload['contacts']) && is_array($payload['contacts'])) {
        foreach ($payload['contacts'] as $indice => $contato) {
            if (!is_array($contato)) {
                continue;
            }
            $contato['name'] = limitar_texto_plenus((string) ($contato['name'] ?? ''), 80);
            $contato['telephone'] = limitar_texto_plenus((string) ($contato['telephone'] ?? ''), 40);
            $contato['cellphone'] = limitar_texto_plenus((string) ($contato['cellphone'] ?? ''), 40);
            $contato['email'] = limitar_texto_plenus((string) ($contato['email'] ?? ''), 255);
            $contato['notes'] = limitar_texto_plenus((string) ($contato['notes'] ?? ''), 255);
            $contato['occupation'] = limitar_texto_plenus((string) ($contato['occupation'] ?? ''), 255);
            $contato['occupationDescription'] = limitar_texto_plenus((string) ($contato['occupationDescription'] ?? ''), 255);
            $contato['descricaoPessoaContatoTipo'] = limitar_texto_plenus((string) ($contato['descricaoPessoaContatoTipo'] ?? ''), 255);
            $payload['contacts'][$indice] = $contato;
        }
    }

return aplicar_maiusculas_payload_plenus($payload);
}

function obter_proximo_codigo_cliente_plenus(PDO $pdo): ?int
{
    try {
        $stmt = $pdo->query('SELECT ISNULL(MAX(CAST(CD_PESSOA AS INT)), 0) + 1 FROM MBAD_PESSOA');
        $codigo = $stmt ? (int) $stmt->fetchColumn() : 0;

        return $codigo > 0 ? $codigo : null;
    } catch (PDOException $e) {
        log_event('Erro ao obter próximo código de cliente no Plenus: ' . $e->getMessage());

        return null;
    }
}

function montar_payload_conta_contabil_plenus(int $numeroConta, string $descricao): array
{
    $numeroComZeros = str_pad((string) $numeroConta, 8, '0', STR_PAD_RIGHT);
    $codigoConta = '1.1.02.01.' . $numeroComZeros;
    $dataCadastro = new DateTimeImmutable('now');
    $dataCadastroDia = $dataCadastro->setTime(0, 0, 0);

    return [
        'codigoContaReferenciada' => '1.01.02.02.01',
        'tipoMovimento' => 0,
        'codigoContaAglutinacao' => '',
        'classificacao' => 0,
        'grupoContas' => 1,
        'categoria' => 2,
        'contaIntegracao' => '',
        'dataInicial' => '',
        'dataFinal' => '',
        'adiantamento' => false,
        'fantasma' => false,
        'numeroContaReferencia' => 0,
        'codigoMoeda' => '',
        'codigoHistorico' => 0,
        'gerencial' => 0,
        'codigoChaveRateio' => '',
        'observacoes' => '',
        'contasGerenciais' => [],
        'numero' => $numeroConta,
        'situacao' => 0,
        'situacaoDescricao' => 'Ativo',
        'codigoConta' => $codigoConta,
        'abreviatura' => '',
        'descricao' => $descricao,
        'atributo1' => '1.1.02.01',
        'atributo2' => '',
        'atributo3' => '',
        'atributo4' => '',
        'userTableFields' => null,
        'creationUser' => 'JOBS',
        'dataCriacao' => $dataCadastro->format(DateTimeInterface::ATOM),
        'usuarioModificacao' => '',
        'dataModificacao' => null,
    ];
}

function obter_descricao_conta_contabil_plenus(array $dadosCnpj): string
{
    $descricao = normalizar_texto($dadosCnpj['nome'] ?? '');
    if ($descricao === '') {
        $descricao = 'CLIENTE SEM NOME';
    }

    return $descricao;
}

function criar_conta_contabil_plenus(PDO $pdo, array $config, array $dadosCnpj, ?int $numeroConta = null): ?array
{
    if ($numeroConta === null) {
        $numeroConta = obter_proximo_codigo_cliente_plenus($pdo);
    }

    if ($numeroConta === null) {
        return null;
    }

    $descricao = obter_descricao_conta_contabil_plenus($dadosCnpj);

    $payloadContaContabil = montar_payload_conta_contabil_plenus($numeroConta, $descricao);

    $resultado = executar_requisicao_plenus(
        $config,
        $payloadContaContabil,
        'POST',
        '/api/admin/contaContabil',
        [],
        [
            'descricao' => $descricao,
            'cnpj' => $dadosCnpj['cnpj'] ?? null,
            'numeroConta' => $numeroConta,
        ]
    );

    if (!$resultado['success']) {
        $mensagem = $resultado['error'] ?: ($resultado['body'] ?? 'sem resposta');
        log_event('Falha ao criar conta contábil no Plenus: ' . $mensagem);

        return null;
    }

    $numeroResposta = $numeroConta;
    $descricaoResposta = $descricao;

    if (is_string($resultado['body'])) {
        $dadosResposta = json_decode($resultado['body'], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($dadosResposta)) {
            if (isset($dadosResposta['numero'])) {
                $numeroResposta = (int) $dadosResposta['numero'];
            }
            if (isset($dadosResposta['descricao']) && trim((string) $dadosResposta['descricao']) !== '') {
                $descricaoResposta = (string) $dadosResposta['descricao'];
            }
        }
    }

    return [
        'numero' => $numeroResposta,
        'descricao' => $descricaoResposta,
    ];
}

function consultar_conta_contabil_plenus(array $config, int $numeroConta): ?array
{
    $resultado = executar_requisicao_plenus(
        $config,
        [],
        'GET',
        '/api/admin/contaContabil/' . $numeroConta,
        [],
        [
            'numeroConta' => $numeroConta,
        ]
    );

    if (!$resultado['success'] || !is_string($resultado['body'])) {
        return null;
    }

    $dados = json_decode($resultado['body'], true);
    return json_last_error() === JSON_ERROR_NONE && is_array($dados) ? $dados : null;
}

function atualizar_conta_contabil_plenus(array $config, int $numeroConta, string $descricao): ?array
{
    $payloadContaContabil = montar_payload_conta_contabil_plenus($numeroConta, $descricao);

    $resultado = executar_requisicao_plenus(
        $config,
        $payloadContaContabil,
        'PUT',
        '/api/admin/contaContabil/' . $numeroConta,
        [],
        [
            'descricao' => $descricao,
            'numeroConta' => $numeroConta,
        ]
    );

    if (!$resultado['success']) {
        $mensagem = $resultado['error'] ?: ($resultado['body'] ?? 'sem resposta');
        log_event('Falha ao atualizar conta contábil no Plenus: ' . $mensagem);
        return null;
    }

    return [
        'numero' => $numeroConta,
        'descricao' => $descricao,
    ];
}

function obter_conta_contabil_cliente(PDO $pdo, string $codigoCliente): ?int
{
    $codigoCliente = trim($codigoCliente);

    if ($codigoCliente === '') {
        return null;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT NR_CONTACONTABIL FROM MBAD_CLIENTE WHERE CD_CLIENTE = ?"
        );
        $stmt->execute([$codigoCliente]);
        $conta = $stmt->fetchColumn();

        if ($conta === false || $conta === null) {
            return null;
        }

        $conta = (int) $conta;
        return $conta > 0 ? $conta : null;
    } catch (PDOException $e) {
        log_event('Erro ao buscar conta contábil do cliente no Plenus: ' . $e->getMessage());
        return null;
    }
}

function atualizar_conta_contabil_cliente(PDO $pdo, string $codigoCliente, int $numeroConta): void
{
    $codigoCliente = trim($codigoCliente);

    if ($codigoCliente === '' || $numeroConta <= 0) {
        return;
    }

    try {
        $stmt = $pdo->prepare(
            "UPDATE MBAD_CLIENTE SET NR_CONTACONTABIL = ? WHERE CD_CLIENTE = ?"
        );
        $stmt->execute([$numeroConta, $codigoCliente]);
    } catch (PDOException $e) {
        log_event('Erro ao atualizar conta contábil do cliente no Plenus: ' . $e->getMessage());
    }
}


function normalizar_state_registration(?string $valor): ?string
{
    if ($valor === null) {
        return null;
    }

  $valor = trim((string) $valor);

    if ($valor === '') {
        return null;
    }

    if (strcasecmp($valor, 'NC') === 0 || strcasecmp($valor, 'NC') === 0) {
        return 'NC';
    }

    return $valor;
}

function determinar_state_id_plenus(array $dadosCnpj): string
{
    $ufOrigem = normalizar_sigla_uf($dadosCnpj['uf'] ?? null);

    if (isset($dadosCnpj['inscricoes_estaduais']) && is_array($dadosCnpj['inscricoes_estaduais'])) {
        $selecionada = selecionar_inscricao_estadual($dadosCnpj['inscricoes_estaduais'], $ufOrigem);
        if ($selecionada !== null) {
            $normalizada = normalizar_state_registration($selecionada['numero'] ?? null);
            if ($normalizada !== null) {
                return $normalizada;
            }
        }
    }

    $origens = [
        'inscricao_estadual' => $dadosCnpj['inscricao_estadual'] ?? null,
        'stateId'            => $dadosCnpj['stateId'] ?? null,
        'ie'                 => $dadosCnpj['ie'] ?? null,
    ];

    foreach ($origens as $origem => $valor) {
        $preparado = is_scalar($valor) ? (string) $valor : null;
        $normalizado = normalizar_state_registration($preparado);
        if ($normalizado !== null) {
            return $normalizado;
        }
    }

    return 'NC';
}


function montar_payload_contato_plenus(string $cnpj, string $nomeContato, string $emailContato, callable $formatarDocumento): array
{
    $legalId = preg_replace('/[^0-9A-Za-z]/', '', (string) $cnpj);
    if ($legalId === '') {
        $legalId = $formatarDocumento($cnpj);
    }

    $payload = [
        'legalId'  => $legalId,
        'contacts' => [
            [
                'seq'        => 1,
                'name'       => $nomeContato,
                'telephone'  => '',
                'cellphone'  => '',
                'email'      => strtolower(trim($emailContato)),
                'notes'      => '',
                'occupation' => 'SITE',
                'occupationDescription' => 'SITE',
                'codigoPessoaContatoTipo' => 4,
                'descricaoPessoaContatoTipo' => 'PRATA',
            ],
        ],
    ];

    return aplicar_limites_payload_plenus($payload);
}

function montar_payload_contato_plenus_put(string $nomeContato, string $emailContato, int $sequencia): array
{
    $sequencia = max(1, $sequencia);

    $contato = [
        'seq'        => $sequencia,
        'name'       => $nomeContato,
        'telephone'  => '',
        'cellphone'  => '',
        'email'      => strtolower(trim($emailContato)),
        'notes'      => '',
        'occupation' => 'SITE',
        'occupationDescription' => 'SITE',
        'codigoPessoaContatoTipo' => 4,
        'descricaoPessoaContatoTipo' => 'PRATA',
    ];

    $contato['name'] = limitar_texto_plenus((string) ($contato['name'] ?? ''), 80);
    $contato['telephone'] = limitar_texto_plenus((string) ($contato['telephone'] ?? ''), 40);
    $contato['cellphone'] = limitar_texto_plenus((string) ($contato['cellphone'] ?? ''), 40);
    $contato['email'] = limitar_texto_plenus((string) ($contato['email'] ?? ''), 255);
    $contato['notes'] = limitar_texto_plenus((string) ($contato['notes'] ?? ''), 255);
    $contato['occupation'] = limitar_texto_plenus((string) ($contato['occupation'] ?? ''), 255);
    $contato['occupationDescription'] = limitar_texto_plenus((string) ($contato['occupationDescription'] ?? ''), 255);
    $contato['descricaoPessoaContatoTipo'] = limitar_texto_plenus((string) ($contato['descricaoPessoaContatoTipo'] ?? ''), 255);
    
return aplicar_maiusculas_payload_plenus([
        'contacts' => [$contato],
    ]);
}

function montar_payload_plenus(
    PDO $pdo,
    array $dadosCnpj,
    string $cnpj,
    string $nomeContato,
    string $emailContato,
    callable $formatarDocumento,
    bool $incluirContatoNfe = false,
    ?array $contaContabil = null
): array {
    $bornDateDados = gerar_born_date_plenus($dadosCnpj['abertura'] ?? null);
    $nomeEmpresa = normalizar_texto($dadosCnpj['nome'] ?? '');
    $fantasia = normalizar_texto($dadosCnpj['fantasia'] ?? ($dadosCnpj['nome_fantasia'] ?? ''));

    if ($nomeEmpresa === '' && isset($dadosCnpj['nome'])) {
        $nomeEmpresa = (string) $dadosCnpj['nome'];
    }

    if ($fantasia === '') {
        $fantasia = $nomeEmpresa;
    }

    $emailEmpresa = obter_email_empresa_plenus($dadosCnpj, $emailContato);

    $telefoneBruto = (string) ($dadosCnpj['telefone'] ?? '');
    if (str_contains($telefoneBruto, '/')) {
        $partesTelefone = array_filter(array_map('trim', explode('/', $telefoneBruto)), static function ($parte) {
            return $parte !== '';
        });
        $telefoneBruto = $partesTelefone ? reset($partesTelefone) : '';
    }

    $telefone = preg_replace('/\D/', '', $telefoneBruto);

    $cep = preg_replace('/\D/', '', (string) ($dadosCnpj['cep'] ?? ''));
    $logradouro = normalizar_texto($dadosCnpj['logradouro'] ?? '');
    $numero = normalizar_texto($dadosCnpj['numero'] ?? '');
    if ($numero === '') {
        $numero = 'SN';
    }
    $complemento = normalizar_texto($dadosCnpj['complemento'] ?? '');
    $bairro = normalizar_texto($dadosCnpj['bairro'] ?? '');
    $cidade = normalizar_texto($dadosCnpj['municipio'] ?? '');
    $uf = isset($dadosCnpj['uf']) ? strtoupper(trim((string) $dadosCnpj['uf'])) : null;
    $estadoNome = isset($dadosCnpj['estado']) ? normalizar_texto($dadosCnpj['estado']) : null;

$codigoEstadoInformado = normalizar_codigo_plenus($dadosCnpj['estado_codigo'] ?? null);
    $codigoPaisInformado = PLENUS_CODIGO_PAIS;
    $codigoCidadeInformado = normalizar_codigo_plenus($dadosCnpj['cidade_codigo'] ?? null);

    $paisNome = normalizar_texto($dadosCnpj['pais'] ?? '');

    $codigos = buscar_codigo_estado($pdo, $estadoNome, $uf, $codigoEstadoInformado, $codigoPaisInformado, $paisNome);
    $codigos['pais'] = PLENUS_CODIGO_PAIS;
    if ($codigos['estado'] === null && $codigoEstadoInformado !== null) {
        $codigos['estado'] = $codigoEstadoInformado;
    }

    $codigoCidade = buscar_codigo_cidade($pdo, $codigos['pais'], $codigos['estado'], $cidade, $codigoCidadeInformado);
    if ($codigoCidade === null && $codigoCidadeInformado !== null) {
        $codigoCidade = $codigoCidadeInformado;
    }
    
    $codigoCidadeParaEnvio = $codigoCidade ?? $codigoCidadeInformado ?? '';
    $suframa = normalizar_state_registration($dadosCnpj['suframa'] ?? null) ?? '';

    $stateId = determinar_state_id_plenus($dadosCnpj);
    $nonContributor = strtoupper($stateId) === 'NC';

    $legalId = preg_replace('/[^0-9A-Za-z]/', '', (string) $cnpj);
    if ($legalId === '') {
        $legalId = $formatarDocumento($cnpj);
    }

    $cnae = '';
    if (isset($dadosCnpj['cnae'])) {
        $cnaeNormalizado = preg_replace('/\D/', '', (string) $dadosCnpj['cnae']);
        if ($cnaeNormalizado !== '') {
            $cnae = $cnaeNormalizado;
        }
    }

    $contatos = [
        [
            'seq'                   => 1,
            'name'                  => $nomeContato,
            'telephone'             => '',
            'cellphone'             => '',
            'email'                 => strtolower(trim($emailContato)),
            'notes'                 => '',
            'occupation'            => 'SITE',
            'occupationDescription' => 'SITE',
        ],
    ];

        $contatos[] = [
        'seq'                   => count($contatos) + 1,
        'name'                  => $nomeContato,
        'telephone'             => '',
        'cellphone'             => '',
        'email'                 => strtolower(trim($emailContato)),
        'notes'                 => '',
        'occupation'            => '2',
        'occupationDescription' => 'COMPRADOR',
    ];

    if ($incluirContatoNfe) {
        $contatos[] = [
            'seq'                   => count($contatos) + 1,
            'name'                  => $nomeEmpresa !== '' ? $nomeEmpresa : $fantasia,
            'telephone'             => $telefone,
            'cellphone'             => '',
            'email'                 => strtolower(trim($emailEmpresa)),
            'notes'                 => '',
            'occupation'            => '1',
            'occupationDescription' => 'NFE',
        ];
    }

        $dataCadastro = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));

    $payload = [
        'camponovo'        => true,
        'id'               => '',
        'type'             => 0,
        'legalId'          => $legalId,
        'name'             => $nomeEmpresa,
        'fantasyName'      => $fantasia,
        'telephone'        => $telefone,
        'cellphone'        => '',
        'email'            => $emailEmpresa,
        'notes'            => '',
        'financial'        => [
            'partialBilling'     => false,
            'earlyBilling'       => false,
            'fractionalDelivery' => false,
            'earlyDelivery'      => false,
            'vendor'             => false,
            'accountingAccount'  => 0,
            'graceDays'          => 0,
            'billingCustomer'    => '',
            'usedCredit'         => '',
            'fullCredit'         => null,
            'availableCredit'    => null,
            'lastPurchase'       => '',
            'biggestPurchase'    => '',
            'averageTerm'        => '',
            'overdueBonds'       => '',
        ],
        'locationAddress'  => [
            'zipCode'        => $cep,
            'streetName'     => $logradouro,
            'streetNumber'   => $numero,
            'complement'     => $complemento,
            'district'       => $bairro,
            'referencePoint' => '',
            'country'        => PLENUS_CODIGO_PAIS,
            'state'          => $codigos['estado'] ?? '',
            'city'           => $codigoCidadeParaEnvio,
        ],
        'stateId'          => $stateId,
        'contacts'         => $contatos,
        'categoryCode'           => '',
        'representativeCode'     => '',
        'conveyorCode'           => '',
        'paymentConditionCode'   => '',
        'carrierCode'            => '',
        'fiscalNatureCode'       => '',
        'freightType'            => 4,
        'nonContributor'         => $nonContributor,
        'cnae'                   => $cnae,
        'taxRegime'              => 0,
        'suframa'                => $suframa,
        'fiscalText'             => '',
        'attribute1'             => '',
        'attribute2'             => '',
        'attribute3'             => '',
        'attribute4'             => $dataCadastro->format('d/m/Y 00:00:00'),
        'attribute5'             => '',
        'attribute6'             => '',
        'attribute7'             => '_USR_TPCP000',
        'attribute8'             => '',
    ];

    if ($contaContabil !== null && isset($contaContabil['numero'], $contaContabil['descricao'])) {
        $payload['financial']['accountingAccount'] = (int) $contaContabil['numero'];
        $payload['contaContabilNumero'] = (int) $contaContabil['numero'];
        $payload['contaContabilDescricao'] = (string) $contaContabil['descricao'];
    }

    if ($bornDateDados !== null) {
        $payload = array_merge($payload, $bornDateDados);
    }

    return aplicar_limites_payload_plenus($payload);
}

function executar_requisicao_plenus(
    array $config,
    array $payload,
    string $metodo,
    string $endpoint,
    array $queryParams = [],
    array $contextoErro = []
): array
{
        $metodoUpper = strtoupper(trim($metodo));
    if ($metodoUpper === '') {
        $metodoUpper = 'GET';
    }
    
    [$baseUrl, $hostHeader, $resolveEntry] = normalizar_base_url_plenus($config);
    $endpoint = '/' . ltrim($endpoint, '/');
    $queryString = http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);
    $url = $baseUrl . $endpoint;
    if ($queryString !== '') {
        $url .= (str_contains($url, '?') ? '&' : '?') . $queryString;
    }

    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: ' . $config['authorization'],
        'X-Session-Id: ' . $config['sessionId'],
        'Host: ' . ($hostHeader !== '' ? $hostHeader : $config['host']),
    ];

    $timeout = isset($config['timeout']) ? (int) $config['timeout'] : 30;
    if ($timeout <= 0) {
        $timeout = 30;
    }

    $options = [
         CURLOPT_CUSTOMREQUEST  => $metodoUpper,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => $timeout,
    ];

    $hostUrl = parse_url($url, PHP_URL_HOST);
    $hostEhIp = $hostUrl !== null && filter_var($hostUrl, FILTER_VALIDATE_IP) !== false;
    if (!empty($config['skipTlsVerify']) || $hostEhIp) {
        $options[CURLOPT_SSL_VERIFYHOST] = 0;
        $options[CURLOPT_SSL_VERIFYPEER] = false;
    }
    
    if ($resolveEntry !== null) {
        $options[CURLOPT_RESOLVE] = [$resolveEntry];
    }

    if (in_array($metodoUpper, ['POST', 'PUT', 'PATCH'], true)) {
        $options[CURLOPT_POSTFIELDS] = $body;
    }

    if ($metodoUpper === 'POST') {
        $options[CURLOPT_POST] = true;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

$decodedResponse = json_decode((string) $response, true);
$success = $response !== false && $httpCode >= 200 && $httpCode < 300;
if (!$success && in_array($metodoUpper, ['POST', 'PUT'], true)) {
    $detalhesErro = [
        'metodo'      => $metodoUpper,
        'url'         => $url,
        'endpoint'    => $endpoint,
        'status'      => $httpCode ?: null,
        'queryParams' => $queryParams,
        'payload'     => $payload,
        'response'    => is_array($decodedResponse) ? $decodedResponse : (string) $response,
        'erroCurl'    => $curlError ?: null,
    ];
    if ($contextoErro !== []) {
        $detalhesErro['contexto'] = $contextoErro;
    }
    registrar_log_json_plenus('Plenus - falha na requisição', $detalhesErro);
}

    return [
        'success'  => $success,
        'code'     => $httpCode,
        'body'     => $response,
        'error'    => $response === false ? $curlError : null,
    ];
}

function enviar_plenus(array $config, array $payload, array $contextoErro = []): array
 {
     return executar_requisicao_plenus($config, $payload, 'POST', '/api/admin/customer', [], $contextoErro);
 }

function contato_site_existe_plenus(PDO $pdo, string $codigoPessoa, string $emailContato): bool
{
    $emailNormalizado = strtolower(trim($emailContato));
    if ($emailNormalizado === '') {
        return false;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM MBAD_PESSOACONTATO WHERE CD_PESSOA = ? " .
            "AND UPPER(LTRIM(RTRIM(COALESCE(CD_FUNCAO, '')))) = 'SITE' " .
            "AND LOWER(LTRIM(RTRIM(COALESCE(DS_EMAIL, '')))) = ?"
        );
        $stmt->execute([$codigoPessoa, $emailNormalizado]);
        return (bool) $stmt->fetchColumn();
    } catch (PDOException $e) {
        log_event('Erro ao verificar contato SITE no Plenus: ' . $e->getMessage());
        return false;
    }
}

function obter_codigo_cliente_plenus_por_cnpj(PDO $pdo, string $cnpj): ?string
{
    $cnpjNumeros = preg_replace('/[^0-9A-Za-z]/', '', $cnpj);
    if ($cnpjNumeros === '') {
        return null;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT TOP 1 CD_PESSOA\n"
            . "  FROM MBAD_PESSOA\n"
            . " WHERE REPLACE(REPLACE(REPLACE(REPLACE(NR_CPFCNPJ, '.', ''), '-', ''), '/', ''), ' ', '') = ?"
        );
        $stmt->execute([$cnpjNumeros]);
        $codigo = $stmt->fetchColumn();
        if ($codigo === false || $codigo === null) {
            return null;
        }
        $codigo = trim((string) $codigo);

        return $codigo !== '' ? $codigo : null;
    } catch (PDOException $e) {
        log_event('Erro ao consultar cliente Plenus por CNPJ: ' . $e->getMessage());
        return null;
    }
}

function sincronizar_cadastro_plenus(
    PDO $pdo,
    string $cnpj,
    string $nomeContato,
    string $emailContato,
    string $baseDir,
    callable $formatarDocumento
): ?array {
    $config = carregar_configuracao_plenus($baseDir);

    if (!$config['enabled']) {
        return null;
    }

    foreach (['host', 'authorization', 'sessionId', 'authentication'] as $campo) {
        if (empty($config[$campo])) {
            log_event('Configuração Plenus ausente: ' . $campo);
            return null;
        }
    }

    $codigoExistente = obter_codigo_cliente_plenus_por_cnpj($pdo, $cnpj);
    if ($codigoExistente !== null) {
        return [
            'codigo' => $codigoExistente,
            'criado' => false,
        ];
    }

        $dadosCnpj = obter_detalhes_cnpj_plenus($cnpj, $baseDir);

    if (!$dadosCnpj) {
        log_event('Não foi possível obter dados do CNPJ para o Plenus.');
        return null;
    }
    
        $contaContabil = criar_conta_contabil_plenus($pdo, $config, $dadosCnpj);
    if ($contaContabil === null) {
        log_event('Não foi possível criar a conta contábil no Plenus antes do cadastro do cliente.');

        return null;
    }

    $payload = montar_payload_plenus(
        $pdo,
        $dadosCnpj,
        $cnpj,
        $nomeContato,
        $emailContato,
        $formatarDocumento,
        true,
        $contaContabil
    );
    
$resultado = enviar_plenus($config, $payload, [
    'cnpj'         => $cnpj,
    'emailContato' => $emailContato,
    'nomeContato'  => $nomeContato,
]);

    if (!$resultado['success']) {
        $mensagem = $resultado['error'] ?: ($resultado['body'] ?? 'sem resposta');
        log_event('Falha ao enviar cadastro ao Plenus: ' . $mensagem);
        return null;
    }
    
    $codigoCriado = obter_codigo_cliente_plenus_por_cnpj($pdo, $cnpj);

    if ($codigoCriado === null) {
        log_event('Cadastro enviado ao Plenus, mas não foi possível obter o código retornado.');
        return null;
    }

    atualizar_conta_contabil_cliente($pdo, $codigoCriado, (int) $contaContabil['numero']);
    atualizar_campo_cnaes_secundarios($pdo, $cnpj, $dadosCnpj);
    atualizar_natureza_cliente_plenus($pdo, $codigoCriado, $dadosCnpj);
    return [
        'codigo' => $codigoCriado,
        'criado' => true,
    ];
}

function atualizar_contato_cliente_plenus(
    PDO $pdo,
    string $codigoPessoa,
    string $nomeContato,
    string $emailContato,
    string $baseDir
): bool {

    $codigoPessoa = trim($codigoPessoa);
    if ($codigoPessoa === '') {
        log_event('Código do cliente Plenus ausente para atualizar contato.');
        return false;
    }

        if (contato_site_existe_plenus($pdo, $codigoPessoa, $emailContato)) {
        log_event('Contato SITE já existente no Plenus para o cliente ' . $codigoPessoa . '. Nenhuma atualização necessária.');
        return false;
    }

    $sequencia = 1;
    try {
        $stmt = $pdo->prepare(
            "SELECT TOP 1 NR_SEQUENCIA\n" .
            "  FROM MBAD_PESSOACONTATO\n" .
            " WHERE CD_PESSOA = ?\n" .
            " ORDER BY NR_SEQUENCIA DESC"
        );
        $stmt->execute([$codigoPessoa]);
        $ultimo = $stmt->fetchColumn();
        if ($ultimo !== false && $ultimo !== null) {
            $sequencia = max(1, ((int) $ultimo) + 1);
        }
    } catch (PDOException $e) {
        log_event('Erro ao obter sequência de contato no Plenus: ' . $e->getMessage());
    }

    $nomeContato = trim($nomeContato);
    if ($nomeContato === '') {
        $nomeContato = 'Não informado';
    }

    $emailContato = strtolower(trim($emailContato));

    try {
        $insert = $pdo->prepare(
            "INSERT INTO MBAD_PESSOACONTATO (CD_PESSOA, NR_SEQUENCIA, DS_CONTATO, DS_EMAIL, CD_FUNCAO, CD_TIPO) " .
            "VALUES (?, ?, ?, ?, 'SITE', 1)"
        );
        $insert->execute([
            $codigoPessoa,
            $sequencia,
            $nomeContato,
            $emailContato,
        ]);
    } catch (PDOException $e) {
        log_event('Erro ao inserir contato SITE no Plenus: ' . $e->getMessage());
        return false;
    }

    return true;
}
