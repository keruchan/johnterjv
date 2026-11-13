(() => {
 const source=document.getElementById('lagunaLocations');if(!source)return;
 const towns=JSON.parse(source.textContent).municipalities;
 const key=value=>value.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().replace(/^city of\s+|\s+city$/g,'').replace(/\bsta\.?(?=\s)/g,'santa').replace(/[^a-z0-9]/g,'');
 document.querySelectorAll('[data-location-group]').forEach(group=>{
   const town=group.querySelector('[data-location-field="Municipality"]'),barangay=group.querySelector('[data-location-field="Barangay"]'),district=group.querySelector('[data-location-field="District"]');
   function fill(select,values,selected=''){select.replaceChildren(new Option('Select '+select.dataset.locationField.toLowerCase(),''));values.forEach(v=>select.add(new Option(v,v,false,key(v)===key(selected))));if(selected&&!values.some(v=>key(v)===key(selected)))select.add(new Option(selected+' (previous entry)',selected,true,true));}
   function refreshTown(keep=true){const old=keep?town.value:'';fill(town,towns.filter(t=>!district||!district.value||t.district===district.value).map(t=>t.name),old);refreshBarangay(keep);}
   function refreshBarangay(keep=true){const selected=towns.find(t=>key(t.name)===key(town.value));fill(barangay,selected?selected.barangays.map(b=>b.name):[],keep?barangay.value:'');}
   district?.addEventListener('change',()=>refreshTown(false));
   town.addEventListener('change',()=>refreshBarangay(false));
   group.addEventListener('location:set',event=>{
      const data=event.detail||{},selected=towns.find(t=>key(t.name)===key(data.municipality||''));
      if(district&&selected)district.value=selected.district;
      fill(town,towns.filter(t=>!district||!district.value||t.district===district.value).map(t=>t.name),selected?.name||data.municipality||'');
      fill(barangay,selected?selected.barangays.map(b=>b.name):[],data.barangay||'');
      group.dispatchEvent(new Event('change',{bubbles:true}));
   });
   refreshTown();
 });
})();
