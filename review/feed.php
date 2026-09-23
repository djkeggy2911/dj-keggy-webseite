<?php
declare(strict_types=1);
ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);header('Allow: GET');exit;}
try {
    require_once dirname(__DIR__).'/lib/reviews.php';
    echo json_encode(['reviews'=>review_public(cms_db())],JSON_THROW_ON_ERROR|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
}catch(Throwable $e){http_response_code(503);echo '{"reviews":[]}';error_log('[cms] review_feed_unavailable');}
