<?php
/** OAuth dynamic client registration (RFC 7591): an app registers itself before asking a person to approve it. */
declare(strict_types=1);
require_once __DIR__ . '/../../src/oauth.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
$fail = function (int $status, string $error, string $description): void {
    http_response_code($status);
    echo json_encode(['error' => $error, 'error_description' => $description]);
    exit;
};
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST, OPTIONS');
    $fail(405, 'invalid_request', 'Register with a POST of JSON client metadata.');
}
try {
    $pdo = mb_db();
    if (!$pdo) {
        $fail(503, 'temporarily_unavailable', 'Accounts aren\'t set up on this site yet.');
    }
    $in = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($in)) {
        $fail(400, 'invalid_client_metadata', 'Send the client metadata as a JSON object.');
    }
    http_response_code(201);
    echo json_encode(mb_oauth_register($pdo, $in), JSON_UNESCAPED_SLASHES);
} catch (MbOAuthError $e) {
    $fail($e->status, $e->error, $e->getMessage());
} catch (Throwable $e) {
    $fail(500, 'server_error', 'Registration failed. Please try again.');
}
