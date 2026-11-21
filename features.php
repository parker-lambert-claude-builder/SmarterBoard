<?php
/**
 * Legacy address. The features page now lives at features/ (clean URLs, no .php).
 * Kept so old bookmarks and links keep working; the query string is preserved.
 */
declare(strict_types=1);
$qs = $_SERVER['QUERY_STRING'] ?? '';
header('Location: features/' . ($qs !== '' ? '?' . $qs : ''), true, 301);
exit;
