/** Intake-only enhancements; the PHP services revalidate units and selected species. */
(() => {
    const form=document.getElementById('permit-form');
    const area=document.getElementById('area_value'),unit=document.getElementById('area_unit'),hectares=document.getElementById('area_hectares');
    function convertArea(){
        if(!area||!unit||!hectares)return;
        const factors={ha:1,sqm:0.0001,sqkm:100},value=Number(area.value)*factors[unit.value];
        hectares.value=area.value!==''&&Number.isFinite(value)?String(Math.round(value*1e4)/1e4):'';
        const message=document.getElementById('area-conversion');
        if(message)message.textContent=hectares.value===''?'':Number(hectares.value).toLocaleString('en-PH',{maximumFractionDigits:4})+' hectares for assessment';
        hectares.dispatchEvent(new Event('input',{bubbles:true}));
    }
    if(area&&unit){area.addEventListener('input',convertArea);unit.addEventListener('change',convertArea);convertArea();}
    const records=document.getElementById('tree-records'),template=document.getElementById('tree-template');
    if(!form||!records||!template)return;
    function species(row,changed=false){
        const select=row.querySelector('[data-tree-input="species_id"]'),common=row.querySelector('[data-tree-input="common_name"]'),science=row.querySelector('[data-tree-input="scientific_name"]');
        const other=select.value==='other';
        common.hidden=!other;common.required=other;science.readOnly=!other;
        if(select.value&&!other){common.value=select.selectedOptions[0].dataset.common||'';science.value=select.selectedOptions[0].dataset.scientific||'';}
        else if(changed){common.value='';science.value='';}
    }
    records.querySelectorAll('[data-tree-row]').forEach(row=>species(row));
    records.addEventListener('change',event=>{if(event.target.matches('[data-tree-input="species_id"]'))species(event.target.closest('[data-tree-row]'),true);});
    const add=document.getElementById('add-tree');
    add?.addEventListener('click',()=>species(records.lastElementChild));
    document.getElementById('generate-tree-table')?.addEventListener('click',()=>{
        const field=document.getElementById('estimated-tree-count'),message=document.getElementById('tree-table-message');
        if(!field.reportValidity())return;
        records.querySelectorAll('[data-tree-input="quantity"]').forEach(input=>{if(input.value==='')input.value='1';});
        const total=[...records.querySelectorAll('[data-tree-input="quantity"]')].reduce((n,input)=>n+(Number(input.value)||0),0);
        const needed=Number(field.value)-total,count=records.querySelectorAll('[data-tree-row]').length;
        if(needed<0){message.textContent='Existing quantities exceed this estimate. Edit the quantities or remove the extra rows first.';return;}
        if(count+needed>2000){message.textContent='Use quantities to group trees of the same species for this estimate. A table supports up to 2,000 rows.';return;}
        const fragment=document.createDocumentFragment();
        for(let i=0;i<needed;i++)fragment.appendChild(template.content.cloneNode(true));
        records.appendChild(fragment);records.querySelectorAll('[data-tree-input="quantity"]').forEach(input=>{if(input.value==='')input.value='1';});records.querySelectorAll('[data-tree-row]').forEach(row=>species(row));
        records.dispatchEvent(new Event('trees:changed'));form.dispatchEvent(new Event('input',{bubbles:true}));
        message.textContent=Number(field.value).toLocaleString()+' trees represented in '+records.children.length+' rows.';
    });
    // A single bounded JSON field prevents PHP max_input_vars truncating a large tree table.
    form.addEventListener('submit',event=>{
        if(event.defaultPrevented)return;
        const rows=[...records.querySelectorAll('[data-tree-row]')].map(row=>Object.fromEntries([...row.querySelectorAll('[data-tree-input]')].map(input=>[input.dataset.treeInput,input.value])));
        let payload=form.querySelector('[name="trees_json"]');
        if(!payload){payload=document.createElement('input');payload.type='hidden';payload.name='trees_json';form.appendChild(payload);}
        payload.value=JSON.stringify(rows);
        records.querySelectorAll('[data-tree-input]').forEach(input=>input.removeAttribute('name'));
    });
    window.addEventListener('pageshow',()=>records.dispatchEvent(new Event('trees:changed')));
})();
