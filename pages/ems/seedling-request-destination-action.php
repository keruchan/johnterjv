<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/seedling_locations.php';
require_role($pdo,'ems');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);header('Allow: POST');exit;}
$id=filter_var($_POST['request_id']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$token=$_SESSION['csrf_seedling_request_token']??'';
if(!$id||!is_string($_POST['csrf_token']??null)||$token===''||!hash_equals($token,$_POST['csrf_token'])){http_response_code(403);exit('Security validation failed.');}
try{seedling_update_destination($pdo,$id,(int)$_SESSION['id'],$_POST);$_SESSION['seedling_request_flash']=['type'=>'success','message'=>'Planting destination updated.'];$_SESSION['csrf_seedling_request_token']=bin2hex(random_bytes(32));}
catch(PDOException $e){error_log('[CERTREEFY DESTINATION] '.$e->getMessage());$_SESSION['seedling_request_flash']=['type'=>'danger','message'=>'Unable to update the destination.'];}
catch(InvalidArgumentException|RuntimeException $e){$_SESSION['seedling_request_flash']=['type'=>'danger','message'=>$e->getMessage()];}
header('Location: seedling-request-detail.php?id='.$id.'#planting-destination',true,303);exit;
