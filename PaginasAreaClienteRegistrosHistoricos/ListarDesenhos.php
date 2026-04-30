<?php
header('Content-Type: application/json; charset=UTF-8');
$baseDir = $_SERVER['DOCUMENT_ROOT'] ?: dirname(__DIR__);
require $baseDir . '/Seguranca/MetodosSegurancao.php';
require_once $baseDir . '/TokensGeradores/TokenPadraoJson.php';
require $baseDir . '/AcessosConsultas/CredenciaisBancoDados.php';
require_once $baseDir . '/SEO/ListaConfiguradoresLinkAmigavel.php';

$token = $_COOKIE['auth_token'] ?? '';
if (!$token) {
    http_response_code(401);
    echo json_encode([]);
    exit;
}

    $segredo = getenv('JWT_SECRET');
    if ($segredo === false) {
        $segredo = trim(file_get_contents($baseDir . '/Configuracoes/Segredo.jwt'));
    }

$dados = JWTHelper::decode($token, $segredo);
if (!$dados) {
    http_response_code(403);
    echo json_encode([]);
    exit;
}

$documentoRequest = preg_replace('/[^0-9A-Za-z]/', '', (string) (filter_input(INPUT_GET, 'documento', FILTER_UNSAFE_RAW) ?? ''));
$documento = preg_replace(
    '/[^0-9A-Za-z]/',
    '',
    (string)($dados['empresaDocumento'] ?? $_COOKIE['ibr_empresa_doc'] ?? '')
);
if ($documento === '' && $documentoRequest !== '') {
    $documento = $documentoRequest;
}
if ($documentoRequest !== '' && $documento !== '' && $documentoRequest !== $documento) {
    http_response_code(403);
    echo json_encode([]);
    exit;
}
if ($documento === '') {
    http_response_code(400);
    echo json_encode([]);
    exit;
}

$pagina = max(0, (int)filter_input(INPUT_GET, 'pagina', FILTER_VALIDATE_INT));
$limite = (int)filter_input(INPUT_GET, 'limite', FILTER_VALIDATE_INT);
if ($limite <= 0) $limite = 10;
if ($limite > 100) $limite = 100;

function parseDataTimestamp(string $str): int {
    if (strpos($str, '/') !== false) {
        [$dataParte, $horaParte] = array_pad(explode(' ', $str), 2, '');
        [$dia, $mes, $ano] = array_map('intval', explode('/', $dataParte));
        [$hora, $min, $seg] = array_pad(explode(':', $horaParte), 3, 0);
        return mktime((int)$hora, (int)$min, (int)$seg, (int)$mes, (int)$dia, (int)$ano);
    }
    $ts = strtotime($str);
    return $ts ?: 0;
}

function obterInfo(PDO $pdo, string $entrada): array {
    static $cache = [];
    if (array_key_exists($entrada, $cache)) return $cache[$entrada];

    $entrada = strtoupper(trim($entrada));
    $isCodigo = preg_match('/^[A-Z]{2,4}\.[0-9]{8}$/', $entrada);

    $sql = $isCodigo
        ? "SELECT TOP 1 CD_PRODUTO, DS_REFERENCIA, DS_PRODUTO FROM MMPR_PRODUTO WHERE CD_PRODUTO = ? AND ID_STATUS = 0 AND CD_PRODCONFIG IS NOT NULL"
        : "SELECT TOP 1 CD_PRODUTO, DS_REFERENCIA, DS_PRODUTO FROM MMPR_PRODUTO WHERE DS_REFERENCIA = ? AND ID_STATUS = 0 AND CD_PRODCONFIG IS NOT NULL";

    try {
        $stm = $pdo->prepare($sql);
        $stm->execute([$entrada]);
        $row = $stm->fetch(PDO::FETCH_ASSOC);
        $cache[$entrada] = [
            'codigo' => $row['CD_PRODUTO'] ?? null,
            'referencia' => $row['DS_REFERENCIA'] ?? $entrada,
            'descricao' => $row['DS_PRODUTO'] ?? null
        ];
    } catch (PDOException $e) {
        $cache[$entrada] = [
            'codigo' => null,
            'referencia' => $entrada,
            'descricao' => null
        ];
    }
    return $cache[$entrada];
}

function gerarLink(PDO $pdo, string $entrada): ?string {
    $entrada = strtoupper(trim($entrada));
    $isCodigo = preg_match('/^[A-Z]{2,4}\.[0-9]{8}$/', $entrada);
    $isReferencia = preg_match('/^[0-9A-Z]{1,5}(\.[0-9A-Z]{1,5}){2,}$/', $entrada);
    if (!$isCodigo && !$isReferencia) {
        return null;
    }

    $sql = "SELECT
                PRODUTO.CD_PRODCONFIG,
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
                ESTRUTURA.NM_VARIAVEL,
                ESTRUTURA.CD_ITEM
            ORDER BY
                ESTRUTURA.CD_ITEM";

    try {
        $query = $pdo->prepare($sql);
        $query->execute([$entrada]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return null;

        $variaveis = [];
        $cdProdConfig = $rows[0]['CD_PRODCONFIG'] ?? '';
        foreach ($rows as $row) {
            $chave = trim($row['NM_VARIAVEL'] ?? '');
            $valorSimples = trim($row['RESPOSTA_SELETOR'] ?? '');
            $valorMultiplo = trim($row['RESPOSTA_SELETORMULTIPLO'] ?? '');
            $valorFinal = $valorMultiplo ?: $valorSimples;
            if ($chave && $valorFinal) {
                $variaveis[$chave] = $valorFinal;
            }
        }
        $queryString = http_build_query($variaveis, '', '&', PHP_QUERY_RFC3986);
        $codAmigavel = codigo_amigavel($cdProdConfig);

        return "https://configurador.redutoresibr.com.br/Configurador{$codAmigavel}?$queryString";
        } catch (PDOException $e) {
        return null;
    }
}

function extrairCodigoConfigurador(string $query): ?string {
    if ($query === '') return null;
    $params = [];
    parse_str($query, $params);
    foreach ($params as $value) {
        if (is_array($value)) continue;
        $valor = strtoupper(trim((string)$value));
        if ($valor !== '' && preg_match('/^[0-9]+\.[A-Z0-9]+$/', $valor)) {
            return $valor;
        }
    }
    return null;
}

function gerarLinkAmigavelPorLink(string $link): ?string {
    $info = parse_url(trim($link));
    if (!$info) return null;
    $path = $info['path'] ?? '';
    if ($path === '' || stripos($path, '/PaginasConfiguradoresSeletores/') === false) {
        return null;
    }
    $query = $info['query'] ?? '';
    $codigo = extrairCodigoConfigurador($query);
    if (!$codigo) return null;
    $amigavel = codigo_amigavel($codigo);
    $qs = $query !== '' ? '?' . $query : '';
    return "https://configurador.redutoresibr.com.br/Configurador{$amigavel}{$qs}";
}

$email = strtolower($dados['email']);
try {
    $pdo = new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
    ]);

         $stmtTot = $pdo->prepare(
        "SELECT COUNT(*) FROM _USR_CONF_SITE_HISTORICO_DESENHO WHERE DS_EMAIL = ? AND NR_CPFCNPJ = ?"
    );
    $stmtTot->execute([$email, $documento]);
    $total = (int)$stmtTot->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT DS_REFERENCIA AS produto, DS_FORMATO AS formato, DRVW_IDFIELD AS idfield, DS_LINK AS link, DT_DATA AS data
                   FROM _USR_CONF_SITE_HISTORICO_DESENHO
          WHERE DS_EMAIL = ? AND NR_CPFCNPJ = ?
          ORDER BY DT_DATA DESC
          OFFSET ? ROWS FETCH NEXT ? ROWS ONLY"
    );
    $stmt->bindValue(1, $email);
    $stmt->bindValue(2, $documento);
    $stmt->bindValue(3, $pagina * $limite, PDO::PARAM_INT);
    $stmt->bindValue(4, $limite, PDO::PARAM_INT);
    $stmt->execute();
    $lista = $stmt->fetchAll(PDO::FETCH_ASSOC);

        usort($lista, function ($a, $b) {
        return parseDataTimestamp($b['data']) <=> parseDataTimestamp($a['data']);
    });
    
    foreach ($lista as &$item) {
        $ts = parseDataTimestamp($item['data']);
        $item['data'] = date('d/m/Y H:i:s', $ts);
        $info = obterInfo($pdo, $item['produto']);
        $item['codigo'] = $info['codigo'] ?? '';
        $item['descricao'] = $info['descricao'] ?? '';
        $item['referencia'] = $info['referencia'] ?? $item['produto'];
        $linkBd = trim($item['link'] ?? '');
        $precisaAmigavel = $linkBd && stripos($linkBd, '/PaginasConfiguradoresSeletores/') !== false;
        $linkConvertido = null;
        if ($precisaAmigavel) {
            $linkConvertido = gerarLinkAmigavelPorLink($linkBd);
            if ($linkConvertido) {
                $linkBd = $linkConvertido;
            }
        }
        if (!$linkBd || ($precisaAmigavel && empty($linkConvertido))) {
            $linkGerado = gerarLink($pdo, $item['referencia']);
            if ($linkGerado) {
                $linkBd = $linkGerado;
            }
        }
        $item['link'] = $linkBd;
        $item['produto'] = $item['referencia'];

        $empDocCompl = array_pad(
            explode('-', (string)($item['idfield'] ?? ''), 3),
            3,
            ''
            );
        [$emp, $doc, $compl] = $empDocCompl;
        $statusSql = "SELECT CASE ID_STATUS
                            WHEN 0 THEN 'Aguardando'
                            WHEN 3 THEN 'Em Andamento'
                            WHEN 6 THEN 'Finalizado'
                            WHEN 7 THEN 'Cancelado'
                            ELSE 'Desconhecido'
                          END AS STATUS
                       FROM PMPR_PROJETO
                      WHERE CD_EMPRESA = ? AND CD_DOCUMENTO = ? AND NR_COMPL = ?";
        $stmStatus = $pdo->prepare($statusSql);
        $stmStatus->execute([$emp, $doc, $compl]);
        $item['situacao'] = $stmStatus->fetchColumn() ?: '';

        $dadosSql = "SELECT CD_NOMECLIENTE, NR_CPFCNPJ, CD_EMAILCLIENTE
                       FROM _USR_PMPR_PROJETO
                      WHERE CD_EMPRESA = ? AND CD_DOCUMENTO = ? AND NR_COMPL = ?";
        $stmDados = $pdo->prepare($dadosSql);
        $stmDados->execute([$emp, $doc, $compl]);
        $rowDados = $stmDados->fetch(PDO::FETCH_ASSOC) ?: [];
        $idfield = str_replace('-', '/', (string)($item['idfield'] ?? ''));
        $nome = $rowDados['CD_NOMECLIENTE'] ?? '';
        $cpf = $rowDados['NR_CPFCNPJ'] ?? '';
        $emailCli = $rowDados['CD_EMAILCLIENTE'] ?? '';

        $dadosPartes = [];
        if ($idfield !== '') $dadosPartes[] = $idfield;
        if ($nome !== '') $dadosPartes[] = $nome;
        if ($cpf !== '') $dadosPartes[] = $cpf;
        if ($emailCli !== '') $dadosPartes[] = $emailCli;

        $item['dados'] = implode(' - ', $dadosPartes);
    }
    unset($item);

    echo json_encode(['total' => $total, 'lista' => $lista]);
    $pdo = null;
} catch (PDOException $e) {
    echo json_encode([]);
}
?>
