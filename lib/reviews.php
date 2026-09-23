<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/admin/core.php';

function review_text(array $data,string $key,int $max,bool $required=true): string {
    $v=$data[$key]??'';
    if(!is_string($v) || !mb_check_encoding($v,'UTF-8')) throw new InvalidArgumentException('Invalid text');
    $v=trim($v);
    if(($required && $v==='') || mb_strlen($v)>$max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u',$v)) throw new InvalidArgumentException('Invalid text');
    return $v;
}
function wedding_save(PDO $db,array $data): int {
    $title=review_text($data,'title',160);$location=review_text($data,'location',200);$note=review_text($data,'internal_note',4000,false);
    $date=review_text($data,'wedding_date',10);$d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    if(!$d || $d->format('Y-m-d')!==$date) throw new InvalidArgumentException('Invalid date');
    $id=filter_var($data['id']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
    if($id===false)throw new InvalidArgumentException('Invalid id');
    if($id) {
        $db->prepare('UPDATE cms_weddings SET title=?,wedding_date=?,location=?,internal_note=? WHERE id=?')->execute([$title,$date,$location,$note,$id]);
    } else {
        $db->prepare('INSERT INTO cms_weddings(title,wedding_date,location,internal_note) VALUES(?,?,?,?)')->execute([$title,$date,$location,$note]);$id=(int)$db->lastInsertId();
    }
    return $id;
}
function wedding_rotate(PDO $db,int $id): string {
    $code=bin2hex(random_bytes(32));
    $q=$db->prepare('UPDATE cms_weddings SET code_hash=?,code_active=1 WHERE id=? AND active=1');$q->execute([hash('sha256',$code),$id]);
    if($q->rowCount()!==1)throw new InvalidArgumentException('Inactive wedding');
    return $code;
}
function wedding_for_code(PDO $db,string $code): ?array {
    if(!preg_match('/^[a-f0-9]{64}$/D',$code))return null;
    return wedding_for_hash($db,hash('sha256',$code));
}
function wedding_for_hash(PDO $db,string $hash,bool $lock=false): ?array {
    $q=$db->prepare('SELECT id,title,wedding_date,location FROM cms_weddings WHERE code_hash=? AND active=1 AND code_active=1'.($lock?' FOR UPDATE':''));$q->execute([$hash]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}
function review_rate(PDO $db,string $scope,string $identity,int $limit,int $seconds=900): bool {
    $now=time();$window=intdiv($now,$seconds)*$seconds;
    // Include the window in the HMAC: no stable identifier or raw IP is stored.
    $bucket=hash_hmac('sha256',$scope.':'.$window.':'.$identity,cms_config('CMS_APP_KEY'));
    $db->prepare('DELETE FROM cms_review_limits WHERE expires_at<=?')->execute([$now]);
    $db->prepare('INSERT INTO cms_review_limits(bucket,attempts,expires_at) VALUES(?,1,?) ON DUPLICATE KEY UPDATE attempts=attempts+1')->execute([$bucket,$window+$seconds]);
    $q=$db->prepare('SELECT attempts FROM cms_review_limits WHERE bucket=?');$q->execute([$bucket]);return (int)$q->fetchColumn()<=$limit;
}
function review_submit(PDO $db,string $codeHash,array $data,string $language,string $submission): void {
    $name=review_text($data,'display_name',80);$body=review_text($data,'body',3000);
    $stars=filter_var($data['stars']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>5]]);
    if($stars===false || $stars===null || ($data['consent']??'')!=='1' || mb_strlen($body)<10 || !in_array($language,['de','hr','en','it'],true) || !preg_match('/^[a-f0-9]{64}$/D',$submission))throw new InvalidArgumentException('Invalid review');
    $db->beginTransaction();
    try {
        $w=wedding_for_hash($db,$codeHash,true);if(!$w)throw new InvalidArgumentException('Inactive code');
        $db->prepare('INSERT INTO cms_reviews(wedding_id,stars,display_name,body,language,consent,submission_hash) VALUES(?,?,?,?,?,1,?)')->execute([$w['id'],$stars,$name,$body,$language,hash('sha256',$submission)]);
        $db->commit();
    } catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function review_moderate(PDO $db,int $id,string $status): void {
    if(!in_array($status,['pending','approved','rejected'],true))throw new InvalidArgumentException('Invalid status');
    $db->prepare('UPDATE cms_reviews SET status=?,moderated_at=UTC_TIMESTAMP() WHERE id=? AND consent=1')->execute([$status,$id]);
}
function review_public(PDO $db): array {
    return $db->query("SELECT stars,display_name,body,language FROM cms_reviews WHERE status='approved' AND consent=1 ORDER BY id DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
}
function review_qr_svg(string $code): string {
    if(!preg_match('/^[a-f0-9]{64}$/D',$code))throw new InvalidArgumentException('Invalid code');
    require_once __DIR__.'/qr.php';
    $qr=new QRCode();$qr->setTypeNumber(8);$qr->setErrorCorrectLevel(QR_ERROR_CORRECT_LEVEL_M);
    $qr->addData('https://marrydj.com/review/#code='.$code,QR_MODE_8BIT_BYTE);$qr->make();
    $n=$qr->getModuleCount();$size=$n+8;$path='';
    for($y=0;$y<$n;$y++)for($x=0;$x<$n;$x++)if($qr->isDark($y,$x))$path.='M'.($x+4).' '.($y+4).'h1v1h-1z';
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$size.' '.$size.'" role="img" aria-label="QR" shape-rendering="crispEdges"><path fill="white" d="M0 0h'.$size.'v'.$size.'H0z"/><path fill="black" d="'.$path.'"/></svg>';
}
