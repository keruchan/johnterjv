<?php
/** Focused data-integrity checks; all database writes are rolled back. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../includes/permit_completion.php';
require_once __DIR__.'/../includes/permit_guidance.php';
require_once __DIR__.'/../includes/seedling_distribution.php';
$checks=0;$temporary=[];
function intake_check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$label);$checks++;echo 'PASS: '.$label.PHP_EOL;}
function intake_reject(callable $call,string $label):void{try{$call();}catch(InvalidArgumentException|RuntimeException $e){intake_check(true,$label);return;}intake_check(false,$label);}
try{
    $original=__DIR__.'/../filesresources/TALLY SHEET.xlsx';$sourceHash=hash_file('sha256',$original);
    intake_reject(fn()=>permit_tally_parse($original,'nga'),'out-of-Laguna sample rejected');
    $file=tempnam(sys_get_temp_dir(),'certreefy-tally-');unlink($file);$file.='.zip';$temporary[]=$file;
    $source=new PharData($original,0,null,Phar::ZIP);$copy=new PharData($file,0,null,Phar::ZIP);
    foreach(['xl/workbook.xml','xl/_rels/workbook.xml.rels','xl/worksheets/sheet1.xml','xl/sharedStrings.xml','[Content_Types].xml'] as $part){$copy[$part]=$source[$part]->getContent();}
    $strings=$copy['xl/sharedStrings.xml']->getContent();
    $strings=str_replace(["7°29'37&quot;","7°29'36&quot;","122°58'54&quot;","122°58'53&quot;","122°58'50&quot;"],
        ["14°16'53&quot;","14°16'53&quot;","121°24'58&quot;","121°24'58&quot;","121°24'58&quot;"],$strings);
    $copy['xl/sharedStrings.xml']=$strings;
    $parsed=permit_tally_parse($file,'nga');
    intake_check($parsed['tree_count']===50 && $parsed['natural_count']===5 && $parsed['planted_count']===45,'50 individual trees classified correctly');
    intake_check($parsed['replacement_count']===2750,'Natural × 100 plus Planted × 50');
    intake_check(permit_matrix_replacement_ratio('nga','balling')===0 && permit_matrix_replacement_ratio('public_places','balling')===null,'existing earth-balling matrix rules retained');
    intake_check(abs($parsed['volume_total']-13.10)<0.00001,'subtotal excluded and numeric volumes recomputed');
    $sheet=$copy['xl/worksheets/sheet1.xml']->getContent();
    $prefixed=preg_replace('/<(\/?)([A-Za-z][A-Za-z0-9]*)(?=[\s>\/])/','<$1x:$2',$sheet);
    $prefixed=str_replace('<x:worksheet ', '<x:worksheet xmlns:x="http://schemas.openxmlformats.org/spreadsheetml/2006/main" ', $prefixed);
    $copy['xl/worksheets/sheet1.xml']=$prefixed;
    intake_check(permit_tally_parse($file,'nga')['replacement_count']===2750,'prefixed worksheet namespace supported');
    $copy['xl/worksheets/sheet1.xml']=preg_replace('/(<c r="C2"[^>]*>)/','$1<f>1+1</f>',$sheet);
    intake_reject(fn()=>permit_tally_parse($file,'nga'),'data-row formulas rejected');
    $copy['xl/worksheets/sheet1.xml']=preg_replace('/(<c r="A3"[^>]*><v>)2\.0(<\/v>)/','$1'.'1.0'.'$2',$sheet);
    intake_reject(fn()=>permit_tally_parse($file,'nga'),'duplicate tree numbers rejected');
    intake_check(hash_file('sha256',$original)===$sourceHash,'original sample workbook unchanged');
    $input=['applicant_type'=>'individual','property_relationship'=>'owner','property_owner_name'=>'Intake Probe',
        'district'=>'District 4','barangay'=>'Bubukal','municipality'=>'Santa Cruz','province'=>'Laguna',
        'purpose_option'=>permit_matrix_category('nga')['purposes'][0],'property_classification'=>'private_property','lot_number'=>'forged',
        'cutting_purpose'=>'forged','tree_origin'=>'natural','declaration_confirmed'=>'1'];
    $data=permit_normalize_application_data($input,'nga',true);
    intake_check($data['property_classification']===null && $data['lot_number']===null && $data['cutting_purpose']===null,'removed intake fields cannot be forged');
    intake_check(permit_validate_application_data($data,true)===[],'detailed property location is optional');
    $trees=permit_normalize_tree_records([['common_name'=>'Probe','quantity'=>'2','tree_origin'=>'natural','scientific_name'=>'Probe species','diameter_cm'=>'20','estimated_height_m'=>'7','condition_notes'=>'forged']],true);
    intake_check($trees[0]['tree_origin']==='natural' && $trees[0]['condition_notes']===null,'per-tree origin retained and removed notes ignored');
    intake_check(permit_validate_tree_records($trees,true,'nga')===[],'quantities and measurements remain supported');
    $bad=$trees;$bad[0]['tree_origin']=null;intake_check(permit_validate_tree_records($bad,true,'nga')!==[],'missing tree origin rejected');
    intake_check(str_contains(permit_reason_with_remarks('decline','site_findings','Observed problem'),'Site assessment'),'reason and additional remarks retained');
    intake_reject(fn()=>permit_reason_with_remarks('decline','other',''),'Other requires an explanation');
    $pdo->beginTransaction();
    $roles=[];foreach(['community','rps','ems'] as $role){$stmt=$pdo->prepare('SELECT * FROM tbl_users WHERE role=? AND status=\'active\' ORDER BY id LIMIT 1');$stmt->execute([$role]);$roles[$role]=$stmt->fetch();if(!$roles[$role])throw new RuntimeException('Active role fixture missing.');}
    $owner=(int)$roles['community']['id'];$rps=(int)$roles['rps']['id'];$ems=(int)$roles['ems']['id'];
    $transaction='TCP-2099-'.random_int(100000,999999);
    $statuses=permit_initial_statuses();$statuses['application']='released';$statuses['decision']='approved';$statuses['donation']='rps_verified';$statuses['release']='released';$statuses['validity']='active';
    $appId=permit_insert_application_record($pdo,permit_application_parameters($owner,new_permit_submission_key(),$roles['community'],$data,$statuses,$transaction,true));
    permit_replace_tree_records($pdo,$appId,$trees);
    $decision=$pdo->prepare("INSERT INTO tbl_permit_decisions (application_id,decided_by_user_id,decision,approved_tree_count) VALUES (?,?,'approved',2)");$decision->execute([$appId,$rps]);$decisionId=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO tbl_permits (application_id,decision_id,prepared_by_user_id,released_by_user_id,released_at,valid_from,valid_until) VALUES (?,?,?,?,CURRENT_TIMESTAMP,?,?)')->execute([$appId,$decisionId,$rps,$rps,date('Y-m-d',strtotime('-1 day')),date('Y-m-d',strtotime('+60 days'))]);
    intake_reject(fn()=>permit_report_cutting_done($pdo,$appId,$rps,['description'=>'Not the owner','completed_on'=>date('Y-m-d')]),'RPS cannot submit a client completion report');
    intake_check($pdo->inTransaction(),'failed nested action preserves outer transaction');
    $report=permit_report_cutting_done($pdo,$appId,$owner,['description'=>'All cutting reported done.','completed_on'=>date('Y-m-d')]);
    intake_check(permit_load_application($pdo,$appId)['application_status']==='released','client report does not close the application');
    $duplicate=permit_report_cutting_done($pdo,$appId,$owner,['description'=>'All cutting reported done.','completed_on'=>date('Y-m-d')]);intake_check($duplicate['duplicate'],'duplicate client report is idempotent');
    intake_reject(fn()=>permit_verify_cutting_done($pdo,$appId,$rps,['report_id'=>(string)$report['id'],'all_approved_trees_cut'=>'1','additional_trees_affected'=>'0','description'=>'Unauthorized']), 'RPS cannot bypass EMS final verification');
    $first=permit_verify_cutting_done($pdo,$appId,$ems,['report_id'=>(string)$report['id'],'all_approved_trees_cut'=>'0','additional_trees_affected'=>'1','description'=>'Two trees remain and another tree was affected.']);
    intake_check(!$first['closed'] && permit_load_application($pdo,$appId)['application_status']==='released','adverse findings keep the case open');
    $next=permit_report_cutting_done($pdo,$appId,$owner,['description'=>'Follow-up work completed.','completed_on'=>date('Y-m-d')]);
    intake_reject(fn()=>permit_verify_cutting_done($pdo,$appId,$ems,['report_id'=>(string)$report['id'],'all_approved_trees_cut'=>'1','additional_trees_affected'=>'0','description'=>'Stale']), 'stale completion report rejected');
    $final=permit_verify_cutting_done($pdo,$appId,$ems,['report_id'=>(string)$next['id'],'all_approved_trees_cut'=>'1','additional_trees_affected'=>'0','description'=>'All approved trees checked; no outstanding additional affected trees.']);
    $closed=permit_load_application($pdo,$appId);intake_check($final['closed'] && $closed['application_status']==='completed' && $closed['validity_status']==='completed','clean EMS verification records final completion');
    intake_check(count(permit_completion_history($pdo,$appId,$owner))===2,'client sees both reports and EMS findings');
    $year=(int)date('Y');$baseline=seedling_annual_distribution($pdo,$year);
    $pdo->prepare('INSERT INTO tbl_seedling_inventory (common_name,available_quantity,low_stock_threshold,created_by_user_id) VALUES (?,20000,10,?)')->execute(['Distribution Probe '.bin2hex(random_bytes(5)),$ems]);$inventory=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO tbl_seedling_requests (submission_key,requester_user_id,requester_name,planting_purpose,planting_location,current_status,planting_barangay,planting_municipality,planting_latitude,planting_longitude) VALUES (?,?,'Probe','Probe','Probe','ready_for_pickup','Probe Barangay','Probe Municipality',14.2814,121.4161)")->execute([new_seedling_submission_key(),$owner]);$request=(int)$pdo->lastInsertId();
    $movement=$pdo->prepare("INSERT INTO tbl_seedling_stock_movements (inventory_id,request_id,movement_type,quantity_delta,quantity_after,recorded_by_user_id,created_at) VALUES (?,?,'released',?,0,?,?)");
    $movement->execute([$inventory,$request,-16000,$ems,$year.'-01-01 00:00:00']);
    $movement->execute([$inventory,$request,-10,$ems,($year-1).'-12-31 23:59:59']);
    $annual=seedling_annual_distribution($pdo,$year);intake_check($annual['total']===$baseline['total']+16000 && $annual['above']>0,'annual target tracks stock release, permits exceeding 15,000, and excludes the previous year');
    $distribution=seedling_distribution_data($pdo,['distribution_year'=>(string)$year,'distribution_species'=>(string)$inventory]);
    intake_check($distribution['total']===16000 && count($distribution['groups'])===1 && $distribution['groups'][0]['lat']!==null,'barangay totals and map location use recorded release data');
    $pdo->prepare("UPDATE tbl_seedling_requests SET current_status='claimed' WHERE id=?")->execute([$request]);intake_check(seedling_annual_distribution($pdo,$year)['total']===$annual['total'],'pickup does not count a second distribution');
    $createSpecies=$pdo->prepare('INSERT INTO tbl_seedling_inventory (common_name,available_quantity,low_stock_threshold,created_by_user_id,indigenous_status) VALUES (?,?,10,?,?)');
    $createSpecies->execute(['Short Forecast '.bin2hex(random_bytes(4)),100,$ems,'non_indigenous']);$short=(int)$pdo->lastInsertId();
    $createSpecies->execute(['Unknown Forecast '.bin2hex(random_bytes(4)),5000,$ems,'unknown']);$unknown=(int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE tbl_seedling_inventory SET indigenous_status='indigenous' WHERE id=?")->execute([$inventory]);
    $movement->execute([$inventory,null,-90,$ems,date('Y-m-d H:i:s')]);$movement->execute([$short,null,-90,$ems,date('Y-m-d H:i:s')]);
    $ranked=seedling_request_recommendations($pdo,[seedling_inventory_find($pdo,$short),seedling_inventory_find($pdo,$unknown),seedling_inventory_find($pdo,$inventory)]);
    intake_check((int)$ranked[0]['id']===$inventory && (int)$ranked[1]['id']===$short && (int)$ranked[2]['id']===$unknown,'latest finite depletion date ranks first and unknown forecasts remain explicit');
    $pdo->prepare('INSERT INTO tbl_permit_tallies (application_id,uploaded_by_user_id,original_filename,storage_path,sha256,rows_json,tree_count,replacement_count,natural_count,planted_count,volume_total) VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([$appId,$rps,'probe.xlsx','tallies/probe.xlsx',str_repeat('a',64),json_encode(array_slice($parsed['rows'],0,2)),2,150,1,1,0.22]);
    $donor=seedling_donation_recommendations($pdo,['application_id'=>$appId,'policy_code'=>'tree_origin_tally']);
    intake_check($donor!==[] && count(array_filter($donor,static fn($row)=>$row['indigenous_status']!=='indigenous'))===0,'natural-tree donation suggestions use confirmed indigenous species');
    // Exercise the actual new-intake approval writer, including its immutable tally policy snapshot.
    $approvalStatuses=permit_initial_statuses();$approvalStatuses['application']='under_review';$approvalStatuses['decision']='under_review';$approvalStatuses['document']='verified';$approvalStatuses['inspection']='not_required';
    $approvalId=permit_insert_application_record($pdo,permit_application_parameters($owner,new_permit_submission_key(),$roles['community'],$data,$approvalStatuses,'TCP-2098-'.random_int(100000,999999),true));
    permit_replace_tree_records($pdo,$approvalId,$trees);
    $approval=permit_load_application($pdo,$approvalId);
    foreach(permit_document_type_catalog($approval) as $type=>$definition){
        if(empty($definition['required']))continue;
        $pdo->prepare("INSERT INTO tbl_permit_documents (application_id,document_type,storage_path,original_filename,mime_type,file_size_bytes,uploaded_by_user_id,verification_status) VALUES (?,?,'probe/file.pdf','probe.pdf','application/pdf',1,?,'accepted')")->execute([$approvalId,$type,$owner]);$doc=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO tbl_permit_document_reviews (application_id,document_id,document_type,review_scope,review_status,original_received,original_received_on,received_by_user_id,scan_compared_with_original,wet_ink_required,reviewed_by_user_id) VALUES (?,?,?,'original','verified',1,?, ?,1,0,?)")->execute([$approvalId,$doc,$type,date('Y-m-d'),$rps,$rps]);
    }
    $pdo->prepare('INSERT INTO tbl_permit_tallies (application_id,uploaded_by_user_id,original_filename,storage_path,sha256,rows_json,tree_count,replacement_count,natural_count,planted_count,volume_total) VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([$approvalId,$rps,'probe.xlsx','tallies/probe.xlsx',str_repeat('b',64),json_encode(array_slice($parsed['rows'],0,2)),2,150,1,1,0.22]);
    intake_check(permit_decision_readiness($pdo,$approvalId)['ready'],'new intake approval readiness accepts optional property details and verified tally');
    record_permit_review_action($pdo,$approvalId,$rps,'approve',['expected_decision_id'=>'0','approved_tree_count'=>'2','decision_notes'=>'Approval integration probe.']);
    $approved=permit_load_application($pdo,$approvalId);$requirement=permit_donation_requirement_for_actor($pdo,$approvalId,$owner);
    intake_check($approved['application_status']==='awaiting_donation' && (int)$requirement['required_seedling_count']===150 && $requirement['policy_code']==='tree_origin_tally','approval creates the tally-based requirement and donation stage');
    intake_check($requirement['property_classification']===null,'new approval preserves no invented property classification');
    $pdo->rollBack();echo $checks.' checks passed; database writes rolled back.'.PHP_EOL;
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
finally{unset($copy,$source);foreach($temporary as $file){if(is_file($file))unlink($file);}}
