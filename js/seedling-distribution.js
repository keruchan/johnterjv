(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        if (!window.CertreefyGeo || !window.L) return;
        var map = CertreefyGeo.baseMap('seedlingDistributionMap');
        var groups = CertreefyGeo.readJson('seedlingDistributionData', []);
        groups.forEach(function (group) {
            if (group.lat === null || group.lng === null) return;
            var color = group.total >= 500 ? '#1f4937' : (group.total >= 100 ? '#4a7c59' : '#a9c4ac');
            L.circleMarker([group.lat, group.lng], {radius:9, color:color, fillColor:color, fillOpacity:0.8, weight:2})
                .bindPopup('<strong>' + CertreefyGeo.escapeHtml(group.barangay) + '</strong><br>'
                    + CertreefyGeo.escapeHtml(group.municipality) + '<br>' + Number(group.total).toLocaleString() + ' seedlings released').addTo(map);
        });
        var municipality = document.getElementById('distributionMunicipality');
        municipality.addEventListener('change', function () { document.getElementById('distributionBarangay').value = ''; });
    });
})();
