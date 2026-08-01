<?php
/* =============================================================================
 * cosmetics.php  —  Squeak & Destroy shared cosmetic-offset store (PHP 5.6)
 * -----------------------------------------------------------------------------
 * GET  : returns the stored cosmetic-offset map as JSON (or {} if none).
 *        No auth — the GAME reads this on boot (entities.js #3c). CORS open so
 *        Electron / native (file://) builds can fetch it too.
 * POST : params `key` + `data` (JSON string). `key` must equal COSMETIC_KEY or
 *        we answer 403. On success the map is sanitised (only objects with
 *        numeric dx/dy/scale/rot, clamped; <=200 entries; <=64KB) and written
 *        ATOMICALLY (flock + temp + rename), keeping one .bak backup.
 *        Answers { "ok": true } / { "ok": false, "err": "..." }.
 *
 * Storage: __DIR__/cosmetics.json (runtime-persistent; NOT in the repo so the
 * deploy never overwrites it). If __DIR__ is not writable we fall back to
 * sys_get_temp_dir() for BOTH read and write. Never emits a fatal error.
 * ========================================================================== */

/* Hardening notes:
 *  - the edit key comes from the server-side .env (COSMETIC_KEY=...) or the
 *    process environment. There is NO default: without a key configured, write
 *    access is disabled entirely and the endpoint stays read-only.
 *  - storage lives OUTSIDE the webroot (../.ca_data/), like the other endpoints;
 *    an older webroot cosmetics.json is still honoured as a read fallback.
 *  - POST is restricted to same-origin (GET stays open — the game reads it on boot). */
function ca_env_key($key, $default) {
    $p = __DIR__ . '/../.env';
    $raw = @file_get_contents($p);
    if (is_string($raw) && $raw !== '') {
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') { continue; }
            $eq = strpos($line, '=');
            if ($eq === false) { continue; }
            if (trim(substr($line, 0, $eq)) !== $key) { continue; }
            $v = trim(substr($line, $eq + 1));
            $v = trim($v, "\"'");
            /* poison-guard: PHP 5.6-ban a definiálatlan konstans a saját nevét adja */
            if ($v !== '' && $v !== $key) { return $v; }
        }
    }
    $g = getenv($key);
    if (is_string($g) && $g !== '' && $g !== $key) { return $g; }
    return $default;
}
// No default: an unset COSMETIC_KEY leaves the endpoint read-only (see the
// key check below, which rejects every write while this is an empty string).
define('COSMETIC_KEY', ca_env_key('COSMETIC_KEY', ''));
define('COSMETIC_MAX_ENTRIES', 200);
define('COSMETIC_MAX_BYTES', 64 * 1024);

$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

/* GET marad nyílt CORS (a játék/Electron boot-olvasása); POST same-origin only. */
if ($method === 'GET' || $method === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
} else {
    $__host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
    $__org  = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
    if ($__org !== '' && $__host !== '' && preg_replace('#^https?://#i','',$__org) === $__host) {
        header('Access-Control-Allow-Origin: ' . $__org);
        header('Vary: Origin');
    }
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/* ---- resolve a usable file path: ../.ca_data (webrooton kívül), fallback temp */
function ca_primary_path() {
    $d = __DIR__ . '/../.ca_data';
    if (!@is_dir($d)) { @mkdir($d, 0700, true); }
    return $d . '/cosmetics.json';
}
/* legacy: a korábbi verzió a webrootba írt — olvasáskor migrációs fallback */
function ca_legacy_path() {
    return __DIR__ . '/cosmetics.json';
}
function ca_temp_path() {
    $d = sys_get_temp_dir();
    if (!$d) { $d = '.'; }
    return rtrim($d, '/\\') . '/ca_cosmetics.json';
}
/* the path we should READ from: primary, else legacy webroot, else temp. */
function ca_read_path() {
    $p = ca_primary_path();
    if (@is_file($p)) { return $p; }
    $l = ca_legacy_path();
    if (@is_file($l)) { return $l; }
    $t = ca_temp_path();
    if (@is_file($t)) { return $t; }
    return $p;
}
/* the path we should WRITE to: primary dir if writable, else temp. */
function ca_write_path() {
    $p = ca_primary_path();
    $dir = dirname($p);
    if (@is_writable($dir) || (@is_file($p) && @is_writable($p))) { return $p; }
    return ca_temp_path();
}

/* ---- GET: hand back the stored map (or {}) -------------------------------- */
if ($method === 'GET') {
    header('Content-Type: application/json; charset=utf-8');
    $path = ca_read_path();
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        echo '{}';
        exit;
    }
    /* validate it is JSON object before echoing; otherwise {} */
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        echo $raw;
    } else {
        echo '{}';
    }
    exit;
}

/* ---- POST: validate key, sanitise, atomic write --------------------------- */
if ($method === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $key = isset($_POST['key']) ? $_POST['key'] : '';
    // An unconfigured COSMETIC_KEY must LOCK writes, not open them: without the
    // first clause an empty submitted key would match the empty constant.
    if (COSMETIC_KEY === '' || !is_string($key) || $key !== COSMETIC_KEY) {
        http_response_code(403);
        echo json_encode(array('ok' => false, 'err' => 'bad_key'));
        exit;
    }

    $dataStr = isset($_POST['data']) ? $_POST['data'] : '';
    if (!is_string($dataStr) || $dataStr === '') {
        echo json_encode(array('ok' => false, 'err' => 'no_data'));
        exit;
    }
    if (strlen($dataStr) > COSMETIC_MAX_BYTES) {
        echo json_encode(array('ok' => false, 'err' => 'too_big'));
        exit;
    }

    $map = json_decode($dataStr, true);
    if (!is_array($map)) {
        echo json_encode(array('ok' => false, 'err' => 'bad_json'));
        exit;
    }

    /* ---- sanitise: keep only well-formed numeric entries, clamped --------- */
    $clean = array();
    $count = 0;
    foreach ($map as $k => $v) {
        if ($count >= COSMETIC_MAX_ENTRIES) { break; }
        if (!is_string($k) || $k === '') { continue; }
        if (!is_array($v)) { continue; }
        if (!isset($v['dx']) || !isset($v['dy']) || !isset($v['scale']) || !isset($v['rot'])) { continue; }
        if (!is_numeric($v['dx']) || !is_numeric($v['dy']) || !is_numeric($v['scale']) || !is_numeric($v['rot'])) { continue; }

        $dx = (float) $v['dx'];
        $dy = (float) $v['dy'];
        $scale = (float) $v['scale'];
        $rot = (float) $v['rot'];

        /* clamp to sane ranges */
        if ($dx < -200) { $dx = -200; } if ($dx > 200) { $dx = 200; }
        if ($dy < -200) { $dy = -200; } if ($dy > 200) { $dy = 200; }
        if ($scale < 0.2) { $scale = 0.2; } if ($scale > 3) { $scale = 3; }
        if ($rot < -3.2) { $rot = -3.2; } if ($rot > 3.2) { $rot = 3.2; }

        /* drop non-finite values */
        if (!is_finite($dx) || !is_finite($dy) || !is_finite($scale) || !is_finite($rot)) { continue; }

        $clean[$k] = array('dx' => $dx, 'dy' => $dy, 'scale' => $scale, 'rot' => $rot);
        $count++;
    }

    $out = json_encode($clean);
    if ($out === false) {
        echo json_encode(array('ok' => false, 'err' => 'encode_failed'));
        exit;
    }
    if (strlen($out) > COSMETIC_MAX_BYTES) {
        echo json_encode(array('ok' => false, 'err' => 'too_big'));
        exit;
    }

    $path = ca_write_path();

    /* one-level backup of the existing file */
    if (@is_file($path)) {
        @copy($path, $path . '.bak');
    }

    /* atomic write: temp file in the same dir + flock + rename */
    $dir = dirname($path);
    $tmp = @tempnam($dir, 'ca_');
    if ($tmp === false) {
        /* fallback temp dir if the target dir blocked tempnam */
        $tmp = @tempnam(sys_get_temp_dir(), 'ca_');
    }
    if ($tmp === false) {
        echo json_encode(array('ok' => false, 'err' => 'no_temp'));
        exit;
    }

    $fh = @fopen($tmp, 'wb');
    if ($fh === false) {
        @unlink($tmp);
        echo json_encode(array('ok' => false, 'err' => 'open_failed'));
        exit;
    }
    $ok = true;
    if (@flock($fh, LOCK_EX)) {
        if (@fwrite($fh, $out) === false) { $ok = false; }
        @fflush($fh);
        @flock($fh, LOCK_UN);
    } else {
        /* still try to write without an exclusive lock */
        if (@fwrite($fh, $out) === false) { $ok = false; }
    }
    @fclose($fh);

    if (!$ok) {
        @unlink($tmp);
        echo json_encode(array('ok' => false, 'err' => 'write_failed'));
        exit;
    }

    /* rename is atomic on the same filesystem; if it fails try a plain copy */
    if (!@rename($tmp, $path)) {
        if (@copy($tmp, $path)) {
            @unlink($tmp);
        } else {
            @unlink($tmp);
            echo json_encode(array('ok' => false, 'err' => 'rename_failed'));
            exit;
        }
    }
    @chmod($path, 0664);

    echo json_encode(array('ok' => true, 'count' => $count));
    exit;
}

/* ---- any other method ----------------------------------------------------- */
http_response_code(405);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(array('ok' => false, 'err' => 'method'));
exit;
