<?php
declare(strict_types=1);
ini_set('display_errors','0');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
require_once dirname(__DIR__).'/lib/reviews.php';
$translations=json_decode(file_get_contents(__DIR__.'/translations.json'),true,8,JSON_THROW_ON_ERROR);
$lang=is_string($_GET['lang']??null)&&isset($translations[$_GET['lang']])?$_GET['lang']:'de';$t=$translations[$lang];
$error='';$w=null;$ready=false;$success=false;
try {
    if(!in_array($_SERVER['REQUEST_METHOD'],['GET','POST'],true)){http_response_code(405);header('Allow: GET, POST');exit;}
    if(!in_array($_SERVER['HTTPS']??'',['on','1'],true)){http_response_code(403);exit(cms_escape($t['unavailable']));}
    $dir=realpath(cms_config('CMS_SESSION_PATH'));$root=realpath(dirname(__DIR__));
    if(!$dir || !is_writable($dir) || $dir===$root || str_starts_with($dir,$root.DIRECTORY_SEPARATOR))throw new RuntimeException('Private sessions required');
    ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');ini_set('session.use_trans_sid','0');ini_set('session.save_handler','files');
    session_save_path($dir);session_name('__Secure-marrydj_review');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/review','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
    if(!session_start())throw new RuntimeException('Session unavailable');
    if(isset($_SESSION['expires']) && $_SESSION['expires']<time()) {$_SESSION=[];if(!session_regenerate_id(true))throw new RuntimeException('Session rotation');}
    $_SESSION['expires']??=time()+7200;$_SESSION['csrf']??=bin2hex(random_bytes(32));
    $db=cms_db();if((int)$db->query('SELECT MAX(version) FROM cms_schema_versions')->fetchColumn()!==2)throw new RuntimeException('Migration required');
    $ready=true;
    if($_SERVER['REQUEST_METHOD']==='POST'){
        if(!cms_csrf_valid(cms_field('csrf'))){http_response_code(403);$error=$t['csrf'];}
        elseif(cms_field('action')==='open') {
            if(!review_rate($db,'open',$_SERVER['REMOTE_ADDR']??'unknown',60)){http_response_code(429);header('Retry-After: 900');$error=$t['limited'];}
            else {
                $code=cms_field('code');$w=wedding_for_code($db,$code);
                if(!$w){http_response_code(422);$error=$t['invalid'];}
                else {
                    if(!session_regenerate_id(true))throw new RuntimeException('Session rotation');
                    $_SESSION['code_hash']=hash('sha256',$code);$_SESSION['submission']=bin2hex(random_bytes(32));$_SESSION['csrf']=bin2hex(random_bytes(32));unset($_SESSION['sent']);
                    header('Location: /review/?lang='.$lang,true,303);exit;
                }
            }
        } elseif(cms_field('action')==='submit') {
            if(isset($_SESSION['sent'])){$error=$t['done'];http_response_code(409);}
            elseif(!review_rate($db,'submit',$_SERVER['REMOTE_ADDR']??'unknown',30)){http_response_code(429);header('Retry-After: 900');$error=$t['limited'];}
            else {
                try {
                    review_submit($db,$_SESSION['code_hash']??'',$_POST,$lang,$_SESSION['submission']??'');
                    $_SESSION['sent']=true;$_SESSION['csrf']=bin2hex(random_bytes(32));
                    header('Location: /review/?lang='.$lang,true,303);exit;
                }catch(InvalidArgumentException $e){http_response_code(422);$error=$t['validation'];}
            }
        } elseif(cms_field('action')==='reset') {
            unset($_SESSION['code_hash'],$_SESSION['sent'],$_SESSION['submission']);header('Location: /review/?lang='.$lang,true,303);exit;
        } else {http_response_code(422);$error=$t['validation'];}
    }
    $success=isset($_SESSION['sent']);
    if(isset($_SESSION['code_hash'])){
        $w=wedding_for_hash($db,$_SESSION['code_hash']);
        if(!$w){unset($_SESSION['code_hash']);$error=$t['invalid'];$success=false;}
    }
}catch(Throwable $e){http_response_code(503);$ready=false;$error=$t['unavailable'];error_log('[cms] review_unavailable');}
function rv(string $key): string {return cms_escape(cms_field($key));}
?>
<!doctype html><html lang="<?=cms_escape($lang)?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=cms_escape($t['title'])?> · DJ KEGGY</title><link rel="stylesheet" href="/review/review.css?v=2"><script defer src="/review/review.js?v=2"></script></head><body><main class="review-shell"><header><a class="brand" href="/">DJ KEGGY</a><nav aria-label="Language"><?php foreach(['de','hr','en','it'] as $l):?><a href="?lang=<?=$l?>" <?=$l===$lang?'aria-current="page"':''?>><?=strtoupper($l)?></a><?php endforeach ?></nav></header><p class="eyebrow">WEDDINGS · MEMORIES · MUSIC</p><h1><?=cms_escape($t['title'])?></h1><p><?=cms_escape($t['intro'])?></p>
<?php if($error):?><p class="notice" role="alert"><?=cms_escape($error)?></p><?php endif ?>
<?php if($ready && $success):?><section class="notice" role="status"><p><?=cms_escape($t['success'])?></p></section>
<?php elseif($ready && $w):?><section class="wedding"><h2><?=cms_escape($w['title'])?></h2><p><?=cms_escape($w['wedding_date'].' · '.$w['location'])?></p></section><p><?=cms_escape($t['required'])?></p><form method="post"><input type="hidden" name="action" value="submit"><input type="hidden" name="csrf" value="<?=cms_escape($_SESSION['csrf'])?>"><div class="form-grid"><label><?=cms_escape($t['stars'])?> *<select name="stars" required><option value=""><?=cms_escape($t['choose'])?></option><?php for($i=1;$i<=5;$i++):?><option value="<?=$i?>" <?=cms_field('stars')===(string)$i?'selected':''?>><?=$i?> / 5</option><?php endfor ?></select></label><label><?=cms_escape($t['name'])?> *<input name="display_name" required maxlength="80" autocomplete="nickname" value="<?=rv('display_name')?>"></label></div><label><?=cms_escape($t['body'])?> *<textarea name="body" required minlength="10" maxlength="3000" rows="7"><?=rv('body')?></textarea></label><label class="consent"><input type="checkbox" name="consent" value="1" required <?=cms_field('consent')==='1'?'checked':''?>><span><?=cms_escape($t['consent'])?> *</span></label><p class="muted"><?=cms_escape($t['privacy'])?></p><p class="muted"><?=cms_escape($t['withdraw'])?></p><button><?=cms_escape($t['send'])?></button></form>
<?php elseif($ready):?><form method="post" id="guest-code-form"><input type="hidden" name="action" value="open"><input type="hidden" name="csrf" value="<?=cms_escape($_SESSION['csrf'])?>"><label><?=cms_escape($t['code'])?><input name="code" required minlength="64" maxlength="64" pattern="[a-f0-9]{64}" autocomplete="off" autocapitalize="none" spellcheck="false"></label><p><?=cms_escape($t['hint'])?></p><noscript><p><?=cms_escape($t['noscript'])?></p></noscript><button><?=cms_escape($t['open'])?></button></form><?php endif ?>
<?php if($ready && ($w || $success)):?><form method="post" id="guest-code-form" hidden><input type="hidden" name="csrf" value="<?=cms_escape($_SESSION['csrf'])?>"><input type="hidden" name="action" value="open"><input type="hidden" name="code"></form><form method="post"><input type="hidden" name="csrf" value="<?=cms_escape($_SESSION['csrf'])?>"><input type="hidden" name="action" value="reset"><button class="secondary"><?=cms_escape($t['change'])?></button></form><?php endif ?><footer><a href="/"><?=cms_escape($t['back'])?></a></footer></main></body></html>
