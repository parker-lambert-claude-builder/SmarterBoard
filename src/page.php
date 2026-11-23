<?php
/**
 * Shared head and foot for the account and pad-management pages.
 * Every link is built from the site root (mb_base_path()), so a page works at any
 * depth: signin/ is one folder down, manage/<owner>/<pad> is three.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function mb_e($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function mb_asset(string $path): string
{
    $file = __DIR__ . '/../assets/' . $path;
    return mb_base_path() . 'assets/' . $path . (is_file($file) ? '?v=' . substr(hash_file('sha256', $file), 0, 12) : '');
}

function mb_page_start(string $title, string $variant = '', ?array $padContext = null): void
{
    header('Cache-Control: no-store');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: DENY');
    $root = mb_e(mb_base_path());
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= mb_e($title) ?> | Mashpad</title>
<link rel="icon" href="<?= mb_e(mb_asset('smarterboard-mark.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= mb_e(mb_asset('boards.css')) ?>">
<link rel="stylesheet" href="<?= mb_e(mb_asset('forms.css')) ?>">
<link rel="stylesheet" href="<?= mb_e(mb_asset('accounts.css')) ?>">
<?php if ($variant === 'manage'): ?><link rel="stylesheet" href="<?= mb_e(mb_asset('manage.css')) ?>"><script src="<?= mb_e(mb_asset('confirm.js')) ?>" defer></script><script src="<?= mb_e(mb_asset('manage.js')) ?>" defer></script><?php endif; ?>
</head>
<body class="account-page<?= $variant === 'pricing' ? ' pricing-page' : '' ?>">
<a class="skip-link" href="#main">Skip to content</a>
<div class="account-report<?= $variant === 'manage' ? ' manage-report' : '' ?>">
  <?php if ($variant !== 'pricing'): ?>
  <aside class="account-sidebar<?= in_array($variant, ['manage', 'admin'], true) ? ' manage-sidebar' : '' ?>" aria-label="Mashpad navigation">
    <a class="brand" href="<?= $root ?>" aria-label="Mashpad home">Mashpad</a>
    <?php if ($variant === 'manage' && $padContext): ?>
    <p class="side-title">Your pad</p>
    <a class="manage-pad-name" href="<?= mb_e($padContext['url']) ?>"><?= mb_e($padContext['name']) ?></a>
    <nav class="pad-pages" aria-label="Pad pages">
      <a href="<?= mb_e($padContext['url']) ?>">Conversation</a>
      <a href="<?= mb_e($padContext['url']) ?>?view=settings">Pad info</a>
      <a href="<?= mb_e($padContext['manage_url']) ?>" aria-current="page">Manage access</a>
    </nav>
    <?php endif; ?>
    <?php if ($variant === 'admin'): ?>
    <p class="side-title">Admin</p>
    <nav class="pad-pages" aria-label="Admin sections">
      <a href="<?= $root ?>admin/"<?= ($padContext['section'] ?? '') === 'accounts' ? ' aria-current="page"' : '' ?>>Accounts</a>
      <a href="<?= $root ?>admin/plans/"<?= ($padContext['section'] ?? '') === 'plans' ? ' aria-current="page"' : '' ?>>Plans</a>
      <a href="<?= $root ?>admin/email/"<?= ($padContext['section'] ?? '') === 'email' ? ' aria-current="page"' : '' ?>>Email</a>
    </nav>
    <?php else: ?>
    <nav class="site-pages" aria-label="Main navigation">
      <a href="<?= $root ?>pads/">Your pads</a>
      <a href="<?= $root ?>features/">How it works</a>
      <a href="<?= $root ?>pricing/">Pricing</a>
    </nav>
    <?php endif; ?>
  </aside>
  <?php else: ?>
  <header class="account-header">
    <a class="brand" href="<?= $root ?>" aria-label="Mashpad home">Mashpad</a>
    <nav aria-label="Main navigation"><a href="<?= $root ?>features/">How it works</a><a href="<?= $root ?>pricing/" aria-current="page">Pricing</a><a href="<?= $root ?>pads/">Your pads</a></nav>
  </header>
  <?php endif; ?>
  <main class="form-page<?= $variant === 'manage' ? ' management-page' : '' ?>" id="main" tabindex="-1">
<?php
}

/** Who runs Mashpad. Used by the Terms, the Privacy Policy and the structured data, so they can't disagree. */
const MB_OPERATOR = [
    'name'    => 'Parker-Lambert',
    'street'  => '922 Chartres St, Unit B',
    'city'    => 'New Orleans',
    'region'  => 'LA',
    'country' => 'US',
    'email'   => 'hello@parker-lambert.com',
    'law'     => 'Louisiana',
];
const MB_LEGAL_UPDATED = '22 November 2026';

/** A JSON-LD block. $data is plain PHP; nothing in it is ever treated as markup. */
function mb_jsonld(array $data): string
{
    return '<script type="application/ld+json">' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . "</script>\n";
}

/** The organisation behind Mashpad, as schema.org data. */
function mb_jsonld_org(): array
{
    return ['@type' => 'Organization', '@id' => mb_site_url() . '#org', 'name' => 'Mashpad', 'url' => mb_site_url(),
            'logo' => mb_site_url() . 'assets/smarterboard-mark.svg', 'email' => MB_OPERATOR['email'],
            'parentOrganization' => ['@type' => 'Organization', 'name' => MB_OPERATOR['name'],
                'address' => ['@type' => 'PostalAddress', 'streetAddress' => MB_OPERATOR['street'], 'addressLocality' => MB_OPERATOR['city'],
                              'addressRegion' => MB_OPERATOR['region'], 'addressCountry' => MB_OPERATOR['country']]]];
}

/** The Terms and Privacy links every page's footer carries. $root is the path back to the site root. */
function mb_legal_links(string $root): string
{
    return '<a href="' . mb_e($root) . 'terms/">Terms</a><a href="' . mb_e($root) . 'privacy/">Privacy</a>';
}

function mb_page_end(): void
{
    $root = mb_base_path();
    echo "</main>\n<footer class=\"account-footer\"><span>Human ideas. Machine help.</span><span class=\"footer-links\">" . mb_legal_links($root)
       . '<a href="' . mb_e($root) . "\">Mashpad home</a></span></footer>\n</div>\n</body>\n</html>\n";
}
