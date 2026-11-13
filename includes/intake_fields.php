<?php
/** Shared intake fields, using the existing inventory as the species catalogue. */
require_once __DIR__.'/view.php';

function intake_area_to_hectares(mixed $value, mixed $unit): ?float
{
    if (!is_scalar($value) || !is_string($unit)) throw new InvalidArgumentException('Enter a valid area and unit.');
    $value=trim((string)$value);
    $factors=['ha'=>1.0,'sqm'=>0.0001,'sqkm'=>100.0];
    if (!isset($factors[$unit])) throw new InvalidArgumentException('Select a valid area unit.');
    if ($value==='') return null;
    $number=filter_var($value,FILTER_VALIDATE_FLOAT);
    if ($number===false || !is_finite($number) || $number<=0) throw new InvalidArgumentException('Area applied for must be greater than zero.');
    $hectares=round($number*$factors[$unit],4);
    if ($hectares<=0 || $hectares>100000) throw new InvalidArgumentException('Area must convert to more than zero and no more than 100,000 hectares.');
    return $hectares;
}

function permit_intake_species(PDO $pdo): array
{
    return $pdo->query('SELECT id,common_name,scientific_name FROM tbl_seedling_inventory WHERE is_active=1 ORDER BY common_name')->fetchAll();
}

function permit_tree_input(array $input): array
{
    if (!isset($input['trees_json'])) return is_array($input['trees']??null)?$input['trees']:[];
    if (!is_string($input['trees_json']) || strlen($input['trees_json'])>2*1024*1024) throw new InvalidArgumentException('The tree table is too large.');
    try {$rows=json_decode($input['trees_json'],true,32,JSON_THROW_ON_ERROR);}
    catch(JsonException $e){throw new InvalidArgumentException('Reload the application and check the tree table.');}
    if (!is_array($rows) || !array_is_list($rows) || count($rows)>2000) throw new InvalidArgumentException('Enter no more than 2,000 tree rows. Use quantities to group additional trees of the same species.');
    return $rows;
}

function permit_resolve_species(PDO $pdo,array $rows): array
{
    $catalog=array_column(permit_intake_species($pdo),null,'id');
    foreach($rows as &$row){
        if (!is_array($row)) continue;
        $id=$row['species_id']??'';
        if ($id==='' || $id==='other') continue; // Historical/manual API rows remain supported.
        if (!is_scalar($id) || !ctype_digit((string)$id) || !isset($catalog[(int)$id])) throw new InvalidArgumentException('Select an available tree species or Other.');
        $row['common_name']=$catalog[(int)$id]['common_name'];
        $row['scientific_name']=$catalog[(int)$id]['scientific_name'];
    }
    unset($row);return $rows;
}

function render_intake_tree_row(array $tree,int $index,bool $editable,array $catalog):void
{
    $selected='other';
    foreach($catalog as $species){
        if (strcasecmp($species['common_name'],(string)($tree['common_name']??''))===0
            && strcasecmp((string)$species['scientific_name'],(string)($tree['scientific_name']??''))===0){$selected=(string)$species['id'];break;}
    }
    if (empty($tree['common_name'])) $selected='';
    $disabled=$editable?'':' disabled';
    $fields=['tree_origin'=>'Tree origin','scientific_name'=>'Scientific name','quantity'=>'Number of trees','diameter_cm'=>'Diameter (cm)','estimated_height_m'=>'Estimated height (m)'];
    ?>
    <tr data-tree-row class="tree-record">
        <td><span class="tree-title"><?php echo $index+1; ?></span></td>
        <td>
            <label class="visually-hidden" for="tree_<?php echo $index; ?>_species_id" data-tree-label="species_id">Species</label>
            <select class="form-select" data-tree-input="species_id" id="tree_<?php echo $index; ?>_species_id" name="trees[<?php echo $index; ?>][species_id]" required<?php echo $disabled; ?>>
                <option value="">Select species</option>
                <?php foreach($catalog as $species):?><option value="<?php echo (int)$species['id']; ?>" data-common="<?php echo e($species['common_name']); ?>" data-scientific="<?php echo e((string)$species['scientific_name']); ?>"<?php echo $selected===(string)$species['id']?' selected':''; ?>><?php echo e($species['common_name']); ?></option><?php endforeach; ?>
                <option value="other"<?php echo $selected==='other'?' selected':''; ?>>Other</option>
            </select>
            <label class="visually-hidden" data-tree-label="common_name" for="tree_<?php echo $index; ?>_common_name">Common name</label>
            <input class="form-control mt-2" data-tree-input="common_name" id="tree_<?php echo $index; ?>_common_name" name="trees[<?php echo $index; ?>][common_name]" value="<?php echo e((string)($tree['common_name']??'')); ?>" maxlength="150" placeholder="Other species name"<?php echo $selected==='other'?' required':' hidden'; ?><?php echo $disabled; ?>>
        </td>
        <?php foreach($fields as $key=>$label):?><td>
            <label class="visually-hidden" data-tree-label="<?php echo $key; ?>" for="tree_<?php echo $index; ?>_<?php echo $key; ?>"><?php echo e($label); ?></label>
            <?php if($key==='tree_origin'):?><select class="form-select" data-tree-input="<?php echo $key; ?>" id="tree_<?php echo $index; ?>_<?php echo $key; ?>" name="trees[<?php echo $index; ?>][<?php echo $key; ?>]" required<?php echo $disabled; ?>><option value="">Select origin</option><?php foreach(permit_matrix_tree_origins() as $origin=>$originLabel):?><option value="<?php echo e($origin); ?>"<?php echo ($tree[$key]??'')===$origin?' selected':''; ?>><?php echo e($originLabel); ?></option><?php endforeach; ?></select>
            <?php else:?><input class="form-control" data-tree-input="<?php echo $key; ?>" id="tree_<?php echo $index; ?>_<?php echo $key; ?>" name="trees[<?php echo $index; ?>][<?php echo $key; ?>]" value="<?php echo e((string)($tree[$key]??'')); ?>"<?php if($key==='scientific_name'):?> type="text" maxlength="150"<?php echo $selected!=='other'?' readonly':''; ?><?php else:?> type="number" min="<?php echo $key==='quantity'?'1':'0.01'; ?>" step="<?php echo $key==='quantity'?'1':'0.01'; ?>" max="<?php echo $key==='quantity'?'65535':'999999.99'; ?>"<?php echo $key==='quantity'?' required':''; ?><?php endif; ?><?php echo $disabled; ?>><?php endif; ?>
        </td><?php endforeach; ?>
        <td><?php if($editable):?><button type="button" class="btn btn-sm btn-outline-danger remove-tree" aria-label="Remove tree entry <?php echo $index+1; ?>"><i class="bi bi-trash" aria-hidden="true"></i></button><?php endif; ?></td>
    </tr>
    <?php
}
