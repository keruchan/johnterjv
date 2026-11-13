<?php
/** Explicit, repeatable fixtures for the owner-confirmed dummy database. Never run on real operational data. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(!in_array('--apply-demo-data',$argv,true)){fwrite(STDERR,"Use --apply-demo-data only with the confirmed dummy database.\n");exit(2);}
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../includes/seedling_locations.php';
$tag='CERTREEFY-REVISION-DEMO-v1';
try{
    $pdo->beginTransaction();
    $ems=(int)$pdo->query("SELECT id FROM tbl_users WHERE role='ems' AND status='active' ORDER BY id LIMIT 1")->fetchColumn();
    $owner=(int)$pdo->query("SELECT id FROM tbl_users WHERE role='community' AND status='active' ORDER BY id LIMIT 1")->fetchColumn();
    if(!$ems||!$owner)throw new RuntimeException('Active EMS and Community demo accounts are required.');
    $destinations=[
        ['municipality'=>'Santa Cruz','barangay'=>'Bubukal','latitude'=>14.2845,'longitude'=>121.414],
        ['municipality'=>'Pagsanjan','barangay'=>'Sabang','latitude'=>14.271,'longitude'=>121.4555],
        ['municipality'=>'Liliw','barangay'=>'Kanlurang Bukal','latitude'=>14.145,'longitude'=>121.44],
    ];
    foreach($destinations as $destination)seedling_location_values($destination);
    $patched=0;$created=0;
    $q=$pdo->query("SELECT id,planting_municipality,planting_barangay FROM tbl_seedling_requests WHERE planting_barangay IS NULL OR planting_barangay='' OR planting_municipality IS NULL OR planting_municipality='' ORDER BY id FOR UPDATE");
    foreach($q->fetchAll() as $i=>$request){
        $d=$destinations[$i%count($destinations)];
        $pdo->prepare("UPDATE tbl_seedling_requests SET planting_province='Laguna',planting_municipality=?,planting_barangay=?,planting_latitude=?,planting_longitude=?,planting_location_source=? WHERE id=?")->execute([$d['municipality'],$d['barangay'],$d['latitude'],$d['longitude'],$tag.' — synthetic destination for an existing dummy request',$request['id']]);
        record_audit_event($pdo,$ems,'seedling','demo_destination_assigned','seedling_request',(int)$request['id'],'Synthetic destination assigned to owner-confirmed dummy data.',['source'=>$tag,'before'=>$request,'after'=>$d]);$patched++;
    }
    // Fixture classification is explicit sample data, not a botanical determination.
    $native=['Pterocarpus indicus','Vitex parviflora','Terminalia microcarpa','Sandoricum koetjape','Syzygium cumini'];
    $nonNative=['Swietenia macrophylla','Mangifera indica','Leucaena leucocephala','Gmelina arborea','Artocarpus heterophyllus'];
    $species=$pdo->query('SELECT * FROM tbl_seedling_inventory WHERE is_active=1 ORDER BY id FOR UPDATE')->fetchAll();
    foreach($species as $i=>$s){
        $classification=in_array($s['scientific_name'],$native,true)?'indigenous':(in_array($s['scientific_name'],$nonNative,true)?'non_indigenous':null);
        if($s['indigenous_status']==='unknown'&&$classification){
            $pdo->prepare('UPDATE tbl_seedling_inventory SET indigenous_status=? WHERE id=?')->execute([$classification,$s['id']]);
            record_audit_event($pdo,$ems,'seedling','demo_species_classified','seedling_inventory',(int)$s['id'],'Demonstration indigenous classification.',['source'=>$tag,'before'=>'unknown','after'=>$classification]);
        }
        $key=hash('sha256',$tag.'release-species-'.$s['id']);
        $exists=$pdo->prepare('SELECT id FROM tbl_seedling_requests WHERE requester_user_id=? AND submission_key=?');$exists->execute([$owner,$key]);if($exists->fetchColumn())continue;
        // Keep the movement ledger balanced. Only newly created sample rows are dated.
        $qty=10+($i%4)*4;$d=$destinations[$i%3];$stamp=date('Y-m-d H:i:s',strtotime('-'.(5+$i*5).' days'));
        if((int)$s['available_quantity']<$qty){seedling_apply_stock_movement($pdo,(int)$s['id'],$ems,'incoming',$qty,null,$tag.' demonstration restock');}
        $reference=seedling_reserve_request_reference($pdo,(int)substr($stamp,0,4));
        $pdo->prepare("INSERT INTO tbl_seedling_requests (request_reference,submission_key,requester_user_id,requester_name,planting_purpose,planting_location,current_status,reviewed_by_user_id,reviewed_at,review_remarks,fulfilled_by_user_id,fulfilled_at,claimed_by_name,released_by_user_id,claimed_on,claim_remarks,submitted_at,planting_province,planting_municipality,planting_barangay,planting_latitude,planting_longitude,planting_location_source) VALUES (?,?,?,'Demo Recipient','Demonstration planting',?,'claimed',?,?,?, ?,?,'Demo Recipient',?,?,?,?,'Laguna',?,?,?,?,?)")->execute([$reference,$key,$owner,$d['barangay'].', '.$d['municipality'],$ems,$stamp,$tag,$ems,$stamp,$ems,substr($stamp,0,10),$tag,$stamp,$d['municipality'],$d['barangay'],$d['latitude'],$d['longitude'],$tag]);
        $requestId=(int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO tbl_seedling_request_items (request_id,inventory_id,common_name,quantity_requested,quantity_approved) VALUES (?,?,?,?,?)')->execute([$requestId,$s['id'],$s['common_name'],$qty,$qty]);
        seedling_apply_stock_movement($pdo,(int)$s['id'],$ems,'released',-$qty,$requestId,$tag.' synthetic historical release');
        $movement=(int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE tbl_seedling_stock_movements SET created_at=? WHERE id=?')->execute([$stamp,$movement]);
        $pdo->prepare("INSERT INTO tbl_seedling_request_status_history (request_id,previous_status,new_status,changed_by_user_id,remarks,created_at) VALUES (?,NULL,'claimed',?,?,?)")->execute([$requestId,$ems,$tag.' completed sample lifecycle',$stamp]);
        record_audit_event($pdo,$ems,'seedling','demo_release_created','seedling_request',$requestId,'Synthetic release history created for the demonstration.',['source'=>$tag,'quantity'=>$qty,'release_date'=>$stamp]);$created++;
    }
    foreach($destinations as $i=>$d){
        $name='Demo Seedling Provider '.($i+1);$q=$pdo->prepare('SELECT id FROM tbl_seedling_providers WHERE provider_name=? AND data_source=?');$q->execute([$name,$tag]);if($q->fetchColumn())continue;
        $pdo->prepare("INSERT INTO tbl_seedling_providers (provider_name,address,municipality,barangay,latitude,longitude,contact_details,opening_hours,notes,data_source,created_by_user_id,updated_by_user_id) VALUES (?,?,?,?,?,?,'Coordinate with EMS','By arrangement','Sample location for demonstration; confirm actual providers with EMS.',?,?,?)")->execute([$name,'Sample nursery location',$d['municipality'],$d['barangay'],$d['latitude'],$d['longitude'],$tag,$ems,$ems]);
        record_audit_event($pdo,$ems,'seedling','demo_provider_created','seedling_provider',(int)$pdo->lastInsertId(),'Demonstration provider created.',['source'=>$tag]);
    }
    $pdo->commit();
    echo "Demo data applied: $patched historical destinations assigned; $created sample release requests added. Existing rows preserved. Re-running does not duplicate fixtures.\n";
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
