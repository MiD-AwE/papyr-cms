<?php declare(strict_types=1);
/* Boot the Papyr spine before any output (session, roles, theme); the nav renders after <body>. */
define('PAPYR_DEFER_NAV', true);
for ($_d = __DIR__; !file_exists($_d . '/nav.php') && dirname($_d) !== $_d; $_d = dirname($_d));
require_once $_d . '/nav.php';
unset($_d);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>PapyrCMS- overview</title>
  <meta name="description" content="PapyrCMS - A helpful but not in the way, refusing to invade or get too big for its britches, does exactly what you asked, nothing more CMS, finally." />
</head>
<body>
<?php papyr_nav(); ?>
<main class="page-content docs">
  <h1>PapyrCMS- overview</h1>
  <div class="body-content">P — Paper thin<br />  
A — And<br />
P — Per<br />
Y — Your<br />
R — Request<br />
<br /><br />
A helpful but not in the way, refusing to invade or get too big for its britches, does exactly what you asked, nothing more, is exactly the principle that's been guiding every implement-now / stage call.</div>
</main>

<style>
  .page-content {
    max-width: 720px;
    padding: 2rem 2.5rem 4rem;
  }
  .page-content h1 {
    font-size: 1.5rem;
    font-weight: 700;
    letter-spacing: -.01em;
    color: var(--accent);
    margin-bottom: .6rem;
  }
  .body-content { color: var(--text); line-height: 1.7; }
  .body-content p  { margin-bottom: 1rem; }
  .body-content h2 { color: var(--accent); margin: 1.6rem 0 .4rem; }
  .body-content a  { color: var(--accent); border-bottom: 1px dotted; }
  .body-content code {
    background: var(--bg-deep, #060810);
    border: 1px solid var(--border);
    border-radius: 3px; padding: .05rem .35rem; font-size: .85em;
  }
</style>
</body>
</html>
