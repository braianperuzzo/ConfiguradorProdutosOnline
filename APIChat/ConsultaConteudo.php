<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/NucleoApiChat.php';

function responder_explorar_site(int $status, array $payload): void
{
    api_chat_middleware_responder($status, $payload);
}

const API_CHAT_EXPLORAR_CACHE_SCHEMA_VERSION = 1;
const API_CHAT_EXPLORAR_ENDPOINT = '/APIChat/ConsultaConteudo.php';
const API_CHAT_IMAGENS_ENDPOINT = '/APIChat/ConsultaConteudo.php';
const API_CHAT_IMAGENS_INDEX_FILE = __DIR__ . '/Indices/imagens_oficiais_ibr_index.json';
const API_CHAT_IMAGENS_STATUS_FILE = __DIR__ . '/Indices/imagens_oficiais_ibr_status.json';
const API_CHAT_IMAGENS_VERSION = '1.2.0';
const API_CHAT_IMAGENS_TIPOS_VALIDOS = [
    'PRODUCT_PHOTO',
    'DIMENSIONAL_DRAWING',
    'ASSEMBLY_MOUNTING',
    'TABLE_CHART',
    'WIRING_DIAGRAM',
    'ICON_OTHER',
];

function api_chat_explorar_async_requested(): bool
{
    $valor = strtolower(trim((string) ($_GET['async'] ?? '')));
    return in_array($valor, ['1', 'true', 'sim', 'yes'], true);
}

function api_chat_explorar_job_urls(string $jobId): array
{
    return [
        'statusUrl' => API_CHAT_EXPLORAR_ENDPOINT . '?acao=status&jobId=' . rawurlencode($jobId),
        'cancelUrl' => API_CHAT_EXPLORAR_ENDPOINT . '?acao=cancel&jobId=' . rawurlencode($jobId),
    ];
}

function api_chat_explorar_responder_aceito(array $resultado): void
{
    $retryAfter = 2;
    $jobId = api_chat_async_job_create(API_CHAT_EXPLORAR_ENDPOINT, ['status' => 'completed', 'result' => $resultado]);
    $urls = api_chat_explorar_job_urls($jobId);
    api_chat_middleware_set_job_context($jobId, $retryAfter);
    responder_explorar_site(202, [
        'ok' => true,
        'status' => 'accepted',
        'jobId' => $jobId,
        'statusUrl' => $urls['statusUrl'],
        'cancelUrl' => $urls['cancelUrl'],
        'retryAfter' => $retryAfter,
    ]);
}

function api_chat_explorar_resposta_cache_ttl(): int
{
    $ttl = (int) (getenv('GPT_ACTIONS_EXPLORAR_RESPONSE_CACHE_TTL') ?: 90);
    return $ttl > 0 ? $ttl : 90;
}

function api_chat_cache_parse_bool(string $nome): bool
{
    $valor = strtolower(trim((string) ($_GET[$nome] ?? '')));
    return in_array($valor, ['1', 'true', 'sim', 'yes'], true);
}

function api_chat_cache_invalidar_dominio_admin(string $responder): bool
{
    if (!api_chat_cache_parse_bool('invalidateCache')) {
        return false;
    }

    $dominio = trim((string) ($_GET['dominio'] ?? 'explorarSite'));
    $permitidos = ['produtos', 'imagens', 'explorarSite'];
    if (!in_array($dominio, $permitidos, true)) {
        call_user_func($responder, 400, ['ok' => false, 'erro' => 'dominio inválido. Use: produtos, imagens ou explorarSite.']);
    }

    $versao = api_chat_storage_invalidate_cache_domain($dominio);
    call_user_func($responder, 200, [
        'ok' => true,
        'acao' => 'invalidarCache',
        'dominio' => $dominio,
        'versaoDominio' => $versao,
    ]);

    return true;
}

function caminho_sensivel_api_chat(string $caminho): bool
{
    $bloqueios = [
        '/configuracoes/',
        '/acessosconsultas/',
        '/app_data/',
        '/paginasareaclientesessaoperfil/',
        '/paginascarrinhoprodutos/',
        '/paginascarrinhoprodutosrecomenda/',
        '/seguranca/',
        '/processamentofilajobs/',
        '/preco',
        '/estoque',
        'precointerno',
        'precoexterno',
        'estoqueinterno',
        'estoqueexterno',
    ];

    $normalizado = strtolower($caminho);
    foreach ($bloqueios as $item) {
        if (strpos($normalizado, $item) !== false) {
            return true;
        }
    }

    return false;
}

function caminhos_publicos_permitidos_api_chat(): array
{
    return [
        'diretorios' => [
            '/DocumentacaoAPIs/',
            '/PaginasConfiguradores/',
            '/PaginasConfiguradoresSeletores/',
            '/PaginasPrincipal/',
            '/PaginaErros/',
            '/Layout/',
        ],
        'arquivos' => [
            '/robots.txt',
            '/README.txt',
        ],
    ];
}

function extensoes_permitidas_api_chat(): array
{
    return ['md', 'txt', 'html'];
}

function api_chat_explorar_site_cache_ttl(): int
{
    $ttl = (int) (getenv('GPT_ACTIONS_EXPLORAR_CACHE_TTL') ?: 120);
    return $ttl > 0 ? $ttl : 120;
}

function api_chat_explorar_site_cache_path(string $baseDir): string
{
    $hashBase = substr(sha1($baseDir), 0, 16);
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'api-chat-explorar-cache-' . $hashBase . '.json';
}

function api_chat_explorar_site_cache_carregar(string $baseDir, string $cacheTag): array
{
    $arquivo = api_chat_explorar_site_cache_path($baseDir);
    if (!is_file($arquivo) || !is_readable($arquivo)) {
        return [];
    }

    $conteudo = file_get_contents($arquivo);
    if (!is_string($conteudo) || trim($conteudo) === '') {
        return [];
    }

    $dados = json_decode($conteudo, true);
    if (!is_array($dados)) {
        return [];
    }

    $expiraEm = (int) ($dados['expiraEm'] ?? 0);
    if ($expiraEm < time()) {
        return [];
    }

    $tag = (string) ($dados['cacheTag'] ?? '');
    if ($tag !== $cacheTag) {
        return [];
    }

    $paginas = $dados['paginas'] ?? [];
    return is_array($paginas) ? $paginas : [];
}

function api_chat_explorar_site_cache_salvar(string $baseDir, string $cacheTag, array $paginas): void
{
    $payload = [
        'cacheTag' => $cacheTag,
        'expiraEm' => time() + api_chat_explorar_site_cache_ttl(),
        'paginas' => array_values($paginas),
    ];

    @file_put_contents(
        api_chat_explorar_site_cache_path($baseDir),
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

function caminho_allowlist_api_chat(string $relativo): bool
{
    if ($relativo === '' || $relativo[0] !== '/') {
        return false;
    }

    $normalizado = str_replace('\\', '/', $relativo);
    $config = caminhos_publicos_permitidos_api_chat();

    foreach ($config['arquivos'] as $arquivo) {
        if ($normalizado === $arquivo) {
            return true;
        }
    }

    foreach ($config['diretorios'] as $diretorio) {
        if (strpos($normalizado, $diretorio) === 0) {
            return true;
        }
    }

    return false;
}

function caminho_extensao_permitida_api_chat(string $relativo): bool
{
    $ext = strtolower((string) pathinfo($relativo, PATHINFO_EXTENSION));
    if ($ext === 'php') {
        return false;
    }

    return in_array($ext, extensoes_permitidas_api_chat(), true);
}

function resolver_alias_caminho_publico_api_chat(string $path, string $baseDir): string
{
    if ($path === '' || $path[0] !== '/') {
        return $path;
    }

    if (caminho_allowlist_api_chat($path)) {
        return $path;
    }

    if (strpos(substr($path, 1), '/') !== false) {
        return $path;
    }

    $config = caminhos_publicos_permitidos_api_chat();
    $baseReal = realpath($baseDir);
    if (!is_string($baseReal)) {
        return $path;
    }

    $matches = [];
    foreach ($config['diretorios'] as $diretorio) {
        $candidato = $diretorio . ltrim($path, '/');
        $absoluto = realpath($baseReal . $candidato);

        if (!is_string($absoluto) || strpos($absoluto, $baseReal) !== 0 || !is_file($absoluto)) {
            continue;
        }

        $matches[] = $candidato;
    }

    if (count($matches) !== 1) {
        return $path;
    }

    return $matches[0];
}

function manifesto_paginas_api_chat(string $baseDir): array
{
    $manifestoPath = $baseDir . '/APIChat/explorar-site-manifest.json';
    if (!is_file($manifestoPath) || !is_readable($manifestoPath)) {
        return [];
    }

    $conteudo = file_get_contents($manifestoPath);
    if (!is_string($conteudo) || trim($conteudo) === '') {
        return [];
    }

    $dados = json_decode($conteudo, true);
    if (!is_array($dados)) {
        return [];
    }

    $saida = [];
    foreach ($dados as $item) {
        if (!is_string($item)) {
            continue;
        }

        $relativo = '/' . ltrim(str_replace('\\', '/', trim($item)), '/');

        if (!caminho_allowlist_api_chat($relativo) || caminho_sensivel_api_chat($relativo)) {
            continue;
        }

        if (!caminho_extensao_permitida_api_chat($relativo)) {
            continue;
        }

        $absoluto = realpath($baseDir . $relativo);
        $baseReal = realpath($baseDir);
        if (!is_string($absoluto) || !is_string($baseReal) || strpos($absoluto, $baseReal) !== 0 || !is_file($absoluto)) {
            continue;
        }

        $saida[] = $relativo;
    }

    return array_values(array_unique($saida));
}

function listar_paginas_api_chat(string $baseDir): array
{
    $manifestoPath = $baseDir . '/APIChat/explorar-site-manifest.json';
    $cacheTag = is_file($manifestoPath)
        ? 'manifesto:' . (string) filemtime($manifestoPath)
        : 'scan';

    $cache = api_chat_explorar_site_cache_carregar($baseDir, $cacheTag);
    if ($cache !== []) {
        return $cache;
    }

    $doManifesto = manifesto_paginas_api_chat($baseDir);
    if ($doManifesto !== []) {
        sort($doManifesto);
        api_chat_explorar_site_cache_salvar($baseDir, $cacheTag, $doManifesto);
        return $doManifesto;
    }

    $config = caminhos_publicos_permitidos_api_chat();
    $baseReal = realpath($baseDir);
    if (!is_string($baseReal)) {
        return [];
    }

    $saida = [];
    foreach ($config['arquivos'] as $arquivoPermitido) {
        if (!caminho_extensao_permitida_api_chat($arquivoPermitido)) {
            continue;
        }

        $absoluto = realpath($baseReal . $arquivoPermitido);
        if (!is_string($absoluto) || strpos($absoluto, $baseReal) !== 0 || !is_file($absoluto)) {
            continue;
        }

        $saida[] = $arquivoPermitido;
    }

    foreach ($config['diretorios'] as $diretorioPermitido) {
        $diretorioAbsoluto = realpath($baseReal . $diretorioPermitido);
        if (!is_string($diretorioAbsoluto) || strpos($diretorioAbsoluto, $baseReal) !== 0 || !is_dir($diretorioAbsoluto)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($diretorioAbsoluto, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $arquivo) {
            if (!$arquivo instanceof SplFileInfo || !$arquivo->isFile()) {
                continue;
            }

            $absoluto = str_replace('\\', '/', $arquivo->getPathname());
            $relativo = '/' . ltrim(str_replace(str_replace('\\', '/', $baseReal), '', $absoluto), '/');

            if (!caminho_allowlist_api_chat($relativo) || caminho_sensivel_api_chat($relativo)) {
                continue;
            }

            if (!caminho_extensao_permitida_api_chat($relativo)) {
                continue;
            }

            $saida[] = $relativo;
        }
    }

    $saida = array_values(array_unique($saida));
    sort($saida);
    api_chat_explorar_site_cache_salvar($baseDir, $cacheTag, $saida);
    return $saida;
}

function ler_pagina_api_chat(string $baseDir, string $path): array
{
    $path = trim($path);
    if ($path === '') {
        return ['erro' => 'Informe o parâmetro "path".'];
    }

    if ($path[0] !== '/') {
        $path = '/' . $path;
    }

    $path = resolver_alias_caminho_publico_api_chat($path, $baseDir);

    if (strpos($path, '..') !== false || caminho_sensivel_api_chat($path)) {
        return ['erro' => 'Acesso negado para o caminho informado.'];
    }

    if (!caminho_allowlist_api_chat($path)) {
        return ['erro' => 'Caminho fora da allowlist pública.'];
    }

    if (!caminho_extensao_permitida_api_chat($path)) {
        return ['erro' => 'Extensão de arquivo não permitida.'];
    }

    $absoluto = realpath($baseDir . $path);
    $baseReal = realpath($baseDir);

    if (!is_string($absoluto) || !is_string($baseReal) || strpos($absoluto, $baseReal) !== 0) {
        return ['erro' => 'Arquivo inválido.'];
    }

    if (!is_file($absoluto) || !is_readable($absoluto)) {
        return ['erro' => 'Arquivo não encontrado ou sem leitura.'];
    }

    $conteudo = file_get_contents($absoluto);
    if (!is_string($conteudo)) {
        return ['erro' => 'Falha ao ler arquivo.'];
    }

    if (strlen($conteudo) > 250000) {
        $conteudo = substr($conteudo, 0, 250000);
    }

    return [
        'path' => $path,
        'bytes' => strlen($conteudo),
        'conteudo' => $conteudo,
    ];
}

$baseDir = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
if ($baseDir === '') {
    $baseDir = dirname(__DIR__);
}

api_chat_middleware_init($baseDir, API_CHAT_EXPLORAR_ENDPOINT);

$metodo = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

$rota = strtolower(trim((string) ($_GET['rota'] ?? '')));
$acaoLegada = strtolower(trim((string) ($_GET['acao'] ?? '')));
$ehFluxoImagens = in_array($rota, ['search', 'by-product', 'resolve', 'status', 'crawl'], true)
    || in_array($acaoLegada, ['buscarimagensoficiaisibr', 'resolverimagensoficiaisibr'], true);

if ($ehFluxoImagens) {
    $_GET['rota'] = $rota !== ''
        ? $rota
        : ($acaoLegada === 'resolverimagensoficiaisibr' ? 'resolve' : 'search');
    api_chat_imagens_oficiais_processar($baseDir);
    return;
}

if ($metodo !== 'GET') {
    responder_explorar_site(405, [
        'ok' => false,
        'erro' => 'Use GET para explorar conteúdo do site ou rotas de imagem (search/by-product/resolve/status/crawl).',
    ]);
}

auth_api_chat_validar_ou_responder($baseDir, 'responder_explorar_site');
api_chat_cache_invalidar_dominio_admin('responder_explorar_site');

$acao = strtolower(trim((string) ($_GET['acao'] ?? 'listar')));
if ($acao === 'status') {
    $jobId = trim((string) ($_GET['jobId'] ?? ''));
    $job = api_chat_async_job_get(API_CHAT_EXPLORAR_ENDPOINT, $jobId);
    if ($job === []) {
        responder_explorar_site(404, ['ok' => false, 'erro' => 'jobId não encontrado.', 'codigoErro' => 'JOB_NAO_ENCONTRADO']);
    }

    api_chat_middleware_set_job_context($jobId, ($job['status'] ?? '') === 'completed' ? null : 2);
    responder_explorar_site(200, [
        'ok' => true,
        'jobId' => $jobId,
        'status' => $job['status'] ?? 'unknown',
        'result' => $job['result'] ?? null,
    ]);
}

if ($acao === 'cancel') {
    $jobId = trim((string) ($_GET['jobId'] ?? ''));
    $job = api_chat_async_job_cancel(API_CHAT_EXPLORAR_ENDPOINT, $jobId);
    if ($job === []) {
        responder_explorar_site(404, ['ok' => false, 'erro' => 'jobId não encontrado.', 'codigoErro' => 'JOB_NAO_ENCONTRADO']);
    }

    api_chat_middleware_set_job_context($jobId, null);
    responder_explorar_site(200, [
        'ok' => true,
        'jobId' => $jobId,
        'status' => 'cancelled',
    ]);
}

if ($acao === 'listar') {
    $pagina = (int) ($_GET['pagina'] ?? 1);
    if ($pagina < 1) {
        $pagina = 1;
    }

    $limite = (int) ($_GET['limite'] ?? 100);
    if ($limite < 1) {
        $limite = 1;
    }
    if ($limite > 200) {
        $limite = 200;
    }

    $filtrosCache = [
        'pagina' => $pagina,
        'limite' => $limite,
        'q' => '',
        'tipoBusca' => 'listar',
    ];
    $cacheKey = api_chat_storage_cache_key(API_CHAT_EXPLORAR_ENDPOINT, 'leitura', $filtrosCache, 'explorarSite', API_CHAT_EXPLORAR_CACHE_SCHEMA_VERSION);
    $cacheRaw = api_chat_storage_get($cacheKey);
    if (is_string($cacheRaw)) {
        $cachePayload = json_decode($cacheRaw, true);
        if (is_array($cachePayload)) {
            responder_explorar_site(200, $cachePayload);
        }
    }

    $paginas = listar_paginas_api_chat($baseDir);
    $total = count($paginas);
    $offset = ($pagina - 1) * $limite;
    $paginasPaginadas = array_slice($paginas, $offset, $limite);
    $temMais = ($offset + $limite) < $total;

    $payload = [
        'ok' => true,
        'acao' => 'listar',
        'pagina' => $pagina,
        'limite' => $limite,
        'total' => $total,
        'temMais' => $temMais,
        'proximaPagina' => $temMais ? ($pagina + 1) : null,
        'paginas' => $paginasPaginadas,
    ];

    api_chat_storage_set($cacheKey, (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), api_chat_explorar_resposta_cache_ttl());
    if (api_chat_explorar_async_requested()) {
        api_chat_explorar_responder_aceito($payload);
    }

    responder_explorar_site(200, $payload);
}

if ($acao === 'ler') {
    $path = (string) ($_GET['path'] ?? '');
    $filtrosCache = [
        'pagina' => 1,
        'limite' => 1,
        'q' => trim($path),
        'tipoBusca' => 'ler',
    ];
    $cacheKey = api_chat_storage_cache_key(API_CHAT_EXPLORAR_ENDPOINT, 'leitura', $filtrosCache, 'explorarSite', API_CHAT_EXPLORAR_CACHE_SCHEMA_VERSION);
    $cacheRaw = api_chat_storage_get($cacheKey);
    if (is_string($cacheRaw)) {
        $cachePayload = json_decode($cacheRaw, true);
        if (is_array($cachePayload)) {
            responder_explorar_site(200, $cachePayload);
        }
    }

    $resultado = ler_pagina_api_chat($baseDir, $path);
    if (isset($resultado['erro'])) {
        responder_explorar_site(400, [
            'ok' => false,
            'acao' => 'ler',
            'erro' => $resultado['erro'],
        ]);
    }

    $payload = [
        'ok' => true,
        'acao' => 'ler',
        'arquivo' => $resultado,
    ];

    api_chat_storage_set($cacheKey, (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), api_chat_explorar_resposta_cache_ttl());
    if (api_chat_explorar_async_requested()) {
        api_chat_explorar_responder_aceito($payload);
    }

    responder_explorar_site(200, $payload);
}

responder_explorar_site(400, [
    'ok' => false,
    'erro' => 'Ação inválida. Use "listar" ou "ler".',
]);

function responder_imagens_oficiais(int $status, array $payload): void
{
    api_chat_middleware_responder($status, $payload);
}

function api_chat_imagens_oficiais_seed_urls(): array
{
    return [
        'https://configurador.redutoresibr.com.br/PaginasPrincipal/PaginaProdutos.html',
        'https://configurador.redutoresibr.com.br/PaginasConfiguradores/ConfiguradorQU.html',
        'https://configurador.redutoresibr.com.br/PaginasConfiguradores/ConfiguradorQUDR.html',
        'https://configurador.redutoresibr.com.br/PaginasConfiguradores/ConfiguradorPL.html',
        'https://configurador.redutoresibr.com.br/PaginasConfiguradores/ConfiguradorHY.html',
        'https://configurador.redutoresibr.com.br/PaginasConfiguradores/ConfiguradorMO.html',
        'https://configurador.redutoresibr.com.br/PaginasConfiguradores/ConfiguradorAC.html',
        'https://configurador.redutoresibr.com.br/PaginasConfiguradores/ConfiguradorFX.html',
        'https://configurador.redutoresibr.com.br/PaginasConfiguradores/ConfiguradorAE.html',
        'https://configurador.redutoresibr.com.br/PaginasConfiguradores/ConfiguradorIN.html',
        'https://configurador.redutoresibr.com.br/PaginasConfiguradores/ConfiguradorVA.html',
        'https://configurador.redutoresibr.com.br/PaginasConfiguradoresSeletores/SeletoresConfiguradorQU.html',
        'https://configurador.redutoresibr.com.br/PaginasConfiguradoresSeletores/SeletoresConfiguradorQUDR.html',
        'https://configurador.redutoresibr.com.br/PaginasConfiguradoresSeletores/SeletoresConfiguradorPL.html',
        'https://configurador.redutoresibr.com.br/PaginasConfiguradoresSeletores/SeletoresConfiguradorHY.html',
        'https://configurador.redutoresibr.com.br/PaginasConfiguradoresSeletores/SeletoresConfiguradorMO.html',
        'https://configurador.redutoresibr.com.br/PaginasConfiguradoresSeletores/SeletoresConfiguradorAC.html',
        'https://configurador.redutoresibr.com.br/PaginasConfiguradoresSeletores/SeletoresConfiguradorFX.html',
        'https://configurador.redutoresibr.com.br/PaginasConfiguradoresSeletores/SeletoresConfiguradorAE.html',
        'https://configurador.redutoresibr.com.br/PaginasConfiguradoresSeletores/SeletoresConfiguradorIN.html',
        'https://configurador.redutoresibr.com.br/PaginasConfiguradoresSeletores/SeletoresConfiguradorVA.html',
    ];
}


function api_chat_imagens_oficiais_base_url(): string
{
    $baseUrl = trim((string) getenv('API_CHAT_IMAGENS_BASE_URL'));
    if ($baseUrl === '') {
        $baseUrl = 'https://configurador.redutoresibr.com.br';
    }

    return rtrim($baseUrl, '/');
}

function api_chat_imagens_oficiais_varrer_diretorio_produtos(): array
{
    $diretorio = realpath(dirname(__DIR__) . '/ImagensProdutos');
    if (!is_string($diretorio) || $diretorio === '' || !is_dir($diretorio)) {
        return [];
    }

    $baseUrl = api_chat_imagens_oficiais_base_url();
    $registros = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($diretorio, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $arquivo) {
        if (!$arquivo instanceof SplFileInfo || !$arquivo->isFile()) {
            continue;
        }

        $caminho = str_replace('\\', '/', (string) $arquivo->getPathname());
        $relativo = ltrim(str_replace(str_replace('\\', '/', $diretorio), '', $caminho), '/');
        if ($relativo === '') {
            continue;
        }

        $url = $baseUrl . '/ImagensProdutos/' . str_replace('%2F', '/', rawurlencode($relativo));
        if (!api_chat_imagens_oficiais_extensao_imagem($url)) {
            continue;
        }

        $familia = trim((string) basename((string) dirname($relativo)));
        $texto = trim(sprintf('%s | %s', $familia, (string) $arquivo->getFilename()));

        $registros[] = [
            'url' => $url,
            'paginaUrl' => $baseUrl . '/ImagensProdutos/' . ($familia !== '' ? rawurlencode($familia) . '/' : ''),
            'trecho' => $familia,
            'texto' => $texto,
            'width' => 0,
            'height' => 0,
        ];
    }

    return $registros;
}

function api_chat_imagens_oficiais_allowlist_hosts(): array
{
    $hosts = ['configurador.redutoresibr.com.br'];
    $extraRaw = trim((string) getenv('API_CHAT_IMAGENS_ALLOWLIST_HOSTS'));
    if ($extraRaw !== '') {
        $extras = preg_split('/\s*,\s*/', $extraRaw) ?: [];
        foreach ($extras as $host) {
            $host = strtolower(trim((string) $host));
            if ($host !== '') {
                $hosts[] = $host;
            }
        }
    }

    return array_values(array_unique($hosts));
}

function api_chat_imagens_oficiais_host_permitido(string $url): bool
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if ($host === '') {
        return false;
    }

    return in_array($host, api_chat_imagens_oficiais_allowlist_hosts(), true);
}

function api_chat_imagens_oficiais_normalizar_url(string $url, string $baseUrl): string
{
    $url = trim($url);
    if ($url === '' || strpos($url, 'data:') === 0 || strpos($url, 'javascript:') === 0) {
        return '';
    }

    if (preg_match('/^https?:\/\//i', $url) === 1) {
        return $url;
    }

    if (strpos($url, '//') === 0) {
        $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';
        return $scheme . ':' . $url;
    }

    $baseParts = parse_url($baseUrl);
    if (!is_array($baseParts) || empty($baseParts['host'])) {
        return '';
    }

    $scheme = (string) ($baseParts['scheme'] ?? 'https');
    $host = (string) $baseParts['host'];
    $port = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';

    if (strpos($url, '/') === 0) {
        return sprintf('%s://%s%s%s', $scheme, $host, $port, $url);
    }

    $basePath = (string) ($baseParts['path'] ?? '/');
    $baseDir = rtrim(str_replace('\\', '/', dirname($basePath)), '/');
    if ($baseDir === '') {
        $baseDir = '/';
    }

    return sprintf('%s://%s%s%s/%s', $scheme, $host, $port, rtrim($baseDir, '/'), ltrim($url, '/'));
}

function api_chat_imagens_oficiais_limpar_url(string $url): string
{
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return $url;
    }

    $scheme = (string) ($parts['scheme'] ?? 'https');
    $host = (string) $parts['host'];
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    $path = (string) ($parts['path'] ?? '/');

    return sprintf('%s://%s%s%s', $scheme, $host, $port, $path);
}

function api_chat_imagens_oficiais_extensao_imagem(string $url): bool
{
    $path = strtolower((string) parse_url($url, PHP_URL_PATH));
    $ext = pathinfo($path, PATHINFO_EXTENSION);
    if ($ext === '') {
        return false;
    }

    return in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'svg'], true);
}

function api_chat_imagens_oficiais_extra_keywords(): array
{
    return [
        'DIMENSIONAL_DRAWING' => ['dimens', 'dimension', 'cotas', 'outline', 'drawing', '2d'],
        'ASSEMBLY_MOUNTING' => ['montagem', 'assembly', 'exploded', 'flange', 'b5', 'b14', 'pé', 'pe ', 'torque arm'],
        'TABLE_CHART' => ['tabela', 'table', 'chart', 'curva', 'graf', 'performance'],
        'WIRING_DIAGRAM' => ['wiring', 'esquema', 'ligação', 'ligacao', 'diagrama'],
    ];
}

function api_chat_imagens_oficiais_tipo_por_texto(string $texto): string
{
    $textoNorm = mb_strtolower($texto, 'UTF-8');
    foreach (api_chat_imagens_oficiais_extra_keywords() as $tipo => $keywords) {
        foreach ($keywords as $keyword) {
            if (mb_strpos($textoNorm, mb_strtolower($keyword, 'UTF-8')) !== false) {
                return $tipo;
            }
        }
    }

    return 'PRODUCT_PHOTO';
}

function api_chat_imagens_oficiais_linha_familia(string $texto): string
{
    $map = [
        'q udr' => 'IBR QDR',
        'qudr' => 'IBR QDR',
        'ibr qdr' => 'IBR QDR',
        'ibr q' => 'IBR Q',
        'configuradorqu' => 'IBR Q',
        'planet' => 'Planetário',
        'anticorros' => 'Anticorrosivo',
        'automa' => 'Automação',
        'hy' => 'IBR HY',
        'mo' => 'IBR MO',
        'pl' => 'IBR PL',
        'ac' => 'IBR AC',
        'ae' => 'IBR AE',
        'in' => 'IBR IN',
        'va' => 'IBR VA',
    ];

    $textoNorm = mb_strtolower($texto, 'UTF-8');
    foreach ($map as $token => $linha) {
        if (mb_strpos($textoNorm, $token) !== false) {
            return $linha;
        }
    }

    return '';
}

function api_chat_imagens_oficiais_metadata_linha_map(): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $base = [];
    $manifestPath = dirname(__DIR__) . '/ImagensProdutos/manifest.json';
    if (is_file($manifestPath) && is_readable($manifestPath)) {
        $conteudo = file_get_contents($manifestPath);
        $json = is_string($conteudo) ? json_decode($conteudo, true) : null;
        $produtos = is_array($json) ? ($json['products'] ?? null) : null;

        if (is_array($produtos)) {
            foreach (array_keys($produtos) as $chave) {
                $codigo = strtoupper(trim((string) $chave));
                if ($codigo === '') {
                    continue;
                }

                $familia = preg_replace('/^IBR/u', '', $codigo) ?? $codigo;
                $familia = trim((string) $familia);
                if ($familia === '') {
                    $familia = $codigo;
                }

                $base[$codigo] = [
                    'lineFamily' => $familia,
                    'linhaFamilia' => 'IBR ' . $familia,
                    'referencia' => 'IBR ' . $familia,
                    'codigoProduto' => '',
                ];
            }
        }
    }

    $overrides = [
        'IBRAPM' => ['lineFamily' => 'MO', 'linhaFamilia' => 'IBR MO', 'referencia' => 'IBR MO', 'codigoProduto' => '3.APM'],
        'IBRQ' => ['lineFamily' => 'QU', 'linhaFamilia' => 'IBR Q', 'referencia' => 'IBR Q', 'codigoProduto' => '1.Q'],
        'IBRQDR' => ['lineFamily' => 'QUDR', 'linhaFamilia' => 'IBR QDR', 'referencia' => 'IBR QDR', 'codigoProduto' => '1.QDR'],
        'IBRQP' => ['lineFamily' => 'QP', 'linhaFamilia' => 'IBR QP', 'referencia' => 'IBR QP', 'codigoProduto' => '1.QP'],
    ];

    $cache = array_replace($base, $overrides);
    return $cache;
}

function api_chat_imagens_oficiais_descricao_por_linha(string $chave): string
{
    $map = [
        'IBRQ' => 'Linha de redutores para operação confiável em múltiplos processos.',
        'IBRQDR' => 'Projeto dedicado para requisitos de alto desempenho mecânico.',
        'IBRQP' => 'Linha otimizada para aplicações com exigência de robustez.',
    ];

    return (string) ($map[$chave] ?? 'Imagem oficial da linha IBR para identificação e consulta técnica.');
}

function api_chat_imagens_oficiais_aliases_por_arquivo(string $imageUrl, array $tags): array
{
    $path = (string) parse_url($imageUrl, PHP_URL_PATH);
    $nomeArquivo = pathinfo($path, PATHINFO_FILENAME);
    $nomeSemPrefixo = ltrim($nomeArquivo, '0');
    $aliases = array_filter([
        $nomeArquivo,
        $nomeSemPrefixo,
        str_replace('-', '', $nomeSemPrefixo),
        str_replace('_', '', $nomeSemPrefixo),
    ], static fn($v): bool => trim((string) $v) !== '');

    foreach ($tags as $tag) {
        $aliases[] = (string) $tag;
        $aliases[] = str_replace(' ', '', (string) $tag);
    }

    return array_values(array_unique($aliases));
}

function api_chat_imagens_oficiais_extrair_chave_linha(string $imageUrl): string
{
    $path = trim((string) parse_url($imageUrl, PHP_URL_PATH), '/');
    $partes = explode('/', $path);
    if (count($partes) < 2) {
        return '';
    }

    return strtoupper((string) $partes[1]);
}

function api_chat_imagens_oficiais_extract_urls_from_srcset(string $srcset): array
{
    $out = [];
    $parts = preg_split('/\s*,\s*/', $srcset) ?: [];
    foreach ($parts as $part) {
        $tokens = preg_split('/\s+/', trim($part));
        if (!empty($tokens[0])) {
            $out[] = (string) $tokens[0];
        }
    }
    return $out;
}

function api_chat_imagens_oficiais_fetch_html(string $url): array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 20,
            'ignore_errors' => true,
            'header' => "User-Agent: APIChat-ImagensOficiaisIBR/1.0\r\nAccept: text/html,application/xhtml+xml\r\n",
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    $headers = isset($http_response_header) && is_array($http_response_header) ? $http_response_header : [];
    $status = 0;
    if (isset($headers[0]) && preg_match('/\s(\d{3})\s/', $headers[0], $m) === 1) {
        $status = (int) $m[1];
    }

    return [
        'ok' => is_string($body) && $body !== '' && $status >= 200 && $status < 400,
        'status' => $status,
        'body' => is_string($body) ? $body : '',
    ];
}

function api_chat_imagens_oficiais_extrair_registros_pagina(string $pageUrl, string $html): array
{
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);

    $title = trim((string) $xpath->evaluate('string(//title)'));
    $h1 = trim((string) $xpath->evaluate('string((//h1)[1])'));
    $h2 = trim((string) $xpath->evaluate('string((//h2)[1])'));
    $headingContext = trim($h1 !== '' ? $h1 : $h2);

    $registros = [];
    $imgNodes = $xpath->query('//img');
    if ($imgNodes instanceof DOMNodeList) {
        foreach ($imgNodes as $img) {
            if (!$img instanceof DOMElement) {
                continue;
            }

            $fontes = [];
            foreach (['src', 'data-src'] as $attr) {
                $valor = trim((string) $img->getAttribute($attr));
                if ($valor !== '') {
                    $fontes[] = $valor;
                }
            }

            $srcset = trim((string) $img->getAttribute('srcset'));
            if ($srcset !== '') {
                $fontes = array_merge($fontes, api_chat_imagens_oficiais_extract_urls_from_srcset($srcset));
            }

            foreach ($fontes as $fonte) {
                $url = api_chat_imagens_oficiais_normalizar_url($fonte, $pageUrl);
                if ($url === '') {
                    continue;
                }

                $alt = trim((string) $img->getAttribute('alt'));
                $imgTitle = trim((string) $img->getAttribute('title'));
                $aria = trim((string) $img->getAttribute('aria-label'));
                $contextoTexto = trim(implode(' | ', array_filter([$title, $headingContext, $alt, $imgTitle, $aria])));

                $registros[] = [
                    'url' => $url,
                    'paginaUrl' => $pageUrl,
                    'trecho' => $headingContext,
                    'texto' => $contextoTexto,
                    'width' => (int) $img->getAttribute('width'),
                    'height' => (int) $img->getAttribute('height'),
                ];
            }
        }
    }

    $sourceNodes = $xpath->query('//source[@srcset]');
    if ($sourceNodes instanceof DOMNodeList) {
        foreach ($sourceNodes as $source) {
            if (!$source instanceof DOMElement) {
                continue;
            }
            foreach (api_chat_imagens_oficiais_extract_urls_from_srcset((string) $source->getAttribute('srcset')) as $fonte) {
                $url = api_chat_imagens_oficiais_normalizar_url($fonte, $pageUrl);
                if ($url !== '') {
                    $registros[] = [
                        'url' => $url,
                        'paginaUrl' => $pageUrl,
                        'trecho' => $headingContext,
                        'texto' => trim(implode(' | ', array_filter([$title, $headingContext]))),
                        'width' => 0,
                        'height' => 0,
                    ];
                }
            }
        }
    }

    if (preg_match_all('/background-image\s*:\s*url\(["\']?([^"\')]+)["\']?\)/i', $html, $matches) === 1) {
        foreach ($matches[1] as $fonte) {
            $url = api_chat_imagens_oficiais_normalizar_url((string) $fonte, $pageUrl);
            if ($url !== '') {
                $registros[] = [
                    'url' => $url,
                    'paginaUrl' => $pageUrl,
                    'trecho' => $headingContext,
                    'texto' => trim(implode(' | ', array_filter([$title, $headingContext, 'background image']))),
                    'width' => 0,
                    'height' => 0,
                ];
            }
        }
    }

    return $registros;
}

function api_chat_imagens_oficiais_contexto_por_tipo(string $tipo): string
{
    $map = [
        'DIMENSIONAL_DRAWING' => 'Desenho dimensional com cotas técnicas relevantes para especificação.',
        'ASSEMBLY_MOUNTING' => 'Imagem de montagem/instalação com foco em flange, fixação e posição de eixo.',
        'TABLE_CHART' => 'Tabela ou gráfico técnico para consulta de performance e seleção.',
        'WIRING_DIAGRAM' => 'Diagrama de ligação elétrica/controle para integração.',
        'PRODUCT_PHOTO' => 'Foto oficial do produto para identificação visual da linha.',
        'ICON_OTHER' => 'Imagem oficial complementar sem classificação técnica prioritária.',
    ];

    return $map[$tipo] ?? $map['ICON_OTHER'];
}

function api_chat_imagens_oficiais_tags(string $texto, string $linhaFamilia): array
{
    $tags = [];
    if ($linhaFamilia !== '') {
        $tags[] = $linhaFamilia;
    }

    $mapTags = [
        'flange' => 'flange',
        'b14' => 'B14',
        'b5' => 'B5',
        'eixo' => 'eixo',
        'inox' => 'inox',
        'ip65' => 'IP65',
        'planet' => 'planetario',
        'tabela' => 'tabela',
        'curva' => 'curva',
        'dimens' => 'dimensional',
        'cotas' => 'cotas',
        'montagem' => 'montagem',
        'diagrama' => 'diagrama',
    ];

    $textoNorm = mb_strtolower($texto, 'UTF-8');
    foreach ($mapTags as $token => $tag) {
        if (mb_strpos($textoNorm, $token) !== false) {
            $tags[] = $tag;
        }
    }

    $tagsNormalizadas = [];
    foreach ($tags as $tag) {
        $tagLimpa = trim((string) $tag);
        if ($tagLimpa === '') {
            continue;
        }

        $tagsNormalizadas[] = $tagLimpa;
        $tagsNormalizadas[] = mb_strtoupper($tagLimpa, 'UTF-8');
        $tagsNormalizadas[] = mb_strtolower($tagLimpa, 'UTF-8');
        $tagsNormalizadas[] = str_replace(' ', '', $tagLimpa);
        $tagsNormalizadas[] = str_replace(' ', '-', $tagLimpa);
    }

    return array_values(array_unique(array_filter($tagsNormalizadas, static fn($tag): bool => trim((string) $tag) !== '')));
}

function api_chat_imagens_oficiais_inferir_produto_codigo(string $texto): string
{
    if (preg_match('/\b(\d+\.[A-Z]{1,5}(?:\.[A-Z0-9]+)+)\b/u', strtoupper($texto), $m) === 1) {
        return (string) $m[1];
    }

    if (preg_match('/\b([A-Z]{2,5}\.\d{5,})\b/u', strtoupper($texto), $m) === 1) {
        return (string) $m[1];
    }

    return '';
}

function api_chat_imagens_oficiais_normalizar_codigo(string $codigo): array
{
    $codigoOriginal = trim($codigo);
    if ($codigoOriginal === '') {
        return [
            'original' => '',
            'normalizado' => '',
            'prefixo' => '',
            'variantes' => [],
            'linhaInferida' => '',
            'familiaInferida' => '',
        ];
    }

    $codigoUpper = strtoupper($codigoOriginal);
    $codigoAlnum = preg_replace('/[^A-Z0-9.]+/u', '', $codigoUpper);
    $partes = array_values(array_filter(explode('.', (string) $codigoAlnum), static fn($v): bool => trim((string) $v) !== ''));
    $prefixo = (string) ($partes[0] ?? '');
    if (ctype_digit($prefixo) && isset($partes[1])) {
        $prefixo = (string) $partes[1];
    }

    $prefixMap = [
        'QU' => ['lineFamily' => 'QU', 'familia' => 'IBR Q', 'codigoBase' => '1.Q'],
        'Q' => ['lineFamily' => 'QU', 'familia' => 'IBR Q', 'codigoBase' => '1.Q'],
        'QDR' => ['lineFamily' => 'QUDR', 'familia' => 'IBR QDR', 'codigoBase' => '1.QDR'],
        'QUDR' => ['lineFamily' => 'QUDR', 'familia' => 'IBR QDR', 'codigoBase' => '1.QDR'],
        'QP' => ['lineFamily' => 'QP', 'familia' => 'IBR QP', 'codigoBase' => '1.QP'],
    ];
    $inferido = $prefixMap[$prefixo] ?? [];

    $variantes = [$codigoUpper, (string) $codigoAlnum];
    if (!empty($inferido['codigoBase'])) {
        $variantes[] = (string) $inferido['codigoBase'];
    }
    if (count($partes) >= 2 && ctype_digit($partes[0])) {
        $variantes[] = $partes[0] . '.' . $partes[1];
        $variantes[] = $partes[1];
    }

    $variantes = array_values(array_unique(array_filter(array_map(
        static fn($v): string => strtoupper(trim((string) $v)),
        $variantes
    ))));

    return [
        'original' => $codigoOriginal,
        'normalizado' => (string) $codigoAlnum,
        'prefixo' => $prefixo,
        'variantes' => $variantes,
        'linhaInferida' => (string) ($inferido['lineFamily'] ?? ''),
        'familiaInferida' => (string) ($inferido['familia'] ?? ''),
    ];
}

function api_chat_imagens_oficiais_item_relaciona_codigo(array $item, array $codigoInfo): bool
{
    $variantes = (array) ($codigoInfo['variantes'] ?? []);
    $codigoItem = strtoupper(trim((string) ($item['produtoCodigo'] ?? $item['codigoProduto'] ?? '')));
    if ($codigoItem !== '' && in_array($codigoItem, $variantes, true)) {
        return true;
    }

    $codigosRelacionados = array_map(
        static fn($codigo): string => strtoupper(trim((string) $codigo)),
        (array) ($item['codigosRelacionados'] ?? [])
    );
    foreach ($variantes as $variante) {
        if (in_array(strtoupper(trim((string) $variante)), $codigosRelacionados, true)) {
            return true;
        }
    }

    $linhaInferida = strtoupper(trim((string) ($codigoInfo['linhaInferida'] ?? '')));
    $familiaInferida = strtoupper(trim((string) ($codigoInfo['familiaInferida'] ?? '')));
    if ($linhaInferida === '' && $familiaInferida === '') {
        return false;
    }

    $haystack = strtoupper(implode(' ', [
        (string) ($item['lineFamily'] ?? ''),
        (string) ($item['linhaFamilia'] ?? ''),
        (string) ($item['referencia'] ?? ''),
        (string) ($item['codigoProduto'] ?? $item['produtoCodigo'] ?? ''),
        implode(' ', (array) ($item['aliases'] ?? [])),
        implode(' ', (array) ($item['tags'] ?? [])),
    ]));

    $aliasesLinha = [];
    if ($linhaInferida !== '') {
        $aliasesLinha[] = $linhaInferida;
        if ($linhaInferida === 'QU') {
            $aliasesLinha = array_merge($aliasesLinha, ['IBR Q', '1.Q']);
        }
        if ($linhaInferida === 'QUDR') {
            $aliasesLinha = array_merge($aliasesLinha, ['IBR QDR', '1.QDR']);
        }
        if ($linhaInferida === 'QP') {
            $aliasesLinha = array_merge($aliasesLinha, ['IBR QP', '1.QP']);
        }
    }

    foreach (array_values(array_unique($aliasesLinha)) as $alias) {
        $alias = strtoupper(trim((string) $alias));
        if ($alias === '') {
            continue;
        }

        if (preg_match('/(?<![A-Z0-9])' . preg_quote($alias, '/') . '(?![A-Z0-9])/u', $haystack) === 1) {
            return true;
        }
    }

    if ($familiaInferida !== '' && preg_match('/(?<![A-Z0-9])' . preg_quote($familiaInferida, '/') . '(?![A-Z0-9])/u', $haystack) === 1) {
        return true;
    }

    return false;
}

function api_chat_imagens_oficiais_deduplicar(array $registros): array
{
    $map = [];
    foreach ($registros as $item) {
        $limpa = api_chat_imagens_oficiais_limpar_url((string) ($item['url'] ?? ''));
        if ($limpa === '') {
            continue;
        }

        if (!api_chat_imagens_oficiais_host_permitido($limpa)) {
            continue;
        }

        if (!api_chat_imagens_oficiais_extensao_imagem($limpa)) {
            continue;
        }

        $width = (int) ($item['width'] ?? 0);
        if ($width > 0 && $width < 200) {
            continue;
        }

        $existente = $map[$limpa] ?? null;
        $textoNovo = trim((string) ($item['texto'] ?? ''));
        $textoExistente = trim((string) (($existente['texto'] ?? '')));
        $trechoNovo = trim((string) ($item['trecho'] ?? ''));
        $trechoExistente = trim((string) (($existente['trecho'] ?? '')));
        $paginaNova = trim((string) ($item['paginaUrl'] ?? ''));
        $paginaExistente = trim((string) (($existente['paginaUrl'] ?? '')));

        if (is_array($existente)) {
            $textos = array_filter([$textoExistente, $textoNovo], static fn($valor): bool => trim((string) $valor) !== '');
            $trechos = array_filter([$trechoExistente, $trechoNovo], static fn($valor): bool => trim((string) $valor) !== '');
            $map[$limpa]['texto'] = trim(implode(' | ', array_values(array_unique($textos))));
            $map[$limpa]['trecho'] = trim(implode(' | ', array_values(array_unique($trechos))));
            if ($paginaExistente === '' && $paginaNova !== '') {
                $map[$limpa]['paginaUrl'] = $paginaNova;
            }
            $map[$limpa]['width'] = max((int) ($existente['width'] ?? 0), $width);
            continue;
        }
        $map[$limpa] = [
            'url' => $limpa,
            'paginaUrl' => (string) ($item['paginaUrl'] ?? ''),
            'trecho' => (string) ($item['trecho'] ?? ''),
            'texto' => (string) ($item['texto'] ?? ''),
            'width' => $width,
        ];
    }

    $saida = [];
    foreach ($map as $limpa => $item) {
        $texto = (string) ($item['texto'] ?? '');
        $tipo = api_chat_imagens_oficiais_tipo_por_texto($texto . ' ' . $limpa);
        $chaveLinha = api_chat_imagens_oficiais_extrair_chave_linha($limpa);
        $linhaMetadata = api_chat_imagens_oficiais_metadata_linha_map()[$chaveLinha] ?? [];
        $linhaFamilia = (string) ($linhaMetadata['linhaFamilia'] ?? api_chat_imagens_oficiais_linha_familia($texto . ' ' . ($item['paginaUrl'] ?? '')));
        $tags = api_chat_imagens_oficiais_tags($texto . ' ' . $limpa, $linhaFamilia);
        $aliases = api_chat_imagens_oficiais_aliases_por_arquivo($limpa, $tags);
        $codigoInferido = api_chat_imagens_oficiais_inferir_produto_codigo($texto . ' ' . $limpa);
        $codigoProduto = (string) ($linhaMetadata['codigoProduto'] ?? $codigoInferido);
        $referencia = (string) ($linhaMetadata['referencia'] ?? $linhaFamilia);
        $lineFamily = (string) ($linhaMetadata['lineFamily'] ?? $linhaFamilia);
        $description = api_chat_imagens_oficiais_descricao_por_linha($chaveLinha);
        $textoContexto = api_chat_imagens_oficiais_contexto_por_tipo($tipo);
        $searchText = trim(implode(' ', array_filter([
            $description,
            $textoContexto,
            $referencia,
            $codigoProduto,
            $lineFamily,
            $linhaFamilia,
            implode(' ', $tags),
            implode(' ', $aliases),
            $texto,
            (string) ($item['paginaUrl'] ?? ''),
        ])));

        $saida[] = [
            'imageId' => sha1($limpa),
            'imageUrl' => $limpa,
            'paginaUrl' => (string) ($item['paginaUrl'] ?? ''),
            'sourcePageUrl' => (string) ($item['paginaUrl'] ?? ''),
            'tipo' => $tipo,
            'type' => $tipo,
            'tags' => $tags,
            'aliases' => $aliases,
            'lineFamily' => $lineFamily,
            'linhaFamilia' => $linhaFamilia,
            'produtoCodigo' => $codigoProduto,
            'codigoProduto' => $codigoProduto,
            'codigosRelacionados' => $codigoProduto !== '' ? [$codigoProduto] : [],
            'referencia' => $referencia,
            'description' => $description,
            'textoContexto' => $textoContexto,
            'searchText' => $searchText,
            'trecho' => (string) ($item['trecho'] ?? ''),
            'updatedAt' => gmdate('c'),
        ];
    }

    return $saida;
}

function api_chat_imagens_oficiais_campos_preservados_index(array $itemNovo, ?array $itemExistente): array
{
    $camposNormalizados = ['tags', 'aliases', 'searchText'];
    foreach ($camposNormalizados as $campo) {
        if (!array_key_exists($campo, $itemNovo)) {
            $itemNovo[$campo] = $campo === 'searchText' ? '' : [];
        }
    }

    return $itemNovo;
}

function api_chat_imagens_oficiais_item_conflita_com_linha_url(array $item): bool
{
    $urlLinha = api_chat_imagens_oficiais_extrair_chave_linha((string) ($item['imageUrl'] ?? ''));
    if ($urlLinha === '') {
        return false;
    }

    $textoItem = strtoupper(implode(' ', [
        (string) ($item['lineFamily'] ?? ''),
        (string) ($item['linhaFamilia'] ?? ''),
        (string) ($item['referencia'] ?? ''),
        (string) ($item['codigoProduto'] ?? $item['produtoCodigo'] ?? ''),
        (string) ($item['searchText'] ?? ''),
        implode(' ', (array) ($item['tags'] ?? [])),
        implode(' ', (array) ($item['aliases'] ?? [])),
    ]));

    foreach (api_chat_imagens_oficiais_metadata_linha_map() as $chaveLinha => $metadata) {
        $tokens = [
            $chaveLinha,
            (string) ($metadata['lineFamily'] ?? ''),
            (string) ($metadata['linhaFamilia'] ?? ''),
            (string) ($metadata['referencia'] ?? ''),
            (string) ($metadata['codigoProduto'] ?? ''),
            str_replace(' ', '', (string) ($metadata['linhaFamilia'] ?? '')),
            str_replace(' ', '', (string) ($metadata['referencia'] ?? '')),
        ];

        foreach (array_values(array_unique(array_filter($tokens, static fn($token): bool => trim((string) $token) !== ''))) as $token) {
            $token = strtoupper(trim((string) $token));
            if ($token === '') {
                continue;
            }

            if (preg_match('/(?<![A-Z0-9])' . preg_quote($token, '/') . '(?![A-Z0-9])/u', $textoItem) !== 1) {
                continue;
            }

            if (strtoupper($chaveLinha) !== $urlLinha) {
                return true;
            }
        }
    }

    return false;
}

function api_chat_imagens_oficiais_deduplicar_saida(array $images): array
{
    $map = [];
    foreach ($images as $item) {
        if (!is_array($item)) {
            continue;
        }

        $url = api_chat_imagens_oficiais_limpar_url((string) ($item['imageUrl'] ?? ''));
        if ($url === '') {
            continue;
        }

        $item['imageUrl'] = $url;
        if (!isset($map[$url])) {
            $map[$url] = $item;
            continue;
        }

        $atual = $map[$url];
        if (((int) ($item['_score'] ?? 0)) > ((int) ($atual['_score'] ?? 0))) {
            $map[$url] = $item;
        }
    }

    return array_values($map);
}

function api_chat_imagens_oficiais_salvar_index(array $dados): void
{
    $indexExistente = api_chat_imagens_oficiais_carregar_index_payload();
    $imagesExistentes = is_array($indexExistente['images'] ?? null) ? $indexExistente['images'] : [];
    $porChaveExistente = [];

    foreach ($imagesExistentes as $itemExistente) {
        if (!is_array($itemExistente)) {
            continue;
        }

        $imageId = trim((string) ($itemExistente['imageId'] ?? ''));
        $imageUrl = api_chat_imagens_oficiais_limpar_url((string) ($itemExistente['imageUrl'] ?? ''));
        $chave = $imageId !== '' ? 'id:' . $imageId : ($imageUrl !== '' ? 'url:' . $imageUrl : '');
        if ($chave === '') {
            continue;
        }

        $porChaveExistente[$chave] = $itemExistente;
    }

    $dadosMesclados = [];
    foreach (array_values($dados) as $itemNovo) {
        if (!is_array($itemNovo)) {
            continue;
        }

        $imageId = trim((string) ($itemNovo['imageId'] ?? ''));
        $imageUrl = api_chat_imagens_oficiais_limpar_url((string) ($itemNovo['imageUrl'] ?? ''));
        $chave = $imageId !== '' ? 'id:' . $imageId : ($imageUrl !== '' ? 'url:' . $imageUrl : '');

        $itemExistente = null;
        if ($chave !== '' && isset($porChaveExistente[$chave]) && is_array($porChaveExistente[$chave])) {
            $itemExistente = $porChaveExistente[$chave];
            $itemNovo = array_merge($itemExistente, $itemNovo);
        }

        $itemNovo = api_chat_imagens_oficiais_campos_preservados_index($itemNovo, $itemExistente);
        $dadosMesclados[] = $itemNovo;
    }

    @mkdir(dirname(API_CHAT_IMAGENS_INDEX_FILE), 0775, true);
    file_put_contents(API_CHAT_IMAGENS_INDEX_FILE, json_encode([
        'version' => API_CHAT_IMAGENS_VERSION,
        'updatedAt' => gmdate('c'),
        'images' => $dadosMesclados,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function api_chat_imagens_oficiais_snapshot_diretorio_produtos(): array
{
    $diretorio = realpath(dirname(__DIR__) . '/ImagensProdutos');
    if (!is_string($diretorio) || $diretorio === '' || !is_dir($diretorio)) {
        return [
            'exists' => false,
            'totalFiles' => 0,
            'maxFileMtime' => 0,
            'filesHash' => '',
        ];
    }

    $total = 0;
    $maxMtime = 0;
    $assinaturas = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($diretorio, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $arquivo) {
        if (!$arquivo instanceof SplFileInfo || !$arquivo->isFile()) {
            continue;
        }

        $total++;
        $mtime = (int) $arquivo->getMTime();
        if ($mtime > $maxMtime) {
            $maxMtime = $mtime;
        }

        $caminho = str_replace('\\', '/', (string) $arquivo->getPathname());
        $relativo = ltrim(str_replace(str_replace('\\', '/', $diretorio), '', $caminho), '/');
        $assinaturas[] = sprintf('%s|%d|%d', $relativo, (int) $arquivo->getSize(), $mtime);
    }

    sort($assinaturas, SORT_STRING);

    return [
        'exists' => true,
        'totalFiles' => $total,
        'maxFileMtime' => $maxMtime,
        'filesHash' => sha1(implode("\n", $assinaturas)),
    ];
}

function api_chat_imagens_oficiais_carregar_index_dados(): array
{
    return api_chat_imagens_oficiais_carregar_index_payload();
}

function api_chat_imagens_oficiais_carregar_index_payload(): array
{
    if (!is_file(API_CHAT_IMAGENS_INDEX_FILE)) {
        return [
            'version' => '',
            'updatedAt' => '',
            'images' => [],
        ];
    }

    $raw = file_get_contents(API_CHAT_IMAGENS_INDEX_FILE);
    if (!is_string($raw) || trim($raw) === '') {
        return [
            'version' => '',
            'updatedAt' => '',
            'images' => [],
        ];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [
            'version' => '',
            'updatedAt' => '',
            'images' => [],
        ];
    }

    $images = $decoded['images'] ?? [];
    return [
        'version' => (string) ($decoded['version'] ?? ''),
        'updatedAt' => (string) ($decoded['updatedAt'] ?? ''),
        'images' => is_array($images) ? $images : [],
    ];
}

function api_chat_imagens_oficiais_carregar_index(): array
{
    $dados = api_chat_imagens_oficiais_carregar_index_dados();
    $images = $dados['images'] ?? [];
    return is_array($images) ? $images : [];
}

function api_chat_imagens_oficiais_salvar_status(array $status): void
{
    @mkdir(dirname(API_CHAT_IMAGENS_STATUS_FILE), 0775, true);
    file_put_contents(API_CHAT_IMAGENS_STATUS_FILE, json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function api_chat_imagens_oficiais_carregar_status(): array
{
    if (!is_file(API_CHAT_IMAGENS_STATUS_FILE)) {
        return [];
    }
    $raw = file_get_contents(API_CHAT_IMAGENS_STATUS_FILE);
    $decoded = is_string($raw) ? json_decode($raw, true) : [];
    return is_array($decoded) ? $decoded : [];
}

function api_chat_imagens_oficiais_crawl(): array
{
    $inicio = microtime(true);
    $indexAnteriorPayload = api_chat_imagens_oficiais_carregar_index_payload();
    $indexAnterior = (array) ($indexAnteriorPayload['images'] ?? []);
    $candidatasDiretorio = api_chat_imagens_oficiais_varrer_diretorio_produtos();
    $candidatas = $candidatasDiretorio;
    $seedStatus = [];

    foreach (api_chat_imagens_oficiais_seed_urls() as $seed) {
        if (!api_chat_imagens_oficiais_host_permitido($seed)) {
            $seedStatus[] = ['url' => $seed, 'ok' => false, 'erro' => 'Host não permitido'];
            continue;
        }

        usleep(200000);
        $fetch = api_chat_imagens_oficiais_fetch_html($seed);
        if (!$fetch['ok']) {
            $seedStatus[] = ['url' => $seed, 'ok' => false, 'status' => $fetch['status']];
            continue;
        }

        $seedStatus[] = ['url' => $seed, 'ok' => true, 'status' => $fetch['status']];
        $candidatas = array_merge($candidatas, api_chat_imagens_oficiais_extrair_registros_pagina($seed, (string) $fetch['body']));
    }

    $index = api_chat_imagens_oficiais_deduplicar($candidatas);
    $crawlOk = true;
    $erro = '';
    if ($index === [] && $indexAnterior !== []) {
        $crawlOk = false;
        $erro = 'Crawl retornou índice vazio; mantendo último índice íntegro.';
    } else {
        api_chat_imagens_oficiais_salvar_index($index);
    }
    $indexDados = api_chat_imagens_oficiais_carregar_index_dados();
    $directorySnapshot = api_chat_imagens_oficiais_snapshot_diretorio_produtos();

    $status = [
        'ok' => $crawlOk,
        'erro' => $erro,
        'version' => API_CHAT_IMAGENS_VERSION,
        'lastCrawlAt' => gmdate('c'),
        'durationMs' => (int) round((microtime(true) - $inicio) * 1000),
        'totalSeeds' => count(api_chat_imagens_oficiais_seed_urls()),
        'totalFetched' => count(array_filter($seedStatus, static fn($item): bool => (bool) ($item['ok'] ?? false))),
        'totalDirectoryCandidates' => count($candidatasDiretorio),
        'totalImages' => $crawlOk ? count($index) : count($indexAnterior),
        'seedStatus' => $seedStatus,
        'indexMeta' => [
            'version' => (string) ($indexDados['version'] ?? API_CHAT_IMAGENS_VERSION),
            'updatedAt' => (string) ($indexDados['updatedAt'] ?? ''),
        ],
        'directorySnapshot' => $directorySnapshot,
    ];

    api_chat_imagens_oficiais_salvar_status($status);
    return $status;
}

function api_chat_imagens_oficiais_tipo_desejado(?string $q, ?string $tipo): string
{
    $tipoNormalizado = strtoupper(trim((string) $tipo));
    if ($tipoNormalizado !== '' && in_array($tipoNormalizado, API_CHAT_IMAGENS_TIPOS_VALIDOS, true)) {
        return $tipoNormalizado;
    }

    $qNorm = mb_strtolower((string) $q, 'UTF-8');
    if ($qNorm === '') {
        return '';
    }

    if (preg_match('/dimensional|desenho|cotas|outline|2d/u', $qNorm) === 1) {
        return 'DIMENSIONAL_DRAWING';
    }
    if (preg_match('/montagem|flange|b14|b5|pé|pe\b|torque arm/u', $qNorm) === 1) {
        return 'ASSEMBLY_MOUNTING';
    }

    return '';
}

function api_chat_imagens_oficiais_score(array $item, string $q, string $linha, string $tipoAlvo): int
{
    $score = 0;
    $texto = mb_strtolower(implode(' ', [
        (string) ($item['lineFamily'] ?? ''),
        (string) ($item['linhaFamilia'] ?? ''),
        (string) ($item['referencia'] ?? ''),
        implode(' ', (array) ($item['tags'] ?? [])),
        implode(' ', (array) ($item['aliases'] ?? [])),
        (string) ($item['description'] ?? ''),
        (string) ($item['searchText'] ?? ''),
        (string) ($item['textoContexto'] ?? ''),
        (string) ($item['paginaUrl'] ?? ''),
        (string) ($item['sourcePageUrl'] ?? ''),
    ]), 'UTF-8');

    $tipo = (string) ($item['tipo'] ?? 'ICON_OTHER');

    if ($tipoAlvo !== '' && $tipo === $tipoAlvo) {
        $score += 35;
    }

    if ($q !== '') {
        foreach (preg_split('/\s+/', mb_strtolower($q, 'UTF-8')) ?: [] as $token) {
            if ($token !== '' && mb_strpos($texto, $token) !== false) {
                $score += 6;
            }
        }
    }

    if ($linha !== '' && mb_strpos($texto, mb_strtolower($linha, 'UTF-8')) !== false) {
        $score += 30;
    }

    $preferenciaBase = [
        'PRODUCT_PHOTO' => 18,
        'DIMENSIONAL_DRAWING' => 14,
        'ASSEMBLY_MOUNTING' => 10,
        'TABLE_CHART' => 7,
        'WIRING_DIAGRAM' => 6,
        'ICON_OTHER' => 1,
    ];
    $score += $preferenciaBase[$tipo] ?? 0;

    return $score;
}

function api_chat_imagens_oficiais_diversificar(array $ordenadas, int $limit): array
{
    $saida = [];
    $tiposUsados = [];

    foreach ($ordenadas as $item) {
        $tipo = (string) ($item['tipo'] ?? 'ICON_OTHER');
        if (!isset($tiposUsados[$tipo])) {
            $saida[] = $item;
            $tiposUsados[$tipo] = true;
            if (count($saida) >= $limit) {
                return $saida;
            }
        }
    }

    foreach ($ordenadas as $item) {
        $saida[] = $item;
        if (count($saida) >= $limit) {
            break;
        }
    }

    return array_slice($saida, 0, $limit);
}

function api_chat_imagens_oficiais_buscar(array $filtro): array
{
    $q = trim((string) ($filtro['q'] ?? ''));
    $linha = trim((string) ($filtro['linha'] ?? ''));
    $tipoParam = trim((string) ($filtro['tipo'] ?? ''));
    $tipoAlvo = api_chat_imagens_oficiais_tipo_desejado($q, $tipoParam);
    $limitInput = (int) ($filtro['limit'] ?? 0);
    $codigoParam = trim((string) ($filtro['codigoProduto'] ?? $filtro['codigo'] ?? ''));
    if ($codigoParam === '') {
        $codigoParam = api_chat_imagens_oficiais_inferir_produto_codigo($q);
    }
    $codigoInfo = api_chat_imagens_oficiais_normalizar_codigo($codigoParam);

    $indexDados = api_chat_imagens_oficiais_carregar_index_dados();
    $index = (array) ($indexDados['images'] ?? []);
    $status = api_chat_imagens_oficiais_carregar_status();
    $snapshotAtual = api_chat_imagens_oficiais_snapshot_diretorio_produtos();
    $snapshotAnterior = (array) ($status['directorySnapshot'] ?? []);
    $precisaRecrawl = $index === []
        || (string) ($indexDados['version'] ?? '') !== API_CHAT_IMAGENS_VERSION
        || $snapshotAnterior === []
        || json_encode($snapshotAnterior) !== json_encode($snapshotAtual);

    $stale = false;
    $staleReason = '';
    if ($precisaRecrawl) {
        $indexAnterior = $index;
        $crawl = api_chat_imagens_oficiais_crawl();
        $indexDados = api_chat_imagens_oficiais_carregar_index_dados();
        $indexAtualizado = (array) ($indexDados['images'] ?? []);

        if (!((bool) ($crawl['ok'] ?? false)) || $indexAtualizado === []) {
            $index = $indexAnterior;
            $stale = $indexAnterior !== [];
            $staleReason = 'Falha ao atualizar índice; retornando último snapshot disponível.';
        } else {
            $index = $indexAtualizado;
        }
    }

    $avaliadas = [];
    foreach ($index as $item) {
        $tipoItem = (string) ($item['tipo'] ?? 'ICON_OTHER');
        if ($tipoParam !== '' && strtoupper($tipoParam) !== $tipoItem) {
            continue;
        }

        if ($codigoParam !== '' && !api_chat_imagens_oficiais_item_relaciona_codigo($item, $codigoInfo)) {
            continue;
        }

        if ($linha !== ''
            && mb_stripos((string) ($item['linhaFamilia'] ?? ''), $linha, 0, 'UTF-8') === false
            && mb_stripos((string) ($item['lineFamily'] ?? ''), $linha, 0, 'UTF-8') === false
            && mb_stripos((string) ($item['paginaUrl'] ?? ''), $linha, 0, 'UTF-8') === false
            && mb_stripos((string) ($item['sourcePageUrl'] ?? ''), $linha, 0, 'UTF-8') === false
            && mb_stripos((string) ($item['searchText'] ?? ''), $linha, 0, 'UTF-8') === false
        ) {
            continue;
        }

        if (api_chat_imagens_oficiais_item_conflita_com_linha_url($item)) {
            continue;
        }

        $item['_score'] = api_chat_imagens_oficiais_score($item, $q, $linha, $tipoAlvo);
        $avaliadas[] = $item;
    }

    $avaliadas = api_chat_imagens_oficiais_deduplicar_saida($avaliadas);

    usort($avaliadas, static function (array $a, array $b): int {
        return ((int) ($b['_score'] ?? 0)) <=> ((int) ($a['_score'] ?? 0));
    });

    $totalDisponiveis = count($avaliadas);
    $limit = $limitInput <= 0 ? $totalDisponiveis : min(5000, $limitInput);
    $limit = max(1, $limit);
    $selecionadas = $limit >= $totalDisponiveis
        ? $avaliadas
        : api_chat_imagens_oficiais_diversificar($avaliadas, $limit);
    $images = [];
    foreach ($selecionadas as $item) {
        $images[] = [
            'imageUrl' => (string) ($item['imageUrl'] ?? ''),
            'type' => (string) ($item['tipo'] ?? 'ICON_OTHER'),
            'tags' => array_values((array) ($item['tags'] ?? [])),
            'aliases' => array_values((array) ($item['aliases'] ?? [])),
            'lineFamily' => (string) ($item['lineFamily'] ?? $item['linhaFamilia'] ?? ''),
            'referencia' => (string) ($item['referencia'] ?? ''),
            'codigoProduto' => (string) ($item['codigoProduto'] ?? $item['produtoCodigo'] ?? ''),
            'codigosRelacionados' => array_values((array) ($item['codigosRelacionados'] ?? [])),
            'description' => (string) ($item['description'] ?? ''),
            'searchText' => (string) ($item['searchText'] ?? ''),
            'context' => (string) ($item['textoContexto'] ?? ''),
            'sourcePageUrl' => (string) ($item['sourcePageUrl'] ?? $item['paginaUrl'] ?? ''),
        ];
    }

    return [
        'ok' => true,
        'query' => [
            'q' => $q,
            'linha' => $linha,
            'tipo' => $tipoParam,
            'codigoProduto' => $codigoParam,
            'codigoNormalizado' => (string) ($codigoInfo['normalizado'] ?? ''),
            'codigoVariantes' => array_values((array) ($codigoInfo['variantes'] ?? [])),
            'linhaInferida' => (string) ($codigoInfo['linhaInferida'] ?? ''),
            'limit' => $limit,
        ],
        'images' => $images,
        'notes' => [
            'Somente imagens oficiais; ordenadas por relevância.',
        ],
        'stale' => $stale,
        'staleReason' => $staleReason,
    ];
}

function api_chat_imagens_oficiais_processar(string $baseDir): void
{
    api_chat_middleware_init($baseDir, API_CHAT_IMAGENS_ENDPOINT);
    auth_api_chat_validar_ou_responder($baseDir, 'responder_imagens_oficiais');

    $metodo = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($metodo !== 'GET' && $metodo !== 'POST') {
        responder_imagens_oficiais(405, ['ok' => false, 'erro' => 'Método não suportado.']);
    }

    $rota = strtolower(trim((string) ($_GET['rota'] ?? $_GET['acao'] ?? 'search')));
    if ($metodo === 'POST') {
        $bodyRaw = file_get_contents('php://input');
        $body = is_string($bodyRaw) ? json_decode($bodyRaw, true) : [];
        if (!is_array($body)) {
            $body = [];
        }
        if ($rota === '' || $rota === 'search') {
            $rotaBody = trim((string) ($body['rota'] ?? $body['acao'] ?? ''));
            if ($rotaBody !== '') {
                $rota = strtolower($rotaBody);
            }
        }
        if ($rota === 'resolve') {
            $filtro = [
                'q' => (string) ($body['q'] ?? $body['texto'] ?? $body['query'] ?? ''),
                'linha' => (string) ($body['linha'] ?? ''),
                'tipo' => (string) ($body['tipo'] ?? ''),
                'codigoProduto' => (string) ($body['codigoProduto'] ?? $body['codigo'] ?? ''),
                'limit' => (int) ($body['limit'] ?? 4),
            ];
            responder_imagens_oficiais(200, api_chat_imagens_oficiais_buscar($filtro));
        }

        if ($rota === 'crawl') {
            $status = api_chat_imagens_oficiais_crawl();
            responder_imagens_oficiais(200, $status);
        }

        responder_imagens_oficiais(400, ['ok' => false, 'erro' => 'Rota POST inválida. Use rota=resolve ou rota=crawl.']);
    }

    if ($rota === 'status') {
        $status = api_chat_imagens_oficiais_carregar_status();
        $indexPayload = api_chat_imagens_oficiais_carregar_index_payload();
        $images = $indexPayload['images'] ?? [];
        $totalIndexedImages = is_array($images) ? count($images) : 0;
        $totalFilesystemImages = count(api_chat_imagens_oficiais_varrer_diretorio_produtos());

        $statusVersion = trim((string) ($status['version'] ?? ''));
        $indexVersion = trim((string) ($indexPayload['version'] ?? ''));
        $versionDivergente = ($statusVersion !== '' && $statusVersion !== API_CHAT_IMAGENS_VERSION)
            || ($indexVersion !== '' && $indexVersion !== API_CHAT_IMAGENS_VERSION)
            || ($statusVersion !== '' && $indexVersion !== '' && $statusVersion !== $indexVersion);

        $indexOutdated = $totalIndexedImages !== $totalFilesystemImages || $versionDivergente;

        responder_imagens_oficiais(200, [
            'ok' => true,
            'status' => $status,
            'totalIndexedImages' => $totalIndexedImages,
            'totalFilesystemImages' => $totalFilesystemImages,
            'indexOutdated' => $indexOutdated,
        ]);
    }

    if ($rota === 'by-product') {
        $codigo = trim((string) ($_GET['codigoProduto'] ?? $_GET['codigo'] ?? ''));
        $limitInput = (int) ($_GET['limit'] ?? 0);
        $codigoInfo = api_chat_imagens_oficiais_normalizar_codigo($codigo);
        $index = api_chat_imagens_oficiais_carregar_index();
        $filtradas = [];
        foreach ($index as $item) {
            if ($codigo === '') {
                $filtradas[] = $item;
                continue;
            }

            if (api_chat_imagens_oficiais_item_relaciona_codigo($item, $codigoInfo)) {
                $filtradas[] = $item;
            }
        }

        usort($filtradas, static function (array $a, array $b): int {
            return ((int) api_chat_imagens_oficiais_score($b, '', '', '')) <=> ((int) api_chat_imagens_oficiais_score($a, '', '', ''));
        });

        $totalFiltradas = count($filtradas);
        $limit = $limitInput <= 0 ? $totalFiltradas : min(5000, $limitInput);
        $limit = max(1, $limit);
        $filtradas = array_slice($filtradas, 0, $limit);
        $images = [];
        foreach ($filtradas as $item) {
            $images[] = [
                'imageUrl' => (string) ($item['imageUrl'] ?? ''),
                'type' => (string) ($item['tipo'] ?? 'ICON_OTHER'),
                'tags' => array_values((array) ($item['tags'] ?? [])),
                'aliases' => array_values((array) ($item['aliases'] ?? [])),
                'lineFamily' => (string) ($item['lineFamily'] ?? $item['linhaFamilia'] ?? ''),
                'referencia' => (string) ($item['referencia'] ?? ''),
                'codigoProduto' => (string) ($item['codigoProduto'] ?? $item['produtoCodigo'] ?? ''),
                'codigosRelacionados' => array_values((array) ($item['codigosRelacionados'] ?? [])),
                'description' => (string) ($item['description'] ?? ''),
                'searchText' => (string) ($item['searchText'] ?? ''),
                'context' => (string) ($item['textoContexto'] ?? ''),
                'sourcePageUrl' => (string) ($item['sourcePageUrl'] ?? $item['paginaUrl'] ?? ''),
            ];
        }

        $payload = [
            'ok' => true,
            'query' => [
                'codigo' => $codigo,
                'codigoNormalizado' => (string) ($codigoInfo['normalizado'] ?? ''),
                'codigoVariantes' => array_values((array) ($codigoInfo['variantes'] ?? [])),
                'linhaInferida' => (string) ($codigoInfo['linhaInferida'] ?? ''),
                'limit' => $limit,
            ],
            'images' => $images,
            'notes' => ['Somente imagens oficiais; associação por código inferido quando disponível.'],
        ];
        if ($codigo !== '') {
            $payload['query']['codigo'] = $codigo;
        }
        responder_imagens_oficiais(200, $payload);
    }

    $filtro = [
        'q' => (string) ($_GET['q'] ?? $_GET['texto'] ?? $_GET['query'] ?? ''),
        'linha' => (string) ($_GET['linha'] ?? ''),
        'tipo' => (string) ($_GET['tipo'] ?? ''),
        'codigoProduto' => (string) ($_GET['codigoProduto'] ?? $_GET['codigo'] ?? ''),
        'limit' => (int) ($_GET['limit'] ?? 4),
    ];
    responder_imagens_oficiais(200, api_chat_imagens_oficiais_buscar($filtro));
}
