<?php
/* ============================================================================
 * Cute Animals — minimal WebRTC SIGNALING relay (one-click online MP).
 * PHP 5.6 compatible (skynet runs 5.6 — NO null-coalescing `??`, no `?:` shorthand
 * abuse beyond what's safe). Each room has two boxes (host / guest): each peer
 * POSTs into the OTHER box and GET-polls its OWN. Pure signaling, auto-expiring.
 *
 * Endpoint: https://skynet.lospolo.hu/animals/signal.php
 * POST JSON { room, secret, box:'host'|'guest', msg:{...} } -> { ok, seq }
 * POST JSON { room, secret, action:'reset' }                -> { ok }
 * GET  ?room=ABCD&secret=..&box=host|guest&since=N          -> { ok, msgs:[{seq,msg}], now }
 *
 * SECURITY (2026-06 review; P2-4 / P3-4):
 *   - PER-MATCH SHARED SECRET is REQUIRED on every POST and GET. matchmake.php
 *     mints the secret at pairing and writes it into the room record; here we
 *     validate the presented secret with hash_equals() against the stored one
 *     BEFORE reading/writing the mailbox. A correct room code WITHOUT the
 *     matching secret is rejected with 403 — no SDP/ICE injection / room read.
 *   - 403 does NOT reveal whether the room exists (missing room, no stored
 *     secret, and wrong secret all return the same opaque 403).
 *   - separate per-IP rate limit on GET (not just the shared sweep) to blunt
 *     room/secret enumeration on the read side.
 *   - the room code is sanitized (clean_room regex) before any filesystem use.
 * ==========================================================================*/

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
// same-origin only (drop the wildcard); reflect Origin if it matches our host.
$__host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
$__org  = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
if ($__org !== '' && $__host !== '' && preg_replace('#^https?://#i','',$__org) === $__host) {
  header('Access-Control-Allow-Origin: ' . $__org);
  header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

define('CA_TTL', 900);        // rooms expire after 15 min of inactivity
define('CA_MAX_MSGS', 200);   // safety cap per box
define('CA_MAX_BLOB', 64000); // 64 KB per message cap (lowered from 200 KB)
define('CA_MAX_ROOMS', 200);  // hard cap on total room files on disk
define('CA_RATE_WINDOW', 10); // seconds
define('CA_RATE_MAX', 60);    // max requests per IP per window (all methods, global sweep)
define('CA_GET_RATE_WINDOW', 10); // seconds — separate GET window
define('CA_GET_RATE_MAX', 40);    // max GET polls per IP per window (blunts read enumeration)

function ca_get($arr, $key, $def) { return (is_array($arr) && isset($arr[$key])) ? $arr[$key] : $def; }

function sig_dir() {
  // private, out of webroot (was world-writable sys_get_temp_dir()/ca_signal)
  $d = __DIR__ . '/../.ca_data/mp';
  if (!is_dir($d)) { @mkdir($d, 0750, true); }
  return $d;
}
function clean_room($r) {
  $r = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$r));
  return substr($r, 0, 8);
}
function room_path($r) { return sig_dir() . '/room_' . $r . '.json'; }

// per-IP rate limit (fail-open on FS error)
function ca_rate_ok() {
  $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '?';
  $key = preg_replace('/[^a-zA-Z0-9_.:-]/', '_', $ip);
  $f = sig_dir() . '/rl_' . $key . '.json';
  $now = time();
  $fh = @fopen($f, 'c+');
  if (!$fh) { return true; }
  $ok = true;
  if (flock($fh, LOCK_EX)) {
    $raw = stream_get_contents($fh);
    $rec = $raw ? json_decode($raw, true) : null;
    if (!is_array($rec) || (int)ca_get($rec,'start',0) < $now - CA_RATE_WINDOW) {
      $rec = array('start'=>$now, 'count'=>0);
    }
    $rec['count'] = (int)ca_get($rec,'count',0) + 1;
    if ($rec['count'] > CA_RATE_MAX) { $ok = false; }
    ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($rec));
    fflush($fh); flock($fh, LOCK_UN);
  }
  fclose($fh);
  return $ok;
}

// SEPARATE per-IP GET rate limit (P3-4): the shared ca_rate_ok() already gates
// all methods, but GET is the enumeration surface (poll a room/secret pair), so
// we add a second, tighter window keyed on the IP for the GET path only.
// Fail-open on FS error (same posture as ca_rate_ok).
function ca_get_rate_ok() {
  $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '?';
  $key = preg_replace('/[^a-zA-Z0-9_.:-]/', '_', $ip);
  $f = sig_dir() . '/rlg_' . $key . '.json';
  $now = time();
  $fh = @fopen($f, 'c+');
  if (!$fh) { return true; }
  $ok = true;
  if (flock($fh, LOCK_EX)) {
    $raw = stream_get_contents($fh);
    $rec = $raw ? json_decode($raw, true) : null;
    if (!is_array($rec) || (int)ca_get($rec,'start',0) < $now - CA_GET_RATE_WINDOW) {
      $rec = array('start'=>$now, 'count'=>0);
    }
    $rec['count'] = (int)ca_get($rec,'count',0) + 1;
    if ($rec['count'] > CA_GET_RATE_MAX) { $ok = false; }
    ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($rec));
    fflush($fh); flock($fh, LOCK_UN);
  }
  fclose($fh);
  return $ok;
}

// Validate the presented per-match secret against the one stored in the room
// record (seeded by matchmake.php). Uses hash_equals (constant-time). Returns
// false when: room file missing, no stored secret, or the secret doesn't match.
// Callers MUST treat any false as an opaque 403 (never reveal which case it is).
function room_secret_ok($room, $presented) {
  $presented = (string)$presented;
  if ($presented === '') { return false; }
  $path = room_path($room);
  if (!is_file($path)) { return false; }
  $data = json_decode(@file_get_contents($path), true);
  if (!is_array($data) || !isset($data['secret']) || $data['secret'] === '') { return false; }
  return hash_equals((string)$data['secret'], $presented);
}

// deterministic GC: every request prunes expired rooms; if still over the
// cap, drops the oldest rooms (was a probabilistic 1-in-12 with no cap).
function ca_gc() {
  $now = time();
  // prune stale per-IP rate-limit files (rl_* / rlg_*) so they can't accumulate
  // one-per-IP forever (slow inode/disk growth). The rate windows are ~10s; a
  // 5-minute staleness cutoff is comfortably past any live window. Mirrors the
  // probabilistic GC in contact.php / account.php (kept cheap + guarded).
  if (mt_rand(1, 20) === 1) {
    foreach (array('/rl_*.json', '/rlg_*.json') as $pat) {
      $rls = glob(sig_dir() . $pat);
      if (is_array($rls)) {
        foreach ($rls as $rf) { if (@filemtime($rf) < ($now - 300)) { @unlink($rf); } }
      }
    }
  }
  $files = glob(sig_dir() . '/room_*.json');
  if (!$files) { return; }
  $cut = $now - CA_TTL;
  foreach ($files as $f) {
    if (@filemtime($f) < $cut) { @unlink($f); }
  }
  // re-list and enforce the hard cap by evicting oldest
  $files = glob(sig_dir() . '/room_*.json');
  if ($files && count($files) > CA_MAX_ROOMS) {
    $byTime = array();
    foreach ($files as $f) { $byTime[$f] = @filemtime($f); }
    asort($byTime); // oldest first
    $over = count($files) - CA_MAX_ROOMS;
    $i = 0;
    foreach ($byTime as $f => $mt) {
      if ($i >= $over) { break; }
      @unlink($f); $i++;
    }
  }
}

function load_locked($path) {
  $fh = @fopen($path, 'c+');
  if (!$fh) { return array(null, null); }
  flock($fh, LOCK_EX);
  $raw = stream_get_contents($fh);
  $data = $raw ? json_decode($raw, true) : null;
  if (!is_array($data)) { $data = array('seq' => 0, 'host' => array(), 'guest' => array()); }
  return array($fh, $data);
}
function save_unlock($fh, $data) {
  ftruncate($fh, 0); rewind($fh);
  fwrite($fh, json_encode($data));
  fflush($fh); flock($fh, LOCK_UN); fclose($fh);
}

if (!ca_rate_ok()) { http_response_code(429); echo json_encode(array('ok'=>false,'err'=>'rate limit')); exit; }
ca_gc();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $body = json_decode(file_get_contents('php://input'), true);
  if (!is_array($body)) { http_response_code(400); echo json_encode(array('ok'=>false,'err'=>'bad json')); exit; }
  $room = clean_room(ca_get($body, 'room', ''));
  if ($room === '') { http_response_code(400); echo json_encode(array('ok'=>false,'err'=>'no room')); exit; }
  // PER-MATCH SECRET REQUIRED (P2-4). Validate BEFORE any mailbox read/write.
  // Opaque 403 — do not reveal whether the room exists or why it failed.
  if (!room_secret_ok($room, ca_get($body, 'secret', ''))) {
    http_response_code(403); echo json_encode(array('ok'=>false,'err'=>'forbidden')); exit;
  }
  $path = room_path($room);

  if (ca_get($body, 'action', '') === 'reset') {
    list($fh, $data) = load_locked($path);
    if (!$fh) { http_response_code(500); echo json_encode(array('ok'=>false)); exit; }
    // PRESERVE the per-match secret across a reset (clearing the mailboxes must
    // not strip the room's authZ, or the next POST/GET would 403 itself out).
    $keep = (is_array($data) && isset($data['secret'])) ? $data['secret'] : '';
    save_unlock($fh, array('seq'=>0, 'host'=>array(), 'guest'=>array(), 'secret'=>$keep));
    echo json_encode(array('ok'=>true)); exit;
  }

  $box = (ca_get($body, 'box', '') === 'host') ? 'host' : 'guest';
  $msg = ca_get($body, 'msg', null);
  if ($msg === null) { http_response_code(400); echo json_encode(array('ok'=>false,'err'=>'no msg')); exit; }
  if (strlen(json_encode($msg)) > CA_MAX_BLOB) { http_response_code(413); echo json_encode(array('ok'=>false,'err'=>'too big')); exit; }

  list($fh, $data) = load_locked($path);
  if (!$fh) { http_response_code(500); echo json_encode(array('ok'=>false)); exit; }
  $seq = ca_get($data, 'seq', 0) + 1;
  $data['seq'] = $seq;
  if (!isset($data[$box]) || !is_array($data[$box])) { $data[$box] = array(); }
  $data[$box][] = array('seq'=>$seq, 'msg'=>$msg);
  if (count($data[$box]) > CA_MAX_MSGS) { $data[$box] = array_slice($data[$box], -CA_MAX_MSGS); }
  save_unlock($fh, $data);
  echo json_encode(array('ok'=>true, 'seq'=>$seq)); exit;
}

// GET — poll a box
// Separate, tighter GET rate limit (P3-4) to blunt read-side enumeration.
if (!ca_get_rate_ok()) { http_response_code(429); echo json_encode(array('ok'=>false,'err'=>'rate limit')); exit; }
$room = clean_room(ca_get($_GET, 'room', ''));
$box  = (ca_get($_GET, 'box', '') === 'host') ? 'host' : 'guest';
$since = (int)ca_get($_GET, 'since', 0);
if ($room === '') { http_response_code(400); echo json_encode(array('ok'=>false,'err'=>'no room')); exit; }
// PER-MATCH SECRET REQUIRED on the read side too. Opaque 403 for missing room,
// no stored secret, or wrong secret alike (don't reveal whether the room exists).
if (!room_secret_ok($room, ca_get($_GET, 'secret', ''))) {
  http_response_code(403); echo json_encode(array('ok'=>false,'err'=>'forbidden')); exit;
}
$path = room_path($room);
@touch($path);
$data = json_decode(@file_get_contents($path), true);
$out = array();
if (is_array($data) && isset($data[$box]) && is_array($data[$box])) {
  foreach ($data[$box] as $e) {
    if (ca_get($e, 'seq', 0) > $since) { $out[] = $e; }
  }
}
echo json_encode(array('ok'=>true, 'msgs'=>$out, 'now'=>time()));
