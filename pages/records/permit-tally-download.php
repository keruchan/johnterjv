<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/permit_donations.php';
require_roles($pdo,['community','rps','ems','superadmin']);
$id=filter_var($_GET['id'] ?? '',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if (!$id) { http_response_code(404); exit; }
$stmt=$pdo->prepare('SELECT * FROM tbl_permit_tallies WHERE id = ?');$stmt->execute([$id]);$tally=$stmt->fetch();
if (!$tally) { http_response_code(404); exit; }
$applicationId=(int)$tally['application_id'];$actorId=(int)$_SESSION['id'];
$allowed=permit_donation_application_for_actor($pdo,$applicationId,$actorId);
if (!$allowed && permit_inspection_actor($pdo,$actorId)!==null) { $allowed=permit_inspection_application_for_actor($pdo,$applicationId,$actorId,'view'); }
if (!$allowed) { http_response_code(404); exit; }
try {
    $tally['absolute_path']=permit_document_resolve_path($tally);
    $tally['mime_type']='application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    $tally['file_size_bytes']=filesize($tally['absolute_path']);
    send_permit_document_download($tally);
} catch (RuntimeException $e) { http_response_code(404); exit('Tally file unavailable.'); }
