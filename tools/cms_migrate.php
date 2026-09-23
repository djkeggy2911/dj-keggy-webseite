<?php
declare(strict_types=1);
// Private SSH CLI only. Never deploy this tool, SQL or backups under public_html.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');
require dirname(__DIR__).'/admin/core.php';
try {
    if(($argv[1]??'')!=='--apply-phase2')throw new RuntimeException('Explicit migration flag required');
    $db=cms_db();if((int)$db->query("SELECT GET_LOCK('marrydj_phase2_migration',0)")->fetchColumn()!==1)throw new RuntimeException('Migration busy');
    $version=(int)$db->query('SELECT MAX(version) FROM cms_schema_versions')->fetchColumn();
    if($version===2){echo "Schema version 2 already installed; no changes.\n";exit;}
    if($version!==1)throw new RuntimeException('Unexpected schema');
    foreach(['cms_weddings','cms_reviews','cms_review_limits'] as $table){$q=$db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$table]);if((int)$q->fetchColumn())throw new RuntimeException('Partial migration; inspect before retry');}
    $session=realpath(cms_config('CMS_SESSION_PATH'));
    $public=realpath(dirname(__DIR__,2).'/public_html') ?: realpath(dirname(__DIR__));
    if(!$session || $session===$public || str_starts_with($session,$public.DIRECTORY_SEPARATOR))throw new RuntimeException('Private backup directory required');
    $backup=dirname($session).'/backups';umask(0077);
    if(!is_dir($backup) && !mkdir($backup,0700))throw new RuntimeException('Backup directory unavailable');
    $resolved=realpath($backup);
    $windowsFixture=PHP_OS_FAMILY==='Windows' && getenv('CMS_TEST_DATABASE')==='1' && getenv('CMS_DB_NAME')==='cms_phase1_test' && in_array(getenv('CMS_DB_HOST'),['localhost','127.0.0.1'],true);
    if(!$resolved || $resolved===$public || str_starts_with($resolved,$public.DIRECTORY_SEPARATOR) || (!$windowsFixture && (fileperms($resolved)&0077)!==0))throw new RuntimeException('Unsafe backup directory');
    $sql="-- Private pre-phase2 snapshot; contains password hashes. Do not publish.\n";
    $db->beginTransaction();
    foreach(['cms_schema_versions','cms_users','cms_login_limits'] as $table){
        $sql.=$db->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1].";\n";
        foreach($db->query('SELECT * FROM `'.$table.'`',PDO::FETCH_ASSOC) as $row){$columns=implode(',',array_map(fn($c)=>'`'.$c.'`',array_keys($row)));$values=implode(',',array_map(fn($v)=>$v===null?'NULL':$db->quote((string)$v),array_values($row)));$sql.='INSERT INTO `'.$table.'` ('.$columns.') VALUES ('.$values.");\n";}
    }
    $db->commit();$path=$resolved.'/before-phase2-'.gmdate('YmdHis').'-'.bin2hex(random_bytes(4)).'.sql';
    $file=fopen($path,'x');if(!$file)throw new RuntimeException('Backup unavailable');
    $written=fwrite($file,$sql);fflush($file);fclose($file);chmod($path,0600);
    if($written!==strlen($sql) || !hash_equals(hash('sha256',$sql),hash_file('sha256',$path)))throw new RuntimeException('Backup verification failed');
    unset($sql);file_put_contents($path.'.sha256',hash_file('sha256',$path)."\n");
    foreach(explode(';',file_get_contents(dirname(__DIR__).'/database/002_reviews.sql')) as $statement)if(trim($statement)!=='')$db->exec($statement);
    echo "PASS: private backup verified; schema version 2 installed.\n";
}catch(Throwable $e){if(isset($db)&&$db->inTransaction())$db->rollBack();fwrite(STDERR,"Migration stopped. Inspect private backup/schema before retry; no automatic rollback or deletion.\n");exit(1);}
finally{if(isset($db))$db->query("SELECT RELEASE_LOCK('marrydj_phase2_migration')");}
