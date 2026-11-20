<?php
declare(strict_types=1);

/*
 * PapyrCMS comments partial — invite-only discussion threads, one per block.
 *
 * nav.php includes this file, so every page that includes nav.php can call:
 *
 *     <?php papyr_comments('acoustic-larvicide'); ?>
 *
 * inside any block. The argument is the block's id (letters, digits, - and _).
 *
 * Locked visitors see only a count ("4 comments · Unlock to read"); the
 * thread HTML is never sent to them. Unlocked participants see the thread
 * and a reply form. Admins also get Remove buttons.
 *
 * Reads request state from the read-only Papyr\Core\Context (papyr()), not
 * from variables in the caller's scope.
 */

use Papyr\Core\Comments;
use Papyr\Core\MemberAuth;

if (!function_exists('papyr_comments')) {

    function papyr_comments(string $block): void
    {
        $p                 = \Papyr\Core\Context::current();
        $_papyrSiteRoot    = $p->siteRoot;
        $_papyrBasePath    = $p->basePath;
        $_papyrPageKey     = $p->pageKey;
        $_papyrIsMember    = $p->isMember();
        $_papyrIsAdmin     = $p->isAdmin();
        $_papyrCsrf        = $p->csrf;
        $_papyrInviteReady = $p->inviteReady;

        if (!Comments::validKey($block) || !Comments::validKey((string) $_papyrPageKey)) {
            return;
        }

        static $assetsSent = false;
        if (!$assetsSent) {
            papyr_comments_assets((string) $_papyrBasePath, (string) $_papyrPageKey, (string) $_papyrCsrf);
            $assetsSent = true;
        }

        $store = new Comments((string) $_papyrSiteRoot);
        $h     = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        /* ---------- locked: count only ---------- */
        if (!$_papyrIsMember) {
            $n = $store->counts((string) $_papyrPageKey)[$block] ?? 0;
            $label = $n > 0
                ? $n . ' comment' . ($n === 1 ? '' : 's') . ' · Unlock to read…'
                : 'Join the discussion · Unlock to comment…';
            if (!$_papyrInviteReady) {
                // Invitations not set up yet: show the count, offer no unlock.
                if ($n > 0) {
                    echo '<div class="pc-thread pc-locked"><span aria-hidden="true">💬</span> '
                        . $n . ' comment' . ($n === 1 ? '' : 's') . '</div>';
                }
                return;
            }
            echo '<div class="pc-thread pc-locked"><button type="button" class="pc-unlock-link" onclick="papyrOpenUnlock()">'
                . '<span aria-hidden="true">💬</span> ' . $h($label) . '</button></div>';
            return;
        }

        /* ---------- unlocked: thread + form ---------- */
        $thread = $store->thread((string) $_papyrPageKey, $block);
        $n      = count($thread);
        $shown  = 3; // newest N visible; older behind "See earlier"

        echo '<section class="pc-thread" data-block="' . $h($block) . '" aria-label="Discussion">';
        echo '<h4 class="pc-head"><span aria-hidden="true">💬</span> <span class="pc-count">'
            . ($n ? $n . ' comment' . ($n === 1 ? '' : 's') : 'Start the discussion') . '</span></h4>';

        if ($n > $shown) {
            echo '<button type="button" class="pc-more" onclick="papyrShowEarlier(this)">See '
                . ($n - $shown) . ' earlier comment' . ($n - $shown === 1 ? '' : 's') . '…</button>';
        }

        echo '<ol class="pc-list">';
        foreach ($thread as $i => $c) {
            $hidden = ($n > $shown && $i < $n - $shown) ? ' hidden' : '';
            echo papyr_comment_html($c, (bool) $_papyrIsAdmin, $hidden);
        }
        echo '</ol>';

        $name = MemberAuth::displayName();
        echo '<form class="pc-form" onsubmit="return papyrPostComment(event, this)">'
            . '<input class="pc-name" name="name" maxlength="' . Comments::MAX_NAME . '" placeholder="Your name" value="' . $h($name) . '" required>'
            . '<textarea name="body" rows="3" maxlength="' . Comments::MAX_BODY . '" placeholder="Add to the discussion…" required></textarea>'
            . '<div class="pc-actions"><span class="pc-msg" role="status"></span><button type="submit">Post</button></div>'
            . '</form>';
        echo '</section>';
    }

    function papyr_comment_html(array $c, bool $admin, string $hiddenAttr = ''): string
    {
        $h    = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $ts   = (int) ($c['ts'] ?? 0);
        $when = $ts ? date('M j, Y · g:i a', $ts) : '';
        $del  = $admin
            ? '<button type="button" class="pc-del" onclick="papyrDeleteComment(this)" title="Remove comment">Remove</button>'
            : '';
        return '<li class="pc-item" data-id="' . $h((string) ($c['id'] ?? '')) . '"' . $hiddenAttr . '>'
            . '<div class="pc-meta"><strong>' . $h((string) ($c['name'] ?? '')) . '</strong>'
            . '<time datetime="' . ($ts ? date('c', $ts) : '') . '">' . $h($when) . '</time>' . $del . '</div>'
            . '<p class="pc-body">' . $h((string) ($c['body'] ?? '')) . '</p></li>';
    }

    /* Styles + script, sent once per page on the first papyr_comments() call. */
    function papyr_comments_assets(string $basePath, string $pageKey, string $csrf): void
    {
        $cfg = json_encode([
            'api'  => $basePath . '/_papyr/api/comments.php',
            'page' => $pageKey,
            'csrf' => $csrf,
        ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
        ?>
<style>
.pc-thread { margin-top: 1rem; padding-top: .8rem; border-top: 1px dashed var(--border-bright); font-size: .92rem; }
.pc-unlock-link, .pc-more { background: none; border: 0; padding: 0; font: inherit; color: var(--accent); cursor: pointer; text-align: left; }
.pc-unlock-link:hover, .pc-more:hover { text-decoration: underline; }
.pc-head { margin: 0 0 .5rem; font-size: .8rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--text-dim); }
.pc-more { font-size: .85rem; margin-bottom: .5rem; }
.pc-list { list-style: none; margin: 0 0 .7rem; padding: 0; display: grid; gap: .6rem; }
.pc-item { background: color-mix(in srgb, var(--accent) 5%, transparent); border-left: 2px solid var(--accent); border-radius: 0 8px 8px 0; padding: .5rem .7rem; }
.pc-meta { display: flex; gap: .6rem; align-items: baseline; flex-wrap: wrap; font-size: .8rem; }
.pc-meta time { color: var(--text-dim); }
.pc-del { margin-left: auto; background: none; border: 0; color: var(--text-dim); font-size: .75rem; cursor: pointer; }
.pc-del:hover { color: hsl(5 80% 66%); }
.pc-body { margin: .25rem 0 0; white-space: pre-wrap; overflow-wrap: anywhere; }
.pc-form { display: grid; gap: .45rem; }
.pc-form input, .pc-form textarea { width: 100%; box-sizing: border-box; font: inherit; color: var(--text); background: var(--bg); border: 1px solid var(--border-bright); border-radius: 8px; padding: .45rem .6rem; }
.pc-form textarea { resize: vertical; min-height: 2.4rem; }
/* Compact until used: one "Add to the discussion…" line per block; name + Post
   appear on focus or once something is typed. Browsers without :has() show all. */
.pc-form:not(:focus-within):has(textarea:placeholder-shown) .pc-name,
.pc-form:not(:focus-within):has(textarea:placeholder-shown) .pc-actions { display: none; }
.pc-form:not(:focus-within):has(textarea:placeholder-shown) textarea { min-height: 0; height: 2.4rem; resize: none; }
.pc-form input:focus, .pc-form textarea:focus { outline: 2px solid var(--accent); outline-offset: 1px; }
.pc-actions { display: flex; align-items: center; justify-content: flex-end; gap: .7rem; }
.pc-msg { font-size: .8rem; color: var(--text-dim); margin-right: auto; }
.pc-form button { font: inherit; font-weight: 700; padding: .4rem 1rem; border-radius: 999px; border: 0; background: var(--accent); color: var(--bg-deep, #000); cursor: pointer; }
.pc-form button:disabled { opacity: .5; cursor: wait; }
</style>
<script>
(function () {
  'use strict';
  var CFG = <?= $cfg ?>;

  function plural(n) { return n ? n + ' comment' + (n === 1 ? '' : 's') : 'Start the discussion'; }

  function send(payload) {
    payload.csrf = CFG.csrf;
    payload.page = CFG.page;
    return fetch(CFG.api, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    }).then(function (r) { return r.json(); });
  }

  function itemEl(c) {
    var li = document.createElement('li'); li.className = 'pc-item'; li.dataset.id = c.id;
    var meta = document.createElement('div'); meta.className = 'pc-meta';
    var who = document.createElement('strong'); who.textContent = c.name;
    var t = document.createElement('time'); t.dateTime = new Date(c.ts * 1000).toISOString();
    t.textContent = 'just now';
    var p = document.createElement('p'); p.className = 'pc-body'; p.textContent = c.body;
    meta.append(who, t); li.append(meta, p);
    return li;
  }

  window.papyrShowEarlier = function (btn) {
    btn.closest('.pc-thread').querySelectorAll('.pc-item[hidden]').forEach(function (li) { li.hidden = false; });
    btn.remove();
  };

  window.papyrPostComment = function (ev, form) {
    ev.preventDefault();
    var thread = form.closest('.pc-thread'), msg = form.querySelector('.pc-msg'), btn = form.querySelector('button');
    btn.disabled = true; msg.textContent = 'Posting…';
    send({ action: 'add', block: thread.dataset.block, name: form.name.value, body: form.body.value })
      .then(function (res) {
        if (!res.ok) { msg.textContent = res.error || 'Could not post.'; return; }
        thread.querySelector('.pc-list').appendChild(itemEl(res.comment));
        thread.querySelector('.pc-count').textContent = plural(res.count);
        form.body.value = ''; msg.textContent = 'Posted. Thank you!';
        thread.querySelectorAll('.pc-more').forEach(function (b) { b.remove(); });
        thread.querySelectorAll('.pc-item[hidden]').forEach(function (li) { li.hidden = false; });
      })
      .catch(function () { msg.textContent = 'Network error — try again.'; })
      .finally(function () { btn.disabled = false; });
    return false;
  };

  window.papyrDeleteComment = function (btn) {
    if (!confirm('Remove this comment permanently?')) return;
    var li = btn.closest('.pc-item'), thread = btn.closest('.pc-thread');
    send({ action: 'delete', block: thread.dataset.block, id: li.dataset.id }).then(function (res) {
      if (res.ok) { li.remove(); thread.querySelector('.pc-count').textContent = plural(res.count); }
      else { alert(res.error || 'Could not remove.'); }
    });
  };
}());
</script>
        <?php
    }
}
