<?php
/**
 * The page Apache shows for an address that isn't there (see ErrorDocument in .htaccess). It keeps the 404 status, wears the
 * same header and footer as the other public pages, and says nothing about any pad: it can't tell a pad that doesn't exist
 * from one the visitor isn't allowed to see, and neither does it.
 */
declare(strict_types=1);
http_response_code(404);
require_once __DIR__ . '/src/page.php';
$signedIn = mb_public_signed_in();
$root     = mb_e(mb_base_path());
mb_page_start('Page not found', 'public', ['layout' => 'prose']);
?>
  <h1>Page not found</h1>
  <p>There is nothing at this address. It may have moved, or it may be a private pad that you need to sign in to see.</p>
  <p><?php if ($signedIn): ?><a href="<?= $root ?>pads/">Go to your pads</a><?php else: ?><a href="<?= $root ?>signin/">Sign in</a> or <a href="<?= $root ?>register/">create an account</a><?php endif; ?>, or go back to <a href="<?= $root ?>">Mashpad home</a>.</p>
<?php mb_page_end();
