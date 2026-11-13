document.addEventListener('DOMContentLoaded',()=>{
    if(!window.CertreefyGeo)return;
    document.querySelectorAll('[data-seedling-picker]').forEach(el=>CertreefyGeo.picker(el.id,{latInput:el.dataset.latInput,lngInput:el.dataset.lngInput}));
    const el=document.getElementById('providerLocationsMap'),data=document.getElementById('providerLocationsData');
    if(el&&data){const map=CertreefyGeo.baseMap(el.id);JSON.parse(data.textContent).forEach(provider=>L.marker([Number(provider.latitude),Number(provider.longitude)]).addTo(map).bindPopup('<strong>'+CertreefyGeo.escapeHtml(provider.provider_name)+'</strong><br>'+CertreefyGeo.escapeHtml(provider.address)));}
});
