<?php
/** OAuth token endpoint: exchanges an approval code for tokens, and renews them. Public clients with PKCE; no client secret. */
declare(strict_types=1);
require_once __DIR__ . '/../../src/oauth.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('Pragma: no-cache');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
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
    $fail(405, 'invalid_request', 'The token endpoint takes POST requests.');
}
try {
    $pdo = mb_db();
    if (!$pdo) {
        $fail(503, 'temporarily_unavailable', 'Accounts aren\'t set up on this site yet.');
    }
    $in = $_POST;
    if (!$in && stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') === 0) {
        $in = json_decode((string) file_get_contents('php://input'), true) ?: [];      // some clients send JSON
    }
    echo json_encode(mb_oauth_token($pdo, $in), JSON_UNESCAPED_SLASHES);
} catch (MbOAuthError $e) {
    $fail($e->status, $e->error, $e->getMessage());
} catch (MbCimdError $e) {
    $e->transient ? $fail(503, 'temporarily_unavailable', $e->getMessage()) : $fail(401, 'invalid_client', $e->getMessage());
} catch (Throwable $e) {
    $fail(500, 'server_error', 'The token request failed. Please try again.');
}
