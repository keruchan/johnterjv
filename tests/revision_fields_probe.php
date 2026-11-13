<?php
/** Focused intake/location checks. No database mutations. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../includes/permit.php';
require_once __DIR__.'/../includes/seedling_locations.php';
$checks=0;
function revision_check(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$message);echo 'PASS: '.$message.PHP_EOL;$checks++;}
function revision_reject(callable $call,string $message):void{try{$call();}catch(InvalidArgumentException|RuntimeException $e){revision_check(true,$message);return;}revision_check(false,$message);}
try{
    revision_check(count(laguna_locations()['municipalities'])===30,'30 Laguna towns/cities bundled');
    revision_check(array_sum(array_map(static fn($t)=>count($t['barangays']),laguna_locations()['municipalities']))===681,'681 barangays bundled');
    revision_check(laguna_validate_location('Sta. Cruz','Bubukal','District 4')===[],'legacy Sta. name matches canonical municipality');
    revision_check(laguna_validate_location('Santa Cruz','Sabang','District 4')!==[],'barangay from another municipality rejected');
    revision_check(laguna_validate_location('Santa Cruz','Bubukal','District 3')!==[],'wrong district rejected');
    revision_check(laguna_validate_location('Jalajala','Pagkalinawan')!==[],'out-of-Laguna municipality rejected');
    revision_check(intake_area_to_hectares('10000','sqm')===1.0,'10,000 square metres becomes one hectare');
    revision_check(intake_area_to_hectares('1.5','sqkm')===150.0,'square kilometres convert to hectares');
    revision_check(intake_area_to_hectares('0.125','ha')===0.125,'hectare input retained');
    revision_reject(fn()=>intake_area_to_hectares('2','acres'),'unknown area unit rejected');
    revision_reject(fn()=>intake_area_to_hectares('-1','ha'),'negative area rejected');
    revision_reject(fn()=>intake_area_to_hectares('INF','ha'),'nonfinite area rejected');
    revision_reject(fn()=>intake_area_to_hectares('0.00000001','ha'),'area below stored precision rejected');
    revision_reject(fn()=>intake_area_to_hectares('1001','sqkm'),'converted area above supported maximum rejected');
    $data=permit_normalize_application_data(['area_value'=>'10000','area_unit'=>'sqm','area_hectares'=>'9999'],'private_lands',true);
    revision_check($data['area_hectares']===1.0,'server ignores forged hectare conversion');
    revision_check(isset(permit_normalize_application_data(['area_value'=>'1','area_unit'=>'bad'],'private_lands',true)['area_input_error']),'normalizer retains unit validation error');
    $rows=permit_tree_input(['trees_json'=>json_encode(array_fill(0,500,['common_name'=>'Other','quantity'=>'1']))]);
    revision_check(count($rows)===500,'large tree table survives bounded JSON transport');
    revision_reject(fn()=>permit_tree_input(['trees_json'=>'{invalid}']),'invalid tree JSON rejected');
    revision_reject(fn()=>permit_tree_input(['trees_json'=>json_encode(array_fill(0,2001,[]))]),'oversized tree table rejected');
    $catalog=permit_intake_species($pdo);if(!$catalog)throw new RuntimeException('Species catalogue missing.');
    $s=$catalog[0];$resolved=permit_resolve_species($pdo,[['species_id'=>(string)$s['id'],'common_name'=>'Forged','scientific_name'=>'Forged']]);
    revision_check($resolved[0]['common_name']===$s['common_name']&&$resolved[0]['scientific_name']===$s['scientific_name'],'species name/scientific name resolved on server');
    $other=permit_resolve_species($pdo,[['species_id'=>'other','common_name'=>'Custom species','scientific_name'=>'Custom name']]);
    revision_check($other[0]['common_name']==='Custom species','Other preserves manual species details');
    revision_reject(fn()=>permit_resolve_species($pdo,[['species_id'=>'999999999']]),'unavailable species rejected');
    revision_reject(fn()=>seedling_location_values(['municipality'=>'Santa Cruz','barangay'=>'Bubukal','latitude'=>'14.6','longitude'=>'120.98']),'provider/destination point outside Laguna rejected');
    $point=seedling_location_values(['municipality'=>'Santa Cruz','barangay'=>'Bubukal','latitude'=>'14.2845','longitude'=>'121.414']);
    revision_check($point['municipality']==='Santa Cruz','valid provider/destination canonicalized');
    echo $checks.' revision checks passed.'.PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
