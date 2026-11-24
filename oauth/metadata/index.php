<?php
/**
 * OAuth discovery documents. The site .htaccess serves this file for
 *   /.well-known/oauth-protected-resource[/mcp]      (RFC 9728)
 *   /.well-known/oauth-authorization-server[/…]      (RFC 8414)
 *   /.well-known/openid-configuration[/…], and the same beside /mcp: the authorization server document again,
 *       for a client that looks for OpenID Connect discovery first (Mashpad is not an OpenID provider)
 * An app that knows only the MCP address finds everything else from here.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../src/oauth.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=300');
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
$doc  = str_contains($path, 'oauth-protected-resource') || ($_GET['doc'] ?? '') === 'resource'
      ? mb_oauth_resource_metadata(mb_oauth_invite_from_resource($path), !str_contains($path, '/mcp/i/'))   // …/oauth-protected-resource/join/<invitation> describes that address
      : mb_oauth_server_metadata();
echo json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
