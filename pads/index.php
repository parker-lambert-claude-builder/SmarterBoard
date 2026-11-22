<?php
/**
 * The pad viewer. One page serves every pad address:
 *   pads/                      your pads
 *   pads/<owner>/<pad>         a private pad (the site .htaccess sends that path here)
 *   pads/?b=<name>             an older open pad, until it expires
 * A ?b= link to a private pad is redirected to its full address when it names
 * exactly one pad the signed-in person can see. Otherwise the page is served
 * as-is and shows what a pad that doesn't exist shows.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/pads.php';

header('Cache-Control: no-store');
$base = mb_base_path();

if (isset($_GET['b']) && is_string($_GET['b'])) {
    $a    = mb_parse_pad_address($_GET['b']);
    $open = $a['owner'] === null && $a['flat'] !== null && is_file(mb_path($a['flat']));
    if (!$open) {
        $pads = [];
        try {
            $pdo  = mb_db();
            $user = $pdo ? mb_current_user() : null;
            $pads = $user ? mb_pads_matching($pdo, $_GET['b'], (int) $user['id']) : [];
        } catch (Throwable $e) {
            // the viewer itself will report what it can't load
        }
        if (count($pads) === 1) {
            $rest = $_GET;
            unset($rest['b']);
            header('Location: ' . $base . 'pads/' . mb_pad_ref($pads[0]) . ($rest ? '?' . http_build_query($rest) : ''), true, 301);
            exit;
        }
    }
}

$asset = function (string $path) use ($base): string {
    return htmlspecialchars($base . 'assets/' . $path . '?v=' . substr((string) hash_file('sha256', __DIR__ . '/../assets/' . $path), 0, 12), ENT_QUOTES, 'UTF-8');
};
$root = htmlspecialchars($base, ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#ffffff">
<title>Mashpad | Pads</title>
<link rel="icon" href="<?= $root ?>assets/smarterboard-mark.svg" type="image/svg+xml">
<link rel="preload" href="<?= $root ?>assets/fonts/ibm-plex-serif-regular.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= $asset('boards.css') ?>">
<script src="<?= $asset('vendor/markdown-it.min.js') ?>" defer></script>
<script src="<?= $asset('board-markdown.js') ?>" defer></script>
<script src="<?= $asset('confirm.js') ?>" defer></script>
<script src="<?= $asset('boards.js') ?>" defer></script>
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<div class="shell">
  <aside>
    <a class="brand" href="<?= $root ?>pads/" data-nav="" aria-label="Mashpad pads">Mashpad</a>
    <div class="account" id="account" aria-live="polite"></div>
    <h2 class="side-title">Pads</h2>
    <nav id="board-nav" aria-label="Pads"></nav>
  </aside>
  <div class="column-rules" aria-hidden="true"></div>
  <main id="main" tabindex="-1"></main>
</div>
</body>
</html>
