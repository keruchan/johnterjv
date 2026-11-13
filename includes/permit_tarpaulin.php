<?php
/** Records physical tarpaulin issue alongside the existing physical permit release. */
require_once __DIR__.'/permit_release.php';

function permit_record_tarpaulin_issue(PDO $pdo,int $applicationId,int $actorId,string $day): void
{
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$day);
    if(!$date || $date->format('Y-m-d')!==$day || $day>date('Y-m-d')){throw new InvalidArgumentException('Enter a valid tarpaulin issue date.');}
    try{
        $pdo->beginTransaction();
        if(permit_release_actor($pdo,$actorId,true)===null){throw new RuntimeException('You cannot record tarpaulin issue.');}
        $application=permit_load_application($pdo,$applicationId,true);
        if(!$application || $application['release_status']!=='released'){throw new RuntimeException('Release the permit before recording tarpaulin issue.');}
        $stmt=$pdo->prepare('SELECT id,released_at,tarpaulin_released_on FROM tbl_permits WHERE application_id=? FOR UPDATE');$stmt->execute([$applicationId]);$permit=$stmt->fetch();
        if(!$permit || $day<substr((string)$permit['released_at'],0,10)){throw new InvalidArgumentException('Tarpaulin issue cannot precede permit release.');}
        if($permit['tarpaulin_released_on']!==null){throw new RuntimeException('Tarpaulin issue has already been recorded.');}
        $pdo->prepare('UPDATE tbl_permits SET tarpaulin_released_on=?,tarpaulin_released_by_user_id=? WHERE id=?')->execute([$day,$actorId,(int)$permit['id']]);
        record_audit_event($pdo,$actorId,'permit','permit_tarpaulin_issued','permit_application',$applicationId,'Physical tarpaulin issue recorded.',['issued_on'=>$day]);
        create_notification($pdo,(int)$application['applicant_user_id'],$actorId,'permit_status','Tarpaulin issue recorded','RPS recorded tarpaulin issue for '.$application['transaction_id'].' on '.$day.'.','permit_application',$applicationId);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function render_permit_tarpaulin(PDO $pdo,array $application,bool $canRecord=false):void
{
    if($application['release_status']!=='released')return;
    $stmt=$pdo->prepare('SELECT p.tarpaulin_released_on,p.released_at,CONCAT(u.fname, \' \', u.lname) AS issuer_name FROM tbl_permits p LEFT JOIN tbl_users u ON u.id=p.tarpaulin_released_by_user_id WHERE p.application_id=?');$stmt->execute([(int)$application['id']]);$permit=$stmt->fetch();
    if(!$permit)return;
    ?>
    <section class="docket-panel mb-3" id="tarpaulin-issue"><div class="section-heading"><h2>Tarpaulin Issue</h2></div>
        <?php if(isset($_SESSION['permit_tarpaulin_flash'])):$flash=$_SESSION['permit_tarpaulin_flash'];unset($_SESSION['permit_tarpaulin_flash']);?><div class="alert alert-<?php echo e($flash['type']); ?>" role="alert"><?php echo e($flash['message']); ?></div><?php endif; ?>
        <?php if($permit['tarpaulin_released_on']):?><p class="mb-0">Issued on <?php echo e($permit['tarpaulin_released_on']); ?> · <?php echo e($permit['issuer_name']); ?></p>
        <?php elseif($canRecord):?><form method="post" action="permit-tarpaulin-action.php" class="row g-3 align-items-end"><input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_permit_release_token']); ?>"><input type="hidden" name="application_id" value="<?php echo (int)$application['id']; ?>"><div class="col-sm-6"><label class="form-label" for="tarpaulinIssueDate">Date tarpaulin issued</label><input class="form-control" type="date" id="tarpaulinIssueDate" name="issued_on" min="<?php echo e(substr((string)$permit['released_at'],0,10)); ?>" max="<?php echo date('Y-m-d'); ?>" required></div><div class="col-sm-6"><button class="btn btn-certreefy" type="submit">Record issue</button></div></form>
        <?php else:?><p class="text-secondary mb-0">Tarpaulin issue has not yet been recorded.</p><?php endif; ?>
    </section>
    <?php
}
