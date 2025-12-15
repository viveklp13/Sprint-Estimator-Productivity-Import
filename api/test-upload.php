<?php
/**
 * Simple Upload Test Endpoint
 * This file helps diagnose why uploads are returning 403 Forbidden
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle OPTIONS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Diagnostic information
$diagnostics = [
    'timestamp' => date('Y-m-d H:i:s'),
    'request_method' => $_SERVER['REQUEST_METHOD'],
    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
    'php_version' => phpversion(),
    'upload_max_filesize' => ini_get('upload_max_filesize'),
    'post_max_size' => ini_get('post_max_size'),
    'file_uploads_enabled' => ini_get('file_uploads') ? 'Yes' : 'No',
    'max_file_uploads' => ini_get('max_file_uploads'),
    'memory_limit' => ini_get('memory_limit'),
    'request_headers' => [],
    'files_received' => isset($_FILES) ? count($_FILES) : 0,
    'post_data_size' => strlen(file_get_contents('php://input')),
];

// Capture request headers
foreach (getallheaders() as $name => $value) {
    $diagnostics['request_headers'][$name] = $value;
}

// Check if file was uploaded
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['file'])) {
        $file = $_FILES['file'];
        $diagnostics['file_info'] = [
            'name' => $file['name'],
            'type' => $file['type'],
            'size' => $file['size'],
            'tmp_name' => $file['tmp_name'],
            'error' => $file['error'],
            'error_message' => getUploadErrorMessage($file['error'])
        ];

        echo json_encode([
            'success' => true,
            'message' => 'File upload received successfully!',
            'diagnostics' => $diagnostics
        ], JSON_PRETTY_PRINT);
    } else {
        $diagnostics['raw_post_data'] = substr(file_get_contents('php://input'), 0, 100) . '...';

        echo json_encode([
            'success' => false,
            'message' => 'No file received in $_FILES',
            'diagnostics' => $diagnostics
        ], JSON_PRETTY_PRINT);
    }
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Only POST requests are accepted',
        'diagnostics' => $diagnostics
    ], JSON_PRETTY_PRINT);
}

function getUploadErrorMessage($error) {
    switch ($error) {
        case UPLOAD_ERR_OK:
            return 'No error';
        case UPLOAD_ERR_INI_SIZE:
            return 'File exceeds upload_max_filesize';
        case UPLOAD_ERR_FORM_SIZE:
            return 'File exceeds MAX_FILE_SIZE';
        case UPLOAD_ERR_PARTIAL:
            return 'File was only partially uploaded';
        case UPLOAD_ERR_NO_FILE:
            return 'No file was uploaded';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'Missing temporary folder';
        case UPLOAD_ERR_CANT_WRITE:
            return 'Failed to write file to disk';
        case UPLOAD_ERR_EXTENSION:
            return 'A PHP extension stopped the upload';
        default:
            return 'Unknown error: ' . $error;
    }
}
?>
