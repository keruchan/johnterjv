<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permit_completion.php';
require_roles($pdo,['community','ems']);
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);header('Allow: POST');exit;}
$id=filter_var($_POST['application_id']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$token=$_SESSION['csrf_permit_completion_token']??'';
if(!$id || !is_string($_POST['csrf_token']??null) || $token==='' || !hash_equals($token,$_POST['csrf_token'])){http_response_code(403);exit('Security validation failed.');}
try{
    if(($_POST['action']??'')==='report' && $_SESSION['role']==='community'){
        permit_report_cutting_done($pdo,$id,(int)$_SESSION['id'],$_POST);
        $message='Completion report submitted. EMS will verify the approved trees and any additional affected trees.';
    }elseif(($_POST['action']??'')==='verify' && $_SESSION['role']==='ems'){
        $result=permit_verify_cutting_done($pdo,$id,(int)$_SESSION['id'],$_POST);
        $message=$result['closed']?'Final verification complete. Tree cutting is recorded as completed.':'Findings recorded. The case remains open for client follow-up and another EMS verification.';
    }else{throw new RuntimeException('This completion action is not available.');}
    $_SESSION['permit_completion_flash']=['type'=>'success','message'=>$message];
}catch(PDOException $e){error_log('[CERTREEFY COMPLETION] '.$e->getMessage());$_SESSION['permit_completion_flash']=['type'=>'danger','message'=>'Unable to record the completion action.'];}
catch(InvalidArgumentException|RuntimeException $e){$_SESSION['permit_completion_flash']=['type'=>'danger','message'=>$e->getMessage()];}
$path=$_SESSION['role']==='community'?'../community/permit-application.php?id=':'../ems/permit-final-verification.php?application_id=';
header('Location: '.$path.$id.'#cutting-final-verification');
