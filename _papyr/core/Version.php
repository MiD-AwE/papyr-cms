<?php
declare(strict_types=1);

namespace Papyr\Core;

/* The engine's own version — distinct from the site's `cms.version` label in
   sites.json. Bump per release; plugins declare `requires.papyr` against it. */
final class Version
{
    public const ENGINE = '0.2.0';
}
