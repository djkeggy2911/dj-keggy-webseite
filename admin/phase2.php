<?php
declare(strict_types=1);
// Included only after the existing admin authentication and CSRF checks.
if(!isset($user,$db) || !$user){http_response_code(404);exit;}
require_once dirname(__DIR__).'/lib/reviews.php';
function phase2_post(PDO $db): void {
    $action=cms_field('action');$id=filter_var($_POST['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if($action==='wedding_save'){
        $new=empty($_POST['id']);$id=wedding_save($db,$_POST);
        if($new)$_SESSION['guest_codes'][$id]=wedding_rotate($db,$id);
    } else {
        if(!$id)throw new InvalidArgumentException('Ungültige Auswahl.');
        if($action==='code_rotate')$_SESSION['guest_codes'][$id]=wedding_rotate($db,$id);
        elseif($action==='code_disable'){
            $db->prepare('UPDATE cms_weddings SET code_active=0 WHERE id=?')->execute([$id]);unset($_SESSION['guest_codes'][$id]);
        } elseif($action==='wedding_disable'){
            $db->prepare('UPDATE cms_weddings SET active=0,code_active=0 WHERE id=?')->execute([$id]);unset($_SESSION['guest_codes'][$id]);
        } elseif($action==='wedding_enable')$db->prepare('UPDATE cms_weddings SET active=1 WHERE id=?')->execute([$id]);
        elseif($action==='review_moderate')review_moderate($db,$id,cms_field('status'));
        elseif($action==='qr_download'){
            $code=$_SESSION['guest_codes'][$id]??'';$w=wedding_for_code($db,$code);
            if(!$w || (int)$w['id']!==$id)throw new InvalidArgumentException('Code nicht mehr verfügbar.');
            header('Content-Type: image/svg+xml');header('Content-Disposition: attachment; filename="wedding-qr-'.$id.'.svg"');echo review_qr_svg($code);exit;
        } else throw new InvalidArgumentException('Unbekannte Aktion.');
    }
    $target=$action==='review_moderate'?'reviews':'weddings';
    header('Location: /admin/?page='.$target.'&saved=1'.($target==='weddings'?'&edit='.$id:''),true,303);exit;
}
function phase2_csrf(): void {echo '<input type="hidden" name="csrf" value="'.cms_escape($_SESSION['csrf']).'">';}
function phase2_button(string $action,int $id,string $label): void {
    echo '<form method="post">';phase2_csrf();echo '<input type="hidden" name="action" value="'.$action.'"><input type="hidden" name="id" value="'.$id.'"><button class="secondary">'.cms_escape($label).'</button></form>';
}
function phase2_render(PDO $db,string $page): void {
    if($page==='weddings') {
        $id=filter_var($_GET['edit']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]])?:0;
        $q=$db->prepare('SELECT * FROM cms_weddings WHERE id=?');$q->execute([$id]);$w=$q->fetch(PDO::FETCH_ASSOC)?:['id'=>0,'title'=>'','wedding_date'=>'','location'=>'','internal_note'=>''];
        echo '<section class="notice"><h2>'.($w['id']?'Hochzeit bearbeiten':'Hochzeit anlegen').'</h2><form method="post">';phase2_csrf();
        echo '<input type="hidden" name="action" value="wedding_save"><input type="hidden" name="id" value="'.(int)$w['id'].'"><div class="form-grid">';
        foreach(['title'=>['Brautpaar / Bezeichnung','text',160],'wedding_date'=>['Hochzeitsdatum','date',10],'location'=>['Ort','text',200]] as $key=>$f)echo '<label>'.cms_escape($f[0]).' *<input name="'.$key.'" type="'.$f[1].'" maxlength="'.$f[2].'" required value="'.cms_escape($w[$key]).'"></label>';
        echo '</div><label>Interne Notiz<textarea name="internal_note" maxlength="4000">'.cms_escape($w['internal_note']).'</textarea></label><button>Speichern</button></form><a href="/admin/?page=weddings">Neue Hochzeit</a></section>';
        if($w['id'] && isset($_SESSION['guest_codes'][$w['id']])){
            $code=$_SESSION['guest_codes'][$w['id']];$valid=wedding_for_code($db,$code);
            if($valid && (int)$valid['id']===(int)$w['id']){
                echo '<section class="notice qr-sheet"><h2>'.cms_escape($w['title']).'</h2><p>Bewertung abgeben · Leave a review · Ostavite recenziju · Lascia una recensione</p><div class="qr">'.review_qr_svg($code).'</div><label>Privater Gästelink<input readonly value="https://marrydj.com/review/#code='.cms_escape($code).'"></label><p>Nur in dieser Admin-Sitzung verfügbar. QR jetzt herunterladen oder über die Druckfunktion des Browsers drucken. Ein neuer Code macht den bisherigen Link ungültig.</p>';
                phase2_button('qr_download',(int)$w['id'],'QR als SVG herunterladen');echo '</section>';
            }
        }
        $offset=max(0,min(100000,(int)($_GET['offset']??0)));$rows=$db->query('SELECT id,title,wedding_date,location,active,code_active FROM cms_weddings ORDER BY wedding_date DESC,id DESC LIMIT 25 OFFSET '.$offset)->fetchAll(PDO::FETCH_ASSOC);
        echo '<div class="record-list">';foreach($rows as $row){$id=(int)$row['id'];echo '<article><h2>'.cms_escape($row['title']).'</h2><p>'.cms_escape($row['wedding_date'].' · '.$row['location']).'</p><p>'.($row['active']?'Aktiv':'Deaktiviert').' · Gästecode '.($row['code_active']?'aktiv':'inaktiv').'</p><a href="?page=weddings&amp;edit='.$id.'">Bearbeiten / QR</a> · <a href="?page=reviews&amp;wedding='.$id.'">Bewertungen</a><div class="actions">';
            if($row['active']){phase2_button('code_rotate',$id,'Neuen Code erzeugen (alten ersetzen)');phase2_button('code_disable',$id,'Code deaktivieren');phase2_button('wedding_disable',$id,'Hochzeit deaktivieren');}else phase2_button('wedding_enable',$id,'Hochzeit aktivieren');echo '</div></article>';}
        echo '</div><nav aria-label="Weitere Hochzeiten">';if($offset)echo '<a href="?page=weddings&amp;offset='.max(0,$offset-25).'">Zurück</a>';if(count($rows)===25)echo '<a href="?page=weddings&amp;offset='.($offset+25).'">Weitere Hochzeiten</a>';echo '</nav>';
    } else {
        $labels=['pending'=>'Ausstehend','approved'=>'Veröffentlicht','rejected'=>'Abgelehnt'];$status=is_string($_GET['status']??null)&&isset($labels[$_GET['status']])?$_GET['status']:'pending';
        $wedding=filter_var($_GET['wedding']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]])?:0;$offset=max(0,min(100000,(int)($_GET['offset']??0)));
        echo '<nav class="actions" aria-label="Bewertungsstatus">';foreach($labels as $s=>$label)echo '<a href="?page=reviews&amp;status='.$s.'&amp;wedding='.$wedding.'" '.($s===$status?'aria-current="page"':'').'>'.$label.'</a>';echo '</nav>';
        $q=$db->prepare('SELECT r.*,w.title,w.wedding_date,w.location FROM cms_reviews r JOIN cms_weddings w ON w.id=r.wedding_id WHERE r.status=?'.($wedding?' AND r.wedding_id=?':'').' ORDER BY r.id DESC LIMIT 25 OFFSET '.$offset);$q->execute($wedding?[$status,$wedding]:[$status]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        if(!$rows)echo '<p>Keine Bewertungen in dieser Auswahl.</p>';
        echo '<div class="record-list">';foreach($rows as $r){echo '<article><p aria-label="'.(int)$r['stars'].' von 5 Sternen">'.str_repeat('★',(int)$r['stars']).'</p><h2>'.cms_escape($r['display_name']).'</h2><p class="review-body">'.cms_escape($r['body']).'</p><p>'.cms_escape($r['title'].' · '.$r['wedding_date'].' · '.$r['location']).'</p><p>Eingegangen: '.cms_escape($r['created_at']).' · '.cms_escape(strtoupper($r['language'])).'</p><div class="actions">';
            foreach(['approved'=>'Freigeben','rejected'=>'Ablehnen','pending'=>'Ausblenden / erneut prüfen'] as $s=>$label){if($s===$r['status'])continue;echo '<form method="post">';phase2_csrf();echo '<input type="hidden" name="action" value="review_moderate"><input type="hidden" name="id" value="'.(int)$r['id'].'"><input type="hidden" name="status" value="'.$s.'"><button>'.$label.'</button></form>';}echo '</div></article>';}
        echo '</div>';if($offset)echo '<a href="?page=reviews&amp;status='.$status.'&amp;wedding='.$wedding.'&amp;offset='.max(0,$offset-25).'">Zurück</a> ';if(count($rows)===25)echo '<a href="?page=reviews&amp;status='.$status.'&amp;wedding='.$wedding.'&amp;offset='.($offset+25).'">Weitere Bewertungen</a>';
    }
}
