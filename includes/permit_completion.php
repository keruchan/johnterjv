<?php
/** Owner completion reports and EMS final verification are separate, append-only actions. */
require_once __DIR__ . '/permit_release.php';

function permit_completion_transaction_start(PDO $pdo): bool
{
    if ($pdo->inTransaction()) { $pdo->exec('SAVEPOINT permit_completion_action'); return false; }
    $pdo->beginTransaction(); return true;
}
function permit_completion_transaction_end(PDO $pdo, bool $owns, bool $success): void
{
    if (!$pdo->inTransaction()) return;
    if ($owns) { if ($success) $pdo->commit(); else $pdo->rollBack(); }
    else { if (!$success) $pdo->exec('ROLLBACK TO SAVEPOINT permit_completion_action'); $pdo->exec('RELEASE SAVEPOINT permit_completion_action'); }
}

function permit_completion_history(PDO $pdo, int $applicationId, int $actorId): array
{
    $actor = permit_document_load_actor($pdo,$actorId);
    $application = permit_load_application($pdo,$applicationId);
    if (!$actor || !$application || $application['transaction_id'] === null) return [];
    $allowed = ($actor['role']==='community' && (int)$application['applicant_user_id']===$actorId)
        || $actor['role']==='rps'
        || ($actor['role']==='ems' && in_array($application['application_status'],['released','completed'],true))
        || ($actor['role']==='superadmin' && (permit_decision_actor($pdo,$actorId)!==null || permit_inspection_actor($pdo,$actorId)!==null));
    if (!$allowed) return [];
    $stmt=$pdo->prepare('SELECT r.*, CONCAT(u.fname, \' \', u.lname) AS reporter_name FROM tbl_permit_completion_reports r
        INNER JOIN tbl_users u ON u.id=r.reported_by_user_id WHERE r.application_id=? ORDER BY r.id DESC');
    $stmt->execute([$applicationId]);$reports=$stmt->fetchAll();
    $reviews=$pdo->prepare('SELECT v.*, CONCAT(u.fname, \' \', u.lname) AS verifier_name FROM tbl_permit_completion_verifications v
        INNER JOIN tbl_users u ON u.id=v.verified_by_user_id WHERE v.report_id=? ORDER BY v.id DESC');
    foreach($reports as &$report){$reviews->execute([(int)$report['id']]);$report['verifications']=$reviews->fetchAll();}
    unset($report);return $reports;
}

function permit_report_cutting_done(PDO $pdo,int $applicationId,int $actorId,array $input): array
{
    $description=is_string($input['description'] ?? null)?trim($input['description']):'';
    $day=is_string($input['completed_on'] ?? null)?trim($input['completed_on']):'';
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$day);
    if($description==='' || mb_strlen($description)>2000 || !$date || $date->format('Y-m-d')!==$day || $day>date('Y-m-d')) {
        throw new InvalidArgumentException('Provide a valid completion date and description of up to 2,000 characters.');
    }
    $owns = permit_completion_transaction_start($pdo);
    try {
        $actor=permit_document_load_actor($pdo,$actorId,true);
        $application=permit_load_application($pdo,$applicationId,true);
        if(!$actor || $actor['role']!=='community' || !$application || (int)$application['applicant_user_id']!==$actorId) {throw new RuntimeException('The application is not available.');}
        if($application['application_status']!=='released' || $application['validity_status']!=='active') {throw new RuntimeException('Completion reports require an active released permit.');}
        $permit=$pdo->prepare('SELECT valid_from, valid_until FROM tbl_permits WHERE application_id=? LIMIT 1 FOR UPDATE');$permit->execute([$applicationId]);$term=$permit->fetch();
        if(!$term || $day<substr((string)$term['valid_from'],0,10) || $day>substr((string)$term['valid_until'],0,10)){throw new InvalidArgumentException('The completion date must be within the permit validity dates.');}
        $latest=$pdo->prepare('SELECT * FROM tbl_permit_completion_reports WHERE application_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE');$latest->execute([$applicationId]);$previous=$latest->fetch();
        if($previous){
            $v=$pdo->prepare('SELECT * FROM tbl_permit_completion_verifications WHERE report_id=? ORDER BY id DESC LIMIT 1');$v->execute([(int)$previous['id']]);$review=$v->fetch();
            if(!$review){
                if($previous['description']===$description && $previous['completed_on']===$day){permit_completion_transaction_end($pdo,$owns,true);return ['id'=>(int)$previous['id'],'duplicate'=>true];}
                throw new RuntimeException('Your completion report is awaiting EMS verification.');
            }
        }
        $insert=$pdo->prepare('INSERT INTO tbl_permit_completion_reports (application_id,reported_by_user_id,description,completed_on) VALUES (?,?,?,?)');
        $insert->execute([$applicationId,$actorId,$description,$day]);$id=(int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE tbl_permit_applications SET completion_status='pending_ems' WHERE id=?")->execute([$applicationId]);
        record_audit_event($pdo,$actorId,'permit','cutting_completion_reported','permit_application',$applicationId,'Client reported tree cutting as done.',['report_id'=>$id,'completed_on'=>$day,'description'=>$description]);
        $recipients=$pdo->query("SELECT id FROM tbl_users WHERE role='ems' AND status='active'")->fetchAll(PDO::FETCH_COLUMN);
        create_notifications_for_users($pdo,$recipients,$actorId,'permit_status','Final tree-cutting verification required',
            $application['transaction_id'].' was marked as done by the client. Verify all approved trees and any additional affected trees.','permit_completion_report',$applicationId);
        permit_completion_transaction_end($pdo,$owns,true);return ['id'=>$id,'duplicate'=>false];
    }catch(Throwable $e){permit_completion_transaction_end($pdo,$owns,false);throw $e;}
}

function permit_verify_cutting_done(PDO $pdo,int $applicationId,int $actorId,array $input): array
{
    $all=$input['all_approved_trees_cut'] ?? null;$additional=$input['additional_trees_affected'] ?? null;
    $description=is_string($input['description'] ?? null)?trim($input['description']):'';
    $expected=filter_var($input['report_id'] ?? '',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if(!in_array($all,['0','1'],true) || !in_array($additional,['0','1'],true) || $description==='' || mb_strlen($description)>2000 || !$expected) {
        throw new InvalidArgumentException('Answer both verification questions and provide a description of up to 2,000 characters.');
    }
    $owns = permit_completion_transaction_start($pdo);
    try{
        $actor=permit_document_load_actor($pdo,$actorId,true);$application=permit_load_application($pdo,$applicationId,true);
        if(!$actor || $actor['role']!=='ems' || !$application || $application['application_status']!=='released' || $application['validity_status']!=='active') {throw new RuntimeException('Only EMS may verify an active released permit.');}
        $report=$pdo->prepare('SELECT * FROM tbl_permit_completion_reports WHERE application_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE');$report->execute([$applicationId]);$latest=$report->fetch();
        if(!$latest || (int)$latest['id']!==$expected){throw new RuntimeException('The completion report changed. Refresh before verifying.');}
        $prior=$pdo->prepare('SELECT * FROM tbl_permit_completion_verifications WHERE report_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE');$prior->execute([$expected]);$last=$prior->fetch();
        if($last){throw new RuntimeException('This report has already been verified. A follow-up requires a new client completion report.');}
        $resolved=($input['follow_up_resolved'] ?? '')==='1';
        $earlier=$pdo->prepare('SELECT 1 FROM tbl_permit_completion_reports r INNER JOIN tbl_permit_completion_verifications v ON v.report_id=r.id
            WHERE r.application_id=? AND r.id<? AND (v.all_approved_trees_cut=0 OR v.additional_trees_affected=1) LIMIT 1');
        $earlier->execute([$applicationId,$expected]);$hasFollowUp=$earlier->fetchColumn()!==false;
        $additionalHistory=$pdo->prepare('SELECT 1 FROM tbl_permit_completion_reports r INNER JOIN tbl_permit_completion_verifications v ON v.report_id=r.id WHERE r.application_id=? AND r.id<? AND v.additional_trees_affected=1 LIMIT 1');
        $additionalHistory->execute([$applicationId,$expected]);$hadAdditionalFindings=$additionalHistory->fetchColumn()!==false;
        if($resolved && !$hasFollowUp){throw new InvalidArgumentException('There are no earlier follow-up findings to resolve.');}
        $insert=$pdo->prepare('INSERT INTO tbl_permit_completion_verifications (report_id,verified_by_user_id,all_approved_trees_cut,additional_trees_affected,description,follow_up_resolved) VALUES (?,?,?,?,?,?)');
        $insert->execute([$expected,$actorId,(int)$all,(int)$additional,$description,(int)$resolved]);$verificationId=(int)$pdo->lastInsertId();
        $closed=$all==='1' && ($additional==='0' || ($hadAdditionalFindings && $resolved));
        $pdo->prepare('UPDATE tbl_permit_applications SET completion_status=? WHERE id=?')->execute([$closed?'verified':'follow_up',$applicationId]);
        if($closed){
            $permit=$pdo->prepare('SELECT id FROM tbl_permits WHERE application_id=? LIMIT 1 FOR UPDATE');$permit->execute([$applicationId]);$permitId=$permit->fetchColumn();
            $approved=$pdo->prepare("SELECT approved_tree_count FROM tbl_permit_decisions WHERE application_id=? AND decision='approved' ORDER BY id DESC LIMIT 1");$approved->execute([$applicationId]);$count=(int)$approved->fetchColumn();
            if(!$permitId || $count<1){throw new RuntimeException('The approved tree count or released permit is missing.');}
            $completion=$pdo->prepare("INSERT INTO tbl_permit_cutting_completions (application_id,permit_id,completion_status,trees_cut_count,completed_on,verified_by_user_id,recorded_by_user_id,remarks) VALUES (?,?,'completed',?,?,?,?,?)");
            $completion->execute([$applicationId,(int)$permitId,$count,$latest['completed_on'],$actorId,$actorId,mb_strcut($description,0,1000,'UTF-8')]);
            $pdo->prepare('UPDATE tbl_permits SET completed_at=CURRENT_TIMESTAMP WHERE id=?')->execute([(int)$permitId]);
            permit_release_apply_status($pdo,$applicationId,$actorId,'validity','active','completed','EMS final verification confirmed all approved trees were cut and any earlier follow-up findings were resolved.');
            permit_release_apply_status($pdo,$applicationId,$actorId,'application','released','completed','Client completion confirmed by EMS.');
        }
        record_audit_event($pdo,$actorId,'verification','cutting_completion_verified','permit_application',$applicationId,$closed?'EMS confirmed final completion.':'EMS recorded findings requiring follow-up.',
            ['verification_id'=>$verificationId,'report_id'=>$expected,'all_approved_trees_cut'=>(int)$all,'additional_trees_affected'=>(int)$additional,'follow_up_resolved'=>$resolved,'description'=>$description]);
        $message=$closed?'EMS confirmed final tree-cutting completion.':'EMS requires follow-up. Review the verification findings before submitting another completion report.';
        create_notification($pdo,(int)$application['applicant_user_id'],$actorId,'permit_status','Tree-cutting verification update',$message.' '.mb_strcut($description,0,500,'UTF-8'),'permit_application',$applicationId);
        $rps=$pdo->query("SELECT id FROM tbl_users WHERE role='rps' AND status='active'")->fetchAll(PDO::FETCH_COLUMN);
        create_notifications_for_users($pdo,$rps,$actorId,'permit_status','EMS final verification recorded',$application['transaction_id'].': '.$message,'permit_application',$applicationId);
        permit_completion_transaction_end($pdo,$owns,true);return ['closed'=>$closed,'verification_id'=>$verificationId];
    }catch(Throwable $e){permit_completion_transaction_end($pdo,$owns,false);throw $e;}
}

function render_permit_completion_panel(PDO $pdo,array $application,int $actorId): void
{
    if(!in_array($application['application_status'],['released','completed'],true))return;
    $history=permit_completion_history($pdo,(int)$application['id'],$actorId);
    $latest=$history[0] ?? null;$review=$latest['verifications'][0] ?? null;
    $pending=$latest!==null && $review===null;
    if(empty($_SESSION['csrf_permit_completion_token']))$_SESSION['csrf_permit_completion_token']=bin2hex(random_bytes(32));
    ?>
    <section class="docket-panel mb-3" id="cutting-final-verification" aria-labelledby="cuttingFinalHeading">
        <div class="section-heading"><h2 id="cuttingFinalHeading">Cutting Completion and Final Verification</h2></div>
        <?php if(isset($_SESSION['permit_completion_flash'])):$flash=$_SESSION['permit_completion_flash'];unset($_SESSION['permit_completion_flash']);?><div class="alert alert-<?php echo e($flash['type']); ?>" role="alert"><?php echo e($flash['message']); ?></div><?php endif; ?>
        <?php if($pending):?><p class="alert alert-light border">Client completion report received. EMS final verification is pending.</p><?php elseif($application['application_status']!=='completed' && $review && (!(int)$review['all_approved_trees_cut'] || (int)$review['additional_trees_affected'])):?><p class="alert alert-warning">Follow-up required. Review the EMS findings below and resolve them before reporting completion again.</p><?php endif; ?>
        <?php foreach($history as $report):?><article class="border rounded p-3 mb-3"><strong>Client completion report · <?php echo e((string)$report['completed_on']); ?></strong><p class="small text-secondary"><?php echo e($report['reporter_name']); ?> · <?php echo e($report['created_at']); ?></p><p class="record-visibility-reason"><?php echo e($report['description']); ?></p><?php foreach($report['verifications'] as $verification):?><div class="border-top pt-2"><strong>EMS verification</strong><p class="small text-secondary"><?php echo e($verification['verifier_name']); ?> · <?php echo e($verification['created_at']); ?></p><p>All approved trees cut: <strong><?php echo (int)$verification['all_approved_trees_cut']?'Yes':'No'; ?></strong> · Additional trees affected: <strong><?php echo (int)$verification['additional_trees_affected']?'Yes':'No'; ?></strong></p><?php if(!empty($verification['follow_up_resolved'])):?><p class="small text-success">Earlier follow-up findings resolved.</p><?php endif;?><p class="record-visibility-reason mb-0"><?php echo e($verification['description']); ?></p></div><?php endforeach; ?></article><?php endforeach; ?>
        <?php if($application['application_status']==='released' && $application['validity_status']==='active'): ?>
            <?php if($_SESSION['role']==='community' && !$pending):?><form method="post" action="../records/permit-completion-action.php" class="row g-3"><input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_permit_completion_token']); ?>"><input type="hidden" name="application_id" value="<?php echo (int)$application['id']; ?>"><input type="hidden" name="action" value="report"><div class="col-md-4"><label class="form-label" for="cuttingCompletedOn">Date cutting completed</label><input class="form-control" type="date" id="cuttingCompletedOn" name="completed_on" max="<?php echo date('Y-m-d'); ?>" required></div><div class="col-12"><label class="form-label" for="cuttingReportDescription">Completion description</label><textarea class="form-control" id="cuttingReportDescription" name="description" maxlength="2000" rows="3" required></textarea></div><div class="col-12"><button class="btn btn-certreefy" type="submit">Mark as done</button><p class="small text-secondary mt-2 mb-0">EMS will verify the trees before final completion is recorded.</p></div></form><?php endif; ?>
            <?php if($_SESSION['role']==='ems' && $pending):?><form method="post" action="../records/permit-completion-action.php" class="row g-3"><input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_permit_completion_token']); ?>"><input type="hidden" name="application_id" value="<?php echo (int)$application['id']; ?>"><input type="hidden" name="report_id" value="<?php echo (int)$latest['id']; ?>"><input type="hidden" name="action" value="verify"><div class="col-md-6"><label class="form-label" for="allApprovedCut">Have all approved trees been cut?</label><select class="form-select" id="allApprovedCut" name="all_approved_trees_cut" required><option value="">Select answer</option><option value="1">Yes</option><option value="0">No</option></select></div><div class="col-md-6"><label class="form-label" for="additionalTreesAffected">Were additional trees affected?</label><select class="form-select" id="additionalTreesAffected" name="additional_trees_affected" required><option value="">Select answer</option><option value="0">No</option><option value="1">Yes</option></select></div><?php if(count($history)>1):?><div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="followUpResolved" name="follow_up_resolved" value="1"><label class="form-check-label" for="followUpResolved">Previous follow-up findings have been resolved</label><div class="form-text">Describe the resolution below. Keep any additional affected trees recorded accurately.</div></div></div><?php endif;?><div class="col-12"><label class="form-label" for="finalVerificationDescription">Findings and description</label><textarea class="form-control" id="finalVerificationDescription" name="description" rows="3" maxlength="2000" required></textarea></div><div class="col-12"><button class="btn btn-certreefy" type="submit">Record verification</button></div></form><?php endif; ?>
        <?php endif; ?>
    </section>
    <?php
}
