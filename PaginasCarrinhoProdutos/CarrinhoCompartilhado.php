<?php
ini_set('display_errors', 0);
error_reporting(0);
$baseDir = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
if ($baseDir === '') {
    $baseDir = dirname(__DIR__);
}

$galeriaHelperFile = $baseDir . '/PaginasCarrinhoProdutos/ImagensProdutosHelper.php';
if (is_file($galeriaHelperFile)) {
    require_once $galeriaHelperFile;
}

$helperFile = $baseDir . '/TokensGeradores/TokensCarrinhosCompartilhados.php';
if (is_file($helperFile)) {
    require_once $helperFile;
}

if (function_exists('obter_diretorio_carrinhos_compartilhados')) {
    $cartDir = obter_diretorio_carrinhos_compartilhados($baseDir);
} else {
    $cartDir = $baseDir . '/Tokens/CarrinhosCompartilhados';
}
if (!is_dir($cartDir)) {
    mkdir($cartDir, 0775, true);
}
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'DELETE') {
    $id = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['id'] ?? '');
    if (!$id) {
        $input = json_decode(file_get_contents('php://input'), true);
        if (is_array($input)) {
            $id = preg_replace('/[^a-zA-Z0-9]/', '', (string) ($input['id'] ?? ''));
        }
    }
    if ($id) {
        if (function_exists('excluir_carrinho_compartilhado_por_id')) {
            excluir_carrinho_compartilhado_por_id($id, $baseDir);
        } else {
            $file = $cartDir . '/' . $id . '.json';
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }
    http_response_code(204);
    exit;
}
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $items = $input['items'] ?? [];
    $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $max = strlen($chars) - 1;
    do {
        $id = '';
        for ($i = 0; $i < 8; $i++) {
            $id .= $chars[random_int(0, $max)];
        }
    } while (file_exists($cartDir . '/' . $id . '.json'));
    file_put_contents($cartDir . '/' . $id . '.json', json_encode($items));
    echo json_encode(['id' => $id]);
    exit;
}
$id = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['id'] ?? '');
$file = $cartDir . '/' . $id . '.json';
if ($id && file_exists($file)) {
    $conteudo = file_get_contents($file);
    $items = json_decode($conteudo, true);
    if (!is_array($items)) {
        $items = [];
    }
    if (function_exists('enriquecer_itens_com_galeria')) {
        $items = enriquecer_itens_com_galeria($items, $baseDir);
    }
    echo json_encode($items);
} else {
    echo json_encode([]);
}
?>
