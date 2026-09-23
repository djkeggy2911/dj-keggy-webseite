<?php
declare(strict_types=1);
// Private cron task every 15 minutes. Not a public endpoint.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');
require dirname(__DIR__).'/admin/core.php';
try {
    $db=cms_db();
    if((int)$db->query('SELECT MAX(version) FROM cms_schema_versions')->fetchColumn()>=2)
        $db->prepare('DELETE FROM cms_review_limits WHERE expires_at<=?')->execute([time()]);
}
catch(Throwable $e){fwrite(STDERR,"CMS cleanup unavailable.\n");exit(1);}
