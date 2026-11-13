<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/permit_tally.php';
require_roles($pdo, ['rps','superadmin']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); header('Allow: POST'); exit; }
$id = filter_var($_POST['application_id'] ?? '', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
if (!$id || permit_inspection_actor($pdo,(int)$_SESSION['id']) === null) { http_response_code(403); exit('Not authorized.'); }
$token = $_SESSION['csrf_permit_inspection_token'] ?? '';
if (!is_string($_POST['csrf_token'] ?? null) || $token === '' || !hash_equals($token,$_POST['csrf_token'])) { http_response_code(403); exit('Security validation failed.'); }
try {
    $tally = permit_tally_upload($pdo,$id,(int)$_SESSION['id'],$_FILES['tally_file'] ?? []);
    $_SESSION['permit_tally_flash']=['type'=>'success','message'=>'Tally saved. '.$tally['tree_count'].' trees; '.$tally['replacement_count'].' replacement seedlings. Continue the inspection and RPS review.'];
} catch (InvalidArgumentException|RuntimeException $e) {
    $_SESSION['permit_tally_flash']=['type'=>'danger','message'=>$e instanceof PDOException ? 'Unable to save the tally.' : $e->getMessage()];
}
header('Location: permit-application.php?id='.$id.'#inspection-tally');
