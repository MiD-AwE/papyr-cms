<?php
declare(strict_types=1);

/* Auth gate — admin session required for the entire dashboard, including
   the POST handlers below. A POST can be sent directly without going
   through the UI, so this must run before any output or action handling. */
require __DIR__ . '/vendor/autoload.php';

use Papyr\Core\Session;

Session::start();
if (!Session::isAdmin()) {
    header('Location: login.php');
    exit;
}
$csrf = Session::csrfToken();

use Papyr\Core\NavBuilder;
use Papyr\Core\PageWriter;
use Papyr\Core\Starters;
use Papyr\Core\ThemeStore;
use Papyr\Core\TokenRegistry;
use Papyr\Core\UrlResolver;

require __DIR__ . '/bootstrap.php';

/* ====================================================================
   POST handler — must run before any output so header() still works.
   ==================================================================== */
$flash     = null; // ['msg' => string, 'type' => 'ok'|'bad']
$postError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action      = $_POST['action']    ?? '';
    $formSiteKey = (string) ($_POST['site'] ?? '');
    $formPath    = trim((string) ($_POST['page_path'] ?? ''));

    if (!Session::sameOrigin() || !Session::checkCsrf($_POST['csrf'] ?? null)) {
        $postError = 'Your session expired. Reload the page and try again.';
    } elseif (!isset($sites[$formSiteKey])) {
        $postError = 'Unknown site key.';
    } elseif ($formPath === '') {
        $postError = 'Page path is required.';
    } else {
        try {
            $writer = new PageWriter($scanner, $formSiteKey, $sites[$formSiteKey], __DIR__);

            // Add Page posts JSON to api/pages.php; only the table's trash button posts here.
            if ($action === 'trash') {
                $moved = $writer->trash($formPath);
                $redir = 'index.php?msg=' . urlencode("Moved to trash: {$formPath} → {$moved} (restore by moving it back)");
                header("Location: {$redir}");
                exit;

            } else {
                $postError = "Unknown action: {$action}";
            }
        } catch (\Throwable $e) {
            $postError = $e->getMessage();
            error_log('[papyr index] action failed: ' . $e->getMessage());
        }
    }
}

if ($postError !== null) {
    $flash = ['msg' => $postError, 'type' => 'bad'];
} elseif (isset($_GET['msg'])) {
    $flash = ['msg' => (string) $_GET['msg'], 'type' => 'ok'];
}
$created = isset($_GET['created']) && is_string($_GET['created']) ? ltrim($_GET['created'], '/') : '';

/* ====================================================================
   Read-side: scan + AI status + siteView
   ==================================================================== */
$scanError = null;
$report    = null;
try {
    $report = $scanner->scan();
} catch (\Throwable $e) {
    $scanError = $e->getMessage();
    error_log('[papyr index] scan failed: ' . $e->getMessage());
}

$aiStatus = [];
try {
    $aiStatus = $ai->status();
} catch (\Throwable $e) {
    error_log('[papyr index] ai status failed: ' . $e->getMessage());
}

$siteView = [];
foreach ($sites as $key => $site) {
    $root     = $site['root'] ?? '';
    $rootOk   = $root !== '' && is_dir($root);
    $pages    = [];
    $tree     = [];
    $pagesErr = null;

    if ($rootOk) {
        try {
            $pages = $scanner->getPages((string) $key);
            $tree  = NavBuilder::tree($pages);
        } catch (\Throwable $e) {
            $pagesErr = $e->getMessage();
            error_log("[papyr index] getPages($key) failed: " . $e->getMessage());
        }
    }

    // Add Page: starters (ADR-002 §7.3), folders for the picker, theme starting values.
    $starters = [];
    $folders  = [];
    if ($rootOk) {
        $starters = (new Starters((string) $root, __DIR__))->all();
        foreach (array_keys($pages) as $rel) {
            for ($d = dirname((string) $rel); $d !== '.' && $d !== ''; $d = dirname($d)) {
                $folders[$d] = true;
            }
        }
        ksort($folders);
    }

    $siteView[$key] = [
        'label'            => $site['label'] ?? $key,
        'url'              => $site['url']   ?? '',
        'root'             => $root,
        'rootOk'           => $rootOk,
        'pages'            => $pages,
        'tree'             => $tree,
        'pagesErr'         => $pagesErr,
        'starters'         => $starters,
        'folders'          => array_keys($folders),
        'basePath'         => $rootOk ? UrlResolver::basePath((string) $root) : '',
        'hasMap'           => isset($pages['map.php']),
    ];
}

/* ---- helpers ---- */
function h(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function fmtTime(?int $ts): string { return $ts ? date('Y-m-d H:i', $ts) : '—'; }

$aiAvailable = false;
foreach ($aiStatus as $st) {
    if (is_array($st) && !empty($st['online'])) { $aiAvailable = true; break; }
}

// ---- Token registry ----
$tokenMapPath   = __DIR__ . '/templates/cms/tokens.css';
$templatePaths  = [];
$tplIter = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(__DIR__ . '/templates/', FilesystemIterator::SKIP_DOTS)
);
foreach ($tplIter as $f) {
    if ($f->isFile() && in_array($f->getExtension(), ['twig', 'php'], true)) {
        $templatePaths[] = $f->getPathname();
    }
}
$templatePaths[] = __DIR__ . '/nav.php';

$tokenRegistry = TokenRegistry::enumerate($tokenMapPath, $templatePaths);
$tokenRendered = TokenRegistry::render($tokenRegistry);

$cms = $config['cms'] ?? [];
$themeDefaults = (new ThemeStore((string) (reset($sites)['root'] ?? '')))->defaults();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($cms['name'] ?? 'Papyr CMS') ?> · <?= h($cms['version'] ?? '') ?></title>
  <style>
    :root{
      --bg:#0f1115; --panel:#161a21; --line:#232a34;
      --ink:#cdd6e2; --dim:#7c8696; --accent:#5ad1c5; --warn:#e0a35a; --bad:#e05a6b;
      --mono:'SFMono-Regular',Consolas,'Liberation Mono',monospace;
    }
    *{box-sizing:border-box;margin:0;padding:0}
    body{background:var(--bg);color:var(--ink);font-family:var(--mono);line-height:1.5;padding:2rem 1.2rem 4rem}
    .wrap{max-width:900px;margin:0 auto}
    h1{font-size:1.1rem;letter-spacing:.04em;font-weight:700;margin-bottom:.2rem}
    .sub{color:var(--dim);font-size:.72rem;letter-spacing:.1em;text-transform:uppercase;margin-bottom:1.4rem}
    .row{display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1.4rem}
    .pill{font-size:.66rem;letter-spacing:.06em;border:1px solid var(--line);border-radius:20px;
          padding:.3rem .7rem;color:var(--dim)}
    .pill b{color:var(--accent)} .pill.warn b{color:var(--warn)} .pill.bad b{color:var(--bad)}
    .flash{display:flex;align-items:center;gap:.8rem;border-radius:5px;padding:.7rem .9rem;
           font-size:.78rem;margin-bottom:1.2rem;border:1px solid var(--line)}
    .flash.ok {border-left:3px solid var(--accent);background:color-mix(in srgb,var(--accent) 6%,var(--panel))}
    .flash.bad{border-left:3px solid var(--bad);background:color-mix(in srgb,var(--bad) 6%,var(--panel))}
    .flash .icon{font-size:1rem;flex-shrink:0}
    .site{border:1px solid var(--line);background:var(--panel);border-radius:7px;margin-bottom:1rem;overflow:hidden}
    .site-head{display:flex;align-items:center;justify-content:space-between;gap:1rem;
               padding:.8rem 1rem;border-bottom:1px solid var(--line)}
    .site-head .name{font-weight:700;font-size:.9rem}
    .site-head .name a{color:var(--ink);text-decoration:none;border-bottom:1px dotted var(--dim)}
    .site-head .meta{font-size:.66rem;color:var(--dim);text-align:right;flex-shrink:0}
    .ok{color:var(--accent)} .no{color:var(--bad)}
    table{width:100%;border-collapse:collapse;font-size:.74rem}
    th{text-align:left;color:var(--dim);font-weight:400;font-size:.62rem;letter-spacing:.1em;
       text-transform:uppercase;padding:.5rem 1rem;border-bottom:1px solid var(--line)}
    td{padding:.42rem 1rem;border-bottom:1px solid var(--line);vertical-align:middle}
    tr:last-child td{border-bottom:none}
    .tag{font-size:.58rem;letter-spacing:.06em;border-radius:3px;padding:.08rem .4rem;border:1px solid var(--line);color:var(--dim)}
    .tag.man{border-color:var(--accent);color:var(--accent)}
    .tag.edit{border-color:var(--warn);color:var(--warn)}
    .tag.draft{border-color:var(--dim);color:var(--dim)}
    .empty{padding:1rem;color:var(--dim);font-size:.74rem}
    .err{color:var(--bad);font-size:.72rem;padding:.6rem 1rem}
    /* ---- Add Page form ---- */
    .add-page-wrap{border-top:1px solid var(--line)}
    .add-page-wrap summary{
      display:flex;align-items:center;gap:.5rem;
      padding:.65rem 1rem;font-size:.74rem;cursor:pointer;
      color:var(--accent);letter-spacing:.06em;user-select:none;
      list-style:none;
    }
    .add-page-wrap summary::-webkit-details-marker{display:none}
    .add-page-wrap summary::before{content:'+'}
    .add-page-wrap[open] summary::before{content:'−'}
    .add-page-form{padding:1rem;border-top:1px solid var(--line);display:grid;gap:.8rem}
    .form-row{display:grid;grid-template-columns:160px 1fr;align-items:start;gap:.5rem}
    .form-row label{font-size:.68rem;color:var(--dim);padding-top:.5rem;letter-spacing:.05em}
    .form-row label .opt{font-size:.58rem;color:var(--dim);opacity:.6;margin-left:.3rem}
    .form-row input,.form-row textarea{
      background:#0a0c10;border:1px solid var(--line);border-radius:4px;
      color:var(--ink);font-family:var(--mono);font-size:.74rem;
      padding:.42rem .6rem;width:100%;
    }
    .form-row input:focus,.form-row textarea:focus{
      outline:none;border-color:var(--accent);
      box-shadow:0 0 0 2px color-mix(in srgb,var(--accent) 15%,transparent);
    }
    .form-row textarea{resize:vertical;min-height:120px}
    .form-hint{grid-column:2;font-size:.64rem;color:var(--dim);margin-top:.25rem}
    .form-row input[type=radio],.form-row input[type=checkbox]{width:auto;flex:none;margin-top:.15rem;accent-color:var(--accent)}
    .ap-fields textarea{min-height:4.5rem}
    .ap-fields{border:0;display:grid;gap:.8rem}
    .ap-fields[disabled]{display:none}
    .ap-layouts{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:.5rem}
    .form-row .ap-layout,.form-row .ap-check{padding-top:0;color:var(--ink);font-size:.72rem;letter-spacing:0;cursor:pointer}
    .ap-layout{display:flex;gap:.5rem;align-items:flex-start;border:1px solid var(--line);border-radius:5px;padding:.5rem .6rem!important}
    .ap-layout:has(input:checked){border-color:var(--accent)}
    .ap-layout small{color:var(--dim);font-size:.64rem;line-height:1.4}
    .ap-check{display:flex;gap:.5rem;align-items:center}
    .ap-fields .ap-html{min-height:160px}
    .ap-theme{margin-top:.7rem;display:grid;gap:.7rem;max-width:340px}
    .ap-preview{border:1px solid var(--line);border-radius:5px;padding:.6rem .8rem;font-size:.72rem;display:grid;gap:.2rem}
    .ap-actions{display:flex;gap:.8rem;align-items:center;flex-wrap:wrap}
    .ap-msg{font-size:.7rem;color:var(--bad)}
    .ap-msg.ok{color:var(--accent)}
    @media (max-width:640px){.form-row{grid-template-columns:1fr}.form-row>div:empty{display:none}.form-hint{grid-column:1}}
    .btn{display:inline-block;border:1px solid var(--line);border-radius:4px;
         background:transparent;color:var(--ink);font-family:var(--mono);font-size:.72rem;
         padding:.4rem .9rem;cursor:pointer;letter-spacing:.04em;transition:border-color .15s,color .15s}
    .btn:hover{border-color:var(--accent);color:var(--accent)}
    .btn.primary{border-color:var(--accent);color:var(--accent)}
    .btn.primary:hover{background:color-mix(in srgb,var(--accent) 10%,transparent)}
    .btn.danger{border-color:transparent;color:var(--dim);font-size:.64rem;padding:.2rem .4rem}
    .btn.danger:hover{border-color:var(--bad);color:var(--bad)}
    .actions-cell{white-space:nowrap}
    code{background:#0a0c10;border:1px solid var(--line);border-radius:3px;padding:.05rem .35rem;color:var(--accent)}
    footer{margin-top:2rem;color:var(--dim);font-size:.66rem;text-align:center;letter-spacing:.04em}
    /* ---- Token registry ---- */
    .registry-wrap{border:1px solid var(--line);background:var(--panel);border-radius:7px;margin-bottom:1rem;overflow:hidden}
    .registry-wrap summary{
      display:flex;align-items:center;gap:.6rem;
      padding:.8rem 1rem;cursor:pointer;font-size:.74rem;user-select:none;
      list-style:none;color:var(--dim);
    }
    .registry-wrap summary::-webkit-details-marker{display:none}
    .registry-wrap summary .reg-title{color:var(--ink);font-weight:700;font-size:.9rem}
    .registry-wrap summary .reg-counts{display:flex;gap:.5rem;margin-left:auto}
    .reg-badge{font-size:.62rem;border-radius:3px;padding:.08rem .4rem;border:1px solid var(--line)}
    .reg-badge.bound  {border-color:var(--accent);color:var(--accent)}
    .reg-badge.unbound{border-color:var(--dim);color:var(--dim)}
    .reg-badge.missing{border-color:var(--bad);color:var(--bad)}
    .reg-table{width:100%;border-collapse:collapse;font-size:.72rem;border-top:1px solid var(--line)}
    .tr-group td{padding:.5rem 1rem .2rem;font-size:.55rem;letter-spacing:.3em;text-transform:uppercase;
                 color:var(--dim);opacity:.55;border-top:1px solid var(--line)}
    .tr-token td{padding:.3rem 1rem;border-bottom:1px solid var(--line)}
    .tr-token:last-child td{border-bottom:none}
    .td-glyph{width:1.5rem;text-align:center}
    .tr-bound   .td-glyph{color:var(--accent)}
    .tr-unbound .td-glyph{color:var(--dim);opacity:.5}
    .tr-missing .td-glyph{color:var(--bad)}
    .td-name code{background:transparent;border:none;padding:0;color:var(--ink)}
    .tr-missing .td-name code{color:var(--bad)}
    .tr-unbound .td-name code{opacity:.55}
    .td-note{color:var(--dim);font-size:.65rem}
    /* ---- AI panel ---- */
    #ai-overlay{
      display:none;position:fixed;inset:0;z-index:199;
      background:rgba(0,0,0,.45);
    }
    #ai-overlay.open{display:block}
    #ai-panel{
      position:fixed;top:0;right:0;bottom:0;z-index:200;
      width:360px;max-width:100vw;
      background:var(--panel);border-left:1px solid var(--line);
      display:flex;flex-direction:column;
      transform:translateX(100%);transition:transform .22s cubic-bezier(.4,0,.2,1);
      font-size:.78rem;
    }
    #ai-panel.open{transform:none}
    .aip-head{
      display:flex;align-items:center;justify-content:space-between;
      padding:.75rem 1rem;border-bottom:1px solid var(--line);flex-shrink:0;
    }
    .aip-head-label{font-size:.6rem;letter-spacing:.3em;text-transform:uppercase;color:var(--accent);font-weight:700}
    .aip-close{border:none;background:none;color:var(--dim);cursor:pointer;font-size:1.1rem;
               padding:.1rem .35rem;font-family:var(--mono);line-height:1}
    .aip-close:hover{color:var(--ink)}
    .aip-context{padding:.7rem 1rem;border-bottom:1px solid var(--line);flex-shrink:0}
    .aip-context .aip-page-title{color:var(--ink);font-weight:700;margin-bottom:.2rem;
                                  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .aip-context .aip-page-path{font-size:.64rem;color:var(--dim)}
    .aip-actions{display:flex;flex-wrap:wrap;gap:.4rem;padding:.75rem 1rem;border-bottom:1px solid var(--line);flex-shrink:0}
    .aip-task{font-size:.64rem;letter-spacing:.04em;padding:.3rem .65rem}
    .aip-task.active{border-color:var(--accent);color:var(--accent)}
    .aip-status{padding:.4rem 1rem;font-size:.68rem;color:var(--dim);min-height:1.6rem;flex-shrink:0}
    .aip-result{
      flex:1;resize:none;
      background:#0a0c10;border:none;border-top:1px solid var(--line);border-bottom:1px solid var(--line);
      color:var(--ink);font-family:var(--mono);font-size:.74rem;
      padding:.75rem 1rem;line-height:1.6;outline:none;
    }
    .aip-foot{
      display:flex;align-items:center;justify-content:space-between;
      padding:.6rem 1rem;flex-shrink:0;
    }
    .aip-provider{font-size:.62rem;color:var(--dim);letter-spacing:.04em}
    .ai-btn{font-size:.62rem;padding:.2rem .5rem;color:var(--dim)}
    .ai-btn:hover{color:var(--accent);border-color:var(--accent)}
  </style>
</head>
<body>
<div class="wrap">

  <h1><?= h($cms['name'] ?? 'Papyr CMS') ?></h1>
  <div class="sub">v<?= h($cms['version'] ?? '?') ?> · <form method="POST" action="login.php" style="display:inline"><input type="hidden" name="action" value="logout"><input type="hidden" name="csrf" value="<?= h(Session::csrfToken()) ?>"><button type="submit" style="background:none;border:0;padding:0;font:inherit;color:var(--dim);cursor:pointer;text-decoration:underline">logout</button></form></div>

  <!-- ===== Stack health pills ===== -->
  <div class="row">
    <span class="pill">autoload <b>ok</b></span>
    <span class="pill">config <b>parsed</b></span>
    <?php if ($scanError === null): ?>
      <span class="pill">scan <b>ok</b></span>
      <?php if (is_array($report)): ?>
        <span class="pill">new <b><?= (int) count($report['new'] ?? []) ?></b></span>
        <span class="pill">modified <b><?= (int) count($report['modified'] ?? []) ?></b></span>
        <span class="pill">deleted <b><?= (int) count($report['deleted'] ?? []) ?></b></span>
      <?php endif; ?>
    <?php else: ?>
      <span class="pill bad">scan <b>failed</b></span>
    <?php endif; ?>
    <?php foreach ($aiStatus as $chan => $st):
      $online = is_array($st) ? !empty($st['online']) : false; ?>
      <span class="pill <?= $online ? '' : 'warn' ?>">ai:<?= h((string)$chan) ?> <b><?= $online ? '✓' : '—' ?></b></span>
    <?php endforeach; ?>
  </div>

  <!-- ===== Flash message ===== -->
  <?php if ($flash !== null): ?>
    <div class="flash <?= h($flash['type']) ?>">
      <span class="icon"><?= $flash['type'] === 'ok' ? '✓' : '✕' ?></span>
      <?= h($flash['msg']) ?>
      <?php if ($created !== '' && $flash['type'] === 'ok'):
        $sv0 = reset($siteView);
        if ($sv0 && isset($sv0['pages'][$created])): ?>
        · <a href="<?= h($sv0['basePath'] . '/' . $created) ?>">open page</a>
        <?php if ($sv0['hasMap']): ?>· <a href="<?= h($sv0['basePath'] . '/map.php#node=' . rawurlencode($created)) ?>">show on map</a><?php endif; ?>
      <?php endif; endif; ?>
    </div>
  <?php endif; ?>

  <!-- ===== Sites ===== -->
  <?php foreach ($siteView as $key => $sv): ?>
    <div class="site">
      <div class="site-head">
        <div class="name">
          <?php if ($sv['url']): ?>
            <a href="<?= h($sv['url']) ?>" target="_blank" rel="noopener"><?= h($sv['label']) ?></a>
          <?php else: ?>
            <?= h($sv['label']) ?>
          <?php endif; ?>
          <span style="color:var(--dim);font-weight:400;font-size:.7rem"> · <?= h((string)$key) ?></span>
        </div>
        <div class="meta">
          root: <?= $sv['rootOk'] ? '<span class="ok">resolved</span>' : '<span class="no">NOT FOUND</span>' ?><br>
        </div>
      </div>

      <?php if (!$sv['rootOk']): ?>
        <div class="err">
          Root directory not found. Update <code>sites.<?= h((string)$key) ?>.root</code> in
          <code>_config/sites.json</code>.
        </div>
      <?php elseif ($sv['pagesErr']): ?>
        <div class="err">getPages error: <?= h($sv['pagesErr']) ?></div>
      <?php elseif (empty($sv['pages'])): ?>
        <div class="empty">No content pages found (root resolved, but scan returned nothing).</div>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>Title</th>
              <th>Path</th>
              <th>Modified</th>
              <th>Flags</th>
              <th></th>
              <?php if ($aiAvailable): ?><th></th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($sv['pages'] as $rel => $p): ?>
            <tr>
              <td><?= h($p['title'] ?? '—') ?></td>
              <td style="color:var(--dim)"><?= h((string) $rel) ?></td>
              <td style="color:var(--dim)"><?= h(fmtTime($p['mtime'] ?? null)) ?></td>
              <td>
                <?php if (!empty($p['draft'])): ?>
                  <span class="tag draft">draft</span>
                <?php endif; ?>
                <?php if (!empty($p['cms_managed'])): ?>
                  <span class="tag man">managed</span>
                <?php endif; ?>
                <?php if (!empty($p['manually_edited'])): ?>
                  <span class="tag edit">edited</span>
                <?php endif; ?>
              </td>
              <td class="actions-cell">
                <?php if (!empty($p['cms_managed'])): ?>
                  <form method="POST" style="display:inline"
                        onsubmit="return confirm('Move <?= h(addslashes((string)$rel)) ?> to the trash?')">
                    <input type="hidden" name="csrf"      value="<?= h($csrf) ?>">
                    <input type="hidden" name="action"    value="trash">
                    <input type="hidden" name="site"      value="<?= h((string)$key) ?>">
                    <input type="hidden" name="page_path" value="<?= h((string)$rel) ?>">
                    <button type="submit" class="btn danger">trash</button>
                  </form>
                <?php endif; ?>
              </td>
              <?php if ($aiAvailable): ?>
              <td>
                <button class="btn ai-btn"
                        data-site="<?= h((string)$key) ?>"
                        data-path="<?= h((string)$rel) ?>"
                        data-title="<?= h((string)($p['title'] ?? $rel)) ?>"
                        onclick="openAiPanel(this)">AI</button>
              </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

      <!-- ===== Add Page: a starter, its fields, folder + name, draft, own colours (ADR-002 §7.3) ===== -->
      <?php if ($sv['rootOk'] && $sv['starters'] !== []): $fid = 'ap-' . h((string) $key); ?>
        <details class="add-page-wrap" id="<?= $fid ?>-wrap">
          <summary>Add Page</summary>
          <form class="add-page-form" id="<?= $fid ?>" data-folders="<?= h(json_encode($sv['folders'])) ?>" novalidate>
            <noscript><p class="form-hint">Add Page needs JavaScript.</p></noscript>

            <div class="form-row">
              <label>layout</label>
              <div class="ap-layouts" role="radiogroup">
                <?php $first = true; foreach ($sv['starters'] as $name => $st): ?>
                  <label class="ap-layout">
                    <input type="radio" name="layout" value="<?= h($name) ?>" <?= $first ? 'checked' : '' ?>>
                    <span><b><?= h($st['label']) ?></b><?= $st['source'] === 'site' ? ' <span class="tag">site</span>' : '' ?>
                      <?php if ($st['description'] !== ''): ?><br><small><?= h($st['description']) ?></small><?php endif; ?></span>
                  </label>
                <?php $first = false; endforeach; ?>
              </div>
            </div>

            <?php foreach ($sv['starters'] as $name => $st): ?>
              <fieldset class="ap-fields" data-layout="<?= h($name) ?>">
                <?php foreach ($st['fields'] as $f):
                  $fieldId = $fid . '-' . h($name) . '-' . h($f['name']); ?>
                  <div class="form-row">
                    <label for="<?= $fieldId ?>"><?= h($f['label']) ?><?= $f['required'] ? '' : '<span class="opt">(optional)</span>' ?></label>
                    <div>
                      <?php if ($f['type'] === 'textarea' || $f['type'] === 'html'): ?>
                        <textarea id="<?= $fieldId ?>" name="<?= h($f['name']) ?>" data-field <?= $f['required'] ? 'required' : '' ?>
                                  class="<?= $f['type'] === 'html' ? 'ap-html' : '' ?>" placeholder="<?= h($f['default']) ?>"></textarea>
                      <?php else: ?>
                        <input id="<?= $fieldId ?>" type="text" name="<?= h($f['name']) ?>" data-field <?= $f['required'] ? 'required' : '' ?>
                               placeholder="<?= h($f['default']) ?>" <?= $f['name'] === 'title' ? 'data-title' : '' ?>>
                      <?php endif; ?>
                      <?php if ($f['help'] !== '' || $f['type'] === 'html' || $f['type'] === 'url'): ?>
                        <div class="form-hint"><?= h($f['help']) ?>
                          <?= $f['type'] === 'html' ? ' HTML is kept; PHP tags are not (they are written as text).' : '' ?>
                          <?= $f['type'] === 'url' && $f['help'] === '' ? ' A site path (docs/overview.php) or a full https:// link.' : '' ?></div>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </fieldset>
            <?php endforeach; ?>

            <div class="form-row">
              <label for="<?= $fid ?>-dir">folder<span class="opt">(optional)</span></label>
              <div>
                <input id="<?= $fid ?>-dir" type="text" name="dir" list="<?= $fid ?>-dirs" placeholder="(site root)"
                       autocomplete="off" spellcheck="false" pattern="[a-z0-9][a-z0-9\-]*(/[a-z0-9][a-z0-9\-]*)*">
                <datalist id="<?= $fid ?>-dirs"><?php foreach ($sv['folders'] as $d): ?><option value="<?= h($d) ?>"><?php endforeach; ?></datalist>
                <div class="form-hint">an existing folder, or a new one (a–z, 0–9, -)</div>
              </div>
            </div>
            <div class="form-row">
              <label for="<?= $fid ?>-slug">page name</label>
              <div>
                <input id="<?= $fid ?>-slug" type="text" name="slug" required autocomplete="off" spellcheck="false"
                       pattern="[a-z0-9][a-z0-9\-]*" placeholder="from the title">
                <div class="form-hint">creates <code class="ap-path">…</code> · never replaces an existing file</div>
              </div>
            </div>
            <div class="form-row">
              <div></div>
              <label class="ap-check"><input type="checkbox" name="draft" checked> Start as a draft (hidden from the nav until you publish it on the map)</label>
            </div>

            <div class="form-row">
              <div></div>
              <div>
                <label class="ap-check"><input type="checkbox" name="own-theme" class="ap-own"> Give this page its own colours</label>
                <div class="ap-theme" hidden>
                  <div class="ap-preview"><b>Preview</b> <span>Body text on the background.</span> <a>A link in the accent</a></div>
                  <div class="ap-sliders"></div>
                </div>
              </div>
            </div>

            <div class="form-row" style="padding-top:.3rem">
              <div></div>
              <div class="ap-actions">
                <button type="submit" class="btn primary">Create page →</button>
                <span class="ap-msg" role="status"></span>
              </div>
            </div>
          </form>
        </details>
      <?php endif; ?>

    </div>
  <?php endforeach; ?>

  <!-- ===== Token registry ===== -->
  <details class="registry-wrap">
    <summary>
      <span class="reg-title">Theme Token Registry</span>
      <span class="reg-counts">
        <span class="reg-badge bound"><?= $tokenRendered['counts']['bound'] ?> bound</span>
        <?php if ($tokenRendered['counts']['unbound']): ?>
          <span class="reg-badge unbound"><?= $tokenRendered['counts']['unbound'] ?> unbound</span>
        <?php endif; ?>
        <?php if ($tokenRendered['counts']['missing']): ?>
          <span class="reg-badge missing"><?= $tokenRendered['counts']['missing'] ?> missing</span>
        <?php endif; ?>
      </span>
    </summary>
    <table class="reg-table">
      <tbody><?= $tokenRendered['rows'] ?></tbody>
    </table>
  </details>

  <footer>
    Papyr CMS · filesystem is the source of truth · <a href="?msg=" style="color:var(--dim)">clear message</a>
  </footer>

</div><!-- /.wrap -->

<?php include_once __DIR__ . '/templates/partials/hsl-sliders.php'; ?>
<script>
/* Add Page → api/pages.php (ADR-002 §7.1). The colour sliders are the shared
   component (partials/hsl-sliders.php), starting from ThemeStore::defaults(). */
(function () {
  'use strict';
  var CSRF = <?= json_encode($csrf) ?>;
  var DEFAULTS = <?= json_encode($themeDefaults, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
  var LABELS = { accent: 'Accent', accent2: 'Accent 2', bg: 'Background', text: 'Text' };

  function slugify(t) {
    return String(t).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase()
      .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 80);
  }

  document.querySelectorAll('form.add-page-form').forEach(function (form) {
    var dir = form.elements.dir, slug = form.elements.slug, msg = form.querySelector('.ap-msg');
    var pathEl = form.querySelector('.ap-path'), slugTouched = false;

    function active() { return form.querySelector('input[name=layout]:checked').value; }
    function fieldset() { return form.querySelector('.ap-fields[data-layout="' + active() + '"]'); }
    function path() {
      var d = dir.value.trim().replace(/^\/+|\/+$/g, '');
      return (d ? d + '/' : '') + (slug.value.trim() || '…') + '.php';
    }
    function showLayout() {
      form.querySelectorAll('.ap-fields').forEach(function (fs) { fs.disabled = fs.dataset.layout !== active(); });
      var t = fieldset().querySelector('[data-title]');
      if (t && !slugTouched) slug.value = slugify(t.value);
      pathEl.textContent = path();
    }
    form.addEventListener('change', function (e) { if (e.target.name === 'layout') showLayout(); });
    form.addEventListener('input', function (e) {
      if (e.target === slug) slugTouched = slug.value !== '';
      if (e.target.hasAttribute('data-title') && !slugTouched) slug.value = slugify(e.target.value);
      pathEl.textContent = path();
    });
    showLayout();

    // Own colours: an object store, so nothing on the dashboard itself changes.
    var store = {}, tokens = [], built = null;
    Object.keys(DEFAULTS).forEach(function (k) {
      var m = /^(.+)-([hsl])$/.exec(k);
      if (!m) return;
      (store[m[1]] = store[m[1]] || {})[m[2]] = DEFAULTS[k];
      if (tokens.indexOf(m[1]) < 0) tokens.push(m[1]);
    });
    var theme = form.querySelector('.ap-theme'), preview = form.querySelector('.ap-preview');
    function css(n) { var c = store[n] || {}; return 'hsl(' + parseFloat(c.h) + ' ' + parseFloat(c.s) + '% ' + parseFloat(c.l) + '%)'; }
    function paint() {
      preview.style.background = css('bg'); preview.style.color = css('text');
      preview.querySelector('a').style.color = css('accent');
      preview.style.borderColor = css('accent2');
    }
    form.querySelector('.ap-own').addEventListener('change', function () {
      theme.hidden = !this.checked;
      if (this.checked && !built) {
        built = window.PapyrHsl.build(form.querySelector('.ap-sliders'), {
          tokens: tokens,
          label:  function (n) { return LABELS[n] || n; },
          read:   function (n, p) { return store[n][p]; },
          write:  function (n, p, v) { store[n][p] = v; paint(); }
        });
        paint();
      }
    });

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      msg.className = 'ap-msg'; msg.textContent = '';
      if (!form.reportValidity()) return;
      var fields = {};
      fieldset().querySelectorAll('[data-field]').forEach(function (el) { fields[el.name] = el.value; });
      var body = {
        action: 'create', csrf: CSRF, dir: dir.value.trim().replace(/^\/+|\/+$/g, ''), slug: slug.value.trim(),
        layout: active(), fields: fields, draft: form.elements.draft.checked
      };
      if (form.querySelector('.ap-own').checked) {
        body.theme = {};
        tokens.forEach(function (n) { ['h', 's', 'l'].forEach(function (p) { body.theme[n + '-' + p] = String(store[n][p]); }); });
      }
      var btn = form.querySelector('button[type=submit]');
      btn.disabled = true; msg.className = 'ap-msg ok'; msg.textContent = 'Creating…';
      fetch('api/pages.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body)
      })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d.ok) { msg.className = 'ap-msg'; msg.textContent = d.error || 'Something went wrong.'; return; }
          var note = 'Created ' + d.path + (body.draft ? ' as a draft' : '') + (d.warning ? '. ' + d.warning : '');
          location.href = 'index.php?created=' + encodeURIComponent(d.path) + '&msg=' + encodeURIComponent(note);
        })
        .catch(function () { msg.className = 'ap-msg'; msg.textContent = 'Network error. Try again.'; })
        .finally(function () { btn.disabled = false; });
    });
  });
}());
</script>

<?php if ($aiAvailable): ?>
<!-- ===== AI panel drawer ===== -->
<div id="ai-overlay" onclick="closeAiPanel()"></div>
<aside id="ai-panel" aria-label="AI assistant panel">
  <div class="aip-head">
    <span class="aip-head-label">papyr AI</span>
    <button class="aip-close" onclick="closeAiPanel()" aria-label="Close">×</button>
  </div>
  <div class="aip-context">
    <div id="aip-page-title" class="aip-page-title">—</div>
    <div id="aip-page-path" class="aip-page-path"></div>
  </div>
  <div class="aip-actions">
    <button class="btn aip-task" data-task="social_media_draft">Draft Social Post</button>
    <button class="btn aip-task" data-task="meta_description">Suggest Meta</button>
    <button class="btn aip-task" data-task="page_summary">Summarize</button>
    <button class="btn aip-task" data-task="content_review">Review Content</button>
  </div>
  <div id="aip-status" class="aip-status"></div>
  <textarea id="aip-result" class="aip-result" readonly placeholder="Select a page then choose an action…"></textarea>
  <div class="aip-foot">
    <span id="aip-provider" class="aip-provider"></span>
    <button class="btn" onclick="copyAiResult()">Copy</button>
  </div>
</aside>

<script>
(function () {
  var panel    = document.getElementById('ai-panel');
  var overlay  = document.getElementById('ai-overlay');
  var ctx      = { site: '', path: '', title: '' };
  var active   = null; // currently active task button

  window.openAiPanel = function (btn) {
    ctx.site  = btn.dataset.site;
    ctx.path  = btn.dataset.path;
    ctx.title = btn.dataset.title;
    document.getElementById('aip-page-title').textContent = ctx.title;
    document.getElementById('aip-page-path').textContent  = ctx.path;
    document.getElementById('aip-result').value           = '';
    document.getElementById('aip-status').textContent     = '';
    document.getElementById('aip-provider').textContent   = '';
    if (active) active.classList.remove('active');
    panel.classList.add('open');
    overlay.classList.add('open');
  };

  window.closeAiPanel = function () {
    panel.classList.remove('open');
    overlay.classList.remove('open');
  };

  window.copyAiResult = function () {
    var ta = document.getElementById('aip-result');
    if (!ta.value) return;
    if (navigator.clipboard) {
      navigator.clipboard.writeText(ta.value);
    } else {
      ta.select();
      document.execCommand('copy');
    }
  };

  document.querySelectorAll('.aip-task').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (!ctx.path) return;

      if (active) active.classList.remove('active');
      active = this;
      this.classList.add('active');

      var status   = document.getElementById('aip-status');
      var result   = document.getElementById('aip-result');
      var provider = document.getElementById('aip-provider');

      status.textContent  = 'Running…';
      result.value        = '';
      provider.textContent = '';

      var fd = new FormData();
      fd.append('task', this.dataset.task);
      fd.append('site', ctx.site);
      fd.append('path', ctx.path);
      fd.append('csrf', <?= json_encode($csrf) ?>);

      fetch('api/ai.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data.ok) {
            result.value          = data.text || '';
            status.textContent    = '✓ done';
            provider.textContent  = (data.provider || '')
              + (data.channel ? ' (' + data.channel + ')' : '');
          } else {
            status.textContent = '✕ ' + (data.error || 'unknown error');
          }
        })
        .catch(function (err) {
          status.textContent = '✕ fetch failed: ' + err.message;
        });
    });
  });
}());
</script>
<?php endif; ?>

</body>
</html>
