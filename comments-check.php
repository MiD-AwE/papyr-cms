<?php
declare(strict_types=1);
define('PAPYR_DEFER_NAV', true);
require_once __DIR__ . '/nav.php';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Comments check</title>
<style>
  body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; }
  main { max-width: 46rem; margin: 0 auto; padding: 2rem 1.25rem; }
  section { margin-block: 2rem; }
</style>
</head>
<body>
<!-- papyr:draft -->
<?php papyr_nav(); ?>
<main>
  <h1>Comments check</h1>
  <p>A test page for the invite-only comments. It is a draft, so it stays out of the nav.
     Each block calls <code>papyr_comments('block-id')</code>; threads are stored in
     <code>data/comments/comments-check.json</code>.</p>

  <section>
    <h2>First block</h2>
    <p>Visitors see a count and an unlock link. Unlock with the invite password to read and post.</p>
    <?php papyr_comments('first'); ?>
  </section>

  <section>
    <h2>Second block</h2>
    <p>A separate thread on the same page.</p>
    <?php papyr_comments('second'); ?>
  </section>
</main>
</body>
</html>
