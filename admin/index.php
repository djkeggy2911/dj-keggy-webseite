<?php
declare(strict_types=1);
ini_set('display_errors','0');
header('Cache-Control: no-store, private'); header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
require __DIR__.'/core.php';
try {
    // Require the complete configuration even when an existing session is sent.
    if (strlen(cms_config('CMS_APP_KEY')) < 32) throw new RuntimeException('Invalid application key');
    cms_session(); $db=cms_db();
    $schema=(int)$db->query('SELECT MAX(version) FROM cms_schema_versions')->fetchColumn();
    if (!in_array($schema,[1,2],true)) throw new RuntimeException('Unsupported schema');
    $error=''; $user=cms_user($db);
    if($user && $schema===2)require __DIR__.'/phase2.php';
    if (!in_array($_SERVER['REQUEST_METHOD'],['GET','POST'],true)) { http_response_code(405); header('Allow: GET, POST'); exit; }
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        if (!cms_csrf_valid(cms_field('csrf'))) { http_response_code(403); $error='Die Sitzung ist abgelaufen. Bitte laden Sie die Seite erneut.'; }
        elseif (cms_field('action')==='logout') { cms_logout(); header('Location: /admin/',true,303); exit; }
        elseif($user && $schema===2){
            try{phase2_post($db);}catch(InvalidArgumentException $e){http_response_code(422);$error='Bitte prüfen Sie Ihre Angaben oder den Status des Gästecodes.';}
        }
        elseif (!$user && cms_field('action')==='login') {
            $email=strtolower(trim(cms_field('email'))); $password=cms_field('password');
            if (strlen($email)>254 || strlen($password)>72) { http_response_code(422); $error='Anmeldung nicht möglich. Bitte prüfen Sie Ihre Eingaben.'; }
            elseif (!cms_allow_login($db,$email,$_SERVER['REMOTE_ADDR'] ?? 'unknown')) { http_response_code(429); header('Retry-After: 900'); $error='Zu viele Anmeldeversuche. Bitte versuchen Sie es in 15 Minuten erneut.'; }
            elseif (filter_var($email,FILTER_VALIDATE_EMAIL) && cms_login($db,$email,$password)) { header('Location: /admin/',true,303); exit; }
            else { http_response_code(401); $error='Anmeldung nicht möglich. Bitte prüfen Sie Ihre Eingaben.'; }
        }
    }
} catch (Throwable $e) {
    error_log('[cms] admin_unavailable'); http_response_code(503);
    exit('Der Adminbereich ist derzeit nicht verfügbar. Die öffentliche Website bleibt erreichbar.');
}
$modules=['dashboard'=>'Dashboard','reviews'=>'Bewertungen','weddings'=>'Hochzeiten & Gästecodes','gallery'=>'Galerie','videos'=>'Videos','references'=>'Referenzen','content'=>'Website-Inhalte','inquiries'=>'Anfragen','analytics'=>'Statistiken','settings'=>'Einstellungen'];
$page=is_string($_GET['page'] ?? null) && isset($modules[$_GET['page']]) ? $_GET['page'] : 'dashboard';
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>MarryDJ · Administration</title><link rel="stylesheet" href="/admin/admin.css?v=2"></head><body>
<?php if (!$user): ?>
<main class="login"><p class="eyebrow">DJ KEGGY · MARRYDJ</p><h1>Willkommen zurück.</h1><p class="muted">Melden Sie sich an, um Ihre Website zu verwalten.</p>
<?php if ($error): ?><p role="alert" class="notice"><?=cms_escape($error)?></p><?php endif ?>
<form method="post" action="/admin/"><input type="hidden" name="action" value="login"><input type="hidden" name="csrf" value="<?=cms_escape($_SESSION['csrf'])?>"><label>E-Mail<input type="email" name="email" required maxlength="254" autocomplete="username" inputmode="email" autocapitalize="none"></label><label>Passwort<input type="password" name="password" required autocomplete="current-password"></label><button>Anmelden</button></form><a href="/">Zur Website</a></main>
<?php else: ?>
<a class="skip" href="#main">Zum Inhalt</a><div class="layout"><aside><a class="brand" href="/admin/">DJ KEGGY<span>ADMINISTRATION</span></a><nav aria-label="Administration"><?php foreach ($modules as $id=>$label): ?><a href="/admin/?page=<?=cms_escape($id)?>" <?=$page===$id?'aria-current="page"':''?>><?=cms_escape($label)?></a><?php endforeach ?></nav><form method="post" action="/admin/"><input type="hidden" name="csrf" value="<?=cms_escape($_SESSION['csrf'])?>"><input type="hidden" name="action" value="logout"><button class="secondary">Abmelden</button></form></aside>
<main id="main"><header><p class="eyebrow">MARRYDJ · IHR ARBEITSBEREICH</p><a href="/">Website ansehen</a></header><h1><?=cms_escape($modules[$page])?></h1>
<?php if ($error): ?><p role="alert" class="notice"><?=cms_escape($error)?></p><?php endif ?>
<?php if(in_array($page,['weddings','reviews'],true) && $schema===2): phase2_render($db,$page); elseif ($page==='dashboard'): ?><p class="muted">Die Basis steht. Ihre Verwaltungsbereiche werden schrittweise ergänzt.</p><div class="cards"><?php foreach (['reviews'=>'Neue Bewertungen','weddings'=>'Hochzeiten & Gästecodes','inquiries'=>'Wichtige neue Anfragen'] as $id=>$label): ?><article><p class="eyebrow"><?=($schema===2 && $id!=='inquiries')?'AKTIV':'IN VORBEREITUNG'?></p><h2><?=cms_escape($label)?></h2><p><?php if($schema===2 && $id!=='inquiries'): ?><?= $id==='reviews' ? (int)$db->query("SELECT COUNT(*) FROM cms_reviews WHERE status='pending'")->fetchColumn().' Bewertungen warten auf Freigabe.' : (int)$db->query("SELECT COUNT(*) FROM cms_weddings WHERE active=1")->fetchColumn().' aktive Hochzeiten.' ?><?php else: ?>Dieses Modul ist noch nicht aktiviert.<?php endif ?></p><a href="/admin/?page=<?=$id?>">Bereich ansehen</a></article><?php endforeach ?></div><section class="notice"><h2>Sicher angemeldet</h2><p><?=cms_escape($user['email'])?></p><p>Die öffentliche Website und das Anfrageformular arbeiten weiterhin unverändert.</p></section>
<?php else: ?><section class="notice"><h2>Für eine spätere Phase vorbereitet</h2><p>Dieser Bereich ist noch nicht aktiviert. Hier werden derzeit keine Inhalte gespeichert oder veröffentlicht.</p><a href="/admin/">Zum Dashboard</a></section><?php endif ?>
</main></div><?php endif ?></body></html>
