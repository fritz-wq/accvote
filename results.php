<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';

startSecureSession(); // only needed so we can show a dashboard link if logged in

$pdo = getDbConnection();
syncElectionStatuses($pdo);

$ssgLogo = getSiteSetting($pdo, 'ssg_logo');
$departmentLogos = getDepartmentLogos($pdo);

// Inline SVG shown when a candidate has no photo on file.
const AVATAR_FALLBACK = 'data:image/svg+xml;base64,'
    . 'PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCA2NCA2NCI+PHJlY3Qgd2lkdGg9IjY0IiBoZWlnaHQ9IjY0IiBmaWxsPSIjZWZlY2UzIi8+PGNpcmNsZSBjeD0iMzIiIGN5PSIyNCIgcj0iMTAiIGZpbGw9IiNiNmMwY2UiLz48cGF0aCBkPSJNMTIgNTZhMjAgMjAgMCAwIDEgNDAgMCIgZmlsbD0iI2I2YzBjZSIvPjwvc3ZnPg==';

function statusMeta(string $status): array
{
    switch ($status) {
        case 'ongoing':  return ['Ongoing', 'ongoing'];
        case 'paused':   return ['Paused', 'paused'];
        case 'scheduled':return ['Upcoming', 'scheduled'];
        case 'draft':    return ['Upcoming', 'scheduled'];
        default:         return ['Ended', 'closed'];
    }
}

/**
 * Builds everything that lives inside the #resultsRoot container:
 * every visible election's cards. Rendered once server-side for the
 * initial page, then re-fetched every 15s via ?partial=1 so results
 * update without a full page reload (and without losing scroll position).
 */
function renderResultsMarkup(PDO $pdo): string
{
    $ssgLogo = getSiteSetting($pdo, 'ssg_logo');
    $departmentLogos = getDepartmentLogos($pdo);

    $elections = $pdo->query(
        "SELECT * FROM elections WHERE status != 'draft' ORDER BY start_date ASC"
    )->fetchAll();

    $out = '';

    foreach ($elections as $e) {
        $visible = $e['results_visibility'] === 'always'
            || ($e['results_visibility'] === 'after' && in_array($e['status'], ['closed', 'archived'], true));
        if (!$visible) {
            continue;
        }

        $posStmt = $pdo->prepare('
            SELECT ep.position_id, p.title
            FROM election_positions ep
            JOIN positions p ON p.id = ep.position_id
            WHERE ep.election_id = :eid
            ORDER BY ep.id ASC
        ');
        $posStmt->execute(['eid' => $e['id']]);
        $positions = $posStmt->fetchAll();

        // Turnout: distinct voters whose votes land on this election's positions,
        // against the number of students actually eligible for this scope.
        $turnoutStmt = $pdo->prepare('
            SELECT COUNT(DISTINCT v.user_id)
            FROM votes v
            JOIN election_positions ep ON ep.position_id = v.position_id
            WHERE ep.election_id = :eid
        ');
        $turnoutStmt->execute(['eid' => $e['id']]);
        $ballotsCast = (int) $turnoutStmt->fetchColumn();
        $eligibleStmt = $e['type'] === 'DSG' && !empty($e['department'])
            ? $pdo->prepare("SELECT COUNT(*) FROM users WHERE registration_status != 'unregistered' AND department = :d")
            : null;
        if ($eligibleStmt) {
            $eligibleStmt->execute(['d' => $e['department']]);
        } else {
            $eligibleStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE registration_status != 'unregistered'");
            $eligibleStmt->execute();
        }
        $eligible = (int) $eligibleStmt->fetchColumn();

        [$statusText, $statusClass] = statusMeta($e['status']);

        $out .= '<section class="elec">';
        $out .= '<header class="elec-head reveal">';
        $out .= '<div class="elec-logo">' . electionLogoHtml($e['type'], $e['department'], $ssgLogo, $departmentLogos) . '</div>';
        $out .= '<div class="elec-title"><h2>' . h($e['name']) . '</h2><div class="elec-sub">'
            . ($e['type'] === 'SSG'
                ? 'Supreme Student Government'
                : 'Department Student Government' . ($e['department'] ? ' &mdash; ' . h($e['department']) : ''));
        try {
            $start = new DateTime($e['start_date']);
            $end = new DateTime($e['end_date']);
            $out .= ' <span class="elec-dates">&middot; ' . date('M j', $start->getTimestamp()) . '&ndash;' . date('M j, Y', $end->getTimestamp()) . '</span>';
        } catch (Exception $ex) { /* dates are cosmetic here */ }
        $out .= '</div></div>';
        $out .= '<span class="status ' . $statusClass . '"><span class="dot"></span>' . $statusText . '</span>';
        $out .= '</header>';

        $out .= '<div class="turnout reveal"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="t-icon"><rect x="4" y="4" width="16" height="17" rx="2"/><path d="M8 4V2h8v2"/><path d="m9 12 2 2 4-4"/></svg>'
            . '<b>' . $ballotsCast . '</b>&nbsp;ballot' . ($ballotsCast === 1 ? '' : 's') . ' cast'
            . ($eligible > 0 ? ' <span class="t-pct">(' . round($ballotsCast / $eligible * 100) . '% of ' . $eligible . ' eligible voters)</span>' : '')
            . '</div>';

        if (empty($positions)) {
            $out .= '<div class="empty-card reveal">No positions were configured for this election.</div>';
        }

        foreach ($positions as $position) {
            $stmt = $pdo->prepare('
                SELECT c.id, c.name, c.photo, c.party, c.course,
                       COUNT(v.id) AS vote_count
                FROM candidates c
                LEFT JOIN votes v ON v.candidate_id = c.id
                WHERE c.position_id = :position_id
                GROUP BY c.id, c.name, c.photo, c.party, c.course
                ORDER BY vote_count DESC, c.name ASC
            ');
            $stmt->execute(['position_id' => $position['position_id']]);
            $candidates = $stmt->fetchAll();
            $totalVotes = array_sum(array_column($candidates, 'vote_count'));

            $topCount = count($candidates) ? (int) $candidates[0]['vote_count'] : 0;

            $out .= '<section class="pos-block reveal">';
            $out .= '<h3>' . h($position['title']) . '</h3>';
            $out .= '<p class="pos-total">' . (int) $totalVotes . ' vote' . ((int) $totalVotes === 1 ? '' : 's') . ' cast for this position</p>';

            if (empty($candidates)) {
                $out .= '<div class="empty-card">No candidates registered for this position.</div>';
            } else {
                $out .= '<ul class="cand-list">';
                foreach ($candidates as $i => $candidate) {
                    $voteCount = (int) $candidate['vote_count'];
                    $percentage = $totalVotes > 0 ? round(($voteCount / $totalVotes) * 100, 1) : 0;
                    $isLeader = $voteCount > 0 && $voteCount === $topCount;
                    $photo = !empty($candidate['photo']) ? $candidate['photo'] : AVATAR_FALLBACK;

                    $subParts = [];
                    if (!empty($candidate['party'])) { $subParts[] = h($candidate['party']); }
                    if (!empty($candidate['course'])) { $subParts[] = h($candidate['course']); }

                    $out .= '<li class="cand-row' . ($isLeader ? ' leader' : '') . '">';
                    $out .= '<span class="rank">' . ($i + 1) . '</span>';
                    $out .= '<img class="avatar" src="' . h($photo) . '" alt="" loading="lazy" onerror="this.onerror=null;this.src=\'' . AVATAR_FALLBACK . '\'">';
                    $out .= '<div class="cand-main">';
                    $out .= '<div class="line1"><span class="name">' . h($candidate['name']);
                    if ($isLeader) {
                        $out .= ' <span class="lead-badge"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Leading</span>';
                    }
                    $out .= '</span><span class="count">' . $voteCount . ' &middot; ' . $percentage . '%</span></div>';
                    if ($subParts) {
                        $out .= '<div class="line2">' . implode(' &middot; ', $subParts) . '</div>';
                    }
                    $out .= '<div class="bar-track"><div class="bar-fill" data-w="' . $percentage . '" style="width:' . $percentage . '%"></div></div>';
                    $out .= '</div>';
                    $out .= '</li>';
                }
                $out .= '</ul>';
            }
            $out .= '</section>';
        }

        $out .= '</section>';
    }

    if ($out === '') {
        $out = '<div class="empty-card big reveal">'
            . '<div class="empty-seal">?</div><h2>No results are available yet</h2>'
            . '<p>Results appear here once an election&rsquo;s visibility window opens &mdash; check back soon.</p></div>';
    }

    return $out;
}

// Partial mode: the client-side poller replaces only this fragment every
// cycle, so the page itself never reloads (scroll position, theme, and the
// "updated Ns ago" ticker all survive).
if (isset($_GET['partial'])) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<div id="resultsRoot" data-root>' . renderResultsMarkup($pdo) . '</div>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Live Results | School Elections</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<style>
/* Same civic-ballot identity as landingpage.php */
:root{
    --navy-950:#081428; --navy-900:#0b1f3a; --navy-800:#12294a;
    --lime:#84cc16; --lime-light:#c8f26a; --lime-dark:#65a30d;
    --gold:#d4af37; --gold-light:#f0dca0;
    --paper:#f7f5ef; --paper-dim:#efece3;
    --ink:#0f172a; --muted:#5c6b81;
    --display:'Sora',system-ui,sans-serif;
    --body:'Inter',system-ui,-apple-system,sans-serif;
    --mono:'IBM Plex Mono',ui-monospace,monospace;
}
*{box-sizing:border-box;}
body{margin:0;font-family:var(--body);background:var(--paper);color:var(--ink);-webkit-font-smoothing:antialiased;}
img{max-width:100%;}
a{color:inherit;}
.wrap{max-width:900px;margin:0 auto;padding:0 1.5rem;}
@media(max-width:640px){.wrap{padding:0 1rem;}}

.reveal{opacity:0;transform:translateY(14px);transition:opacity .6s ease,transform .6s ease;}
.reveal.in-view{opacity:1;transform:none;}
@media(prefers-reduced-motion:reduce){.reveal{opacity:1;transform:none;transition:none;}}

/* ---- Nav ---- */
.nav{position:sticky;top:0;z-index:100;background:rgba(11,31,58,.88);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);border-bottom:1px solid rgba(212,175,55,.25);}
.nav-inner{display:flex;align-items:center;justify-content:space-between;padding:.85rem 0;gap:1rem;}
.brand{display:flex;align-items:center;gap:.7rem;text-decoration:none;min-width:0;}
.brand img{height:36px;width:36px;border-radius:50%;background:#fff;flex-shrink:0;}
.brand .name{color:#fff;font-weight:700;font-size:.92rem;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.brand .name small{display:block;color:var(--gold-light);font-weight:500;font-size:.64rem;letter-spacing:.08em;text-transform:uppercase;}
.nav-actions{display:flex;align-items:center;gap:.5rem;flex-shrink:0;}
.nav-actions a{padding:.45rem .9rem;border-radius:999px;font-weight:600;font-size:.8rem;text-decoration:none;color:#dbe4f2;border:1.5px solid rgba(212,175,55,.55);transition:background .15s;}
.nav-actions a:hover{background:rgba(212,175,55,.15);color:#fff;}
.nav-actions a.solid{background:var(--lime);border-color:var(--lime);color:#052e16;}
.nav-actions a.solid:hover{background:var(--lime-light);}

/* ---- Page head / live strip ---- */
.page-head{padding:2.75rem 0 1.25rem;display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:1rem;}
.page-head h1{font-family:var(--display);font-weight:800;font-size:clamp(1.8rem,4vw,2.6rem);margin:0;color:var(--navy-900);letter-spacing:-.02em;}
.page-head p{margin:.35rem 0 0;color:var(--muted);font-size:.95rem;}
.live-chip{display:inline-flex;align-items:center;gap:.55rem;font-family:var(--mono);font-size:.74rem;font-weight:600;letter-spacing:.06em;text-transform:uppercase;background:#fff;border:1px solid var(--paper-dim);border-radius:999px;padding:.55rem 1rem;box-shadow:0 2px 10px rgba(15,23,42,.05);white-space:nowrap;}
.live-dot{width:8px;height:8px;border-radius:50%;background:var(--lime-dark);animation:pulse 1.6s ease-in-out infinite;}
@keyframes pulse{0%,100%{box-shadow:0 0 0 0 rgba(101,163,13,.45);}50%{box-shadow:0 0 0 6px rgba(101,163,13,0);}}
@media(prefers-reduced-motion:reduce){.live-dot{animation:none;}}

/* ---- Election sections ---- */
#resultsRoot{display:flex;flex-direction:column;gap:2.5rem;padding-bottom:3.5rem;}
.elec{background:#fff;border-radius:18px;padding:1.75rem 1.75rem 1.5rem;box-shadow:0 4px 22px rgba(11,31,58,.07);border-top:4px solid var(--gold);}
.elec-head{display:flex;align-items:center;gap:1rem;margin-bottom:1rem;flex-wrap:wrap;}
.elec-logo{width:52px;height:52px;border-radius:50%;background:var(--paper-dim);overflow:hidden;flex-shrink:0;display:flex;align-items:center;justify-content:center;box-shadow:inset 0 0 0 1px rgba(15,23,42,.06);}
.elec-logo img{width:100%;height:100%;object-fit:cover;background:#fff;}
.elec-logo svg{width:55%;height:55%;color:var(--muted);}
.elec-title{flex:1;min-width:200px;}
.elec-title h2{font-family:var(--display);font-weight:700;font-size:1.3rem;margin:0 0 .15rem;color:var(--ink);line-height:1.2;}
.elec-sub{color:var(--muted);font-size:.82rem;}
.elec-dates{font-family:var(--mono);font-size:.72rem;color:#94a3b8;}
.status{display:inline-flex;align-items:center;gap:.4rem;font-family:var(--mono);font-size:.66rem;font-weight:600;letter-spacing:.06em;text-transform:uppercase;padding:.32rem .75rem;border-radius:999px;flex-shrink:0;}
.status .dot{width:6px;height:6px;border-radius:50%;}
.status.ongoing{background:rgba(34,197,94,.12);color:#15803d;} .status.ongoing .dot{background:#22c55e;}
.status.scheduled{background:rgba(99,102,241,.12);color:#3730a3;} .status.scheduled .dot{background:#6366f1;}
.status.paused{background:rgba(245,158,11,.15);color:#92400e;} .status.paused .dot{background:#f59e0b;}
.status.closed{background:rgba(239,68,68,.1);color:#b91c1c;} .status.closed .dot{background:#ef4444;}

.turnout{display:flex;align-items:center;gap:.55rem;font-size:.86rem;color:var(--muted);background:var(--paper);border:1px dashed var(--paper-dim);border-radius:10px;padding:.6rem .9rem;margin-bottom:1.25rem;}
.turnout b{color:var(--navy-900);font-weight:700;}
.t-icon{width:16px;height:16px;color:var(--gold);flex-shrink:0;}
.t-pct{font-family:var(--mono);font-size:.74rem;}

.pos-block{margin:1.4rem 0 0;}
.pos-block h3{font-family:var(--mono);font-size:.78rem;letter-spacing:.1em;text-transform:uppercase;color:var(--navy-800);margin:0 0 .1rem;padding-top:1.1rem;border-top:1px solid var(--paper-dim);}
.pos-block:first-of-type h3{border-top:none;}
.pos-total{margin:0 0 .8rem;font-size:.76rem;color:var(--muted);}

.cand-list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:.65rem;}
.cand-row{display:flex;gap:.85rem;align-items:flex-start;background:var(--paper);border:1px solid transparent;border-radius:12px;padding:.75rem .9rem;transition:border-color .3s,box-shadow .3s;}
.cand-row.leader{background:#fff;border-color:rgba(212,175,55,.55);box-shadow:0 3px 14px rgba(212,175,55,.14);}
.rank{width:24px;height:24px;border-radius:50%;background:var(--paper-dim);color:var(--muted);font-family:var(--mono);font-size:.7rem;font-weight:600;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:.35rem;}
.cand-row.leader .rank{background:var(--gold);color:#fff;}
.avatar{width:44px;height:44px;border-radius:50%;object-fit:cover;background:var(--paper-dim);flex-shrink:0;box-shadow:0 0 0 2px #fff,0 0 0 3px var(--paper-dim);}
.cand-row.leader .avatar{box-shadow:0 0 0 2px #fff,0 0 0 3.5px var(--gold);}
.cand-main{flex:1;min-width:0;}
.line1{display:flex;justify-content:space-between;align-items:center;gap:.75rem;}
.name{font-weight:700;font-size:.92rem;color:var(--ink);}
.count{font-family:var(--mono);font-size:.78rem;color:var(--muted);white-space:nowrap;font-weight:600;}
.cand-row.leader .count{color:var(--lime-dark);}
.line2{font-size:.72rem;color:var(--muted);margin-top:.1rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.lead-badge{display:inline-flex;align-items:center;gap:.28rem;vertical-align:middle;background:rgba(132,204,22,.14);color:var(--lime-dark);font-size:.62rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;border-radius:999px;padding:.18rem .55rem;transform:translateY(-1px);}
.lead-badge svg{width:10px;height:10px;}
.bar-track{height:8px;border-radius:99px;background:var(--paper-dim);margin-top:.55rem;overflow:hidden;}
.bar-fill{height:100%;border-radius:99px;background:linear-gradient(90deg,#a3b8d4,var(--navy-700));transform-origin:left;animation:growBar .9s cubic-bezier(.25,1,.4,1) both;}
.cand-row.leader .bar-fill{background:linear-gradient(90deg,var(--lime),var(--lime-dark));}
@keyframes growBar{from{transform:scaleX(0);}to{transform:scaleX(1);}}
@media(prefers-reduced-motion:reduce){.bar-fill{animation:none;}}

.empty-card{background:#fff;border:1.5px dashed var(--paper-dim);border-radius:12px;padding:1.4rem;text-align:center;color:var(--muted);font-size:.9rem;}
.empty-card.big{padding:4rem 2rem;margin-top:2rem;}
.empty-card.big h2{font-family:var(--display);color:var(--navy-900);margin:.9rem 0 .3rem;}
.empty-card.big p{margin:0;}
.empty-seal{width:64px;height:64px;margin:0 auto;border-radius:50%;border:2px solid var(--gold);color:var(--gold);font-family:var(--display);font-weight:800;font-size:1.6rem;display:flex;align-items:center;justify-content:center;}

footer{background:var(--navy-950);color:#aab6c8;text-align:center;padding:1.6rem 1rem;font-size:.8rem;}
footer b{color:#fff;}
a:focus-visible,button:focus-visible{outline:2px solid var(--lime);outline-offset:2px;}
</style>
</head>
<body>
<nav class="nav">
    <div class="wrap nav-inner">
        <a href="landingpage.php" class="brand">
            <img src="assets/logo.png" alt="School seal">
            <span class="name">Aklan Catholic College<small>Live Election Results</small></span>
        </a>
        <div class="nav-actions">
            <?php if (!empty($_SESSION['user_id'])): ?>
                <a href="dashboard.php" class="solid">Dashboard</a>
            <?php else: ?>
                <a href="login.php" class="solid">Student Login</a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<main class="wrap">
    <div class="page-head">
        <div>
            <h1>Vote Wisely!</h1>
            <p>Official tallies, updated automatically.</p>
        </div>
        <span class="live-chip" title="Results refresh automatically every 15 seconds">
            <span class="live-dot"></span> Live &middot; updated <span id="lastUpdated">just now</span>
        </span>
    </div>

    <div id="resultsRoot"><?= renderResultsMarkup($pdo) ?></div>
</main>

<footer>
    <b>Aklan Catholic College</b> &middot; Student Elections &middot; <?= date('Y') ?> &middot; One vote per position, fully confidential
</footer>

<script>
(function () {
    const root = document.getElementById('resultsRoot');
    const updatedEl = document.getElementById('lastUpdated');
    let lastRefresh = Date.now();

    function refresh() {
        return fetch(location.pathname + '?partial=1', { headers: { 'X-Requested-With': 'fetch' } })
            .then(res => res.ok ? res.text() : Promise.reject(res.status))
            .then(html => {
                const tmp = document.createElement('div');
                tmp.innerHTML = html;
                const fresh = tmp.querySelector('[data-root]');
                if (fresh) root.innerHTML = fresh.innerHTML;
                lastRefresh = Date.now();
                observeReveals();
            })
            .catch(() => { /* keep showing the last good data on transient errors */ });
    }

    setInterval(() => {
        // Skip polling while the tab is hidden to be polite to the server.
        if (!document.hidden) refresh();
    }, 15000);

    // "Updated Xs ago" ticker.
    setInterval(() => {
        const secs = Math.round((Date.now() - lastRefresh) / 1000);
        updatedEl.textContent = secs < 3 ? 'just now' : secs + 's ago';
    }, 1000);

    // Reveal-on-scroll for freshly rendered blocks.
    let io = null;
    function observeReveals() {
        const els = root.querySelectorAll('.reveal:not(.in-view)');
        if (!('IntersectionObserver' in window)) {
            els.forEach(el => el.classList.add('in-view'));
            return;
        }
        if (!io) {
            io = new IntersectionObserver(entries => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('in-view');
                        io.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.1 });
        }
        els.forEach(el => io.observe(el));
    }
    observeReveals();
})();
</script>
</body>
</html>
