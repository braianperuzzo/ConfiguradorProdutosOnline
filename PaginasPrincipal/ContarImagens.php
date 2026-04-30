<?php
header('Content-Type: application/json; charset=UTF-8');
$folder = preg_replace('/[^A-Za-z0-9_-]/', '', $_GET['folder'] ?? '');
$baseDir = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . '/ImagensProdutos/';
if (!$folder) {
    echo json_encode(['count' => 1]);
    exit;
}
$dir = realpath($baseDir . $folder);
$baseReal = realpath($baseDir);
if ($dir === false || $baseReal === false || strpos($dir, $baseReal) !== 0) {
    echo json_encode(['count' => 1]);
    exit;
}
$files = glob($dir . '/*' . $folder . '.webp');
$maxIndex = -1;
foreach ($files as $f) {
    if (preg_match('/(\d+)' . preg_quote($folder, '/') . '\.webp$/i', basename($f), $m)) {
        $idx = (int)$m[1];
        if ($idx > $maxIndex) $maxIndex = $idx;
    }
}
$count = $maxIndex + 1;
if ($count <= 0) $count = 1;
echo json_encode(['count' => $count]);
?>