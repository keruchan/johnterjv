<?php
/** Bundled PSGC names; no request-time network dependency or inferred map coordinates. */
require_once __DIR__.'/view.php';
function laguna_locations(): array
{
    static $data;return $data??=json_decode(file_get_contents(__DIR__.'/../assets/geo/laguna-locations.json'),true,512,JSON_THROW_ON_ERROR);
}
function location_key(string $name): string
{
    $name=mb_strtolower(trim($name));$name=preg_replace('/^city of\s+|\s+city$/u','',$name);
    $name=preg_replace('/\bsta\.?(?=\s)/u','santa',$name);
    return preg_replace('/[^a-z0-9]/','',iconv('UTF-8','ASCII//TRANSLIT',$name));
}
function laguna_town(string $name): ?array
{
    foreach(laguna_locations()['municipalities'] as $town)if(location_key($town['name'])===location_key($name))return $town;
    return null;
}
function laguna_barangay(array $town,string $name): ?array
{
    foreach($town['barangays'] as $b)if(location_key($b['name'])===location_key($name))return $b;
    return null;
}
function laguna_validate_location(string $municipality,string $barangay,?string $district=null,bool $required=true): array
{
    $errors=[];$town=$municipality!==''?laguna_town($municipality):null;
    if(($required||$municipality!=='')&&!$town)$errors[]='Select a municipality or city in Laguna.';
    if(($required||$barangay!=='')&&(!$town||!laguna_barangay($town,$barangay)))$errors[]='Select a barangay belonging to the selected municipality.';
    if($district!==null&&$town&&$district!==$town['district'])$errors[]='Select the district matching the municipality.';
    return $errors;
}
function render_location_select(string $id,string $name,string $label,array $options,string $value,bool $required,bool $disabled=false):void
{
    ?><div class="col-md-6"><label class="form-label" for="<?php echo e($id); ?>"><?php echo e($label); ?><?php if($required):?> <span class="text-danger">*</span><?php endif;?></label><select class="form-select" id="<?php echo e($id); ?>" name="<?php echo e($name); ?>" data-location-field="<?php echo e($label); ?>"<?php echo $required?' required':''; ?><?php echo $disabled?' disabled':''; ?>><option value="">Select <?php echo e(mb_strtolower($label)); ?></option><?php if($value!==''&&!in_array($value,$options,true)):?><option selected value="<?php echo e($value); ?>"><?php echo e($value); ?> (previous entry)</option><?php endif;?><?php foreach($options as $option):?><option value="<?php echo e($option); ?>"<?php echo $value===$option?' selected':''; ?>><?php echo e($option); ?></option><?php endforeach;?></select></div><?php
}
function render_laguna_location_fields(array $values,array $names,bool $includeDistrict=false,bool $disabled=false):void
{
    $town=laguna_town((string)($values['municipality']??''));
    $municipality=$town['name']??(string)($values['municipality']??'');
    $barangay=$town?(laguna_barangay($town,(string)($values['barangay']??''))['name']??(string)($values['barangay']??'')):(string)($values['barangay']??'');
    $district=(string)($values['district']??'');
    $towns=laguna_locations()['municipalities'];
    $districts=array_values(array_unique(array_column($towns,'district')));sort($districts);
    ?>
    <div class="row g-3" data-location-group>
    <?php render_location_select($names['province'],$names['province'],'Province',['Laguna'],'Laguna',true,$disabled);
    if($includeDistrict)render_location_select($names['district'],$names['district'],'District',$districts,$district,true,$disabled);
    render_location_select($names['municipality'],$names['municipality'],'Municipality',array_column($towns,'name'),$municipality,true,$disabled);
    render_location_select($names['barangay'],$names['barangay'],'Barangay',$town?array_column($town['barangays'],'name'):[],$barangay,true,$disabled); ?>
    </div>
    <?php
}
function render_location_script(string $base='../../'):void
{
    static $rendered=false;if($rendered)return;$rendered=true;
    echo '<script type="application/json" id="lagunaLocations">'.json_encode(laguna_locations(),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).'</script>';
    echo '<script src="'.e($base.'js/location-selects.js?v='.certreefy_asset_version('js/location-selects.js')).'"></script>';
}
