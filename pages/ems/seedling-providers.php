<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/navigation.php';
require_once __DIR__.'/../../includes/seedling_locations.php';
require_once __DIR__.'/../../includes/registry_filters.php';
require_role($pdo,'ems');
$_SESSION['csrf_provider_token']??=bin2hex(random_bytes(32));
$providers=seedling_providers($pdo,false);$edit=null;
if(isset($_GET['edit'])){
    foreach($providers as $provider)if((string)$provider['id']===(string)$_GET['edit'])$edit=$provider;
    if(!$edit){http_response_code(404);exit('Provider not found.');}
}
$rows=registry_date_rows($providers,'created_at');
$predicates=['active'=>static fn($p)=>(int)$p['is_active']===1,'inactive'=>static fn($p)=>(int)$p['is_active']===0];
$counts=registry_counts($rows,$predicates);$rows=registry_category_rows($rows,$predicates);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>CERTREEFY | Seedling Providers</title>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet"><link href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" rel="stylesheet"><link href="../../css/dashboard.css?v=<?php echo e(certreefy_asset_version('css/dashboard.css')); ?>" rel="stylesheet">
</head><body><a class="skip-link" href="#main-content">Skip to main content</a><div class="app-shell">
<?php render_certreefy_navigation('ems','seedling_providers'); ?><main class="main" id="main-content">
<section class="page-header mb-4"><div class="eyebrow">Enforcement &amp; Monitoring Section</div><h1 class="page-title">Seedling Providers</h1><p class="meta-copy mb-0">Maintain provider locations and collection details for permit applicants.</p></section>
<?php if(isset($_SESSION['provider_flash'])):$flash=$_SESSION['provider_flash'];unset($_SESSION['provider_flash']);?><div class="alert alert-<?php echo e($flash['type']); ?>" role="alert"><?php echo e($flash['message']); ?></div><?php endif; ?>
<?php render_registry_date_filter('Provider registration dates');render_registry_cards($counts,['all'=>'All providers','active'=>'Active providers','inactive'=>'Inactive providers'],['edit'=>null]); ?>
<section class="docket-panel mb-4" id="provider-form"><div class="section-heading"><h2><?php echo $edit?'Edit provider':'Add provider'; ?></h2><?php if($edit):?><a class="btn btn-sm btn-outline-secondary" href="seedling-providers.php">Add another</a><?php endif;?></div>
<form method="post" action="seedling-provider-action.php" class="row g-3"><input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_provider_token']); ?>"><input type="hidden" name="provider_id" value="<?php echo (int)($edit['id']??0); ?>">
<?php foreach(['provider_name'=>'Provider name','address'=>'Street / detailed address','contact_details'=>'Contact details','opening_hours'=>'Collection hours','data_source'=>'Information source','notes'=>'Notes'] as $key=>$label):?><div class="col-md-6"><label class="form-label" for="<?php echo $key; ?>"><?php echo e($label); ?></label><input class="form-control" id="<?php echo $key; ?>" name="<?php echo $key; ?>" value="<?php echo e((string)($edit[$key]??'')); ?>" maxlength="<?php echo ['address'=>500,'notes'=>1000,'data_source'=>500][$key]??200; ?>"<?php echo in_array($key,['provider_name','address','data_source'],true)?' required':''; ?>></div><?php endforeach;?>
<div class="col-12"><?php render_laguna_location_fields($edit??[],['province'=>'province','municipality'=>'municipality','barangay'=>'barangay']); ?></div>
<div class="col-md-6"><label class="form-label" for="latitude">Latitude</label><input class="form-control" type="number" step="0.0000001" id="latitude" name="latitude" value="<?php echo e((string)($edit['latitude']??'')); ?>" required></div><div class="col-md-6"><label class="form-label" for="longitude">Longitude</label><input class="form-control" type="number" step="0.0000001" id="longitude" name="longitude" value="<?php echo e((string)($edit['longitude']??'')); ?>" required></div>
<div class="col-12"><div id="providerPicker" class="geo-map" data-seedling-picker data-lat-input="latitude" data-lng-input="longitude" aria-label="Pin provider location"></div></div>
<div class="col-12"><div class="form-check"><input type="checkbox" class="form-check-input" id="is_active" name="is_active" value="1"<?php echo !$edit||(int)$edit['is_active']===1?' checked':''; ?>><label class="form-check-label" for="is_active">Active provider</label></div></div><div class="col-12"><button class="btn btn-certreefy" type="submit">Save provider</button></div></form></section>
<section class="docket-panel mb-4"><div class="section-heading"><h2>Provider Registry</h2></div><div class="table-responsive"><table class="table align-middle" data-table-tools><thead><tr><th>Provider</th><th>Location</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach($rows as $provider):?><tr><td><a data-row-link href="?edit=<?php echo (int)$provider['id']; ?>#provider-form"><?php echo e($provider['provider_name']); ?></a></td><td><?php echo e($provider['barangay'].', '.$provider['municipality']); ?></td><td><?php echo (int)$provider['is_active']?'Active':'Inactive'; ?></td><td><a class="btn btn-sm btn-outline-secondary" title="Edit provider" aria-label="Edit provider" href="?edit=<?php echo (int)$provider['id']; ?>#provider-form"><i class="bi bi-pencil" aria-hidden="true"></i></a></td></tr><?php endforeach;?></tbody></table></div></section>
<?php render_seedling_provider_locations($pdo); ?>
</main></div><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script><script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script><?php geo_render_map_script('../../');render_location_script(); ?><script src="../../js/seedling-locations.js?v=<?php echo e(certreefy_asset_version('js/seedling-locations.js')); ?>"></script></body></html>
