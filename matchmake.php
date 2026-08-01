<?php
/* ============================================================================
 * Squeak & Destroy — quick matchmaking (PHP 5.6 compatible — NO `??`).
 * A tiny file-backed pairing queue. Two players who "find" within ~30s get
 * paired into a shared room CODE (one host, one guest); the client then uses
 * the existing WebRTC auto-signaling (signal.php) to connect.
 *
 *   POST {action:'find',   name}   -> { ok, status:'waiting', ticket }   OR
 *                                       { ok, status:'matched', room, role, secret }
 *   POST {action:'poll',   ticket} -> { ok, status:'waiting' }           OR
 *                                       { ok, status:'matched', room, role, secret }
 *   POST {action:'cancel', ticket} -> { ok }
 *   POST {action:'register', room?}-> { ok, room, secret }   (manual HOST: mint a
 *                                       room+secret; if room omitted we pick one)
 *   POST {action:'claim', room}    -> { ok, secret }         (manual GUEST: present
 *                                       the code as the "knock"; granted the secret
 *                                       on the FIRST successful claim only)
 *
 * SECURITY (2026-06 review; P2-4):
 *   - state file lives OUTSIDE the webroot (../.ca_data/mp, mode 0750) —
 *     was world-writable sys_get_temp_dir()/ca_signal.
 *   - per-IP rate limit.
 *   - a QUEUE of waiting tickets (not a single slot) so one client cannot
 *     monopolize matchmaking; the queue is also capped.
 *   - PER-MATCH SHARED SECRET: when two peers are paired (or a manual room is
 *     created + claimed) a high-entropy secret is minted and returned to BOTH
 *     peers. signal.php REQUIRES that secret on every read/write — a correct
 *     room code WITHOUT the matching secret is rejected (no SDP/ICE injection).
 *   - the secret is mirrored into the signal-room record (room_<CODE>.json) so
 *     signal.php can validate it WITHOUT depending on matchmake's own state TTL.
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
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(array('ok'=>false)); exit; }

define('MM_WAIT_TTL', 30);    // a waiting player expires after 30s
define('MM_MATCH_TTL', 60);   // a match record is kept 60s for polling
define('MM_QUEUE_MAX', 100);  // hard cap on waiting tickets
define('MM_RATE_WINDOW', 10); // seconds
define('MM_RATE_MAX', 30);    // max requests per IP per window
define('MM_ROOM_TTL', 45);    // public room delisted after 45s with no heartbeat
define('MM_ROOMS_MAX', 60);   // hard cap on listed public rooms

function mm_get($a,$k,$d){ return (is_array($a) && isset($a[$k])) ? $a[$k] : $d; }
function mm_dir(){
  // private, out of webroot (was world-writable sys_get_temp_dir()/ca_signal)
  $d = __DIR__ . '/../.ca_data/mp';
  if (!is_dir($d)) { @mkdir($d, 0750, true); }
  return $d;
}
function mm_file(){ return mm_dir() . '/matchmake.json'; }
function mm_ticket(){ return substr(md5(uniqid('', true) . mt_rand()), 0, 12); }
function mm_code(){
  // longer code (6 chars from a 32-symbol alphabet => ~30 bits, ~1e9 space) to
  // blunt brute-forcing; the per-match secret is the real authZ, this just
  // lengthens the "knock". signal.php's clean_room() caps at 8, so 6 is safe.
  $a = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; $c = '';
  for ($i=0;$i<6;$i++){ $c .= $a[mt_rand(0, strlen($a)-1)]; }
  return $c;
}
// high-entropy per-match secret (128-bit). random_bytes is PHP7+, so fall back
// to openssl_random_pseudo_bytes (present on 5.6). QA-audit: NINCS mt_rand
// fallback — az nem CSPRNG, és a secret az EGYETLEN authZ a signal-relay-en;
// inkább 500-zal bukjon a matchmaking, mint hogy kitalálható secretet adjunk.
function mm_secret(){
  if (function_exists('random_bytes')) {
    try { return bin2hex(random_bytes(16)); } catch (Exception $e) {}
  }
  if (function_exists('openssl_random_pseudo_bytes')) {
    $strong = false;
    $b = openssl_random_pseudo_bytes(16, $strong);
    if ($b !== false) { return bin2hex($b); }
  }
  http_response_code(500);
  echo json_encode(array('ok'=>false,'err'=>'no CSPRNG on host'));
  exit;
}
// sanitize a room code exactly like signal.php clean_room() so the file we seed
// matches the file signal.php will look up (uppercase A-Z0-9, max 8 chars).
function mm_clean_room($r){
  $r = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$r));
  return substr($r, 0, 8);
}
// Seed the signal-room record (room_<CODE>.json) with the per-match secret so
// signal.php can validate it independently of matchmake's own state TTL. Done
// under flock; if the file already has a secret we DO NOT overwrite it (so a
// second 'register'/'claim' on the same code can't silently rotate the secret
// out from under a connected pair). Returns the secret actually in force.
function mm_seed_room_secret($code, $secret){
  $code = mm_clean_room($code);
  if ($code === '') { return $secret; }
  $path = mm_dir() . '/room_' . $code . '.json';
  $fh = @fopen($path, 'c+');
  if (!$fh) { return $secret; } // fail-soft: matchmake still returns the secret
  $out = $secret;
  if (flock($fh, LOCK_EX)) {
    $raw = stream_get_contents($fh);
    $data = $raw ? json_decode($raw, true) : null;
    if (!is_array($data)) { $data = array('seq'=>0, 'host'=>array(), 'guest'=>array()); }
    if (isset($data['secret']) && $data['secret'] !== '') {
      $out = $data['secret']; // keep the existing one
    } else {
      $data['secret'] = $secret;
      ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($data));
      fflush($fh);
    }
    flock($fh, LOCK_UN);
  }
  fclose($fh);
  return $out;
}

// per-IP rate limit (fail-open on FS error)
function mm_rate_ok(){
  $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '?';
  $key = preg_replace('/[^a-zA-Z0-9_.:-]/', '_', $ip);
  $f = mm_dir() . '/rl_mm_' . $key . '.json';
  $now = time();
  $fh = @fopen($f, 'c+');
  if (!$fh) { return true; }
  $ok = true;
  if (flock($fh, LOCK_EX)) {
    $raw = stream_get_contents($fh);
    $rec = $raw ? json_decode($raw, true) : null;
    if (!is_array($rec) || (int)mm_get($rec,'start',0) < $now - MM_RATE_WINDOW) {
      $rec = array('start'=>$now, 'count'=>0);
    }
    $rec['count'] = (int)mm_get($rec,'count',0) + 1;
    if ($rec['count'] > MM_RATE_MAX) { $ok = false; }
    ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($rec));
    fflush($fh); flock($fh, LOCK_UN);
  }
  fclose($fh);
  return $ok;
}

// Probabilistic GC of stale per-IP rate-limit files (rl_mm_*). The state file
// (matchmake.json) self-prunes its queue/matches inline, but the rate-limit
// files accumulate one-per-IP forever otherwise (slow inode/disk growth). The
// rate window is ~10s; a 5-minute staleness cutoff is well past any live window.
// Mirrors the probabilistic GC in contact.php / account.php (cheap + guarded).
function mm_gc(){
  if (mt_rand(1, 20) !== 1) { return; }
  $now = time();
  $rls = glob(mm_dir() . '/rl_mm_*.json');
  if (is_array($rls)) {
    foreach ($rls as $rf) { if (@filemtime($rf) < ($now - 300)) { @unlink($rf); } }
  }
}

if (!mm_rate_ok()) { http_response_code(429); echo json_encode(array('ok'=>false,'err'=>'rate limit')); exit; }
mm_gc();

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) { http_response_code(400); echo json_encode(array('ok'=>false,'err'=>'bad json')); exit; }
$action = (string)mm_get($body, 'action', '');

$path = mm_file();
$fh = @fopen($path, 'c+');
if (!$fh) { http_response_code(500); echo json_encode(array('ok'=>false)); exit; }
flock($fh, LOCK_EX);
$raw = stream_get_contents($fh);
$st = $raw ? json_decode($raw, true) : null;
if (!is_array($st)) { $st = array('queue'=>array(), 'matches'=>array()); }
// migrate the old single-slot 'waiting' shape -> queue
if (!isset($st['queue']) || !is_array($st['queue'])) {
  $st['queue'] = array();
  $oldw = mm_get($st, 'waiting', null);
  if (is_array($oldw) && mm_get($oldw,'ticket','') !== '') { $st['queue'][] = $oldw; }
}
unset($st['waiting']);
if (!isset($st['matches']) || !is_array($st['matches'])) { $st['matches'] = array(); }
// PUBLIC ROOMS registry (code -> {name,theme,mode,players,ts}) for lobby discovery
if (!isset($st['rooms']) || !is_array($st['rooms'])) { $st['rooms'] = array(); }
$now = time();

// prune stale waiting tickets (queue) + stale matches + stale public rooms
$q = array();
foreach ($st['queue'] as $w) {
  if (is_array($w) && mm_get($w,'ticket','') !== '' && mm_get($w,'ts',0) >= $now - MM_WAIT_TTL) {
    $q[] = $w;
  }
}
$st['queue'] = $q;
foreach ($st['matches'] as $k => $m) {
  if (mm_get($m, 'ts', 0) < $now - MM_MATCH_TTL) { unset($st['matches'][$k]); }
}
foreach ($st['rooms'] as $rc => $rm) {
  if (!is_array($rm) || mm_get($rm,'ts',0) < $now - MM_ROOM_TTL) { unset($st['rooms'][$rc]); }
}

$resp = array('ok'=>true);

if ($action === 'find') {
  // QA P2: a name korlátlan + szűretlen volt (queue-fájl felfújás). Cap 24 +
  // kontroll-karakterek ki (a lobby-UI esc()-el véd XSS ellen, ez a tárolást védi).
  $name = (string)mm_get($body, 'name', 'Játékos');
  $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name);
  if (function_exists('mb_substr')) { $name = mb_substr($name, 0, 24, 'UTF-8'); }
  else { $name = substr($name, 0, 24); }
  if ($name === '') { $name = 'Játékos'; }
  // pop the oldest still-waiting ticket (FIFO) and pair with it
  $partner = null;
  while (count($st['queue']) > 0) {
    $cand = array_shift($st['queue']);
    if (is_array($cand) && mm_get($cand,'ticket','') !== '') { $partner = $cand; break; }
  }
  if ($partner) {
    $room = mm_code();
    $secret = mm_secret();
    // mirror the secret into the signal-room record so signal.php can validate
    // it; if the file somehow already carries a secret, adopt that one for both.
    $secret = mm_seed_room_secret($room, $secret);
    $hostTicket = $partner['ticket'];
    $myTicket = mm_ticket();
    $st['matches'][$hostTicket] = array('room'=>$room, 'role'=>'host', 'secret'=>$secret, 'ts'=>$now);
    $st['matches'][$myTicket]   = array('room'=>$room, 'role'=>'guest', 'secret'=>$secret, 'ts'=>$now);
    $resp['status'] = 'matched'; $resp['room'] = $room; $resp['role'] = 'guest'; $resp['secret'] = $secret; $resp['ticket'] = $myTicket;
  } else {
    $myTicket = mm_ticket();
    if (count($st['queue']) < MM_QUEUE_MAX) {
      $st['queue'][] = array('ticket'=>$myTicket, 'name'=>$name, 'ts'=>$now);
      $resp['status'] = 'waiting'; $resp['ticket'] = $myTicket;
    } else {
      // queue full — tell the client to retry shortly
      $resp = array('ok'=>false, 'err'=>'queue full');
    }
  }
} elseif ($action === 'poll') {
  $ticket = (string)mm_get($body, 'ticket', '');
  if ($ticket !== '' && isset($st['matches'][$ticket])) {
    $m = $st['matches'][$ticket];
    $resp['status'] = 'matched'; $resp['room'] = mm_get($m,'room',''); $resp['role'] = mm_get($m,'role','guest');
    $resp['secret'] = mm_get($m,'secret','');
  } else {
    // refresh our waiting timestamp so we don't expire while actively polling
    foreach ($st['queue'] as $i => $w) {
      if (mm_get($w,'ticket','') === $ticket) { $st['queue'][$i]['ts'] = $now; break; }
    }
    $resp['status'] = 'waiting';
  }
} elseif ($action === 'register') {
  // Manual HOST: mint a room + secret and seed the signal-room record. The host
  // gets the secret back; the guest will obtain it via 'claim' using the code as
  // the knock. If a code is supplied we sanitize + reuse it (lets the host keep
  // its own pretty code); otherwise we pick one. Idempotent on an existing room:
  // re-registering the same code returns the SAME secret (so a host retry won't
  // rotate the secret away from an already-claimed guest).
  $room = mm_clean_room(mm_get($body, 'room', ''));
  if ($room === '') { $room = mm_code(); }
  $secret = mm_seed_room_secret($room, mm_secret());
  $resp['room'] = $room; $resp['secret'] = $secret;
  // PUBLIC ROOM listing: a host may advertise an OPEN room in the lobby so
  // others can discover + join it directly (no code typing). Private rooms are
  // never listed (only joinable by code). Re-registering refreshes the entry.
  if (mm_get($body, 'public', false)) {
    if (count($st['rooms']) < MM_ROOMS_MAX || isset($st['rooms'][$room])) {
      $rn = (string)mm_get($body, 'name', 'Játékos');
      $rn = preg_replace('/[\x00-\x1F\x7F]/', '', $rn);
      $rn = function_exists('mb_substr') ? mb_substr($rn,0,24,'UTF-8') : substr($rn,0,24);
      if ($rn === '') { $rn = 'Játékos'; }
      $th = preg_replace('/[^a-z]/', '', strtolower((string)mm_get($body,'theme','')));
      $md = preg_replace('/[^a-z]/', '', strtolower((string)mm_get($body,'mode','')));
      $mx = (int)mm_get($body,'max',2); if ($mx < 2) { $mx = 2; } if ($mx > 4) { $mx = 4; }
      $st['rooms'][$room] = array('name'=>$rn, 'theme'=>substr($th,0,16),
        'mode'=>substr($md,0,16), 'players'=>(int)mm_get($body,'players',1), 'max'=>$mx, 'ts'=>$now);
    }
  } else {
    // explicitly private (or toggled to private) -> ensure it is NOT listed
    if (isset($st['rooms'][$room])) { unset($st['rooms'][$room]); }
  }
} elseif ($action === 'rooms') {
  // LOBBY DISCOVERY: hand back the current list of OPEN public rooms.
  $list = array();
  foreach ($st['rooms'] as $rc => $rm) {
    if (!is_array($rm)) { continue; }
    $list[] = array('code'=>$rc, 'name'=>mm_get($rm,'name','Játékos'),
      'theme'=>mm_get($rm,'theme',''), 'mode'=>mm_get($rm,'mode',''),
      'players'=>(int)mm_get($rm,'players',1), 'max'=>(int)mm_get($rm,'max',2),
      'age'=>($now - (int)mm_get($rm,'ts',$now)));
  }
  $resp['rooms'] = $list;
} elseif ($action === 'roombeat') {
  // HOST HEARTBEAT: keep a public room listed while it waits for a player.
  $room = mm_clean_room(mm_get($body, 'room', ''));
  if ($room !== '' && isset($st['rooms'][$room])) {
    $st['rooms'][$room]['ts'] = $now;
    if (($p = (int)mm_get($body,'players',0)) > 0) { $st['rooms'][$room]['players'] = $p; }
    $mxb = (int)mm_get($body,'max',0); if ($mxb >= 2 && $mxb <= 4) { $st['rooms'][$room]['max'] = $mxb; }
  }
} elseif ($action === 'unlist') {
  // remove a public room from the lobby (match started / host left / went private)
  $room = mm_clean_room(mm_get($body, 'room', ''));
  if ($room !== '' && isset($st['rooms'][$room])) { unset($st['rooms'][$room]); }
} elseif ($action === 'claim') {
  // Manual GUEST: present the room code (the knock). If a room record with a
  // secret exists, hand back that secret so the guest can talk to signal.php.
  // We DO NOT reveal whether the room exists when there is no secret — a missing
  // room and an unsecured/legacy room both look the same (ok:false, no secret).
  $room = mm_clean_room(mm_get($body, 'room', ''));
  $secret = '';
  if ($room !== '') {
    $rp = mm_dir() . '/room_' . $room . '.json';
    if (is_file($rp)) {
      $rd = json_decode(@file_get_contents($rp), true);
      if (is_array($rd) && isset($rd['secret']) && $rd['secret'] !== '') { $secret = $rd['secret']; }
    }
  }
  if ($secret !== '') { $resp['secret'] = $secret; }
  else { $resp = array('ok'=>false, 'err'=>'no room'); }
} elseif ($action === 'cancel') {
  $ticket = (string)mm_get($body, 'ticket', '');
  $q = array();
  foreach ($st['queue'] as $w) { if (mm_get($w,'ticket','') !== $ticket) { $q[] = $w; } }
  $st['queue'] = $q;
  if ($ticket !== '' && isset($st['matches'][$ticket])) { unset($st['matches'][$ticket]); }
} else {
  $resp = array('ok'=>false, 'err'=>'bad action');
}

// re-index matches (unset above can leave gaps) so json_encode keeps it an object
$st['matches'] = is_array($st['matches']) ? $st['matches'] : array();

ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($st));
fflush($fh); flock($fh, LOCK_UN); fclose($fh);
echo json_encode($resp);
