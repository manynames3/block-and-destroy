<?php
/* ============================================================================
 * Squeak & Destroy — global leaderboard (PHP 5.6 compatible — NO `??`).
 * File-backed top-scores list. Cheap, runs on shared hosting.
 *   GET  ?mode=all&limit=20          -> { ok, scores:[ {name,score,mode,ts}, ... ] }
 *   POST {token,score,team,mode}     -> { ok }   (token REQUIRED; name + avatar
 *                                                 are derived server-side from
 *                                                 the validated uid — clients
 *                                                 CANNOT spoof name/avatar)
 *
 * SECURITY (2026-06 review):
 *   - submissions require a valid account token (account.php uid_authorized);
 *     anonymous / forged submits are rejected.
 *   - name + avatar come from the server-side account/SSO record, NOT the body.
 *   - per-IP file-based rate limit.
 *   - data + rate-limit files live OUTSIDE the webroot (../.ca_data).
 *   - the old ?action=clear&key=... GET-wipe is REMOVED (was a guessable key);
 *     wiping is now gated behind a SkyNet admin session.
 *   - CORS narrowed to same-origin.
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

define('LB_MAX', 500);          // keep at most this many scores on disk
define('LB_RATE_WINDOW', 60);   // seconds
define('LB_RATE_MAX', 10);      // max POSTs per IP per window

// ── data root (.ca_data) — ROOT .htaccess 403s it; also drop a deny-all .htaccess
// INTO it (defense-in-depth; applies to every subdir: profiles/clans/matches/mp).
$DATA_DIR = __DIR__ . '/../.ca_data';
if (!is_dir($DATA_DIR)) { @mkdir($DATA_DIR, 0750, true); }
$__ht = $DATA_DIR . '/.htaccess';
if (!is_file($__ht)) { @file_put_contents($__ht,
  "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n".
  "<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n"); }
$ACC_FILE = $DATA_DIR . '/accounts.json';

function lb_get($a,$k,$d){ return (is_array($a) && isset($a[$k])) ? $a[$k] : $d; }

// mbstring guard (mirror account.php/contact.php): fall back to byte-substr if
// ext-mbstring is absent so the endpoint never fatals (500) on such a host.
function lb_substr($s, $start, $len){
  return function_exists('mb_substr') ? mb_substr((string)$s, $start, $len, 'UTF-8') : substr((string)$s, $start, $len);
}

function lb_dir(){
  $d = __DIR__ . '/../.ca_data';
  if (!is_dir($d)) { @mkdir($d, 0750, true); }
  return $d;
}
function lb_file(){ return lb_dir() . '/leaderboard.json'; }

function lb_clean_name($n){
  $n = preg_replace('/[\x00-\x1F\x7F]/u', '', (string)$n);
  $n = trim($n);
  if ($n === '') { $n = 'Névtelen'; }
  return lb_substr($n, 0, 20);
}
function lb_load($fh){
  $raw = stream_get_contents($fh);
  $data = $raw ? json_decode($raw, true) : null;
  if (!is_array($data)) { $data = array(); }
  return $data;
}
function lb_cmp($a, $b){
  $sa = (int)lb_get($a, 'score', 0); $sb = (int)lb_get($b, 'score', 0);
  if ($sa === $sb) { return 0; }
  return ($sa > $sb) ? -1 : 1;
}

// ── per-IP file-based rate limit ───────────────────────────────────────
function lb_rate_ok(){
  $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '?';
  $key = preg_replace('/[^a-zA-Z0-9_.:-]/', '_', $ip);
  $f = lb_dir() . '/rl_lb_' . $key . '.json';
  $now = time();
  $fh = @fopen($f, 'c+');
  if (!$fh) { return true; } // fail-open on FS error (don't lock out legit users)
  $ok = true;
  if (flock($fh, LOCK_EX)) {
    $raw = stream_get_contents($fh);
    $rec = $raw ? json_decode($raw, true) : null;
    if (!is_array($rec) || (int)lb_get($rec,'start',0) < $now - LB_RATE_WINDOW) {
      $rec = array('start'=>$now, 'count'=>0);
    }
    $rec['count'] = (int)lb_get($rec,'count',0) + 1;
    if ($rec['count'] > LB_RATE_MAX) { $ok = false; }
    ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($rec));
    fflush($fh); flock($fh, LOCK_UN);
  }
  fclose($fh);
  return $ok;
}

// ── account / SSO identity helpers (mirror account.php) ────────────────
function lb_load_json_file($f){
  if (!is_file($f)) { return array(); }
  $j = json_decode(@file_get_contents($f), true);
  return is_array($j) ? $j : array();
}
// resolve the verified display identity for a (uid? token) pair.
// returns array('name'=>..,'avatar'=>..,'uid'=>..) or null if not authorized.
// token check — supports hashed storage (thash) AND legacy plaintext (token),
// with a 90-day expiry (rows without tiat are grandfathered fresh). Mirrors
// account.php tok_match()/token_fresh() so auth never drifts between endpoints.
function lb_tok_ok($row, $token){
  if (!is_array($row) || $token === '') { return false; }
  $ok = false;
  if (isset($row['thash']))      { $ok = hash_equals($row['thash'], hash('sha256', $token)); }
  else if (isset($row['token'])) { $ok = hash_equals($row['token'], $token); }   // legacy plaintext
  if (!$ok) { return false; }
  $iat = isset($row['tiat']) ? (int)$row['tiat'] : 0;
  if ($iat > 0 && (time() - $iat) >= 90*86400) { return false; }   // token expired
  return true;
}
function lb_identity($uid, $token, $ACC_FILE){
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
          // relative host-app avatar path -> absolute, using the deployment's
          // own base (set CA_AVATAR_BASE in the environment). Unset = leave relative.
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
    $accs = lb_load_json_file($ACC_FILE);
    $key  = substr($uid, 4);
    if (isset($accs[$key]) && lb_tok_ok($accs[$key], $token)) {
      return array('name'=>$accs[$key]['user'], 'avatar'=>'', 'uid'=>'acc:'.$key);
    }
  }
  return null;
}

// ── only allow avatars on our own domains (no arbitrary URLs) ──────────
function lb_safe_avatar($url){
  $url = (string)$url;
  if ($url === '') { return ''; }
  if (strlen($url) > 300) { return ''; }
  if (!preg_match('#^https://([a-z0-9.-]+\.)?lospolo\.hu/#i', $url)) { return ''; }
  return $url;
}

$path = lb_file();

// ── admin-gated wipe (replaces the old guessable ?key=wipe_lb_2026) ────
if (isset($_GET['action']) && $_GET['action'] === 'clear') {
  $is_admin = false;
  try {
    // optional host-app SSO (absent on a standalone install)
    if (file_exists(__DIR__ . '/../includes/auth.php')) {
      require_once __DIR__ . '/../includes/auth.php';
    }
    if (function_exists('currentUser')) {
      $cu = currentUser();
      if ($cu && isset($cu['role']) && in_array($cu['role'], array('tulajdonos','superadmin','admin'))) {
        $is_admin = true;
      }
    }
  } catch (Exception $e) {}
  if (!$is_admin) { http_response_code(403); echo json_encode(array('ok'=>false,'err'=>'admin only')); exit; }
  @unlink($path);
  echo json_encode(array('ok'=>true, 'cleared'=>true)); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!lb_rate_ok()) { http_response_code(429); echo json_encode(array('ok'=>false,'err'=>'rate limit')); exit; }

  $body = json_decode(file_get_contents('php://input'), true);
  if (!is_array($body)) { http_response_code(400); echo json_encode(array('ok'=>false,'err'=>'bad json')); exit; }

  // identity MUST come from a verified token/SSO — reject anonymous submits.
  $uid   = (string)lb_get($body, 'uid', '');
  $token = (string)lb_get($body, 'token', '');
  $ident = lb_identity($uid, $token, $ACC_FILE);
  if (!$ident) { http_response_code(401); echo json_encode(array('ok'=>false,'err'=>'auth')); exit; }

  // name + avatar are SERVER-DERIVED (ignore whatever the body claims).
  $name   = lb_clean_name($ident['name']);
  $avatar = lb_safe_avatar($ident['avatar']);

  $score = (int)lb_get($body, 'score', 0);
  $mode  = preg_replace('/[^a-z0-9_]/i', '', (string)lb_get($body, 'mode', 'classic'));
  $team  = lb_clean_name(lb_get($body, 'team', ''));
  if ($mode === '') { $mode = 'classic'; }
  if ($score < 0) { $score = 0; }
  if ($score > 100000000) { $score = 100000000; }

  $fh = @fopen($path, 'c+');
  if (!$fh) { http_response_code(500); echo json_encode(array('ok'=>false)); exit; }
  flock($fh, LOCK_EX);
  $data = lb_load($fh);
  // one row per uid: keep the player's best score only (replace if higher)
  $found = false;
  foreach ($data as $i => $row) {
    if (lb_get($row, 'uid', '') === $ident['uid'] && lb_get($row, 'mode', '') === $mode) {
      $found = true;
      if ($score > (int)lb_get($row, 'score', 0)) {
        $data[$i] = array('uid'=>$ident['uid'], 'name'=>$name, 'score'=>$score, 'mode'=>$mode, 'team'=>$team, 'avatar'=>$avatar, 'ts'=>time());
      }
      break;
    }
  }
  if (!$found) {
    $data[] = array('uid'=>$ident['uid'], 'name'=>$name, 'score'=>$score, 'mode'=>$mode, 'team'=>$team, 'avatar'=>$avatar, 'ts'=>time());
  }
  // sort desc by score, keep top LB_MAX
  usort($data, 'lb_cmp');
  if (count($data) > LB_MAX) { $data = array_slice($data, 0, LB_MAX); }
  ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($data));
  fflush($fh); flock($fh, LOCK_UN); fclose($fh);
  echo json_encode(array('ok'=>true)); exit;
}

// GET — top scores
$mode  = preg_replace('/[^a-z0-9_]/i', '', (string)lb_get($_GET, 'mode', 'all'));
$limit = (int)lb_get($_GET, 'limit', 20);
if ($limit < 1) { $limit = 1; }
if ($limit > 100) { $limit = 100; }
$scores = array();
if (is_file($path)) {
  $fh = @fopen($path, 'r');
  if ($fh) { flock($fh, LOCK_SH); $scores = lb_load($fh); flock($fh, LOCK_UN); fclose($fh); }
}
if ($mode !== '' && $mode !== 'all') {
  $f = array();
  foreach ($scores as $s) { if (lb_get($s, 'mode', '') === $mode) { $f[] = $s; } }
  $scores = $f;
}
usort($scores, 'lb_cmp');
$scores = array_slice($scores, 0, $limit);
// strip uid from the public payload (don't leak the identifier)
$out = array();
foreach ($scores as $s) {
  $out[] = array(
    'name'  => lb_get($s, 'name', '—'),
    'score' => (int)lb_get($s, 'score', 0),
    'mode'  => lb_get($s, 'mode', 'classic'),
    'team'  => lb_get($s, 'team', ''),
    'avatar'=> lb_get($s, 'avatar', ''),
    'ts'    => (int)lb_get($s, 'ts', 0)
  );
}
echo json_encode(array('ok'=>true, 'scores'=>$out));
