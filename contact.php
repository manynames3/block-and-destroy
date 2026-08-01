<?php
/* ============================================================================
 * Squeak & Destroy — Contact form mailer (PHP 5.6 compatible — NO `??`,
 * NO short-array, NO type hints, NO arrow fns).
 *
 *   POST {name,email,message}  -> { ok:true } | { ok:false, err }
 *
 * SECURITY:
 *   - same-origin CORS only (reflect Origin if its host matches HTTP_HOST);
 *     OPTIONS -> 204.
 *   - per-IP file-based rate limit (5 msgs / 10 min) in ../.ca_data (outside
 *     webroot; deny-all .htaccess), with flock + probabilistic GC — mirrors
 *     account.php / leaderboard.php.
 *   - length caps + control-char stripping on every field.
 *   - email validated with a newline-forbidding regex (no header injection);
 *     Reply-To is built ONLY from the validated address.
 *   - Subject/headers contain no user-controlled newlines.
 *   - NO secrets in this file.
 * ==========================================================================*/
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ── same-origin CORS (reflect Origin only if its host matches ours) ────────
$__host = isset($_SERVER['HTTP_HOST'])   ? $_SERVER['HTTP_HOST']   : '';
$__org  = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
if ($__org !== '' && $__host !== '' && preg_replace('#^https?://#i', '', $__org) === $__host) {
  header('Access-Control-Allow-Origin: ' . $__org);
  header('Access-Control-Allow-Credentials: true');
  header('Vary: Origin');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

define('CT_TO',           'contact@squeakanddestroy.com');
define('CT_RATE_WINDOW',  600);   // seconds (10 min)
define('CT_RATE_MAX',     5);     // max messages per IP per window
define('CT_NAME_MAX',     80);
define('CT_EMAIL_MAX',    160);
define('CT_MSG_MAX',      4000);
define('CT_MSG_MIN',      5);

function ct_out($a){ echo json_encode($a); exit; }
function ct_get($a, $k, $d){ return (is_array($a) && isset($a[$k])) ? $a[$k] : $d; }

// strip ASCII control chars (incl. CR/LF) but KEEP normal whitespace where asked.
function ct_strip_ctrl($s){
  return preg_replace('/[\x00-\x1F\x7F]/u', '', (string)$s);
}
// for multi-line body: keep newlines, drop only the other control chars.
function ct_clean_body($s){
  $s = str_replace(array("\r\n", "\r"), "\n", (string)$s);
  $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
  return trim($s);
}
function ct_valid_email($e){
  $e = (string)$e;
  // forbid ANY whitespace/newline (header-injection guard) + basic shape + length.
  if ($e === '' || strlen($e) > CT_EMAIL_MAX) { return false; }
  if (preg_match('/[\r\n\t ]/', $e)) { return false; }
  return (bool)preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $e);
}

// ── data root (.ca_data) — mirror account.php / leaderboard.php ─────────────
$DATA_DIR = __DIR__ . '/../.ca_data';
if (!is_dir($DATA_DIR)) { @mkdir($DATA_DIR, 0750, true); }
$__ht = $DATA_DIR . '/.htaccess';
if (!is_file($__ht)) { @file_put_contents($__ht,
  "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n".
  "<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n"); }

// ── per-IP sliding-window rate limit (file-backed, flock + GC) ─────────────
function ct_rate_ok($DATA_DIR, $max, $win){
  $ip   = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0';
  $safe = preg_replace('/[^0-9a-fA-F:.]/', '_', $ip);
  $f    = $DATA_DIR . '/rl_contact_' . substr(md5($safe), 0, 16) . '.json';
  $now  = time();
  $fh   = @fopen($f, 'c+');
  if (!$fh) { return true; } // fail-open on FS error (don't lock out legit users)
  $ok = true;
  if (flock($fh, LOCK_EX)) {
    $raw  = stream_get_contents($fh);
    $hits = $raw ? json_decode($raw, true) : null;
    if (!is_array($hits)) { $hits = array(); }
    $keep = array();
    for ($i = 0; $i < count($hits); $i++) {
      if (($now - (int)$hits[$i]) < $win) { $keep[] = (int)$hits[$i]; }
    }
    if (count($keep) >= $max) {
      $ok = false;
    } else {
      $keep[] = $now;
    }
    ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($keep));
    fflush($fh); flock($fh, LOCK_UN);
  }
  fclose($fh);
  // probabilistic GC: prune stale rl_ files so they can't accumulate (disk DoS).
  if (mt_rand(1, 50) === 1) {
    $g = @glob($DATA_DIR . '/rl_*.json');
    if (is_array($g)) {
      foreach ($g as $rf) { if (@filemtime($rf) < ($now - 86400)) { @unlink($rf); } }
    }
  }
  return $ok;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405); ct_out(array('ok' => false, 'err' => 'method'));
}

if (!ct_rate_ok($DATA_DIR, CT_RATE_MAX, CT_RATE_WINDOW)) {
  http_response_code(429); ct_out(array('ok' => false, 'err' => 'rate limit'));
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) { http_response_code(400); ct_out(array('ok' => false, 'err' => 'bad json')); }

// ── validate + sanitize ────────────────────────────────────────────────────
$name = ct_strip_ctrl(ct_get($body, 'name', ''));
$name = trim($name);
if (function_exists('mb_substr')) { $name = mb_substr($name, 0, CT_NAME_MAX, 'UTF-8'); }
else { $name = substr($name, 0, CT_NAME_MAX); }

$email = trim((string)ct_get($body, 'email', ''));
$message = ct_clean_body(ct_get($body, 'message', ''));
if (function_exists('mb_substr')) { $message = mb_substr($message, 0, CT_MSG_MAX, 'UTF-8'); }
else { $message = substr($message, 0, CT_MSG_MAX); }

if ($name === '')              { http_response_code(422); ct_out(array('ok' => false, 'err' => 'name')); }
if (!ct_valid_email($email))   { http_response_code(422); ct_out(array('ok' => false, 'err' => 'email')); }
$mlen = function_exists('mb_strlen') ? mb_strlen($message, 'UTF-8') : strlen($message);
if ($mlen < CT_MSG_MIN)        { http_response_code(422); ct_out(array('ok' => false, 'err' => 'message')); }

// ── compose mail (no user-controlled newlines in Subject/headers) ──────────
$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '?';

$subject = 'Squeak & Destroy — uj uzenet: ' . $name;       // name already control-stripped
$subject = str_replace(array("\r", "\n"), ' ', $subject);  // belt-and-suspenders

$bodyText  = "Nev:    " . $name . "\n";
$bodyText .= "Email:  " . $email . "\n";
$bodyText .= "IP:     " . $ip . "\n";
$bodyText .= "Ido:    " . date('Y-m-d H:i:s') . "\n";
$bodyText .= "-------------------------------------------\n\n";
$bodyText .= $message . "\n";

// Reply-To built ONLY from the validated (newline-free) email.
$headers  = 'From: Squeak & Destroy <' . CT_TO . ">\r\n";
$headers .= 'Reply-To: ' . $email . "\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

$sent = @mail(CT_TO, '=?UTF-8?B?' . base64_encode($subject) . '?=', $bodyText, $headers);

if ($sent) { ct_out(array('ok' => true)); }
http_response_code(500);
ct_out(array('ok' => false, 'err' => 'send'));
