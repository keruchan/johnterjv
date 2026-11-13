<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/seedling_locations.php';
require_role($pdo,'ems');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);header('Allow: POST');exit;}
$token=$_SESSION['csrf_provider_token']??'';
if(!is_string($_POST['csrf_token']??null)||$token===''||!hash_equals($token,$_POST['csrf_token'])){http_response_code(403);exit('Security validation failed.');}
try{$id=seedling_save_provider($pdo,(int)$_SESSION['id'],$_POST);$_SESSION['csrf_provider_token']=bin2hex(random_bytes(32));$_SESSION['provider_flash']=['type'=>'success','message'=>'Provider details saved.'];}
catch(PDOException $e){error_log('[CERTREEFY PROVIDER] '.$e->getMessage());$_SESSION['provider_flash']=['type'=>'danger','message'=>'Unable to save provider details.'];}
catch(InvalidArgumentException|RuntimeException $e){$_SESSION['provider_flash']=['type'=>'danger','message'=>$e->getMessage()];}
header('Location: seedling-providers.php'.(isset($id)?'?edit='.$id:''),true,303);exit;
