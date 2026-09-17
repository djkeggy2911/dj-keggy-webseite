<?php
declare(strict_types=1);
// Functions only; direct HTTP access cannot run setup or disclose configuration.
function cms_config(string $name): string {
    $value = getenv($name);
    if ($value !== false && $value !== '') return $value;
    // Hostinger's web PHP need not inherit SSH environment variables. Keep the
    // fallback outside public_html, owned/readable only by the hosting account.
    static $private;
    if ($private === null) {
        $file = realpath(dirname(__DIR__, 2).'/cms-private/config.php');
        $public = realpath(dirname(__DIR__));
        if (!$file || str_starts_with($file, $public.DIRECTORY_SEPARATOR)
            || (fileperms($file) & 0077) !== 0) throw new RuntimeException('Private configuration unavailable');
        $private = require $file;
        if (!is_array($private)) throw new RuntimeException('Private configuration invalid');
    }
    if (!isset($private[$name]) || !is_string($private[$name]) || $private[$name] === '') throw new RuntimeException('Configuration unavailable');
    return $private[$name];
}
function cms_db(): PDO {
    static $db;
    if ($db instanceof PDO) return $db;
    $host = cms_config('CMS_DB_HOST'); $name = cms_config('CMS_DB_NAME');
    if (!preg_match('/^[a-zA-Z0-9._-]+$/D', $host) || !preg_match('/^[a-zA-Z0-9_]+$/D', $name)) throw new RuntimeException('Invalid configuration');
    $port = getenv('CMS_DB_PORT') ?: '3306';
    if (!ctype_digit($port) || (int)$port < 1 || (int)$port > 65535) throw new RuntimeException('Invalid configuration');
    $db = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", cms_config('CMS_DB_USER'), cms_config('CMS_DB_PASSWORD'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
    $db->exec("SET time_zone = '+00:00'");
    return $db;
}
function cms_escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function cms_field(string $key): string { return isset($_POST[$key]) && is_string($_POST[$key]) ? $_POST[$key] : ''; }
function cms_csrf_valid(string $token): bool { return $token !== '' && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token); }
function cms_hash(string $password): string {
    if (strlen($password) < 14 || strlen($password) > 72) throw new InvalidArgumentException('Use 14 to 72 bytes for the password.');
    return password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);
}
function cms_session_expired(array $session, int $now): bool {
    return !isset($session['seen'], $session['created']) || $now - $session['seen'] >= 1800 || $now - $session['created'] >= 28800;
}
function cms_logout(): void {
    $_SESSION = [];
    if (!session_regenerate_id(true)) throw new RuntimeException('Session rotation failed');
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
function cms_session(): void {
    if (($_SERVER['HTTPS'] ?? '') !== 'on' && ($_SERVER['HTTPS'] ?? '') !== '1') {
        http_response_code(403); exit('HTTPS erforderlich.');
    }
    $dir = realpath(cms_config('CMS_SESSION_PATH'));
    $root = realpath(dirname(__DIR__));
    if (!$dir || !is_dir($dir) || !is_writable($dir) || $dir === $root || str_starts_with($dir, $root . DIRECTORY_SEPARATOR)) throw new RuntimeException('Private sessions required');
    ini_set('session.use_strict_mode', '1'); ini_set('session.use_only_cookies', '1');
    ini_set('session.save_handler', 'files');
    ini_set('session.use_trans_sid', '0'); ini_set('session.gc_maxlifetime', '1800');
    session_save_path($dir); session_name('__Secure-marrydj_admin');
    session_set_cookie_params(['lifetime'=>0, 'path'=>'/admin', 'secure'=>true, 'httponly'=>true, 'samesite'=>'Strict']);
    if (!session_start()) throw new RuntimeException('Session unavailable');
    if (isset($_SESSION['user_id']) && cms_session_expired($_SESSION, time())) cms_logout();
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}
// Atomic counters: concurrent requests cannot bypass a read-then-increment check.
function cms_allow_login(PDO $db, string $email, string $ip): bool {
    $key = cms_config('CMS_APP_KEY');
    if (strlen($key) < 32) throw new RuntimeException('Invalid application key');
    $allowed = true; $now = time(); $window = intdiv($now, 900) * 900;
    foreach (['ip:'.$ip=>25, 'account:'.$email=>5] as $identity=>$limit) {
        $bucket = hash_hmac('sha256', $identity, $key);
        $q=$db->prepare('INSERT INTO cms_login_limits (bucket, window_start, attempts) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE attempts=IF(window_start=VALUES(window_start), attempts+1, 1), window_start=VALUES(window_start)');
        $q->execute([$bucket,$window]);
        $q=$db->prepare('SELECT attempts FROM cms_login_limits WHERE bucket=?'); $q->execute([$bucket]);
        if ((int)$q->fetchColumn() > $limit) { $allowed=false; break; }
    }
    $db->prepare('DELETE FROM cms_login_limits WHERE window_start < ?')->execute([$window-86400]);
    return $allowed;
}
function cms_login(PDO $db, string $email, string $password): bool {
    $q=$db->prepare('SELECT id,password_hash,active,session_version FROM cms_users WHERE email=?'); $q->execute([$email]); $user=$q->fetch(PDO::FETCH_ASSOC);
    // Do the same hashing work for known and unknown accounts. Never log input.
    $dummy=cms_hash(bin2hex(random_bytes(20)));
    $hash=$user ? $user['password_hash'] : $dummy;
    $valid=password_verify($password,$hash);
    if (!$user || !$valid || !(int)$user['active']) return false;
    $algo=defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    if (password_needs_rehash($hash,$algo)) $db->prepare('UPDATE cms_users SET password_hash=? WHERE id=?')->execute([password_hash($password,$algo),$user['id']]);
    if (!session_regenerate_id(true)) throw new RuntimeException('Session rotation failed');
    $_SESSION=['user_id'=>(int)$user['id'],'version'=>(int)$user['session_version'],'created'=>time(),'seen'=>time(),'csrf'=>bin2hex(random_bytes(32))];
    $db->prepare('UPDATE cms_users SET last_login_at=UTC_TIMESTAMP() WHERE id=?')->execute([$user['id']]);
    return true;
}
function cms_user(PDO $db): ?array {
    if (!isset($_SESSION['user_id'])) return null;
    $q=$db->prepare('SELECT id,email,session_version FROM cms_users WHERE id=? AND active=1'); $q->execute([$_SESSION['user_id']]); $user=$q->fetch(PDO::FETCH_ASSOC);
    if (!$user || (int)$user['session_version'] !== ($_SESSION['version'] ?? -1)) { cms_logout(); return null; }
    $_SESSION['seen']=time(); return $user;
}
