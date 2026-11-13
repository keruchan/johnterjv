<?php
/** EMS-maintained provider locations and audited destination corrections. */
require_once __DIR__.'/seedling.php';
require_once __DIR__.'/view.php';

function seedling_providers(PDO $pdo,bool $activeOnly=true):array
{
    return $pdo->query('SELECT * FROM tbl_seedling_providers'.($activeOnly?' WHERE is_active=1':'').' ORDER BY provider_name')->fetchAll();
}
function seedling_location_values(array $input):array
{
    foreach(['municipality','barangay','latitude','longitude'] as $field)if(!is_scalar($input[$field]??''))throw new SeedlingValidationException('Enter valid location details.');
    $townName=trim((string)($input['municipality']??''));$barangay=trim((string)($input['barangay']??''));
    $errors=laguna_validate_location($townName,$barangay);
    if($errors)throw new SeedlingValidationException(implode(' ',$errors));
    $town=laguna_town($townName);$latitude=filter_var($input['latitude']??'',FILTER_VALIDATE_FLOAT);$longitude=filter_var($input['longitude']??'',FILTER_VALIDATE_FLOAT);
    if($latitude===false||$longitude===false||!geo_point_in_laguna($latitude,$longitude))throw new SeedlingValidationException('Pin a valid location within Laguna.');
    return ['municipality'=>$town['name'],'barangay'=>laguna_barangay($town,$barangay)['name'],'latitude'=>$latitude,'longitude'=>$longitude];
}
function seedling_save_provider(PDO $pdo,int $actorId,array $input):int
{
    $values=seedling_location_values($input);
    foreach(['provider_name'=>200,'address'=>500,'contact_details'=>200,'opening_hours'=>200,'notes'=>1000,'data_source'=>500] as $field=>$max){
        if(!is_scalar($input[$field]??''))throw new SeedlingValidationException('Enter valid provider details.');
        $values[$field]=trim((string)($input[$field]??''));
        if(mb_strlen($values[$field])>$max)throw new SeedlingValidationException('Provider '.$field.' is too long.');
    }
    foreach(['provider_name','address','data_source'] as $field)if($values[$field]==='')throw new SeedlingValidationException('Enter the provider name, address, and information source.');
    $id=filter_var($input['provider_id']??'0',FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
    if($id===false)throw new SeedlingValidationException('Invalid provider.');
    $values['is_active']=isset($input['is_active'])?1:0;
    try{
        $pdo->beginTransaction();
        if(!seedling_ems_actor($pdo,$actorId,true))throw new RuntimeException('Only EMS can manage seedling providers.');
        $before=null;
        if($id){$q=$pdo->prepare('SELECT * FROM tbl_seedling_providers WHERE id=? FOR UPDATE');$q->execute([$id]);$before=$q->fetch();if(!$before)throw new RuntimeException('Provider not found.');}
        $assign=implode(',',array_map(static fn($key)=>$key.'=:'.$key,array_keys($values)));
        $values['updated_by_user_id']=$actorId;
        if($id){$values['id']=$id;$sql='UPDATE tbl_seedling_providers SET '.$assign.',updated_by_user_id=:updated_by_user_id WHERE id=:id';}
        else{$values['created_by_user_id']=$actorId;$sql='INSERT INTO tbl_seedling_providers SET '.$assign.',created_by_user_id=:created_by_user_id,updated_by_user_id=:updated_by_user_id';}
        $pdo->prepare($sql)->execute($values);if(!$id)$id=(int)$pdo->lastInsertId();
        record_audit_event($pdo,$actorId,'seedling',$before?'provider_updated':'provider_created','seedling_provider',$id,'Seedling provider details saved.',['before'=>$before,'after'=>$values]);
        $pdo->commit();return $id;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function seedling_update_destination(PDO $pdo,int $requestId,int $actorId,array $input):void
{
    $values=seedling_location_values($input);$reason=trim((string)($input['reason']??''));
    if($reason===''||mb_strlen($reason)>1000)throw new SeedlingValidationException('Provide a reason of up to 1,000 characters for correcting the destination.');
    try{
        $pdo->beginTransaction();
        if(!seedling_ems_actor($pdo,$actorId,true))throw new RuntimeException('Only EMS can correct planting destinations.');
        $q=$pdo->prepare('SELECT * FROM tbl_seedling_requests WHERE id=? FOR UPDATE');$q->execute([$requestId]);$before=$q->fetch();
        if(!$before)throw new RuntimeException('Request not found.');
        $pdo->prepare("UPDATE tbl_seedling_requests SET planting_province='Laguna',planting_municipality=?,planting_barangay=?,planting_latitude=?,planting_longitude=?,planting_site_id=NULL,planting_location_source=? WHERE id=?")->execute([$values['municipality'],$values['barangay'],$values['latitude'],$values['longitude'],$reason,$requestId]);
        record_audit_event($pdo,$actorId,'seedling','request_destination_corrected','seedling_request',$requestId,'Planting destination corrected.',['before'=>array_intersect_key($before,array_flip(['planting_municipality','planting_barangay','planting_latitude','planting_longitude','planting_location_source'])),'after'=>$values,'reason'=>$reason]);
        create_notification($pdo,(int)$before['requester_user_id'],$actorId,'seedling_request','Planting destination updated',mb_strcut('EMS corrected the recorded planting destination for '.$before['request_reference'].'. Reason: '.$reason,0,995,'UTF-8'),'seedling_request',$requestId);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function render_seedling_provider_locations(PDO $pdo):void
{
    $providers=seedling_providers($pdo);
    ?>
    <section class="docket-panel mb-3" id="seedling-provider-locations"><div class="section-heading"><h2>Where to Obtain Seedlings</h2></div><p class="small text-secondary">Contact a provider to confirm availability, species and collection arrangements. Coordinate donation requirements with EMS.</p>
    <?php if(!$providers):?><p class="mb-0">EMS has not listed any provider locations yet.</p><?php else:?>
        <div class="geo-map mb-3" id="providerLocationsMap" aria-label="Seedling provider locations"></div><div class="row g-3"><?php foreach($providers as $provider):?><article class="col-md-6"><div class="border rounded p-3 h-100"><h3 class="h6"><?php echo e($provider['provider_name']); ?></h3><p class="small mb-1"><?php echo e($provider['address'].', '.$provider['barangay'].', '.$provider['municipality'].', Laguna'); ?></p><?php foreach(['contact_details','opening_hours','notes'] as $field):if(!empty($provider[$field])):?><p class="small mb-1"><?php echo e($provider[$field]); ?></p><?php endif;endforeach; ?></div></article><?php endforeach;?></div>
        <script type="application/json" id="providerLocationsData"><?php echo json_encode(array_map(static fn($p)=>array_intersect_key($p,array_flip(['provider_name','address','latitude','longitude'])),$providers),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?></script>
    <?php endif;?></section>
    <?php
}
