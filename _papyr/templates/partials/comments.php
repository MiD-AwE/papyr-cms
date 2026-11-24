<?php
declare(strict_types=1);

/*
 * PapyrCMS comments partial — invite-only discussion threads, one per block.
 *
 * Pages call papyr_comments('acoustic-larvicide') inside any block; nav.php
 * defines that function (a no-op without the engine) and calls
 * papyr_comments_render() here. The argument is the block's id (letters,
 * digits, - and _), unique on the page.
 *
 * Locked visitors see one short invitation per thread (sites.json
 * "comments": { "prompt" }, see Comments::invitation())
 * with the count and an unlock button; the thread HTML is never sent to them.
 * Without an invite password configured they see only the count. Unlocked participants see the thread
 * and a reply form. Admins also get Remove buttons.
 *
 * Reads request state from the read-only Papyr\Core\Context (papyr()), not
 * from variables in the caller's scope.
 */

use Papyr\Core\Comments;
use Papyr\Core\MemberAuth;

if (!function_exists('papyr_comments_render')) {

    // Older nav.php spines (before the stub moved there) still get papyr_comments().
    if (!function_exists('papyr_comments')) {
        function papyr_comments(string $block): void
        {
            papyr_comments_render($block);
        }
    }

    function papyr_comments_render(string $block): void
    {
        $p       = \Papyr\Core\Context::current();
        $pageKey = $p->page['key'];
        $isAdmin = $p->isAdmin();

        if (!Comments::validKey($block) || !Comments::validKey($pageKey)) {
            return;
        }

        static $assetsSent = false;
        if (!$assetsSent) {
            papyr_comments_assets();
            $assetsSent = true;
        }

        $store = new Comments(\Papyr\Core\Session::siteRoot());
        $h     = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        /* ---------- locked: invitation + count ---------- */
        if (!$p->isMember()) {
            $n = $store->counts($pageKey)[$block] ?? 0;
            if (!$p->site['invites']) {
                // Invitations not set up yet: show the count, offer no unlock.
                if ($n > 0) {
                    echo '<div class="pc-thread pc-locked"><span aria-hidden="true">💬</span> '
                        . $n . ' comment' . ($n === 1 ? '' : 's') . '</div>';
                }
                return;
            }
            $inv   = $p->site['comments'] ?? Comments::invitation([]);
            $count = $n > 0 ? $n . ' comment' . ($n === 1 ? '' : 's') . ' · ' : '';
            echo '<div class="pc-thread pc-locked"><p class="pc-invite"><span aria-hidden="true">💬</span> '
                . '<span class="pc-count">' . $h($count) . '</span>' . $h($inv['prompt']) . ' ' . $h($inv['howTo'])
                . '<br><button type="button" class="pc-unlock-link" onclick="papyrOpenUnlock()">Have an invite? Unlock</button></p></div>';
            return;
        }

        /* ---------- unlocked: thread + form ---------- */
        $thread = $store->thread($pageKey, $block);
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
            echo papyr_comment_html($c, $isAdmin, $hidden);
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
    /* Endpoint, page key and CSRF token come from window.PAPYR (nav.php). */
    function papyr_comments_assets(): void
    {
        ?>
<style>
.pc-thread { margin-top: 1rem; padding-top: .8rem; border-top: 1px dashed var(--border-bright); font-size: .92rem; }
.pc-unlock-link, .pc-more { background: none; border: 0; padding: 0; font: inherit; color: var(--accent); cursor: pointer; text-align: left; }
.pc-unlock-link:hover, .pc-more:hover { text-decoration: underline; }
.pc-invite { margin: 0; font-size: .85rem; line-height: 1.45; color: var(--text-dim); }
.pc-invite .pc-unlock-link { margin-top: .3rem; }
.pc-invite .pc-count { color: var(--text); }
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
  var P   = window.PAPYR || { pageKey: '', csrf: '', api: {} };
  var CFG = { api: P.api.comments, page: P.pageKey, csrf: P.csrf };

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
