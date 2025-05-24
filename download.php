<?php
$downloadsDir = __DIR__ . '/downloads/';

if (!isset($_GET['file'])) {
    http_response_code(400);
    echo "File tidak ditentukan.";
    exit;
}

$filename = basename($_GET['file']);
$filepath = realpath($downloadsDir . $filename);

// Cek apakah file benar-benar ada dalam folder downloads
if (!$filepath || strpos($filepath, realpath($downloadsDir)) !== 0 || !is_file($filepath)) {
    http_response_code(404);
    echo "File tidak ditemukan.";
    exit;
}

$mime = mime_content_type($filepath);
header('Content-Description: File Transfer');
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . basename($filepath) . '"');
header('Content-Length: ' . filesize($filepath));
flush();
readfile($filepath);
exit;
?>
