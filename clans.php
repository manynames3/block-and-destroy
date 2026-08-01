<?php
/* ============================================================================
 * Squeak & Destroy — CLANS ("Crypts") backend (PHP 5.6 compatible — NO `??`).
 * File-backed, stateful clan membership + clan ranking. Cheap, shared-hosting.
 * One JSON file per clan, plus a member index (uid -> clanId) for O(1) lookups.
 *
 *   POST {action:'create', uid, token, name, tag}
 *        -> { ok:true, clan }   | 409 already_in_clan | 409 tag_taken | 400 bad input
 *   POST {action:'join',   uid, token, tag|id}
 *        -> { ok:true, clan }   | 409 already_in_clan | 409 full | 404 not_found
 *   POST {action:'leave',  uid, token}
 *        -> { ok:true }   (leader leaving promotes oldest member; empty clan deleted)
 *   POST {action:'contribute', uid, token, points}
 *        -> { ok:true, points }   (member-only; points clamped 0..500)
 *   GET  ?action=mine&uid=&token=  -> { ok:true, clan:<clan>|null }
 *   GET  ?action=info&tag=|&id=    -> { ok:true, clan:<public clan> }
 *   GET  ?action=top&limit=20      -> { ok:true, clans:[ {name,tag,points,memberCount} ] }
 *
 * Clan record: { id, name, tag, leader:uid, members:[{uid,name,joinedTs}],
 *                points:0, createdTs, updatedTs }
 *
 * SECURITY (mirrors leaderboard.php / match.php, 2026-06):
 *   - ALL writes require a valid account token / SkyNet SSO; anon/forged rejected.
 *   - member display name is SERVER-DERIVED from the validated identity.
 *   - per-IP file-based rate limit.
 *   - data + rate-limit + clan files live OUTSIDE the webroot (../.ca_data/clans/).
 *   - atomic writes + flock (mirrors leaderboard).
 *   - CORS narrowed to same-origin; no-store; OPTIONS -> 204.
 *   - probabilistic GC: prune clans whose updatedTs is older than ~90 days
 *     (and clean their member-index entries).
 * ==========================================================================*/
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
// same-origin only; reflect Origin only if it matches our host.
$__host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
$__org  = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
if ($__org !== '' && $__host !== '' && preg_replace('#^https?://#i','',$__org) === $__host) {
  header('Access-Control-Allow-Origin: ' . $__org);
  header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

define('C_MEMBER_CAP', 30);       // max members per clan
define('C_RATE_WINDOW', 60);      // seconds
define('C_RATE_MAX', 20);         // max POSTs per IP per window
define('C_POINTS_MAX', 500);      // per-contribute clamp
define('C_GC_AGE', 7776000);      // ~90 days in seconds
define('C_TAG_RE', '/^[A-Z0-9]{2,4}$/');

// ── WEEKLY CLAN GOAL ─────────────────────────────────────────────────────
//   The clan collectively accumulates `weekPoints` over an ISO week. When it
//   reaches C_WEEK_TARGET the goal is "reached" and the clan unlocks a purely
//   cosmetic coin reward (client-side bragging only — no gameplay impact, so
//   no exploit surface). The counter resets when the ISO year-week rolls over.
//
//   Numbers (tunable, kept deliberately modest for a small player base):
//     C_WEEK_TARGET = 500 pts/week   — a clan of a few active rats can hit it.
//     C_WEEK_REWARD = 250 coins      — cosmetic-only payout shown in the UI.
define('C_WEEK_TARGET', 500);     // collective points needed per ISO week
define('C_WEEK_REWARD', 250);     // cosmetic coins granted when target reached

// ISO year-week id, e.g. "2026-W24". Mirrors the date-based GC/TTL logic:
// server-side time only, never trust the client. Returns a stable string for
// the whole ISO week so a simple !== comparison detects a week rollover.
function c_week_id($ts){
  $ts = (int)$ts;
  if ($ts <= 0) { $ts = time(); }
  // 'o' = ISO-8601 year, 'W' = ISO-8601 week number (01..53). Both PHP 5.6-safe.
  return date('o', $ts) . '-W' . date('W', $ts);
}
// seconds left until the current ISO week ends (next Monday 00:00 local).
function c_week_seconds_left($ts){
  $ts = (int)$ts;
  if ($ts <= 0) { $ts = time(); }
  // 'N' = ISO weekday (1=Mon..7=Sun). End of week = start of next Monday.
  $dow = (int)date('N', $ts);
  $secsToday = (int)date('H', $ts) * 3600 + (int)date('i', $ts) * 60 + (int)date('s', $ts);
  $left = (7 - $dow) * 86400 + (86400 - $secsToday);
  if ($left < 0) { $left = 0; }
  return $left;
}
// normalize a clan's weekly-goal fields against the current week, resetting
// weekPoints when the stored weekId is stale. Mutates+returns $clan in place.
function c_week_sync(&$clan){
  if (!is_array($clan)) { return $clan; }
  $cur = c_week_id(time());
  $stored = (string)c_get($clan, 'weekId', '');
  if ($stored !== $cur) {
    $clan['weekId']     = $cur;
    $clan['weekPoints'] = 0;
  } else {
    $clan['weekPoints'] = (int)c_get($clan, 'weekPoints', 0);
  }
  return $clan;
}

// ── data root OUTSIDE the web-served dir ──────────────────────────────
function c_dir(){
  $d = __DIR__ . '/../.ca_data';
  if (!is_dir($d)) { @mkdir($d, 0750, true); }
  return $d;
}
function c_clans_dir(){
  $d = c_dir() . '/clans';
  if (!is_dir($d)) { @mkdir($d, 0750, true); }
  return $d;
}
function c_acc_file(){ return c_dir() . '/accounts.json'; }
function c_clan_file($id){ return c_clans_dir() . '/' . $id . '.json'; }
function c_index_file(){ return c_clans_dir() . '/_members.json'; }

function c_get($a,$k,$d){ return (is_array($a) && isset($a[$k])) ? $a[$k] : $d; }

// ── mbstring guard (mirror account.php/contact.php): fall back to byte-length
//    if ext-mbstring is absent so the endpoint never fatals (500) on such a host.
function c_strlen($s){
  return function_exists('mb_strlen') ? mb_strlen((string)$s, 'UTF-8') : strlen((string)$s);
}

// ── input sanitizers ───────────────────────────────────────────────────
function c_clean_name($n){
  $n = preg_replace('/[\x00-\x1F\x7F]/u', '', (string)$n);
  $n = trim($n);
  return $n; // length validated by caller (3..24)
}
function c_valid_tag($tag){
  $tag = strtoupper(trim((string)$tag));
  return ($tag !== '' && preg_match(C_TAG_RE, $tag)) ? $tag : '';
}
function c_valid_id($id){
  $id = (string)$id;
  return ($id !== '' && preg_match('/^[a-z0-9-]{1,32}$/', $id)) ? $id : '';
}
// build a slug id from a tag: lowercased tag + '-' + 6 hex.
function c_make_id($tag){
  $hex = '';
  for ($i = 0; $i < 6; $i++) { $hex .= dechex(mt_rand(0, 15)); }
  return strtolower($tag) . '-' . $hex;
}

function c_load($fh){
  $raw = stream_get_contents($fh);
  $data = $raw ? json_decode($raw, true) : null;
  if (!is_array($data)) { $data = array(); }
  return $data;
}

// ── per-IP file-based rate limit (mirrors leaderboard/match) ───────────
function c_rate_ok(){
  $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '?';
  $key = preg_replace('/[^a-zA-Z0-9_.:-]/', '_', $ip);
  $f = c_dir() . '/rl_clans_' . $key . '.json';
  $now = time();
  $fh = @fopen($f, 'c+');
  if (!$fh) { return true; } // fail-open on FS error
  $ok = true;
  if (flock($fh, LOCK_EX)) {
    $raw = stream_get_contents($fh);
    $rec = $raw ? json_decode($raw, true) : null;
    if (!is_array($rec) || (int)c_get($rec,'start',0) < $now - C_RATE_WINDOW) {
      $rec = array('start'=>$now, 'count'=>0);
    }
    $rec['count'] = (int)c_get($rec,'count',0) + 1;
    if ($rec['count'] > C_RATE_MAX) { $ok = false; }
    ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($rec));
    fflush($fh); flock($fh, LOCK_UN);
  }
  fclose($fh);
  return $ok;
}

// ── account / SSO identity helpers (mirror leaderboard.php / match.php) ─
function c_load_json_file($f){
  if (!is_file($f)) { return array(); }
  $j = json_decode(@file_get_contents($f), true);
  return is_array($j) ? $j : array();
}
// resolve the verified display identity for a (uid, token) pair.
// returns array('name'=>..,'uid'=>..) or null if not authorized.
// token check — supports hashed storage (thash) AND legacy plaintext (token),
// with a 90-day expiry (rows without tiat are grandfathered fresh). Mirrors
// account.php tok_match()/token_fresh() so auth never drifts between endpoints.
function c_tok_ok($row, $token){
  if (!is_array($row) || $token === '') { return false; }
  $ok = false;
  if (isset($row['thash']))      { $ok = hash_equals($row['thash'], hash('sha256', $token)); }
  else if (isset($row['token'])) { $ok = hash_equals($row['token'], $token); }   // legacy plaintext
  if (!$ok) { return false; }
  $iat = isset($row['tiat']) ? (int)$row['tiat'] : 0;
  if ($iat > 0 && (time() - $iat) >= 90*86400) { return false; }   // token expired
  return true;
}
function c_identity($uid, $token, $ACC_FILE){
  $uid   = (string)$uid;
  $token = (string)$token;
  // SkyNet SSO identity (uid sn:<Nev>) — verified via includes/auth.php session
  if ($uid === '' || strpos($uid, 'sn:') === 0) {
    try {
      // optional host-app SSO (absent on a standalone install)
      if (file_exists(__DIR__ . '/../includes/auth.php')) {
        require_once __DIR__ . '/../includes/auth.php';
      }
      if (function_exists('currentUser')) {
        $cu = currentUser();
        if ($cu && !empty($cu['nev'])) {
          return array('name'=>$cu['nev'], 'uid'=>'sn:'.$cu['nev']);
        }
      }
    } catch (Exception $e) {}
    return null;
  }
  // regular account (uid acc:<key>) — verified via matching token
  if (strpos($uid, 'acc:') === 0) {
    if ($token === '') { return null; }
    $accs = c_load_json_file($ACC_FILE);
    $key  = substr($uid, 4);
    if (isset($accs[$key]) && c_tok_ok($accs[$key], $token)) {
      return array('name'=>$accs[$key]['user'], 'uid'=>'acc:'.$key);
    }
  }
  return null;
}

// ── member index helpers (uid -> clanId), flock-protected ──────────────
function c_index_read(){
  $f = c_index_file();
  if (!is_file($f)) { return array(); }
  $fh = @fopen($f, 'r');
  if (!$fh) { return array(); }
  flock($fh, LOCK_SH);
  $idx = c_load($fh);
  flock($fh, LOCK_UN); fclose($fh);
  return $idx;
}
// apply a set of uid=>clanId|null mutations to the index atomically.
// null value removes the uid entry.
function c_index_apply($mutations){
  if (!is_array($mutations) || !count($mutations)) { return; }
  $f = c_index_file();
  $fh = @fopen($f, 'c+');
  if (!$fh) { return; }
  if (flock($fh, LOCK_EX)) {
    $idx = c_load($fh);
    foreach ($mutations as $u => $cid) {
      if ($cid === null) { unset($idx[$u]); }
      else { $idx[$u] = $cid; }
    }
    ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($idx));
    fflush($fh); flock($fh, LOCK_UN);
  }
  fclose($fh);
}
function c_index_lookup($uid){
  $idx = c_index_read();
  return isset($idx[$uid]) ? $idx[$uid] : '';
}

// ── clan file IO ───────────────────────────────────────────────────────
function c_clan_read($id){
  $id = c_valid_id($id);
  if ($id === '') { return null; }
  $f = c_clan_file($id);
  if (!is_file($f)) { return null; }
  $fh = @fopen($f, 'r');
  if (!$fh) { return null; }
  flock($fh, LOCK_SH);
  $raw = stream_get_contents($fh);
  flock($fh, LOCK_UN); fclose($fh);
  $clan = $raw ? json_decode($raw, true) : null;
  return is_array($clan) ? $clan : null;
}
function c_clan_write($clan){
  $id = c_valid_id(c_get($clan, 'id', ''));
  if ($id === '') { return false; }
  $f = c_clan_file($id);
  $fh = @fopen($f, 'c+');
  if (!$fh) { return false; }
  $ok = false;
  if (flock($fh, LOCK_EX)) {
    ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($clan));
    fflush($fh); flock($fh, LOCK_UN); $ok = true;
  }
  fclose($fh);
  return $ok;
}
// scan every clan file; returns array of clan records.
function c_all_clans(){
  $out = array();
  $files = @glob(c_clans_dir() . '/*.json');
  if (!is_array($files)) { return $out; }
  foreach ($files as $f) {
    $base = basename($f, '.json');
    if ($base === '_members') { continue; }      // skip the index
    if (c_valid_id($base) === '') { continue; }
    $raw = @file_get_contents($f);
    $clan = $raw ? json_decode($raw, true) : null;
    if (is_array($clan)) { $out[] = $clan; }
  }
  return $out;
}
function c_find_by_tag($tag){
  $tag = c_valid_tag($tag);
  if ($tag === '') { return null; }
  foreach (c_all_clans() as $clan) {
    if (strtoupper((string)c_get($clan, 'tag', '')) === $tag) { return $clan; }
  }
  return null;
}

// ── strip private fields for public output (no tokens are stored anyway) ─
function c_public_clan($clan){
  if (!is_array($clan)) { return null; }
  $members = array();
  $raw = c_get($clan, 'members', array());
  if (is_array($raw)) {
    foreach ($raw as $m) {
      $members[] = array(
        'uid'      => c_get($m, 'uid', ''),
        'name'     => c_get($m, 'name', '—'),
        'joinedTs' => (int)c_get($m, 'joinedTs', 0)
      );
    }
  }
  // weekly goal view (computed against the current ISO week; non-destructive
  // copy so we don't have to persist on a pure read). Stale weeks read as 0.
  $curWeek    = c_week_id(time());
  $storedWeek = (string)c_get($clan, 'weekId', '');
  $weekPoints = ($storedWeek === $curWeek) ? (int)c_get($clan, 'weekPoints', 0) : 0;
  $reached    = ($weekPoints >= C_WEEK_TARGET);
  return array(
    'id'        => c_get($clan, 'id', ''),
    'name'      => c_get($clan, 'name', ''),
    'tag'       => c_get($clan, 'tag', ''),
    'leader'    => c_get($clan, 'leader', ''),
    'members'   => $members,
    'points'    => (int)c_get($clan, 'points', 0),
    'createdTs' => (int)c_get($clan, 'createdTs', 0),
    'updatedTs' => (int)c_get($clan, 'updatedTs', 0),
    // weekly clan goal (see C_WEEK_* + c_week_* helpers above)
    'weekId'      => $curWeek,
    'weekPoints'  => $weekPoints,
    'weekTarget'  => C_WEEK_TARGET,
    'weekReached' => $reached,
    'weekReward'  => C_WEEK_REWARD,
    'weekSecsLeft'=> c_week_seconds_left(time())
  );
}

function c_cmp_points($a, $b){
  $pa = (int)c_get($a, 'points', 0); $pb = (int)c_get($b, 'points', 0);
  if ($pa === $pb) { return 0; }
  return ($pa > $pb) ? -1 : 1;
}

// ── probabilistic GC: prune clans stale > C_GC_AGE, clean index entries ─
function c_gc(){
  $now = time();
  $purgeUids = array();
  foreach (c_all_clans() as $clan) {
    $upd = (int)c_get($clan, 'updatedTs', 0);
    if ($upd > 0 && $upd < $now - C_GC_AGE) {
      $id = c_valid_id(c_get($clan, 'id', ''));
      if ($id === '') { continue; }
      $mem = c_get($clan, 'members', array());
      if (is_array($mem)) {
        foreach ($mem as $m) {
          $u = (string)c_get($m, 'uid', '');
          if ($u !== '') { $purgeUids[$u] = null; }
        }
      }
      @unlink(c_clan_file($id));
    }
  }
  if (count($purgeUids)) { c_index_apply($purgeUids); }
}

$ACC_FILE = c_acc_file();

// run GC ~5% of requests (cheap, best-effort)
if (mt_rand(1, 100) <= 5) { try { c_gc(); } catch (Exception $e) {} }

$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

// ────────────────────────────────────────────────────────────────────── POST
if ($method === 'POST') {
  if (!c_rate_ok()) { http_response_code(429); echo json_encode(array('ok'=>false,'err'=>'rate limit')); exit; }

  $body = json_decode(file_get_contents('php://input'), true);
  if (!is_array($body)) { http_response_code(400); echo json_encode(array('ok'=>false,'err'=>'bad json')); exit; }

  $action = (string)c_get($body, 'action', '');

  // identity MUST come from a verified token/SSO — reject anonymous writes.
  $uid   = (string)c_get($body, 'uid', '');
  $token = (string)c_get($body, 'token', '');
  $ident = c_identity($uid, $token, $ACC_FILE);
  if (!$ident) { http_response_code(401); echo json_encode(array('ok'=>false,'err'=>'auth')); exit; }
  $myUid  = $ident['uid'];
  $myName = c_clean_name($ident['name']);
  if ($myName === '') { $myName = 'Névtelen'; }

  // ── create ───────────────────────────────────────────────────────────
  if ($action === 'create') {
    if (c_index_lookup($myUid) !== '') {
      http_response_code(409); echo json_encode(array('ok'=>false,'err'=>'already_in_clan')); exit;
    }
    $name = c_clean_name(c_get($body, 'name', ''));
    if (c_strlen($name) < 3 || c_strlen($name) > 24) {
      http_response_code(400); echo json_encode(array('ok'=>false,'err'=>'bad_name')); exit;
    }
    $tag = c_valid_tag(c_get($body, 'tag', ''));
    if ($tag === '') {
      http_response_code(400); echo json_encode(array('ok'=>false,'err'=>'bad_tag')); exit;
    }
    if (c_find_by_tag($tag) !== null) {
      http_response_code(409); echo json_encode(array('ok'=>false,'err'=>'tag_taken')); exit;
    }
    // pick a non-colliding id
    $id = '';
    for ($try = 0; $try < 5; $try++) {
      $cand = c_make_id($tag);
      if (!is_file(c_clan_file($cand))) { $id = $cand; break; }
    }
    if ($id === '') { http_response_code(500); echo json_encode(array('ok'=>false,'err'=>'id')); exit; }
    $now = time();
    $clan = array(
      'id'        => $id,
      'name'      => $name,
      'tag'       => $tag,
      'leader'    => $myUid,
      'members'   => array(array('uid'=>$myUid, 'name'=>$myName, 'joinedTs'=>$now)),
      'points'    => 0,
      'weekId'    => c_week_id($now),
      'weekPoints'=> 0,
      'createdTs' => $now,
      'updatedTs' => $now
    );
    if (!c_clan_write($clan)) { http_response_code(500); echo json_encode(array('ok'=>false,'err'=>'io')); exit; }
    c_index_apply(array($myUid => $id));
    echo json_encode(array('ok'=>true, 'clan'=>c_public_clan($clan))); exit;
  }

  // ── join ─────────────────────────────────────────────────────────────
  if ($action === 'join') {
    if (c_index_lookup($myUid) !== '') {
      http_response_code(409); echo json_encode(array('ok'=>false,'err'=>'already_in_clan')); exit;
    }
    $id = c_valid_id(c_get($body, 'id', ''));
    if ($id === '') {
      $byTag = c_find_by_tag(c_get($body, 'tag', ''));
      if ($byTag !== null) { $id = c_valid_id(c_get($byTag, 'id', '')); }
    }
    if ($id === '') { http_response_code(404); echo json_encode(array('ok'=>false,'err'=>'not_found')); exit; }

    // lock + mutate the clan file
    $f = c_clan_file($id);
    if (!is_file($f)) { http_response_code(404); echo json_encode(array('ok'=>false,'err'=>'not_found')); exit; }
    $fh = @fopen($f, 'c+');
    if (!$fh) { http_response_code(500); echo json_encode(array('ok'=>false,'err'=>'io')); exit; }
    if (!flock($fh, LOCK_EX)) { fclose($fh); http_response_code(500); echo json_encode(array('ok'=>false,'err'=>'lock')); exit; }
    $raw = stream_get_contents($fh);
    $clan = $raw ? json_decode($raw, true) : null;
    if (!is_array($clan)) { flock($fh, LOCK_UN); fclose($fh); http_response_code(404); echo json_encode(array('ok'=>false,'err'=>'not_found')); exit; }
    $members = c_get($clan, 'members', array());
    if (!is_array($members)) { $members = array(); }
    // already a member? (defensive — index should have caught it)
    foreach ($members as $m) {
      if ((string)c_get($m, 'uid', '') === $myUid) {
        flock($fh, LOCK_UN); fclose($fh);
        http_response_code(409); echo json_encode(array('ok'=>false,'err'=>'already_in_clan')); exit;
      }
    }
    if (count($members) >= C_MEMBER_CAP) {
      flock($fh, LOCK_UN); fclose($fh);
      http_response_code(409); echo json_encode(array('ok'=>false,'err'=>'full')); exit;
    }
    $members[] = array('uid'=>$myUid, 'name'=>$myName, 'joinedTs'=>time());
    $clan['members']   = $members;
    $clan['updatedTs'] = time();
    ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($clan));
    fflush($fh); flock($fh, LOCK_UN); fclose($fh);
    c_index_apply(array($myUid => $id));
    echo json_encode(array('ok'=>true, 'clan'=>c_public_clan($clan))); exit;
  }

  // ── leave ────────────────────────────────────────────────────────────
  if ($action === 'leave') {
    $id = c_index_lookup($myUid);
    if ($id === '' || c_valid_id($id) === '') {
      // not in a clan — idempotent success, clean any stray index entry
      c_index_apply(array($myUid => null));
      echo json_encode(array('ok'=>true)); exit;
    }
    $f = c_clan_file($id);
    if (!is_file($f)) { c_index_apply(array($myUid => null)); echo json_encode(array('ok'=>true)); exit; }
    $fh = @fopen($f, 'c+');
    if (!$fh) { http_response_code(500); echo json_encode(array('ok'=>false,'err'=>'io')); exit; }
    if (!flock($fh, LOCK_EX)) { fclose($fh); http_response_code(500); echo json_encode(array('ok'=>false,'err'=>'lock')); exit; }
    $raw = stream_get_contents($fh);
    $clan = $raw ? json_decode($raw, true) : null;
    if (!is_array($clan)) {
      flock($fh, LOCK_UN); fclose($fh);
      c_index_apply(array($myUid => null));
      echo json_encode(array('ok'=>true)); exit;
    }
    $members = c_get($clan, 'members', array());
    if (!is_array($members)) { $members = array(); }
    $kept = array();
    foreach ($members as $m) {
      if ((string)c_get($m, 'uid', '') !== $myUid) { $kept[] = $m; }
    }
    if (!count($kept)) {
      // last member left -> delete the clan file
      ftruncate($fh, 0); flock($fh, LOCK_UN); fclose($fh);
      @unlink($f);
      c_index_apply(array($myUid => null));
      echo json_encode(array('ok'=>true)); exit;
    }
    // if the leader left, promote the oldest remaining member (min joinedTs)
    $leader = (string)c_get($clan, 'leader', '');
    if ($leader === $myUid) {
      $oldest = $kept[0];
      foreach ($kept as $m) {
        if ((int)c_get($m, 'joinedTs', 0) < (int)c_get($oldest, 'joinedTs', 0)) { $oldest = $m; }
      }
      $clan['leader'] = (string)c_get($oldest, 'uid', '');
    }
    $clan['members']   = $kept;
    $clan['updatedTs'] = time();
    ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($clan));
    fflush($fh); flock($fh, LOCK_UN); fclose($fh);
    c_index_apply(array($myUid => null));
    echo json_encode(array('ok'=>true)); exit;
  }

  // ── contribute ───────────────────────────────────────────────────────
  if ($action === 'contribute') {
    $id = c_index_lookup($myUid);
    if ($id === '' || c_valid_id($id) === '') {
      http_response_code(403); echo json_encode(array('ok'=>false,'err'=>'not_member')); exit;
    }
    $pts = (int)c_get($body, 'points', 0);
    if ($pts < 0) { $pts = 0; }
    if ($pts > C_POINTS_MAX) { $pts = C_POINTS_MAX; }
    $f = c_clan_file($id);
    if (!is_file($f)) { http_response_code(403); echo json_encode(array('ok'=>false,'err'=>'not_member')); exit; }
    $fh = @fopen($f, 'c+');
    if (!$fh) { http_response_code(500); echo json_encode(array('ok'=>false,'err'=>'io')); exit; }
    if (!flock($fh, LOCK_EX)) { fclose($fh); http_response_code(500); echo json_encode(array('ok'=>false,'err'=>'lock')); exit; }
    $raw = stream_get_contents($fh);
    $clan = $raw ? json_decode($raw, true) : null;
    if (!is_array($clan)) { flock($fh, LOCK_UN); fclose($fh); http_response_code(403); echo json_encode(array('ok'=>false,'err'=>'not_member')); exit; }
    // verify membership inside the locked record
    $isMember = false;
    $members = c_get($clan, 'members', array());
    if (is_array($members)) {
      foreach ($members as $m) { if ((string)c_get($m, 'uid', '') === $myUid) { $isMember = true; break; } }
    }
    if (!$isMember) { flock($fh, LOCK_UN); fclose($fh); http_response_code(403); echo json_encode(array('ok'=>false,'err'=>'not_member')); exit; }
    $total = (int)c_get($clan, 'points', 0) + $pts;
    $clan['points']    = $total;
    // weekly goal: reset if the ISO week rolled over, then accumulate.
    c_week_sync($clan);
    $clan['weekPoints'] = (int)$clan['weekPoints'] + $pts;
    $weekTotal   = (int)$clan['weekPoints'];
    $weekReached = ($weekTotal >= C_WEEK_TARGET);
    $clan['updatedTs'] = time();
    ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($clan));
    fflush($fh); flock($fh, LOCK_UN); fclose($fh);
    echo json_encode(array(
      'ok'=>true,
      'points'=>$total,
      'weekPoints'=>$weekTotal,
      'weekTarget'=>C_WEEK_TARGET,
      'weekReached'=>$weekReached,
      'weekReward'=>C_WEEK_REWARD
    )); exit;
  }

  http_response_code(400);
  echo json_encode(array('ok'=>false, 'err'=>'bad_action')); exit;
}

// ────────────────────────────────────────────────────────────────────── GET
if ($method === 'GET') {
  $action = (string)c_get($_GET, 'action', '');

  // ?action=mine — the verified caller's clan (via the index)
  if ($action === 'mine') {
    $uid   = (string)c_get($_GET, 'uid', '');
    $token = (string)c_get($_GET, 'token', '');
    $ident = c_identity($uid, $token, $ACC_FILE);
    if (!$ident) { http_response_code(401); echo json_encode(array('ok'=>false,'err'=>'auth')); exit; }
    $id = c_index_lookup($ident['uid']);
    $clan = ($id !== '') ? c_clan_read($id) : null;
    echo json_encode(array('ok'=>true, 'clan'=>($clan ? c_public_clan($clan) : null))); exit;
  }

  // ?action=info — public view of one clan (by tag or id)
  if ($action === 'info') {
    $id = c_valid_id(c_get($_GET, 'id', ''));
    $clan = null;
    if ($id !== '') { $clan = c_clan_read($id); }
    else { $clan = c_find_by_tag(c_get($_GET, 'tag', '')); }
    if (!$clan) { http_response_code(404); echo json_encode(array('ok'=>false,'err'=>'not_found')); exit; }
    echo json_encode(array('ok'=>true, 'clan'=>c_public_clan($clan))); exit;
  }

  // ?action=top — clan ranking by points DESC
  if ($action === 'top') {
    $limit = (int)c_get($_GET, 'limit', 20);
    if ($limit < 1) { $limit = 1; }
    if ($limit > 50) { $limit = 50; }
    $clans = c_all_clans();
    usort($clans, 'c_cmp_points');
    $clans = array_slice($clans, 0, $limit);
    $out = array();
    foreach ($clans as $clan) {
      $mem = c_get($clan, 'members', array());
      $out[] = array(
        'name'        => c_get($clan, 'name', ''),
        'tag'         => c_get($clan, 'tag', ''),
        'points'      => (int)c_get($clan, 'points', 0),
        'memberCount' => is_array($mem) ? count($mem) : 0
      );
    }
    echo json_encode(array('ok'=>true, 'clans'=>$out)); exit;
  }

  http_response_code(400);
  echo json_encode(array('ok'=>false, 'err'=>'bad_action')); exit;
}

// ─────────────────────────────────────────────────────── unknown method
http_response_code(405);
echo json_encode(array('ok'=>false, 'err'=>'method not allowed'));
exit;
