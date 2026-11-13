<?php
require_once __DIR__.'/../../config/config.php';require_once __DIR__.'/../../includes/auth.php';require_once __DIR__.'/../../includes/permit_tarpaulin.php';
require_roles($pdo,['rps','superadmin']);
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);header('Allow: POST');exit;}
$id=filter_var($_POST['application_id']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);$token=$_SESSION['csrf_permit_release_token']??'';
if(!$id || permit_release_actor($pdo,(int)$_SESSION['id'])===null || !is_string($_POST['csrf_token']??null) || $token==='' || !hash_equals($token,$_POST['csrf_token'])){http_response_code(403);exit('Security validation failed.');}
try{permit_record_tarpaulin_issue($pdo,$id,(int)$_SESSION['id'],is_string($_POST['issued_on']??null)?$_POST['issued_on']:'');$_SESSION['permit_tarpaulin_flash']=['type'=>'success','message'=>'Physical tarpaulin issue recorded.'];}
catch(PDOException $e){error_log('[CERTREEFY TARPAULIN] '.$e->getMessage());$_SESSION['permit_tarpaulin_flash']=['type'=>'danger','message'=>'Unable to record tarpaulin issue.'];}
catch(InvalidArgumentException|RuntimeException $e){$_SESSION['permit_tarpaulin_flash']=['type'=>'danger','message'=>$e->getMessage()];}
header('Location: permit-application.php?id='.$id.'#tarpaulin-issue');
