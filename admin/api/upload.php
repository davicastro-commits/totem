<?php
/**
 * POST /admin/api/upload.php  (multipart/form-data, field: "imagem")
 *
 * Uploads a product image, resizes to max 800×800, saves to assets/uploads/produtos/.
 * Returns { success, url } where url is relative to the webroot.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
session_start();
require_once '../../config/csrf.php';
require_once 'auth.php';
requireAdmin();

$uploadDir = __DIR__ . '/../../assets/uploads/produtos/';
$uploadUrl = '../../assets/uploads/produtos/';

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
    // Security: block PHP execution inside uploads
    file_put_contents($uploadDir . '.htaccess',
        "Options -Indexes -ExecCGI\n" .
        "AddHandler cgi-script .php .php3 .php4 .php5 .phtml .pl .py\n" .
        "Deny from all\n" .  // overridden by Allow below
        "<FilesMatch \"\\.(jpe?g|png|webp|gif)$\">\n  Allow from all\n</FilesMatch>\n"
    );
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);
    exit;
}

$file = $_FILES['imagem'] ?? null;
if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
    $codes = [1=>'Arquivo muito grande',2=>'Arquivo muito grande',3=>'Upload incompleto',4=>'Nenhum arquivo enviado'];
    echo json_encode(['success' => false, 'error' => $codes[$file['error'] ?? 4] ?? 'Erro de upload']);
    exit;
}

// Validate size (max 3MB)
if ($file['size'] > 3 * 1024 * 1024) {
    echo json_encode(['success' => false, 'error' => 'Imagem muito grande (máx 3 MB)']);
    exit;
}

// Validate MIME via content (not extension)
$finfo    = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($file['tmp_name']);
$allowed  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

if (!array_key_exists($mimeType, $allowed)) {
    echo json_encode(['success' => false, 'error' => 'Formato não suportado. Use JPG, PNG ou WebP.']);
    exit;
}

$ext      = $allowed[$mimeType];
$filename = uniqid('prod_', true) . '.' . $ext;
$destPath = $uploadDir . $filename;

// ── Resize with GD ────────────────────────────────────────────────────
$maxDim = 800;

if (extension_loaded('gd')) {
    [$srcW, $srcH] = getimagesize($file['tmp_name']);

    // Calculate new dimensions
    $ratio  = min($maxDim / max($srcW, 1), $maxDim / max($srcH, 1));
    $newW   = $ratio < 1 ? (int)round($srcW * $ratio) : $srcW;
    $newH   = $ratio < 1 ? (int)round($srcH * $ratio) : $srcH;

    $src = match ($mimeType) {
        'image/jpeg' => imagecreatefromjpeg($file['tmp_name']),
        'image/png'  => imagecreatefrompng($file['tmp_name']),
        'image/webp' => imagecreatefromwebp($file['tmp_name']),
        'image/gif'  => imagecreatefromgif($file['tmp_name']),
        default      => false,
    };

    if ($src !== false) {
        $dst = imagecreatetruecolor($newW, $newH);

        // Preserve transparency for PNG/WebP
        if (in_array($mimeType, ['image/png', 'image/webp', 'image/gif'])) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $trans = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefilledrectangle($dst, 0, 0, $newW, $newH, $trans);
        }

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);

        $saved = match ($mimeType) {
            'image/jpeg' => imagejpeg($dst, $destPath, 85),
            'image/png'  => imagepng($dst, $destPath, 6),
            'image/webp' => imagewebp($dst, $destPath, 85),
            'image/gif'  => imagegif($dst, $destPath),
            default      => false,
        };
        imagedestroy($src);
        imagedestroy($dst);

        if (!$saved) {
            echo json_encode(['success' => false, 'error' => 'Falha ao processar imagem.']);
            exit;
        }
    } else {
        // GD failed to load: just move the file
        move_uploaded_file($file['tmp_name'], $destPath);
    }
} else {
    // GD not available: save as-is
    move_uploaded_file($file['tmp_name'], $destPath);
}

// Return public URL (relative to webroot)
$publicUrl = 'assets/uploads/produtos/' . $filename;

echo json_encode([
    'success' => true,
    'url'     => $publicUrl,
    'filename'=> $filename,
]);
