<?php
header('Content-Type: application/json; charset=UTF-8');
$baseDir = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
if ($baseDir === '') {
    $baseDir = dirname(__DIR__);
}
require_once $baseDir . '/Seguranca/CSRF.php';
require_valid_csrf_token();
$baseDir = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
if ($baseDir === '') {
    $baseDir = dirname(__DIR__);
}
require $baseDir . '/Seguranca/MetodosSegurancao.php';
require $baseDir . '/AcessosConsultas/CredenciaisBancoDados.php';
require_once $baseDir . '/SEO/ListaConfiguradoresLinkAmigavel.php';
require_once $baseDir . '/PaginasNavegacaoCompartilhada/LinkConfiguradorQU.php';
require_once $baseDir . '/PaginasNavegacaoCompartilhada/LinkConfiguradorQUDR.php';
require_once $baseDir . '/PaginasNavegacaoCompartilhada/LinkConfiguradorMO.php';
require_once $baseDir . '/PaginasNavegacaoCompartilhada/LinkConfiguradorPL.php';
require_once $baseDir . '/PaginasNavegacaoCompartilhada/LinkConfiguradorVA.php';
require_once $baseDir . '/PaginasNavegacaoCompartilhada/LinkConfiguradorHY.php';
require_once $baseDir . '/PaginasNavegacaoCompartilhada/LinkConfiguradorIN.php';
require_once $baseDir . '/PaginasNavegacaoCompartilhada/LinkConfiguradorFX.php';
require_once $baseDir . '/PaginasNavegacaoCompartilhada/LinkConfiguradorAE.php';
require_once $baseDir . '/PaginasNavegacaoCompartilhada/LinkConfiguradorAC.php';
require_once $baseDir . '/ProcessamentoFilaJobs/CriaFilaProcessamento.php';
registrar_verificacao_periodica_fila_jobs($baseDir);

try {
    $pdo = new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
    ]);

    $entrada = trim(filter_input(INPUT_POST, 'valor', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '');
    $entrada = strtoupper($entrada);

    $isCodigo = preg_match('/^[A-Z]{2,4}\.[0-9]{8}$/', $entrada);
    $isReferencia = preg_match('/^(?=.{1,250}$)[0-9A-Z]+(\.[0-9A-Z]+){2,}$/', $entrada);

    if (!$isCodigo && !$isReferencia) {
        echo json_encode(['erro' => 'Entrada inválida.']);
        exit;
    }

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
            ORDER BY 
                ESTRUTURA.CD_ITEM";

    $query = $pdo->prepare($sql);
    $query->execute([$entrada]);

    $rows = $query->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        echo json_encode(['notFound' => true]);
        exit;
    }

    $variaveis = [];
    $cdProdConfig = $rows[0]['CD_PRODCONFIG'] ?? '';
    $referencia = $rows[0]['DS_REFERENCIA'] ?? '';

    foreach ($rows as $row) {
        $chave = strtoupper(trim($row['NM_VARIAVEL'] ?? ''));
        $chave = strtoupper(preg_replace('/[\s_-]+(PL|MO|VA|MH)$/i', '', $chave));
        $valorSimples = trim($row['RESPOSTA_SELETOR'] ?? '');
        $valorMultiplo = trim($row['RESPOSTA_SELETORMULTIPLO'] ?? '');
        $valorFinal = $valorMultiplo ?: $valorSimples;

        if ($chave && $valorFinal) {
            $variaveis[$chave] = $valorFinal;
        }
    }

    if (isset($variaveis['MOLN'])) {
        unset($variaveis['MORMES'], $variaveis['MOES']);
    }

    $url = '';
    $cdProdConfigNormalizado = strtoupper(trim((string) $cdProdConfig));
    if ($cdProdConfigNormalizado === 'QU') {
        $url = montar_link_configurador_qu($variaveis);
    } elseif ($cdProdConfigNormalizado === 'QUDR') {
        $url = montar_link_configurador_qudr($variaveis);
    } elseif ($cdProdConfigNormalizado === 'MO') {
        $url = montar_link_configurador_mo($variaveis);
    } elseif ($cdProdConfigNormalizado === 'PL') {
        $url = montar_link_configurador_pl($variaveis);
    } elseif ($cdProdConfigNormalizado === 'VA') {
        $url = montar_link_configurador_va($variaveis);
    } elseif ($cdProdConfigNormalizado === 'HY') {
        $url = montar_link_configurador_hy($variaveis);
    } elseif ($cdProdConfigNormalizado === 'IN') {
        $url = montar_link_configurador_in($variaveis);
    } elseif ($cdProdConfigNormalizado === 'FX') {
        $url = montar_link_configurador_fx($variaveis);
    } elseif ($cdProdConfigNormalizado === 'AE') {
        $url = montar_link_configurador_ae($variaveis);
    } elseif ($cdProdConfigNormalizado === 'AC') {
        $url = montar_link_configurador_ac($variaveis);
    }
    if ($url === '') {
        $queryString = http_build_query($variaveis, '', '&', PHP_QUERY_RFC3986);
        $linha = '';
        foreach ($variaveis as $chave => $valor) {
            if (substr($chave, -2) === 'LN' && strpos($chave, 'MO') !== 0) {
                $linha = $valor;
                break;
            }
        }
        if (!$linha && isset($variaveis['MOLN'])) {
            $linha = $variaveis['MOLN'];
        }
        $amigavel = codigo_amigavel($linha ?: $cdProdConfig);
        $url = "https://configurador.redutoresibr.com.br/Configurador{$amigavel}?$queryString";
    }
    echo json_encode(['url' => $url, 'referencia' => $referencia ?: $entrada]);
    
} catch (PDOException $e) {
    echo json_encode(['erro' => $e->getMessage()]);
}

$pdo = null;
?>
