<?php
declare(strict_types=1);

/* Auth gate — admin session required for the entire dashboard, including
   the POST handlers below. A POST can be sent directly without going
   through the UI, so this must run before any output or action handling. */
session_start();
if (!($_SESSION['papyr_admin'] ?? false)) {
    header('Location: login.php');
    exit;
}

use Papyr\Core\NavBuilder;
use Papyr\Core\PageWriter;
use Papyr\Core\TemplateEngine;
use Papyr\Core\TokenRegistry;

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/core/TemplateEngine.php';
require_once __DIR__ . '/core/PageWriter.php';
require_once __DIR__ . '/core/TokenRegistry.php';

/* ====================================================================
   POST handler — must run before any output so header() still works.
   ==================================================================== */
$flash     = null; // ['msg' => string, 'type' => 'ok'|'bad']
$postError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action      = $_POST['action']    ?? '';
    $formSiteKey = (string) ($_POST['site'] ?? '');
    $formPath    = trim((string) ($_POST['page_path'] ?? ''));

    if (!isset($sites[$formSiteKey])) {
        $postError = 'Unknown site key.';
    } elseif ($formPath === '') {
        $postError = 'Page path is required.';
    } else {
        try {
            $writer = new PageWriter($scanner, $formSiteKey, $sites[$formSiteKey], __DIR__);

            if ($action === 'create') {
                $abs  = $writer->write($formPath, $_POST['vars'] ?? []);
                $redir = 'index.php?msg=' . urlencode("Created: {$formPath}");
                header("Location: {$redir}");
                exit;

            } elseif ($action === 'delete') {
                $writer->delete($formPath);
                $redir = 'index.php?msg=' . urlencode("Deleted: {$formPath}");
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

    // Template var names for the Add Page form
    $templateVarNames = [];
    $templateName     = $site['template'] ?? '';
    if ($templateName !== '') {
        try {
            $engine = new TemplateEngine([__DIR__ . '/templates']);
            $templateVarNames = $engine->extractVarNames($templateName);
        } catch (\Throwable $e) {
            // Template missing — form will still render without auto-fields
            error_log("[papyr index] extractVarNames($templateName) failed: " . $e->getMessage());
        }
    }

    $siteView[$key] = [
        'label'            => $site['label'] ?? $key,
        'url'              => $site['url']   ?? '',
        'root'             => $root,
        'rootOk'           => $rootOk,
        'pages'            => $pages,
        'tree'             => $tree,
        'pagesErr'         => $pagesErr,
        'templateVarNames' => $templateVarNames,
        'template'         => $templateName,
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
    .form-hint{grid-column:2;font-size:.64rem;color:var(--dim);margin-top:-.3rem}
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
  <div class="sub">v<?= h($cms['version'] ?? '?') ?> · <a href="login.php?action=logout" style="color:var(--dim)">logout</a></div>

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
          <code>config/sites.json</code>.
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
                        onsubmit="return confirm('Delete <?= h(addslashes((string)$rel)) ?>?')">
                    <input type="hidden" name="action"    value="delete">
                    <input type="hidden" name="site"      value="<?= h((string)$key) ?>">
                    <input type="hidden" name="page_path" value="<?= h((string)$rel) ?>">
                    <button type="submit" class="btn danger">delete</button>
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

      <!-- ===== Add Page form (works without JS via <details>) ===== -->
      <?php if ($sv['rootOk'] && $sv['template'] !== ''): ?>
        <details class="add-page-wrap">
          <summary>Add Page</summary>
          <form class="add-page-form" method="POST">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="site"   value="<?= h((string)$key) ?>">

            <div class="form-row">
              <label>page path</label>
              <div>
                <input type="text" name="page_path"
                       placeholder="about.php" required
                       pattern="[^/\.\.].*\.php"
                       title="Relative path ending in .php — no .. — e.g. about.php or docs/overview.php">
                <div class="form-hint">relative to site root · e.g. <code>about.php</code> or <code>docs/overview.php</code></div>
              </div>
            </div>

            <?php foreach ($sv['templateVarNames'] as $varName):
              $optional = ($varName === 'page_class');
              $isArea   = ($varName === 'body_content'); ?>
              <div class="form-row">
                <label>
                  <?= h($varName) ?>
                  <?php if ($optional): ?><span class="opt">(optional)</span><?php endif; ?>
                </label>
                <?php if ($isArea): ?>
                  <textarea name="vars[<?= h($varName) ?>]"
                            placeholder="HTML or plain text content…"
                            <?= $optional ? '' : 'required' ?>></textarea>
                <?php else: ?>
                  <input type="text" name="vars[<?= h($varName) ?>]"
                         placeholder="<?= h($varName) ?>"
                         <?= $optional ? '' : 'required' ?>>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>

            <div class="form-row" style="padding-top:.3rem">
              <div></div>
              <div>
                <button type="submit" class="btn primary">Create Page →</button>
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
