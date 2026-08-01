<?php
/* ============================================================================
 * Squeak & Destroy — async challenge standings (PHP 5.6 compatible — NO `??`).
 * File-backed, score-based, BUCKETED PER challenge id (cid). NOT real-time, NO
 * lockstep — this is "best score per player per challenge", exactly like the
 * leaderboard but partitioned into one file per cid. Use-cases:
 *   - daily "Crypt Challenge"  (cid = e.g. "crypt-2026-06-06", many players)
 *   - friend "duels"           (cid = e.g. "duel-abc123",      2 players)
 *
 *   POST {cid,uid,token,score,meta}  -> { ok:true }
 *        cid REQUIRED, matches ^[a-z0-9_-]{1,48}$ (else 400).
 *        token/uid REQUIRED & verified; name + avatar are derived SERVER-SIDE
 *        from the validated identity — clients CANNOT spoof them.
 *        Keeps the player's BEST score for that cid (one entry per uid per cid).
 *        meta is a tiny optional sanitized object ({team,shots,turns,...}).
 *   GET  ?cid=<cid>&limit=20   -> { ok:true, scores:[ {name,avatar,score,ts,meta?}, ... ] }
 *        sorted DESC by score, top `limit` (cap 100).
 *   GET  ?mine=1&uid=&token=   -> { ok:true, cids:[ {cid,score,ts}, ... ] }
 *        the verified user's entries across challenges, most-recent first (inbox).
 *
 * SECURITY (mirrors leaderboard.php, 2026-06):
 *   - writes require a valid account token / SkyNet SSO; anonymous/forged rejected.
 *   - name + avatar come from the server-side account/SSO record, NOT the body.
 *   - per-IP file-based rate limit.
 *   - data + rate-limit files live OUTSIDE the webroot (../.ca_data/matches/).
 *   - CORS narrowed to same-origin.
 *   - probabilistic GC: prune challenge files older than 30 days.
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

define('M_PER_CID_MAX', 500);     // keep at most this many entries per challenge
define('M_RATE_WINDOW', 60);      // seconds
define('M_RATE_MAX', 20);         // max POSTs per IP per window
define('M_SCORE_MAX', 10000000);  // clamp scores to 0..10M
define('M_GC_AGE', 2592000);      // 30 days in seconds
define('M_FILES_MAX', 2000);      // cap number of challenge files on disk
define('M_CID_RE', '/^[a-z0-9_-]{1,48}$/');

// ── data root OUTSIDE the web-served dir ──────────────────────────────
function m_dir(){
  $d = __DIR__ . '/../.ca_data';
  if (!is_dir($d)) { @mkdir($d, 0750, true); }
  return $d;
}
function m_matches_dir(){
  $d = m_dir() . '/matches';
  if (!is_dir($d)) { @mkdir($d, 0750, true); }
  return $d;
}
function m_acc_file(){ return m_dir() . '/accounts.json'; }
function m_file($cid){ return m_matches_dir() . '/' . $cid . '.json'; }

function m_get($a,$k,$d){ return (is_array($a) && isset($a[$k])) ? $a[$k] : $d; }

// mbstring guard (mirror account.php/contact.php): fall back to byte-substr if
// ext-mbstring is absent so the endpoint never fatals (500) on such a host.
function m_substr($s, $start, $len){
  return function_exists('mb_substr') ? mb_substr((string)$s, $start, $len, 'UTF-8') : substr((string)$s, $start, $len);
}

function m_valid_cid($cid){
  $cid = (string)$cid;
  return ($cid !== '' && preg_match(M_CID_RE, $cid)) ? $cid : '';
}

function m_clean_name($n){
  $n = preg_replace('/[\x00-\x1F\x7F]/u', '', (string)$n);
  $n = trim($n);
  if ($n === '') { $n = 'Névtelen'; }
  return m_substr($n, 0, 20);
}
function m_clean_str($s, $max){
  $s = preg_replace('/[\x00-\x1F\x7F]/u', '', (string)$s);
  $s = trim($s);
  return m_substr($s, 0, $max);
}
function m_load($fh){
  $raw = stream_get_contents($fh);
  $data = $raw ? json_decode($raw, true) : null;
  if (!is_array($data)) { $data = array(); }
  return $data;
}
function m_cmp($a, $b){
  $sa = (int)m_get($a, 'score', 0); $sb = (int)m_get($b, 'score', 0);
  if ($sa === $sb) { return 0; }
  return ($sa > $sb) ? -1 : 1;
}

// ── sanitize a tiny optional meta object ───────────────────────────────
// strips control chars, caps key/value sizes and total key count. Only
// scalar values are kept (no nested arrays/objects).
function m_clean_meta($meta){
  if (!is_array($meta)) { return null; }
  $out = array();
  $n = 0;
  foreach ($meta as $k => $v) {
    if ($n >= 12) { break; }                 // cap number of keys
    $k = m_clean_str($k, 24);
    if ($k === '') { continue; }
    if (is_bool($v)) {
      $out[$k] = $v;
    } else if (is_int($v) || is_float($v)) {
      $out[$k] = $v;
    } else if (is_string($v)) {
      $out[$k] = m_clean_str($v, 64);
    } else {
      continue;                               // skip arrays/objects/null
    }
    $n++;
  }
  return $n ? $out : null;
}

// ── per-IP file-based rate limit ───────────────────────────────────────
function m_rate_ok(){
  $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '?';
  $key = preg_replace('/[^a-zA-Z0-9_.:-]/', '_', $ip);
  $f = m_dir() . '/rl_match_' . $key . '.json';
  $now = time();
  $fh = @fopen($f, 'c+');
  if (!$fh) { return true; } // fail-open on FS error (don't lock out legit users)
  $ok = true;
  if (flock($fh, LOCK_EX)) {
    $raw = stream_get_contents($fh);
    $rec = $raw ? json_decode($raw, true) : null;
    if (!is_array($rec) || (int)m_get($rec,'start',0) < $now - M_RATE_WINDOW) {
      $rec = array('start'=>$now, 'count'=>0);
    }
    $rec['count'] = (int)m_get($rec,'count',0) + 1;
    if ($rec['count'] > M_RATE_MAX) { $ok = false; }
    ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($rec));
    fflush($fh); flock($fh, LOCK_UN);
  }
  fclose($fh);
  return $ok;
}

// ── account / SSO identity helpers (mirror leaderboard.php) ────────────
function m_load_json_file($f){
  if (!is_file($f)) { return array(); }
  $j = json_decode(@file_get_contents($f), true);
  return is_array($j) ? $j : array();
}
// resolve the verified display identity for a (uid, token) pair.
// returns array('name'=>..,'avatar'=>..,'uid'=>..) or null if not authorized.
// token check — supports hashed storage (thash) AND legacy plaintext (token),
// with a 90-day expiry (rows without tiat are grandfathered fresh). Mirrors
// account.php tok_match()/token_fresh() so auth never drifts between endpoints.
function m_tok_ok($row, $token){
  if (!is_array($row) || $token === '') { return false; }
  $ok = false;
  if (isset($row['thash']))      { $ok = hash_equals($row['thash'], hash('sha256', $token)); }
  else if (isset($row['token'])) { $ok = hash_equals($row['token'], $token); }   // legacy plaintext
  if (!$ok) { return false; }
  $iat = isset($row['tiat']) ? (int)$row['tiat'] : 0;
  if ($iat > 0 && (time() - $iat) >= 90*86400) { return false; }   // token expired
  return true;
}
function m_identity($uid, $token, $ACC_FILE){
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
          $f = isset($cu['foto']) ? $cu['foto'] : '';
          // see leaderboard.php: CA_AVATAR_BASE makes this deployment-specific
          $ab = getenv('CA_AVATAR_BASE');
          if ($f && strpos($f, 'http') !== 0 && is_string($ab) && $ab !== '') {
            $f = rtrim($ab, '/') . '/' . ltrim($f, '/');
          }
          return array('name'=>$cu['nev'], 'avatar'=>$f, 'uid'=>'sn:'.$cu['nev']);
        }
      }
    } catch (Exception $e) {}
    return null;
  }
  // regular account (uid acc:<key>) — verified via matching token
  if (strpos($uid, 'acc:') === 0) {
    if ($token === '') { return null; }
    $accs = m_load_json_file($ACC_FILE);
    $key  = substr($uid, 4);
    if (isset($accs[$key]) && m_tok_ok($accs[$key], $token)) {
      return array('name'=>$accs[$key]['user'], 'avatar'=>'', 'uid'=>'acc:'.$key);
    }
  }
  return null;
}

// ── only allow avatars on our own domains (no arbitrary URLs) ──────────
function m_safe_avatar($url){
  $url = (string)$url;
  if ($url === '') { return ''; }
  if (strlen($url) > 300) { return ''; }
  if (!preg_match('#^https://([a-z0-9.-]+\.)?lospolo\.hu/#i', $url)) { return ''; }
  return $url;
}

// ── probabilistic GC: prune stale challenge files, cap file count ──────
function m_gc(){
  $dir = m_matches_dir();
  $now = time();
  $files = @glob($dir . '/*.json');
  if (!is_array($files)) { return; }
  $alive = array();
  foreach ($files as $f) {
    $mt = @filemtime($f);
    if ($mt === false) { continue; }
    if ($mt < $now - M_GC_AGE) { @unlink($f); continue; }
    $alive[$f] = $mt;
  }
  // if still over the cap, drop the oldest until under it
  if (count($alive) > M_FILES_MAX) {
    asort($alive); // oldest first
    $excess = count($alive) - M_FILES_MAX;
    foreach ($alive as $f => $mt) {
      if ($excess <= 0) { break; }
      @unlink($f); $excess--;
    }
  }
}

$ACC_FILE = m_acc_file();

// run GC ~5% of requests (cheap, best-effort)
if (mt_rand(1, 100) <= 5) { try { m_gc(); } catch (Exception $e) {} }

$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

// ────────────────────────────────────────────────────────────────────── POST
if ($method === 'POST') {
  if (!m_rate_ok()) { http_response_code(429); echo json_encode(array('ok'=>false,'err'=>'rate limit')); exit; }

  $body = json_decode(file_get_contents('php://input'), true);
  if (!is_array($body)) { http_response_code(400); echo json_encode(array('ok'=>false,'err'=>'bad json')); exit; }

  $cid = m_valid_cid(m_get($body, 'cid', ''));
  if ($cid === '') { http_response_code(400); echo json_encode(array('ok'=>false,'err'=>'bad cid')); exit; }

  // identity MUST come from a verified token/SSO — reject anonymous submits.
  $uid   = (string)m_get($body, 'uid', '');
  $token = (string)m_get($body, 'token', '');
  $ident = m_identity($uid, $token, $ACC_FILE);
  if (!$ident) { http_response_code(401); echo json_encode(array('ok'=>false,'err'=>'auth')); exit; }

  // name + avatar are SERVER-DERIVED (ignore whatever the body claims).
  $name   = m_clean_name($ident['name']);
  $avatar = m_safe_avatar($ident['avatar']);

  $score = (int)m_get($body, 'score', 0);
  if ($score < 0) { $score = 0; }
  if ($score > M_SCORE_MAX) { $score = M_SCORE_MAX; }

  $meta = m_clean_meta(m_get($body, 'meta', null));

  $path = m_file($cid);
  $fh = @fopen($path, 'c+');
  if (!$fh) { http_response_code(500); echo json_encode(array('ok'=>false,'err'=>'io')); exit; }
  if (!flock($fh, LOCK_EX)) { fclose($fh); http_response_code(500); echo json_encode(array('ok'=>false,'err'=>'lock')); exit; }
  $data = m_load($fh);
  // one row per uid per cid: keep the player's best score only (replace if higher)
  $found = false;
  foreach ($data as $i => $row) {
    if (m_get($row, 'uid', '') === $ident['uid']) {
      $found = true;
      if ($score > (int)m_get($row, 'score', 0)) {
        $entry = array('uid'=>$ident['uid'], 'name'=>$name, 'score'=>$score, 'avatar'=>$avatar, 'ts'=>time());
        if ($meta !== null) { $entry['meta'] = $meta; }
        $data[$i] = $entry;
      }
      break;
    }
  }
  if (!$found) {
    $entry = array('uid'=>$ident['uid'], 'name'=>$name, 'score'=>$score, 'avatar'=>$avatar, 'ts'=>time());
    if ($meta !== null) { $entry['meta'] = $meta; }
    $data[] = $entry;
  }
  // sort desc by score, keep top M_PER_CID_MAX
  usort($data, 'm_cmp');
  if (count($data) > M_PER_CID_MAX) { $data = array_slice($data, 0, M_PER_CID_MAX); }
  ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($data));
  fflush($fh); flock($fh, LOCK_UN); fclose($fh);
  echo json_encode(array('ok'=>true)); exit;
}

// ────────────────────────────────────────────────────────────────────── GET
if ($method === 'GET') {

  // ?mine=1 — the verified user's entries across challenges (inbox)
  if (m_get($_GET, 'mine', '') === '1' || m_get($_GET, 'mine', '') === 1) {
    $uid   = (string)m_get($_GET, 'uid', '');
    $token = (string)m_get($_GET, 'token', '');
    $ident = m_identity($uid, $token, $ACC_FILE);
    if (!$ident) { http_response_code(401); echo json_encode(array('ok'=>false,'err'=>'auth')); exit; }

    $mine = array();
    $files = @glob(m_matches_dir() . '/*.json');
    if (is_array($files)) {
      foreach ($files as $f) {
        $cid = basename($f, '.json');
        if (m_valid_cid($cid) === '') { continue; }
        $fh = @fopen($f, 'r');
        if (!$fh) { continue; }
        flock($fh, LOCK_SH);
        $rows = m_load($fh);
        flock($fh, LOCK_UN); fclose($fh);
        foreach ($rows as $row) {
          if (m_get($row, 'uid', '') === $ident['uid']) {
            $mine[] = array('cid'=>$cid, 'score'=>(int)m_get($row,'score',0), 'ts'=>(int)m_get($row,'ts',0));
            break;
          }
        }
      }
    }
    // most-recent first, capped
    usort($mine, 'm_cmp_ts');
    if (count($mine) > 100) { $mine = array_slice($mine, 0, 100); }
    echo json_encode(array('ok'=>true, 'cids'=>$mine)); exit;
  }

  // ?cid=... — standings for one challenge
  $cid = m_valid_cid(m_get($_GET, 'cid', ''));
  if ($cid === '') { http_response_code(400); echo json_encode(array('ok'=>false,'err'=>'bad cid')); exit; }

  $limit = (int)m_get($_GET, 'limit', 20);
  if ($limit < 1) { $limit = 1; }
  if ($limit > 100) { $limit = 100; }

  $scores = array();
  $path = m_file($cid);
  if (is_file($path)) {
    $fh = @fopen($path, 'r');
    if ($fh) { flock($fh, LOCK_SH); $scores = m_load($fh); flock($fh, LOCK_UN); fclose($fh); }
  }
  usort($scores, 'm_cmp');
  $scores = array_slice($scores, 0, $limit);
  // strip uid from the public payload (don't leak the identifier)
  $out = array();
  foreach ($scores as $s) {
    $row = array(
      'name'  => m_get($s, 'name', '—'),
      'avatar'=> m_get($s, 'avatar', ''),
      'score' => (int)m_get($s, 'score', 0),
      'ts'    => (int)m_get($s, 'ts', 0)
    );
    $mt = m_get($s, 'meta', null);
    if (is_array($mt)) { $row['meta'] = $mt; }
    $out[] = $row;
  }
  echo json_encode(array('ok'=>true, 'scores'=>$out)); exit;
}

// ─────────────────────────────────────────────────────── unknown method
http_response_code(405);
echo json_encode(array('ok'=>false, 'err'=>'method not allowed'));
exit;

// sort helper for ?mine= (most-recent first by ts)
function m_cmp_ts($a, $b){
  $ta = (int)m_get($a, 'ts', 0); $tb = (int)m_get($b, 'ts', 0);
  if ($ta === $tb) { return 0; }
  return ($ta > $tb) ? -1 : 1;
}
