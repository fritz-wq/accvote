<?php
/**
 * Reusable helper functions.
 */

// The school's local timezone. Election start/end dates are stored as the
// literal wall-clock value the admin typed in (no timezone attached), so
// anything in PHP that computes "now" to compare against them — vote.php's
// open/closed check, the student dashboard's countdown — needs to run in
// this same timezone or the comparison silently drifts by the UTC offset.
// (syncElectionStatuses() below now does its own timezone conversion
// directly in SQL via `NOW() AT TIME ZONE 'Asia/Manila'`, so it doesn't
// depend on this setting — but every OTHER PHP-side "what time is it right
// now" calculation in the app still does.) Change this if the school is
// ever outside the Philippines.
date_default_timezone_set('Asia/Manila');

// ------------------------------------------------------------------
// Global error handling. display_errors stays OFF so an unexpected
// exception or parse-level problem can never print a stack trace, file
// paths, or query context to a visitor; the real error still lands in
// the server log via log_errors. The exception handler below renders a
// generic message — JSON for API endpoints (they set their Content-Type
// before doing work), a plain HTML page for everything else.
// ------------------------------------------------------------------
ini_set('display_errors', '0');
ini_set('log_errors', '1');

set_exception_handler(function (Throwable $e): void {
    error_log('Uncaught exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

    $isJson = false;
    if (function_exists('headers_list')) {
        foreach (headers_list() as $header) {
            if (stripos($header, 'Content-Type: application/json') === 0) {
                $isJson = true;
                break;
            }
        }
    }

    if (!headers_sent()) {
        http_response_code(500);
    }

    if ($isJson) {
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode(['success' => false, 'error' => 'A server error occurred. Please try again later.']);
    } else {
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '<title>Something went wrong</title></head>'
            . '<body style="margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
            . 'font-family:system-ui,sans-serif;background:#f7f5ef;color:#0f172a;text-align:center;padding:1.5rem;">'
            . '<div><h1 style="font-size:1.4rem;margin:0 0 .5rem;">Something went wrong</h1>'
            . '<p style="color:#5c6b81;margin:0 0 1.25rem;">An unexpected error occurred. Please try again later.</p>'
            . '<a href="index.php" style="color:#65a30d;font-weight:600;">&larr; Back to home</a></div>'
            . '</body></html>';
    }
    exit;
});

/**
 * Starts a session with sane, hardened cookie settings.
 * Safe to call multiple times.
 */
function startSecureSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

/**
 * Redirects to the login page unless the user is authenticated.
 * Call at the top of any protected page.
 */
function requireLogin(): void
{
    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
}

/**
 * Escapes a string for safe HTML output.
 */
function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Returns an asset path with a ?v= cache-buster derived from the file's
 * last-modified time, e.g. "assets/dashboard-app.css?v=1756108800".
 * Whenever the file changes, the URL changes, so browsers fetch the new
 * copy immediately instead of serving a stale cached stylesheet/script
 * (which is how dark-mode CSS fixes once failed to appear for users).
 * Falls back to a plain version-less path if the file doesn't exist.
 */
function assetV(string $path): string
{
    $full = __DIR__ . '/../' . ltrim($path, '/');
    $mtime = @filemtime($full);
    return $mtime ? $path . '?v=' . $mtime : $path;
}

/**
 * Generates (or reuses) a CSRF token stored in the session.
 */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validates a submitted CSRF token against the session token.
 */
function verifyCsrfToken(?string $submitted): bool
{
    if (empty($_SESSION['csrf_token']) || empty($submitted)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $submitted);
}

/**
 * Sends a JSON response and terminates the script.
 */
function jsonResponse(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

/**
 * Require admin login. Redirect to admin login page if not authenticated.
 *
 * JSON API endpoints (admin/api/*.php) get a 401 JSON body instead of a
 * redirect: a relative "Location: login.php" sent from /admin/api/ resolves
 * to /admin/api/login.php, which doesn't exist — the client would follow it
 * into a confusing 404/405 instead of a clear "not logged in".
 */
function requireAdminLogin(): void
{
    if (!empty($_SESSION['admin_id'])) {
        return;
    }

    if (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/api/') !== false) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Not logged in.']);
        exit;
    }

    header('Location: login.php');   // Relative – works from /admin/
    exit;
}

/**
 * Best-effort real client IP for rate limiting.
 *
 * Behind Render (or any reverse proxy), REMOTE_ADDR is the proxy's IP, so
 * every visitor would share one rate-limit bucket. When a validated
 * X-Forwarded-For chain is present, the first public address wins.
 *
 * Caveat: X-Forwarded-For is client-suppliable when there is no proxy, so
 * only values that parse as PUBLIC IPs are accepted (private/reserved
 * ranges are skipped). A determined attacker can still rotate spoofed
 * IPs — this raises the bar for bulk abuse, it is not an identity system.
 */
function clientIp(): string
{
    $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($xff !== '') {
        foreach (explode(',', $xff) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $candidate;
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

/**
 * Core rate-limit check keyed by an arbitrary identifier string.
 * Login/registration endpoints call the isRateLimited() wrapper (IP-keyed);
 * per-user endpoints (voting, drafts, admin writes) call this directly with
 * a "<bucket>:user:<id>" identifier so one busy IP can't lock everyone out
 * and one user can't hammer their own bucket past the cap.
 */
function isRateLimitedKey(PDO $pdo, string $identifier, int $maxAttempts = 8, int $windowSeconds = 300): bool
{
    // Housekeeping: drop attempts old enough that they can never matter
    // again, so this table doesn't grow forever.
    if (isMysql()) {
        $pdo->prepare('DELETE FROM login_attempts WHERE identifier = :id AND attempted_at < NOW() - INTERVAL 1 HOUR')
            ->execute(['id' => $identifier]);
    } else {
        $pdo->prepare("DELETE FROM login_attempts WHERE identifier = :id AND attempted_at < NOW() - INTERVAL '1 hour'")
            ->execute(['id' => $identifier]);
    }

    $windowSql = isMysql()
        ? 'NOW() - INTERVAL :window SECOND'
        : "NOW() - (:window * INTERVAL '1 second')";
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM login_attempts
        WHERE identifier = :id AND attempted_at > {$windowSql}
    ");
    $stmt->execute(['id' => $identifier, 'window' => $windowSeconds]);

    return (int) $stmt->fetchColumn() >= $maxAttempts;
}

/**
 * Records one attempt against an arbitrary identifier (see isRateLimitedKey()).
 */
function recordAttemptKey(PDO $pdo, string $identifier): void
{
    $pdo->prepare('INSERT INTO login_attempts (identifier) VALUES (:id)')->execute(['id' => $identifier]);
}

/**
 * Simple DB-backed rate limiter for login/registration endpoints. IP-based,
 * so it isn't bulletproof (shared NAT/mobile carriers share an IP, and a
 * determined attacker can rotate IPs), but it raises the bar a lot against
 * scripted brute-forcing or account-enumeration attempts at essentially
 * zero cost to legitimate users, who almost never hit these limits.
 *
 * $bucket separates independent limits per endpoint (e.g. 'student_login',
 * 'admin_login', 'register') so hammering one doesn't lock out another.
 */
function isRateLimited(PDO $pdo, string $bucket, int $maxAttempts = 8, int $windowSeconds = 300): bool
{
    return isRateLimitedKey($pdo, $bucket . ':' . clientIp(), $maxAttempts, $windowSeconds);
}

/**
 * Records one attempt against a rate-limit bucket (see isRateLimited()).
 * Call this once per real login/registration POST, regardless of whether
 * it ultimately succeeds or fails.
 */
function recordAttempt(PDO $pdo, string $bucket): void
{
    recordAttemptKey($pdo, $bucket . ':' . clientIp());
}

/**
 * Auto-transitions election statuses based on the current time, so the
 * schedule the admin saves is actually what runs the election — no one
 * has to remember to click Start or End on the right day.
 *
 *   scheduled -> ongoing  once start_date has arrived (and we're still
 *                before end_date)
 *   scheduled/
 *   ongoing   -> closed   once end_date has passed
 *
 * "draft" elections are deliberately EXCLUDED here. A brand-new election's
 * start/end dates are just wizard defaults (start = "now") until the admin
 * has actually reviewed and saved a schedule for it — auto-starting a
 * draft the instant it's created (because its placeholder start date is
 * technically already in the past) would launch an election nobody meant
 * to run yet. Saving a schedule is what promotes draft -> scheduled (see
 * admin/api/elections.php), and only from there do these automatic
 * start/end transitions apply.
 *
 * "paused" and "archived" are also never touched here: pausing is a
 * manual override the admin has to lift themselves (by hitting Resume,
 * which is the same as Start), and archiving is a manual admin action.
 *
 * TIMEZONE NOTE: start_date/end_date are stored as the literal wall-clock
 * value the admin typed into the schedule picker (Philippines time),
 * with no timezone attached — same as activity_logs treats CURRENT_
 * TIMESTAMP as UTC on the frontend (see dashboard2.php's
 * parseServerTimestamp()). Postgres's own NOW() returns the current
 * instant in UTC on this host, so comparing it directly against a
 * Philippines-local literal would be off by the UTC+8 offset — an
 * election scheduled to start in 1 minute wouldn't actually flip to
 * "ongoing" until Postgres's UTC clock caught up to that same literal
 * number, ~8 hours late. `NOW() AT TIME ZONE 'Asia/Manila'` converts
 * Postgres's real UTC instant into that same instant's Philippines
 * wall-clock representation, so it's finally comparable apples-to-apples
 * against the literal start_date/end_date values already in the table.
 *
 * Call this once near the top of any page/endpoint that reads elections.
 */
function syncElectionStatuses(PDO $pdo): void
{
    // "Now" as Manila wall-clock, in each driver's own dialect. Postgres:
    // NOW() is UTC on the server, so convert the instant to Asia/Manila.
    // MySQL: UTC_TIMESTAMP() + CONVERT_TZ with a fixed offset needs no tz
    // tables and always works (MariaDB/MySQL both accept '+00:00' offsets).
    $nowSql = isMysql()
        ? "CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', '+08:00')"
        : "(NOW() AT TIME ZONE 'Asia/Manila')";
    $pdo->exec("
        UPDATE elections SET status = 'ongoing'
        WHERE status = 'scheduled'
          AND start_date <= {$nowSql}
          AND end_date > {$nowSql}
    ");
    $pdo->exec("
        UPDATE elections SET status = 'closed'
        WHERE status IN ('scheduled', 'ongoing')
          AND end_date <= {$nowSql}
    ");
}

/**
 * ================================================================
 * ELECTION LOGOS (SSG site-wide logo + per-department DSG logos)
 * ================================================================
 * Same base64-in-DB storage pattern already used for candidate photos:
 * whatever the browser uploads is stored directly as a data: URL, so it
 * survives redeploys on hosts (like Render) that wipe local disk.
 */

/**
 * Reads a single value from the generic site_settings key/value table.
 * Returns null if the key has never been set (or was explicitly cleared).
 */
function getSiteSetting(PDO $pdo, string $key): ?string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM site_settings WHERE setting_key = :k');
    $stmt->execute(['k' => $key]);
    $value = $stmt->fetchColumn();
    return ($value !== false && $value !== null && $value !== '') ? $value : null;
}

/**
 * Returns [department_code => logo-data-url-or-null] for every department,
 * for bulk-rendering a page full of election cards without a query per card.
 */
function getDepartmentLogos(PDO $pdo): array
{
    $rows = $pdo->query('SELECT code, logo FROM departments')->fetchAll();
    $map = [];
    foreach ($rows as $row) {
        $map[$row['code']] = !empty($row['logo']) ? $row['logo'] : null;
    }
    return $map;
}

/**
 * A plain, generic "institution" glyph — shown whenever an election's
 * type (SSG) or department (DSG) doesn't have a logo uploaded yet, so an
 * election is never left with a broken image or a placeholder emoji.
 * Inline SVG (not a file) so it always matches the current theme color
 * via currentColor, in both light and dark mode.
 */
function defaultElectionLogoSvg(): string
{
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
        . 'stroke-linecap="round" stroke-linejoin="round" class="elogo-placeholder">'
        . '<path d="M3 21h18"/><path d="M5 21V9.5L12 4l7 5.5V21"/><path d="M9 21v-6h6v6"/>'
        . '</svg>';
}

/**
 * Renders the logo/icon markup for one election: its SSG logo, its
 * department's DSG logo, or the generic placeholder glyph if neither has
 * been uploaded. Returns ready-to-echo HTML (the <img> src is already
 * escaped) — safe to drop straight into a template.
 *
 * $ssgLogo:         from getSiteSetting($pdo, 'ssg_logo')
 * $departmentLogos: from getDepartmentLogos($pdo)
 */
function electionLogoHtml(string $type, ?string $departmentCode, ?string $ssgLogo, array $departmentLogos): string
{
    $logo = $type === 'SSG' ? $ssgLogo : ($departmentLogos[$departmentCode ?? ''] ?? null);
    if ($logo) {
        return '<img src="' . h($logo) . '" alt="" class="elogo-img">';
    }
    return defaultElectionLogoSvg();
}

/**
 * ================================================================
 * UPLOAD VALIDATION (candidate photos + election/department logos)
 * ================================================================
 * Both arrive as base64 data: URLs from the browser. The old check only
 * trusted the "data:image/..." string prefix, so a crafted payload with
 * a PNG prefix but arbitrary bytes (SVG/script/HTML) would have been
 * stored unchecked. This validates the DECODED BYTES: the payload must
 * actually parse as a raster image (JPEG/PNG/GIF/WebP) via
 * getimagesize(), with sane dimensions. SVG is deliberately rejected —
 * it can carry embedded script.
 */
function isValidImageDataUrl(string $dataUrl): bool
{
    $comma = strpos($dataUrl, ',');
    if ($comma === false) {
        return false;
    }
    $decoded = base64_decode(substr($dataUrl, $comma + 1), true);
    if ($decoded === false || strlen($decoded) < 8) {
        return false;
    }
    $info = @getimagesizefromstring($decoded);
    if (!is_array($info) || empty($info['mime'])) {
        return false;
    }
    if (!in_array($info['mime'], ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
        return false;
    }
    $width = (int) ($info[0] ?? 0);
    $height = (int) ($info[1] ?? 0);
    if ($width < 1 || $height < 1 || $width > 5000 || $height > 5000) {
        return false;
    }
    return true;
}

/**
 * Server-side password policy shared by student self-registration and the
 * admin panel's student forms. Returns an error message, or null if the
 * password passes: 8+ characters with at least one letter and one number.
 */
function passwordPolicyError(string $password): ?string
{
    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters long.';
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        return 'Password must contain both letters and numbers.';
    }
    return null;
}
