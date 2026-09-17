<?php
declare(strict_types=1);
require dirname(__DIR__).'/admin/core.php';
function check(bool $ok,string $name): void { if (!$ok) throw new RuntimeException($name); }
session_start();
$_SESSION['csrf']=bin2hex(random_bytes(32));
check(cms_csrf_valid($_SESSION['csrf']),'Valid CSRF');
check(!cms_csrf_valid('') && !cms_csrf_valid('wrong'),'Reject CSRF');
check(cms_session_expired([],time()),'Missing session times');
check(cms_session_expired(['seen'=>100,'created'=>100],1900),'Idle expiry');
check(cms_session_expired(['seen'=>28890,'created'=>100],28900),'Absolute expiry');
check(!cms_session_expired(['seen'=>100,'created'=>100],101),'Active session');
check(cms_escape('<script>"')==='&lt;script&gt;&quot;','Output escaping');
$password=bin2hex(random_bytes(20));$hash=cms_hash($password);
check(password_verify($password,$hash) && !password_verify('wrong',$hash),'Hash verification');
try {cms_hash('short');throw new RuntimeException('Short password accepted');} catch (InvalidArgumentException $e) {}
try {cms_hash(str_repeat('x',73));throw new RuntimeException('Truncated password accepted');} catch (InvalidArgumentException $e) {}
if (getenv('CMS_TEST_DATABASE')==='1') {
    // Explicit opt-in and fixed disposable DB name. Never target production.
    check(getenv('CMS_DB_NAME')==='cms_phase1_test' && in_array(getenv('CMS_DB_HOST'),['127.0.0.1','localhost'],true),'Local disposable database only');
    $db=cms_db();
    foreach (explode(';',file_get_contents(dirname(__DIR__).'/database/001_cms.sql')) as $sql) if (trim($sql)!=='') $db->exec($sql);
    $email='admin@example.invalid';
    $db->prepare('INSERT INTO cms_users (email,password_hash) VALUES (?,?)')->execute([$email,$hash]);
    check((int)$db->query('SELECT MAX(version) FROM cms_schema_versions')->fetchColumn()===1,'Schema version');
    try {
        $db->prepare('INSERT INTO cms_users (email,password_hash) VALUES (?,?)')->execute([strtoupper($email),$hash]);
        throw new RuntimeException('Duplicate email accepted');
    } catch (PDOException $e) { check($e->getCode()==='23000','Unique email constraint'); }
    check(!cms_login($db,$email,'wrong'),'Wrong password');
    check(!cms_login($db,"' OR 1=1 --",$password),'SQL injection');
    $before=session_id();$csrf=$_SESSION['csrf'];
    check(cms_login($db,$email,$password),'Login');
    check(session_id()!==$before && $_SESSION['csrf']!==$csrf,'Rotate session and CSRF');
    check(cms_user($db)!==null,'Authenticated user');
    $db->exec('UPDATE cms_users SET session_version=session_version+1');
    check(cms_user($db)===null,'Revoke existing session');
    for($i=0;$i<5;$i++) check(cms_allow_login($db,$email,'127.0.0.1'),'Allowed attempts');
    check(!cms_allow_login($db,$email,'127.0.0.2'),'Account throttle across IPs');
    for($i=0;$i<25;$i++) check(cms_allow_login($db,'user'.$i.'@example.invalid','127.0.0.3'),'IP allowance');
    check(!cms_allow_login($db,'another@example.invalid','127.0.0.3'),'IP throttle across accounts');
    $db->exec('UPDATE cms_login_limits SET window_start=0');
    check(cms_allow_login($db,$email,'127.0.0.1'),'Expired throttle');
    check(cms_login($db,$email,$password),'Login before account disable');
    $db->exec('UPDATE cms_users SET active=0');
    check(cms_user($db)===null,'Disabled account revokes active session');
    check(!cms_login($db,$email,$password),'Disabled account');
    echo "PASS: disposable MySQL schema, login, revocation and rate limits.\n";
} else { echo "NOT RUN: MySQL integration (requires disposable test DB).\n"; }
echo "PASS: CSRF, expiry, escaping and password hashing.\n";
