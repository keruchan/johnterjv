<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/navigation.php';
require_once __DIR__.'/../../includes/permit_completion.php';
require_once __DIR__.'/../../includes/registry_filters.php';
require_role($pdo,'ems');
$currentRole='ems';$userId=(int)$_SESSION['id'];
$stmt=$pdo->query('SELECT a.id,a.transaction_id,a.applicant_name,a.application_status,r.created_at,r.completed_on,r.id AS report_id,
    v.id AS verification_id,v.all_approved_trees_cut,v.additional_trees_affected
    FROM tbl_permit_completion_reports r INNER JOIN tbl_permit_applications a ON a.id=r.application_id
    LEFT JOIN tbl_permit_completion_verifications v ON v.id=(SELECT MAX(v2.id) FROM tbl_permit_completion_verifications v2 WHERE v2.report_id=r.id)
    WHERE r.id=(SELECT MAX(r2.id) FROM tbl_permit_completion_reports r2 WHERE r2.application_id=a.id)
    ORDER BY (v.id IS NULL) DESC,r.id DESC');
$rows=registry_date_rows($stmt->fetchAll(),'created_at');
$predicates=['pending'=>static fn($r)=>$r['verification_id']===null,
    'followup'=>static fn($r)=>$r['application_status']!=='completed' && $r['verification_id']!==null && (!(int)$r['all_approved_trees_cut'] || (int)$r['additional_trees_affected']),
    'complete'=>static fn($r)=>$r['application_status']==='completed'];
$counts=registry_counts($rows,$predicates);$rows=registry_category_rows($rows,$predicates);
$selected=null;$id=filter_var($_GET['application_id']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if(isset($_GET['application_id'])){
    if(!$id){http_response_code(404);exit('Completion report not found.');}
    $selected=permit_load_application($pdo,$id);
    $check=$pdo->prepare('SELECT 1 FROM tbl_permit_completion_reports WHERE application_id=? LIMIT 1');$check->execute([$id]);
    if(!$selected || !$check->fetchColumn()){http_response_code(404);exit('Completion report not found.');}
}
?>
<!doctype html><html lang="en"><head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CERTREEFY | Final Verification</title>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../../css/dashboard.css?v=<?php echo e(certreefy_asset_version('css/dashboard.css')); ?>" rel="stylesheet">
</head><body><a class="skip-link" href="#main-content">Skip to main content</a><div class="app-shell">
    <?php render_certreefy_navigation('ems','permit_final_verification'); ?>
    <main class="main" id="main-content">
        <section class="page-header mb-4"><div class="eyebrow">Enforcement &amp; Monitoring Section</div><h1 class="page-title">Final Tree-Cutting Verification</h1><p class="meta-copy mb-0">Verify client completion reports and record findings requiring follow-up.</p></section>
        <?php render_registry_date_filter('Client completion report dates');render_registry_cards($counts,['all'=>'Completion reports','pending'=>'Awaiting verification','followup'=>'Follow-up required','complete'=>'Completed'], ['application_id'=>null]); ?>
        <?php if($selected): ?>
            <section class="docket-panel mb-3"><div class="section-heading"><h2><?php echo e($selected['transaction_id']); ?></h2><a class="btn btn-sm btn-outline-secondary" href="permit-final-verification.php">Back to reports</a></div><p class="mb-0"><?php echo e($selected['applicant_name']); ?></p></section>
            <?php render_permit_completion_panel($pdo,$selected,$userId); ?>
        <?php endif; ?>
        <section class="docket-panel"><div class="section-heading"><h2>Client Completion Reports</h2><span class="section-note"><?php echo count($rows); ?> records</span></div>
            <div class="table-responsive"><table class="table align-middle" data-table-tools data-search-placeholder="Search transaction or applicant"><thead><tr><th>Transaction</th><th>Applicant</th><th>Reported completion</th><th>Status</th><th>Action</th></tr></thead><tbody>
            <?php foreach($rows as $row):?><tr><td><a class="record-link" data-row-link href="permit-final-verification.php?application_id=<?php echo (int)$row['id']; ?>#cutting-final-verification"><?php echo e($row['transaction_id']); ?></a></td><td><?php echo e($row['applicant_name']); ?></td><td><?php echo e((string)$row['completed_on']); ?></td><td><?php echo $row['verification_id']===null?'Awaiting EMS verification':($row['application_status']==='completed'?'Completed':'Follow-up required'); ?></td><td><a class="btn btn-sm btn-outline-secondary" href="permit-final-verification.php?application_id=<?php echo (int)$row['id']; ?>#cutting-final-verification" title="View" aria-label="View <?php echo e($row['transaction_id']); ?>"><i class="bi bi-eye" aria-hidden="true"></i></a></td></tr><?php endforeach; ?>
            </tbody></table></div><?php if(!$rows):?><p class="text-secondary mb-0">No completion reports for this selection.</p><?php endif; ?>
        </section>
    </main>
</div><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script></body></html>
