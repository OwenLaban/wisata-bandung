var map = L.map('map').setView([-6.90389, 107.61861], 13);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);

var layerGroup = L.layerGroup().addTo(map);
var allData = [];
var _markersByName = {}; // { namaLower: {marker, lat, lon, d} }
var userMarker;
var userLocation = null;
var renderToken = 0;
var photoCache = {};
var imageObserver = null;
var photoQueue = Promise.resolve();
var routingControl = null;
var routeLayerGroup = L.layerGroup().addTo(map);
var destinationMarker = null;
var categoryLabels = {
    all: 'Semua',
    attraction: 'Atraksi',
    kuliner: 'Kuliner',
    belanja: 'Belanja',
    museum: 'Museum',
    viewpoint: 'Pemandangan',
    camp_site: 'Camping',
    picnic_site: 'Piknik',
    theme_park: 'Taman hiburan',
    gallery: 'Galeri',
    artwork: 'Seni publik',
    zoo: 'Kebun binatang',
    lainnya: 'Lainnya'
};

var categoryColors = {
    attraction: '#FF6B6B',
    kuliner: '#FF9F43',
    belanja: '#A29BFE',
    museum: '#4ECDC4',
    viewpoint: '#45B7D1',
    camp_site: '#96CEB4',
    picnic_site: '#FFEAA7',
    theme_park: '#DDA0DD',
    gallery: '#F8B500',
    artwork: '#FF69B4',
    zoo: '#9B59B6',
    lainnya: '#95A5A6'
};

function getMarkerColor(kategori) {
    return categoryColors[kategori] || categoryColors.lainnya;
}

function escapeHTML(value) {
    return String(value ?? '').replace(/[&<>"']/g, function(char) {
        return {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        }[char];
    });
}

function createColoredMarkerIcon(color) {
    var svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 32" width="24" height="32">
        <path fill="${color}" d="M12 0C5.383 0 0 5.383 0 12c0 7 12 20 12 20s12-13 12-20c0-6.617-5.383-12-12-12zm0 16c-2.209 0-4-1.791-4-4s1.791-4 4-4 4 1.791 4 4-1.791 4-4 4z"/>
        <circle cx="12" cy="12" r="2.5" fill="white" opacity="0.9"/>
    </svg>`;
    return L.icon({
        iconUrl: 'data:image/svg+xml;base64,' + btoa(svg),
        iconSize: [24, 32],
        iconAnchor: [12, 32],
        popupAnchor: [0, -32]
    });
}

function createUserMarkerIcon() {
    return L.divIcon({
        html: '<div class="user-marker-wrap"><div class="user-marker-pulse"></div><div class="user-marker-pin"></div></div>',
        className: '',
        iconSize: [40, 40],
        iconAnchor: [20, 20],
        popupAnchor: [0, -22]
    });
}

function createDestinationMarkerIcon(name) {
    var label = (name || '').substring(0, 18) + ((name || '').length > 18 ? '…' : '');
    return L.divIcon({
        html: `<div class="dest-marker-wrap">
                 <div class="dest-marker-pin"></div>
                 <div class="dest-marker-label">${label}</div>
               </div>`,
        className: '',
        iconSize: [160, 60],
        iconAnchor: [80, 52],
        popupAnchor: [0, -54]
    });
}

function placeholderPhoto(record) {
    const lat = Number(record.latitude);
    const lng = Number(record.longitude);

    if (Number.isFinite(lat) && Number.isFinite(lng)) {
        return `https://staticmap.openstreetmap.de/staticmap.php?center=${encodeURIComponent(lat + ',' + lng)}&zoom=15&size=600x360&markers=${encodeURIComponent(lat + ',' + lng + ',red-pushpin')}`;
    }

    return 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 600 360"><rect width="600" height="360" fill="#dce5ee"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#637083" font-family="Arial" font-size="28">Foto belum tersedia</text></svg>');
}

function getRecordPhoto(record) {
    return placeholderPhoto(record);
}

function getPhotoKey(record) {
    return [
        record.nama || '',
        record.latitude || '',
        record.longitude || ''
    ].join('|');
}

function getCategoryLabel(value) {
    return categoryLabels[value] || value || 'Lainnya';
}

function fetchRealPhoto(record) {
    const key = getPhotoKey(record);
    if (photoCache[key]) {
        return Promise.resolve(photoCache[key]);
    }

    const params = new URLSearchParams({
        name: record.nama || '',
        lat: record.latitude || '',
        lng: record.longitude || ''
    });

    photoQueue = photoQueue.then(() => fetch('get_photo.php?' + params.toString())
        .then(res => {
            if (!res.ok) {
                throw new Error('Foto tidak tersedia');
            }
            return res.json();
        })
        .then(data => {
            photoCache[key] = data;
            return data;
        })
        .catch(() => {
            photoCache[key] = { photo: getRecordPhoto(record), source: 'local', attribution: '' };
            return photoCache[key];
        }));

    return photoQueue;
}

function renderStars(rating) {
    const full = Math.floor(rating);
    const half = (rating - full) >= 0.4 ? 1 : 0;
    const empty = 5 - full - half;
    return '★'.repeat(full) + (half ? '½' : '') + '☆'.repeat(empty);
}

function updatePhotoElements(record, data) {
    if (!data || !data.photo) {
        return;
    }

    const key = window.CSS && CSS.escape ? CSS.escape(getPhotoKey(record)) : getPhotoKey(record).replace(/["\\]/g, '\\$&');
    document.querySelectorAll(`[data-photo-key="${key}"]`).forEach(img => {
        img.src = data.photo;
    });
    document.querySelectorAll(`[data-credit-key="${key}"]`).forEach(credit => {
        credit.textContent = data.attribution ? `Foto: ${data.attribution}` : '';
    });
    if (data.rating) {
        const ratingNum = Number(data.rating).toFixed(1);
        const reviewCount = data.userRatingCount ? ` (${Number(data.userRatingCount).toLocaleString('id-ID')})` : '';
        document.querySelectorAll(`[data-rating-key="${key}"]`).forEach(el => {
            // popup uses wp-rating-num, sidebar uses old format
            if (el.classList.contains('wp-rating-num')) {
                el.textContent = `${ratingNum}${reviewCount}`;
            } else {
                el.textContent = `Google ${ratingNum}${reviewCount}`;
            }
        });
        document.querySelectorAll(`[data-stars-key="${key}"]`).forEach(el => {
            el.textContent = renderStars(Number(data.rating));
        });
    }
    if (data.googleMapsUri) {
        document.querySelectorAll(`[data-maps-key="${key}"]`).forEach(link => {
            link.href = data.googleMapsUri;
            if (link.classList.contains('wp-maps-btn')) {
                link.innerHTML = `<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg> Google Maps`;
            } else {
                link.textContent = 'Buka di Google Maps';
            }
            link.hidden = false;
        });
    }

    // Status buka/tutup
    if (data.openNow !== null && data.openNow !== undefined) {
        document.querySelectorAll(`[data-open-key="${key}"]`).forEach(el => {
            el.textContent = data.openNow ? '● Buka' : '● Tutup';
            el.style.background = data.openNow ? 'rgba(34,197,94,0.9)' : 'rgba(239,68,68,0.9)';
            el.style.display = '';
        });
    }

    // Detail alamat, telepon, website, jam
    document.querySelectorAll(`[data-details-key="${key}"]`).forEach(el => {
        let html = '';
        if (data.address) {
            html += `<div class="wp-detail-row"><span class="wp-detail-icon">📍</span><span>${escapeHTML(data.address)}</span></div>`;
        }
        if (data.phone) {
            html += `<div class="wp-detail-row"><span class="wp-detail-icon">📞</span><a href="tel:${escapeHTML(data.phone)}" style="color:inherit;">${escapeHTML(data.phone)}</a></div>`;
        }
        if (data.website) {
            const shortUrl = data.website.replace(/^https?:\/\/(www\.)?/, '').split('/')[0];
            html += `<div class="wp-detail-row"><span class="wp-detail-icon">🌐</span><a href="${escapeHTML(data.website)}" target="_blank" rel="noopener" style="color:#2D5016;">${escapeHTML(shortUrl)}</a></div>`;
        }
        if (data.hours && data.hours.length > 0) {
            const today = new Date().getDay();
            const dayIdx = today === 0 ? 6 : today - 1;
            const todayHours = data.hours[dayIdx] || data.hours[0] || '';
            if (todayHours) {
                html += `<div class="wp-detail-row"><span class="wp-detail-icon">🕐</span><span>${escapeHTML(todayHours)}</span></div>`;
            }
        }
        el.innerHTML = html;
    });

    if (data.source === 'google') {
        document.getElementById("photo-mode").textContent = 'Foto & rating Google aktif';
    } else if (data.source === 'wikipedia') {
        document.getElementById("photo-mode").textContent = 'Foto Wikipedia aktif';
    } else if (data.source === 'wikimedia') {
        document.getElementById("photo-mode").textContent = 'Foto Wikimedia aktif';
    }
}

function observeRealPhoto(img, record) {
    if (!imageObserver || !img) {
        fetchRealPhoto(record).then(data => updatePhotoElements(record, data));
        return;
    }

    img._wisataRecord = record;
    imageObserver.observe(img);
}

// FETCH DATA DARI DATABASE PHP
fetch('get_wisata.php')
.then(res => {
    if (!res.ok) {
        throw new Error('Gagal memuat data wisata');
    }
    return res.json();
})
.then(data => {
    if (!Array.isArray(data)) {
        throw new Error(data.error || 'Format data wisata tidak valid');
    }

    allData = data;

    // ── Baca URL param ?kategori= dan apply filter langsung ──────────────
    (function applyURLFilter() {
        var params = new URLSearchParams(window.location.search);
        var kat = params.get('kategori');
        if (kat) {
            var sel = document.getElementById('filter');
            if (sel) {
                sel.value = kat;
                // Update label aktif di sidebar
                var label = document.getElementById('category-label');
                if (label) label.textContent = sel.options[sel.selectedIndex]
                    ? sel.options[sel.selectedIndex].text.replace(/^[^\s]+\s/, '')
                    : kat;
            }
        }
    })();

    applyFilters();

    // ── Baca URL param ?place= → zoom & buka popup marker ────────────────
    (function applyPlaceParam() {
        var params = new URLSearchParams(window.location.search);
        var placeName = params.get('place');
        if (!placeName) return;
        var key = decodeURIComponent(placeName).toLowerCase();
        var found = _markersByName[key];
        // fallback: partial match
        if (!found) {
            var keys = Object.keys(_markersByName);
            var partialKey = keys.find(function(k) {
                return k.indexOf(key) !== -1 || key.indexOf(k) !== -1;
            });
            if (partialKey) found = _markersByName[partialKey];
        }
        if (found) {
            setTimeout(function() {
                map.setView([found.lat, found.lon], 17);
                found.marker.openPopup();
                fetchRealPhoto(found.d).then(function(realPhoto) {
                    updatePhotoElements(found.d, realPhoto);
                });
            }, 300);
        }
    })();

    // Gambar rute AI setelah semua marker selesai dimuat (tidak ada race condition)
    if (window._pendingAIRestore) {
        var places = window._pendingAIRestore.places;
        var startC = window._pendingAIRestore.startCoords || null;
        window._pendingAIRestore = null;
        setTimeout(function() { drawAIRoute(places, startC); }, 150);
    }
})
.catch(error => {
    document.getElementById("total").textContent = error.message;
    document.getElementById("sidebar-list").innerHTML = '<p class="error-state">Data wisata belum bisa dimuat.</p>';
});

function applyFilters() {
    const keyword = document.getElementById("search").value.toLowerCase();
    const category = document.getElementById("filter").value;
    const filtered = allData.filter(d => {
        const nama = String(d.nama ?? '').toLowerCase();
        const kategori = String(d.kategori ?? '').toLowerCase();
        const matchKeyword = nama.includes(keyword);
        const matchCategory = category === 'all' || kategori === category;

        return matchKeyword && matchCategory;
    });

    document.getElementById("total").textContent = filtered.length;
    document.getElementById("category-label").textContent = getCategoryLabel(category);
    renderUI(filtered);
}

function renderUI(data) {
    const currentRender = ++renderToken;
    layerGroup.clearLayers();
    const sidebar = document.getElementById("sidebar-list");
    sidebar.innerHTML = "";

    if (imageObserver) {
        imageObserver.disconnect();
    }

    imageObserver = 'IntersectionObserver' in window ? new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (!entry.isIntersecting) {
                return;
            }

            const img = entry.target;
            imageObserver.unobserve(img);
            fetchRealPhoto(img._wisataRecord).then(photo => updatePhotoElements(img._wisataRecord, photo));
        });
    }, {
        root: sidebar,
        rootMargin: '250px 0px'
    }) : null;

    let autoPhotoCount = 0;
    for (let d of data) {
        const lat = Number(d.latitude);
        const lon = Number(d.longitude);
        if (!Number.isFinite(lat) || !Number.isFinite(lon)) {
            continue;
        }

        if (currentRender !== renderToken) {
            return;
        }

        let photo = getRecordPhoto(d);
        let photoKey = escapeHTML(getPhotoKey(d));
        let namaRaw = d.nama || 'Tidak diketahui';
        let nama = escapeHTML(namaRaw);
        let namaEnc = encodeURIComponent(namaRaw);
        let rating = d.rating ? escapeHTML(d.rating) : '-';

        // Marker Peta dengan warna by kategori
        let markerColor = getMarkerColor(d.kategori);
        let marker = L.marker([lat, lon], {icon: createColoredMarkerIcon(markerColor)}).addTo(layerGroup);
        // Simpan untuk akses via ?place= URL param
        _markersByName[namaRaw.toLowerCase()] = { marker, lat, lon, d };
        let reviewText = d.review ? escapeHTML(d.review).substring(0, 110) + (d.review.length > 110 ? '…' : '') : '';
        let initRating = d.rating ? Number(d.rating) : 0;
        let initStars = initRating > 0 ? renderStars(initRating) : '';
        marker.bindPopup(`
            <div class="wp-img-wrap">
                <img src="${photo}" alt="${nama}" data-photo-key="${photoKey}">
                <div class="wp-img-gradient"></div>
                <span class="wp-cat-badge">${escapeHTML(getCategoryLabel(d.kategori))}</span>
                <span class="wp-open-badge" data-open-key="${photoKey}" style="display:none;"></span>
            </div>
            <div class="wp-content">
                <div class="wp-title">${nama}</div>
                <div class="wp-rating-row">
                    <span class="wp-stars" data-stars-key="${photoKey}">${initStars}</span>
                    <span class="wp-rating-num" data-rating-key="${photoKey}">${initRating > 0 ? Number(initRating).toFixed(1) : '–'}</span>
                </div>
                ${reviewText ? `<p class="wp-desc">${reviewText}</p>` : ''}
                <div class="wp-details" data-details-key="${photoKey}"></div>
                <p class="wp-credit" data-credit-key="${photoKey}"></p>
                <div class="wp-action-row">
                    <a class="wp-maps-btn" data-maps-key="${photoKey}" target="_blank" rel="noopener" hidden>
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                        Google Maps
                    </a>
                    <button class="wp-route-btn" onclick="showRoute({lat:${lat},lng:${lon},name:decodeURIComponent('${namaEnc}')})">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/><circle cx="12" cy="9" r="2.5"/></svg>
                        Rute
                    </button>
                </div>
            </div>
        `, { maxWidth: 360, minWidth: 360, className: 'wisata-popup' });
        marker.on('click', () => {
            fetchRealPhoto(d).then(realPhoto => updatePhotoElements(d, realPhoto));
        });

        // Sidebar Item dengan warna kategori
        let item = document.createElement("div");
        item.className = "place-card";
        item.style.borderLeft = `4px solid ${getMarkerColor(d.kategori)}`;
        item.innerHTML = `<img src="${photo}" alt="${nama}" data-photo-key="${photoKey}">
                          <div><h5>${nama}</h5><p class="meta-line" data-rating-key="${photoKey}">Rating ${rating}</p><p data-credit-key="${photoKey}" class="photo-credit"></p><a data-maps-key="${photoKey}" class="maps-link" target="_blank" rel="noopener" hidden></a><span class="category-pill">${escapeHTML(getCategoryLabel(d.kategori))}</span><button class="sidebar-route-btn" onclick="event.stopPropagation(); showRoute({lat:${lat},lng:${lon},name:decodeURIComponent('${namaEnc}')})"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/><circle cx="12" cy="9" r="2.5"/></svg> Arah</button></div>`;
        item.onclick = () => {
            map.setView([lat, lon], 16);
            marker.openPopup();
            fetchRealPhoto(d).then(realPhoto => updatePhotoElements(d, realPhoto));
        };
        sidebar.appendChild(item);
        if (autoPhotoCount < 12) {
            observeRealPhoto(item.querySelector('img'), d);
            autoPhotoCount++;
        }
    }
}

// SEARCH
document.getElementById("search").addEventListener("input", applyFilters);
document.getElementById("filter").addEventListener("change", applyFilters);

// LOKASI SAYA
function lokasiSaya() {
    if (!navigator.geolocation) {
        alert("Browser tidak mendukung geolocation.");
        return;
    }

    navigator.geolocation.getCurrentPosition(pos => {
        let {latitude, longitude} = pos.coords;
        userLocation = [latitude, longitude];
        if (userMarker) map.removeLayer(userMarker);
        userMarker = L.marker([latitude, longitude], { icon: createUserMarkerIcon(), zIndexOffset: 1000 })
            .addTo(map)
            .bindPopup('<b>📍 Lokasi Anda</b>');
        map.setView([latitude, longitude], 14);
    }, () => {
        alert("Lokasi tidak bisa diakses. Pastikan izin lokasi sudah aktif.");
    });
}

// ROUTING
function showRoute(destination) {
    if (!window.isLoggedIn) {
        if (typeof showToast === 'function') showToast('Login terlebih dahulu untuk menggunakan fitur rute', '🔒');
        if (typeof openModal === 'function') openModal('login');
        return;
    }

    if (!userLocation) {
        if (typeof showToast === 'function') showToast('Aktifkan lokasi Anda terlebih dahulu', '📍');
        lokasiSaya();
        return;
    }

    clearRoute();

    // Sembunyikan semua marker tempat wisata
    map.removeLayer(layerGroup);

    // Pasang marker tujuan
    var destName = destination.name || 'Tujuan';
    destinationMarker = L.marker([destination.lat, destination.lng], {
        icon: createDestinationMarkerIcon(destName),
        zIndexOffset: 900
    }).addTo(map);

    var destEl = document.getElementById('routing-dest-name');
    if (destEl) destEl.textContent = destName;

    routingControl = L.Routing.control({
        waypoints: [
            L.latLng(userLocation[0], userLocation[1]),
            L.latLng(destination.lat, destination.lng)
        ],
        lineOptions: {
            styles: [
                { color: '#17324d', opacity: 0.15, weight: 9 },
                { color: '#008c8c', opacity: 0.9, weight: 5 }
            ],
            extendToWaypoints: true,
            missingRouteTolerance: 2
        },
        router: L.Routing.osrmv1({ timeout: 10000 }),
        routeWhileDragging: false,
        fitSelectedRoutes: true,
        showAlternatives: false,
        createMarker: function() { return null; }
    }).on('routesfound', function(e) {
        var route = e.routes[0];
        var km = (route.summary.totalDistance / 1000).toFixed(1);
        var mins = Math.round(route.summary.totalTime / 60);
        var durText = mins >= 60 ? (Math.floor(mins/60) + ' j ' + (mins%60) + ' mnt') : (mins + ' mnt');

        document.getElementById('routing-distance').textContent = km + ' km';
        document.getElementById('routing-duration').textContent = durText;
        document.getElementById('routing-info').style.display = 'block';

        // Render turn-by-turn steps in sidebar
        var stepsEl = document.getElementById('route-steps');
        if (stepsEl && route.instructions) {
            var icons = {
                'Straight': '↑', 'SlightRight': '↗', 'Right': '→', 'SharpRight': '↱',
                'TurnRight': '→', 'SlightLeft': '↖', 'Left': '←', 'SharpLeft': '↰',
                'TurnLeft': '←', 'Uturn': '↩', 'WaypointReached': '📍', 'Roundabout': '↻',
                'DestinationReached': '🏁', 'EnterRoundAbout': '↻', 'Head': '↑'
            };
            var html = '';
            route.instructions.forEach(function(step, i) {
                var dist = step.distance >= 1000
                    ? (step.distance / 1000).toFixed(1) + ' km'
                    : (step.distance > 0 ? Math.round(step.distance) + ' m' : '');
                var icon = icons[step.type] || '•';
                var isLast = i === route.instructions.length - 1;
                html += '<div class="route-step' + (isLast ? ' route-step-last' : '') + '">'
                    + '<div class="route-step-icon">' + icon + '</div>'
                    + '<div class="route-step-body">'
                    + '<div class="route-step-text">' + escapeHTML(step.text) + '</div>'
                    + (dist ? '<div class="route-step-dist">' + dist + '</div>' : '')
                    + '</div></div>';
            });
            stepsEl.innerHTML = html;

            // Update mini bar with first step
            if (route.instructions.length > 0 && typeof window.updateMiniBar === 'function') {
                var first = route.instructions[0];
                var firstDist = first.distance >= 1000
                    ? (first.distance / 1000).toFixed(1) + ' km'
                    : (first.distance > 0 ? Math.round(first.distance) + ' m' : '');
                window.updateMiniBar(
                    icons[first.type] || '↑',
                    first.text,
                    firstDist,
                    destination.name || 'Tujuan'
                );
            }
        }

        if (typeof window.positionRoutingInfo === 'function') window.positionRoutingInfo();
        if (typeof showToast === 'function') showToast('Rute ditemukan: ' + km + ' km · ' + durText, '🗺️');
    }).on('routingerror', function() {
        if (typeof showToast === 'function') showToast('Rute tidak ditemukan, coba lagi', '⚠️');
        clearRoute();
    }).addTo(map);
}

function clearRoute() {
    if (routingControl) {
        map.removeControl(routingControl);
        routingControl = null;
    }
    if (destinationMarker) {
        map.removeLayer(destinationMarker);
        destinationMarker = null;
    }
    // Tampilkan kembali semua marker wisata
    if (!map.hasLayer(layerGroup)) map.addLayer(layerGroup);

    // Kembalikan routing-info ke sidebar jika sedang floating
    var ri = document.getElementById('routing-info');
    var sidebar = document.getElementById('sidebar');
    var sidebarList = document.getElementById('sidebar-list');
    if (ri && sidebar && ri.parentElement !== sidebar) {
        sidebar.insertBefore(ri, sidebarList);
        ri.classList.remove('route-panel-floating');
    }
    ri.style.display = 'none';
    var stepsEl = document.getElementById('route-steps');
    if (stepsEl) stepsEl.innerHTML = '';
}

// ── AI ROUTE ─────────────────────────────────────────────────────────────
var aiRouteMarkers   = [];
var aiRoutingControl = null;
var AI_COLORS = ['#e74c3c','#e67e22','#f39c12','#27ae60','#2980b9','#8e44ad','#16a085','#e91e63'];

function makeAIIcon(i, color) {
    return L.divIcon({
        className: '',
        html: '<div style="background:' + color + ';color:#fff;width:30px;height:30px;border-radius:50%;' +
              'display:flex;align-items:center;justify-content:center;font-weight:800;font-size:13px;' +
              'border:2.5px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,0.45);">' + (i + 1) + '</div>',
        iconSize: [30, 30], iconAnchor: [15, 15],
    });
}

var DAY_COLORS = ['#e74c3c','#2980b9','#27ae60','#8e44ad','#e67e22'];

function drawAIRoute(places, startCoords) {
    clearAIRoute(false); // false = jangan hapus localStorage saat redraw

    // Sembunyikan semua marker wisata biasa
    if (map.hasLayer(layerGroup)) map.removeLayer(layerGroup);

    var withCoords = places.filter(function(p) { return p.latitude && p.longitude; });
    if (withCoords.length < 1) return;

    // Kelompokkan per hari
    var byDay = {};
    withCoords.forEach(function(p, i) {
        var d = p.hari || 1;
        if (!byDay[d]) byDay[d] = [];
        byDay[d].push(Object.assign({}, p, { _idx: i }));
    });

    // ── Marker titik awal / penginapan ───────────────────────────────────
    if (startCoords && startCoords.lat && startCoords.lng) {
        var homeIcon = L.divIcon({
            className: '',
            html: '<div style="background:#27ae60;color:#fff;width:34px;height:34px;border-radius:50%;'
                + 'display:flex;align-items:center;justify-content:center;font-size:17px;'
                + 'border:3px solid #fff;box-shadow:0 2px 10px rgba(0,0,0,0.5);">🏠</div>',
            iconSize: [34, 34], iconAnchor: [17, 17],
        });
        var homeMarker = L.marker([startCoords.lat, startCoords.lng], { icon: homeIcon, zIndexOffset: 600 })
            .addTo(map)
            .bindPopup('<div style="min-width:140px"><b>📍 Titik Awal</b><br>' + (startCoords.name || 'Lokasi Kamu') + '</div>');
        aiRouteMarkers.push(homeMarker);

        // Sisipkan titik awal ke awal rute hari 1
        var days0 = Object.keys(byDay).sort(function(a,b){ return a-b; });
        if (days0.length > 0) {
            byDay[days0[0]].unshift({ latitude: startCoords.lat, longitude: startCoords.lng, _isHome: true });
        }
    }

    // Marker bernomor per stop (warna berdasarkan hari, skip titik home)
    withCoords.forEach(function(p, i) {
        var dayColor = DAY_COLORS[((p.hari || 1) - 1) % DAY_COLORS.length];
        var marker = L.marker([p.latitude, p.longitude], { icon: makeAIIcon(i, dayColor), zIndexOffset: 500 })
            .addTo(map)
            .bindPopup(
                '<div style="min-width:160px"><b style="color:' + dayColor + ';">Hari ' + (p.hari||1) + ' · ' + p.jam + '</b><br>' +
                p.nama + (p.kategori ? '<br><small style="opacity:.7">' + p.kategori + '</small>' : '') + '</div>'
            );
        aiRouteMarkers.push(marker);
    });

    // Gambar rute OSRM per hari dengan warna berbeda
    var days = Object.keys(byDay).sort(function(a,b){ return a-b; });
    days.forEach(function(day) {
        var pts = byDay[day];
        if (pts.length < 2) return;
        var waypoints = pts.map(function(p) { return L.latLng(p.latitude, p.longitude); });
        var lineColor = DAY_COLORS[(parseInt(day) - 1) % DAY_COLORS.length];
        var ctrl = L.Routing.control({
            waypoints          : waypoints,
            routeWhileDragging : false,
            addWaypoints       : false,
            draggableWaypoints : false,
            fitSelectedRoutes  : days.length === 1,
            show               : false,
            lineOptions: {
                styles: [{ color: lineColor, weight: 5, opacity: 0.85 }],
                extendToWaypoints    : true,
                missingRouteTolerance: 0,
            },
            createMarker: function() { return null; },
            router: L.Routing.osrmv1({
                serviceUrl: 'https://router.project-osrm.org/route/v1',
                profile   : 'driving',
            }),
        }).addTo(map);
        ctrl.on('routesfound', function() {
            var c = ctrl.getContainer();
            if (c) c.style.display = 'none';
        });
        // simpan agar bisa di-clear
        if (!aiRoutingControl) aiRoutingControl = [];
        if (Array.isArray(aiRoutingControl)) aiRoutingControl.push(ctrl);
    });

    // Fit bounds semua stops + titik awal
    var allPts = withCoords.map(function(p){ return [p.latitude, p.longitude]; });
    if (startCoords && startCoords.lat && startCoords.lng) allPts.push([startCoords.lat, startCoords.lng]);
    if (allPts.length > 0) {
        map.fitBounds(L.latLngBounds(allPts), { padding: [40, 40] });
    }

    // Panel sidebar — kelompokkan per hari
    var panel = document.getElementById('ai-route-panel');
    var stops = document.getElementById('ai-route-stops');
    if (panel && stops) {
        var html = '';
        // Jika ada titik awal/home, tampilkan sebagai header
        if (startCoords && startCoords.name) {
            html += '<div style="display:flex;align-items:center;gap:7px;padding:3px 0 6px;">'
                + '<span style="background:#27ae60;color:#fff;width:20px;height:20px;border-radius:50%;'
                + 'display:inline-flex;align-items:center;justify-content:center;font-size:11px;flex-shrink:0;">🏠</span>'
                + '<span style="font-size:0.8rem;opacity:0.8;">' + startCoords.name + '</span></div>';
        }
        days.forEach(function(day) {
            var dc = DAY_COLORS[(parseInt(day)-1) % DAY_COLORS.length];
            html += '<div style="font-size:0.7rem;font-weight:700;opacity:0.75;margin:8px 0 3px;letter-spacing:0.5px;color:' + dc + ';">📅 HARI ' + day + '</div>';
            byDay[day].forEach(function(p) {
                if (p._isHome) return; // skip titik awal dari loop stops
                html += '<div style="display:flex;align-items:center;gap:7px;padding:2px 0;">' +
                    '<span style="background:' + dc + ';color:#fff;width:20px;height:20px;border-radius:50%;' +
                    'display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;flex-shrink:0;">' +
                    (p._idx + 1) + '</span><span style="font-size:0.8rem;"><b>' + p.jam + '</b> ' + p.nama + '</span></div>';
            });
        });
        stops.innerHTML = html;
        panel.style.display = 'block';
    }
}

function clearAIRoute(clearStorage) {
    aiRouteMarkers.forEach(function(m) { map.removeLayer(m); });
    aiRouteMarkers = [];
    if (aiRoutingControl) {
        var list = Array.isArray(aiRoutingControl) ? aiRoutingControl : [aiRoutingControl];
        list.forEach(function(ctrl) { try { map.removeControl(ctrl); } catch(e) {} });
        aiRoutingControl = null;
    }
    var panel = document.getElementById('ai-route-panel');
    if (panel) panel.style.display = 'none';
    // Hanya hapus localStorage jika user klik "Hapus" (clearStorage !== false)
    if (clearStorage !== false) localStorage.removeItem('wb_ai_session');
    if (!map.hasLayer(layerGroup)) map.addLayer(layerGroup);
}

// ── AI Planner Modal (langsung di peta) ──────────────────────────────────
var _mapDurasi = 1;
var _mapMinat  = ['Alam'];
var _mapBudget = 'Sedang';
var _mapOrang  = 2;

function openAIPlannerModal() {
    var m = document.getElementById('ai-planner-modal');
    if (m) { m.style.display = 'flex'; }
}
function closeAIPlannerModal() {
    var m = document.getElementById('ai-planner-modal');
    if (m) m.style.display = 'none';
    var res = document.getElementById('map-ai-result');
    if (res) res.style.display = 'none';
}
function selectMapChip(type, val, el) {
    var container = el.parentElement;
    container.querySelectorAll('.map-chip').forEach(function(c) { c.classList.remove('active'); });
    el.classList.add('active');
    if (type === 'durasi') _mapDurasi = val;
    if (type === 'budget') _mapBudget = val;
    if (type === 'orang') _mapOrang = val;
}
function toggleMapMinat(val, el) {
    var idx = _mapMinat.indexOf(val);
    if (idx === -1) { _mapMinat.push(val); el.classList.add('active'); }
    else { _mapMinat.splice(idx, 1); el.classList.remove('active'); }
    if (_mapMinat.length === 0) { _mapMinat.push(val); el.classList.add('active'); }
}
function parseMarkdownSimple(md) {
    return md
        .replace(/^## (.+)$/gm,  '<h2 style="color:#C4956A;margin:12px 0 4px;font-size:1rem;">$1</h2>')
        .replace(/^### (.+)$/gm, '<h3 style="color:#a8c97a;margin:10px 0 3px;font-size:0.9rem;">$1</h3>')
        .replace(/\*\*(.+?)\*\*/g, '<b>$1</b>')
        .replace(/\*(.+?)\*/g, '<i>$1</i>')
        .replace(/^- (.+)$/gm, '<div style="padding:2px 0 2px 10px;border-left:2px solid #C4956A40;">$1</div>')
        .replace(/^---$/gm, '<hr style="border-color:#ffffff20;margin:8px 0;">')
        .replace(/\n/g, '<br>');
}
function submitAIPlannerMap() {
    var btn = document.getElementById('map-ai-submit-btn');
    var loading = document.getElementById('map-ai-loading');
    var result  = document.getElementById('map-ai-result');
    btn.disabled = true;
    loading.style.display = 'block';
    if (result) result.style.display = 'none';

    fetch('ai_planner.php', {
        method : 'POST',
        headers: { 'Content-Type': 'application/json' },
        body   : JSON.stringify({ durasi: _mapDurasi, minat: _mapMinat, budget: _mapBudget, orang: _mapOrang }),
    })
    .then(function(r) {
        // Proxy (504/502) bisa menyela dengan halaman HTML saat AI lambat.
        if (!r.ok) throw new Error('Server sibuk (HTTP ' + r.status + '). Tunggu sebentar lalu coba lagi.');
        return r.text().then(function(t) {
            try { return JSON.parse(t); }
            catch (e) { throw new Error('Respons server tidak valid. Coba lagi.'); }
        });
    })
    .then(function(data) {
        if (data.error) throw new Error(data.error);
        if (result) {
            result.innerHTML = parseMarkdownSimple(data.result);
            result.style.display = 'block';
        }
        sessionStorage.setItem('ai_route', JSON.stringify({ places: data.places, durasi: data.durasi, result: data.result }));
        drawAIRoute(data.places);
    })
    .catch(function(err) {
        if (result) { result.innerHTML = '⚠️ ' + (err.message || 'Terjadi kesalahan.'); result.style.display = 'block'; }
    })
    .finally(function() {
        btn.disabled = false;
        loading.style.display = 'none';
    });
}

// ── Auto-restore AI route dari localStorage (lintas halaman) ──────────────
// Jangan restore jika user datang dari klik kategori (?kategori= di URL)
(function () {
    var params = new URLSearchParams(window.location.search);
    if (params.get('kategori')) return; // user mau lihat kategori, bukan rute AI

    var raw = localStorage.getItem('wb_ai_session');
    if (!raw) return;
    var session;
    try { session = JSON.parse(raw); } catch(e) { return; }
    if (!session || !session.places || session.places.length === 0) return;
    var withCoords = session.places.filter(function(p) { return p.latitude && p.longitude; });
    if (withCoords.length < 1) return;
    window._pendingAIRestore = { places: session.places, startCoords: session.startCoords || null };
})();
