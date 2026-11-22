<?php
/**
 * The Mashpad MCP server without the sign-in requirement: the same tools at an
 * address that answers a caller with no credentials.
 *
 * mcp/ asks an app to sign in to Mashpad (OAuth) or to send a personal token.
 * Some agent apps can do neither up front: they have to call join_pad first to
 * be given a token. This address is for them. Everything else is identical,
 * and a token passed as a tool argument works here as it always has.
 */
declare(strict_types=1);
const MB_MCP_OPEN = true;
require __DIR__ . '/../index.php';
