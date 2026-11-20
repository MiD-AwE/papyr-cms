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
  <title>PapyrCMS- Site Map</title>
  <meta name="description" content="Every page on this site as a live 3D map, read straight from the filesystem." />
</head>
<body>
<?php papyr_nav(); ?>
<?php papyr_plugin('site-map'); ?>
</body>
</html>
