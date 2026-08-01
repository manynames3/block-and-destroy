<?php
// ═══════════════════════════════════════════════════════════════════════
// Squeak & Destroy — Accounts + Cloud-Profile  (skynet.lospolo.hu/animals/)
//   Three login worlds, one uid space:
//     • SkyNet SSO  → uid "sn:<Nev>"   (auto, if logged into the vezérlőpult)
//     • Regular acc → uid "acc:<user>" (username + password, file-based)
//     • Anonymous   → no uid (localStorage only)
//
//   GET  ?action=skynet                      → {ok,user|null}      (SSO probe)
//   POST ?action=register {user,pass}        → {ok,token,user} | {ok:false,err}
//   POST ?action=login    {user,pass}        → {ok,token,user} | {ok:false,err}
//   GET  ?action=session&token=T             → {ok,user} | {ok:false}
//   GET  ?action=load&uid=U                  → {ok,profile|null}
//   POST ?action=save {uid,token?,profile}   → {ok} | {ok:false,err}
//
//   Storage (OUTSIDE webroot): ../.ca_data/accounts.json,
//                              ../.ca_data/profiles/<safe-uid>.json
//   (Defense-in-depth: data lives in a sibling dir that Apache does not serve,
//    on top of the animals/.htaccess deny rule. The legacy in-dir files are
//    migrated + removed once by _seccleanup.php.)
//   PHP 5.6 compatible: array(), no ??, no type hints. JSON responses.
// ═══════════════════════════════════════════════════════════════════════
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$DIR      = __DIR__;
// ── data root (.ca_data). NOTE: the docroot is the repo root, so this sits at
// /.ca_data on the web — the ROOT .htaccess now 403s it, and as defense-in-depth
// we also drop a deny-all .htaccess INTO .ca_data (applies to every subdir too).
$DATA_DIR = $DIR . '/../.ca_data';
if (!is_dir($DATA_DIR)) { @mkdir($DATA_DIR, 0750, true); }
$__ht = $DATA_DIR . '/.htaccess';
if (!is_file($__ht)) { @file_put_contents($__ht,
  "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n".
  "<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n"); }
$ACC_FILE = $DATA_DIR . '/accounts.json';
$PROF_DIR = $DATA_DIR . '/profiles';

// ---- tiny helpers -----------------------------------------------------
function j_out($a){ echo json_encode($a); exit; }
function j_in(){
    $raw = file_get_contents('php://input');
    $d = json_decode($raw, true);
    return is_array($d) ? $d : array();
}
function s_get($k, $def){ return isset($_GET[$k]) ? $_GET[$k] : $def; }
function load_json($f){
    if (!is_file($f)) return array();
    $j = json_decode(@file_get_contents($f), true);
    return is_array($j) ? $j : array();
}
function save_json($f, $data){
    $fp = @fopen($f, 'c+');
    if (!$fp) { @file_put_contents($f, json_encode($data), LOCK_EX); return; }
    if (flock($fp, LOCK_EX)) {
        ftruncate($fp, 0); rewind($fp);
        fwrite($fp, json_encode($data)); fflush($fp); flock($fp, LOCK_UN);
    }
    fclose($fp);
}
function clean_user($n){
    $n = trim((string)$n);
    $n = preg_replace('/[\x00-\x1F\x7F]/u', '', $n);
    // charset allowlist: letters/digits/space/_-. — strips ':' '/' and other chars
    // that could end up in the account KEY / uid (defense-in-depth vs path tricks).
    $n = preg_replace('/[^\p{L}\p{N} _.\-]/u', '', $n);
    $n = trim($n);
    if (function_exists('mb_strlen') && mb_strlen($n,'UTF-8') > 32) $n = mb_substr($n,0,32,'UTF-8');
    return $n;
}
// ── per-IP brute-force / spam rate limit (sliding window, file-backed) ──────
// Returns true if the request is ALLOWED (under the limit), false if throttled.
function rl_ok($DATA_DIR, $bucket, $max, $win){
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0';
    $safe = preg_replace('/[^0-9a-fA-F:.]/', '_', $ip);
    $f = $DATA_DIR . '/rl_' . $bucket . '_' . substr(md5($safe),0,16) . '.json';
    $now = time();
    // flock-atomic read-modify-write (mirrors contact.php / clans.php): the prior
    // file_get_contents + file_put_contents was a racy RMW, so concurrent
    // login/register attempts could read the same window and slip past the cap.
    $fh = @fopen($f, 'c+');
    if (!$fh) { return true; } // fail-open on FS error (don't lock out legit users)
    $ok = true;
    if (flock($fh, LOCK_EX)) {
        $raw  = stream_get_contents($fh);
        $hits = $raw ? json_decode($raw, true) : null;
        if (!is_array($hits)) { $hits = array(); }
        $keep = array();
        for ($i=0;$i<count($hits);$i++){ if (($now - (int)$hits[$i]) < $win) $keep[] = (int)$hits[$i]; }
        if (count($keep) >= $max) {
            $ok = false;
        } else {
            $keep[] = $now;
        }
        ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($keep));
        fflush($fh); flock($fh, LOCK_UN);
    }
    fclose($fh);
    // probabilistic GC: prune stale rl_ files so they can't accumulate forever (disk DoS).
    if (mt_rand(1,50) === 1) {
        $g = @glob($DATA_DIR . '/rl_*.json');
        if (is_array($g)) { foreach ($g as $rf) { if (@filemtime($rf) < ($now - 86400)) @unlink($rf); } }
    }
    return $ok;
}
function rand_token(){
    if (function_exists('random_bytes')) { return bin2hex(random_bytes(20)); }
    return bin2hex(openssl_random_pseudo_bytes(20));
}
// uid → a filesystem-safe filename
function uid_file($PROF_DIR, $uid){
    $safe = preg_replace('/[^a-zA-Z0-9_.:-]/', '_', (string)$uid);
    $safe = substr($safe, 0, 80);
    return $PROF_DIR . '/' . $safe . '.json';
}

// ---- SkyNet SSO probe (mirrors towerdefense) --------------------------
function skynet_user(){
    $u = null;
    try {
        // Optional host-app single-sign-on: if this game is dropped into a site
        // that exposes an auth bootstrap one level up, reuse its session. On a
        // standalone install the file simply isn't there and we fall through to
        // the game's own accounts.
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
                $u = array(
                    'uid'    => 'sn:' . $cu['nev'],
                    'name'   => $cu['nev'],
                    'foto'   => $f,
                    'role'   => isset($cu['role']) ? $cu['role'] : '',
                    'source' => 'skynet'
                );
            }
        }
    } catch (Exception $e) { $u = null; }
    return $u;
}

$action = s_get('action', 'skynet');

// ── SkyNet SSO ─────────────────────────────────────────────────────────
if ($action === 'skynet') {
    j_out(array('ok'=>true, 'user'=>skynet_user()));
}

// ── dev log (login/register audit → dev/log/auth.log) ──────────────────
function dev_log($event, $who){
    // log OUTSIDE the web-served dir (was animals/dev/log → fetchable)
    $dir = __DIR__ . '/../.ca_data/log';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '?';
    $line = date('Y-m-d H:i:s') . "\t" . $event . "\t" . $who . "\t" . $ip . "\n";
    @file_put_contents($dir . '/auth.log', $line, FILE_APPEND | LOCK_EX);
}

// ── Register ───────────────────────────────────────────────────────────
if ($action === 'register') {
    if (!rl_ok($DATA_DIR, 'reg', 6, 600)) j_out(array('ok'=>false, 'err'=>'Túl sok próbálkozás. Várj pár percet.'));
    $in    = j_in();
    $user  = clean_user(isset($in['user']) ? $in['user'] : '');
    $pass  = isset($in['pass'])  ? (string)$in['pass']  : '';
    $email = isset($in['email']) ? trim((string)$in['email']) : '';
    if (strlen($user) < 3)  j_out(array('ok'=>false, 'err'=>'A név legalább 3 karakter.'));
    if (strlen($pass) < 4)  j_out(array('ok'=>false, 'err'=>'A jelszó legalább 4 karakter.'));
    if ($email==='' || !preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email)) j_out(array('ok'=>false, 'err'=>'Érvényes email cím kell.'));
    $accs = load_json($ACC_FILE);
    $key  = function_exists('mb_strtolower') ? mb_strtolower($user,'UTF-8') : strtolower($user);
    if (isset($accs[$key])) j_out(array('ok'=>false, 'err'=>'Ez a név már foglalt.'));
    $token = rand_token();
    $accs[$key] = array(
        'user'  => $user,
        'email' => $email,
        'hash'  => password_hash($pass, PASSWORD_DEFAULT),
        'thash' => hash('sha256', $token),   // store the TOKEN HASHED at rest (not plaintext)
        'tiat'  => time(),          // token issued-at (for expiry)
        'made'  => time()
    );
    save_json($ACC_FILE, $accs);
    dev_log('REGISTER', $user . ' <' . $email . '>');
    j_out(array('ok'=>true, 'token'=>$token, 'user'=>array('uid'=>'acc:'.$key, 'name'=>$user, 'source'=>'account')));
}

// ── Login ──────────────────────────────────────────────────────────────
if ($action === 'login') {
    if (!rl_ok($DATA_DIR, 'login', 8, 600)) j_out(array('ok'=>false, 'err'=>'Túl sok próbálkozás. Várj pár percet.'));
    $in   = j_in();
    $user = clean_user(isset($in['user']) ? $in['user'] : '');
    $pass = isset($in['pass']) ? (string)$in['pass'] : '';
    $accs = load_json($ACC_FILE);
    $key  = function_exists('mb_strtolower') ? mb_strtolower($user,'UTF-8') : strtolower($user);
    // uniform error (no username enumeration: same message for "no such user" + "bad pass")
    if (!isset($accs[$key])) j_out(array('ok'=>false, 'err'=>'Hibás név vagy jelszó.'));
    $row = $accs[$key];
    if (!password_verify($pass, $row['hash'])) j_out(array('ok'=>false, 'err'=>'Hibás név vagy jelszó.'));
    // refresh token on each login (single active session per account)
    $token = rand_token();
    unset($accs[$key]['token']);                  // drop any legacy plaintext token
    $accs[$key]['thash'] = hash('sha256', $token); // store hashed
    $accs[$key]['tiat']  = time();                // reset token issued-at
    save_json($ACC_FILE, $accs);
    dev_log('LOGIN', $row['user']);
    j_out(array('ok'=>true, 'token'=>$token, 'user'=>array('uid'=>'acc:'.$key, 'name'=>$row['user'], 'source'=>'account')));
}

// a token is valid only if issued within TOKEN_MAX_AGE (90 days). Legacy rows with
// no 'tiat' are grandfathered (treated fresh) so this rollout doesn't mass-logout.
// token compare — supports hashed storage (thash) AND legacy plaintext (token),
// so the rollout to hashed tokens doesn't log existing users out.
function tok_match($row, $token){
    if (!is_array($row) || $token === '') return false;
    if (isset($row['thash'])) return hash_equals($row['thash'], hash('sha256', $token));
    if (isset($row['token'])) return hash_equals($row['token'], $token);   // legacy plaintext
    return false;
}
function token_fresh($row){
    if (!is_array($row) || (!isset($row['token']) && !isset($row['thash']))) return false;
    $iat = isset($row['tiat']) ? (int)$row['tiat'] : 0;
    if ($iat === 0) return true;
    return (time() - $iat) < (90 * 86400);
}

// ── Session (validate a stored token) ──────────────────────────────────
if ($action === 'session') {
    $token = s_get('token', '');
    if ($token === '') j_out(array('ok'=>false));
    $accs = load_json($ACC_FILE);
    foreach ($accs as $key=>$row) {
        if (tok_match($row, $token) && token_fresh($row)) {
            j_out(array('ok'=>true, 'user'=>array('uid'=>'acc:'.$key, 'name'=>$row['user'], 'source'=>'account')));
        }
    }
    j_out(array('ok'=>false));
}

// ── Logout (revoke the server-side token) ──────────────────────────────
if ($action === 'logout') {
    $in    = j_in();
    $uid   = isset($in['uid'])   ? (string)$in['uid']   : '';
    $token = isset($in['token']) ? (string)$in['token'] : '';
    if (strpos($uid, 'acc:') === 0) {
        $accs = load_json($ACC_FILE);
        $key  = substr($uid, 4);
        if (isset($accs[$key]) && tok_match($accs[$key], $token)) {
            unset($accs[$key]['token'], $accs[$key]['thash'], $accs[$key]['tiat']);   // revoke
            save_json($ACC_FILE, $accs);
        }
    }
    j_out(array('ok'=>true));   // idempotent; SkyNet SSO logout is handled by SkyNet
}

// ── verify that the caller may write uid (SkyNet session OR matching FRESH token)
function uid_authorized($uid, $token, $ACC_FILE){
    if (strpos($uid, 'sn:') === 0) {
        $su = skynet_user();
        return ($su && $su['uid'] === $uid);
    }
    if (strpos($uid, 'acc:') === 0) {
        $accs = load_json($ACC_FILE);
        $key  = substr($uid, 4);
        return (isset($accs[$key]) && tok_match($accs[$key], $token) && token_fresh($accs[$key]));
    }
    return false;
}

// ── Load cloud profile ─────────────────────────────────────────────────
if ($action === 'load') {
    $uid   = (string)s_get('uid', '');
    $token = (string)s_get('token', '');
    if ($uid === '') j_out(array('ok'=>false, 'err'=>'uid kell'));
    // IDOR fix: only the owner may read their cloud profile (token / SkyNet SSO).
    if (!uid_authorized($uid, $token, $ACC_FILE)) j_out(array('ok'=>false, 'err'=>'nem jogosult'));
    $f = uid_file($PROF_DIR, $uid);
    if (!is_file($f)) j_out(array('ok'=>true, 'profile'=>null));
    $row = load_json($f);
    j_out(array('ok'=>true, 'profile'=>isset($row['profile']) ? $row['profile'] : null, 'updated'=>isset($row['updated']) ? $row['updated'] : 0));
}

// ── Save cloud profile ─────────────────────────────────────────────────
if ($action === 'save') {
    $in    = j_in();
    $uid   = isset($in['uid']) ? (string)$in['uid'] : '';
    $token = isset($in['token']) ? (string)$in['token'] : '';
    $prof  = isset($in['profile']) ? $in['profile'] : null;
    if ($uid === '' || !is_array($prof)) j_out(array('ok'=>false, 'err'=>'uid + profile kell'));
    if (!uid_authorized($uid, $token, $ACC_FILE)) j_out(array('ok'=>false, 'err'=>'nem jogosult'));
    // size guard: cap the stored blob (~96 KB of JSON)
    $enc = json_encode($prof);
    if (strlen($enc) > 98304) j_out(array('ok'=>false, 'err'=>'túl nagy profil'));
    if (!is_dir($PROF_DIR)) @mkdir($PROF_DIR, 0750, true);
    save_json(uid_file($PROF_DIR, $uid), array('uid'=>$uid, 'profile'=>$prof, 'updated'=>time()));
    j_out(array('ok'=>true));
}

// ── Delete account + cloud profile (App Store Guideline 5.1.1(v)) ──────────
// Removes the account row (acc:) and the cloud-profile file for the uid.
// Requires the matching session token (acc:) or an active SkyNet SSO (sn:).
if ($action === 'delete') {
    $in    = j_in();
    $uid   = isset($in['uid'])   ? (string)$in['uid']   : '';
    $token = isset($in['token']) ? (string)$in['token'] : '';
    if ($uid === '') j_out(array('ok'=>false, 'err'=>'uid kell'));
    if (!uid_authorized($uid, $token, $ACC_FILE)) j_out(array('ok'=>false, 'err'=>'nem jogosult'));
    // 1) drop the cloud-profile file (if any)
    $f = uid_file($PROF_DIR, $uid);
    if (is_file($f)) @unlink($f);
    // 2) drop the account row (regular accounts only; SkyNet rows live elsewhere)
    $who = $uid;
    if (strpos($uid, 'acc:') === 0) {
        $accs = load_json($ACC_FILE);
        $key  = substr($uid, 4);
        if (isset($accs[$key])) { $who = $accs[$key]['user']; unset($accs[$key]); save_json($ACC_FILE, $accs); }
    }
    dev_log('DELETE', $who);
    j_out(array('ok'=>true));
}

j_out(array('ok'=>false, 'err'=>'ismeretlen action'));
