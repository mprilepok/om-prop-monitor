/**
 * PSKReporter MQTT WebSocket — live OM spots + matrix rendering
 *
 * Single data layer: replaces PSKReporter REST API.
 * Maintains a time-windowed spot buffer → feeds:
 *   • Band × Continent matrix  (#band-tbody)
 *   • Locator × Continent matrix (#loc-tbody)
 *   • Live spots table (#mqtt-tbody)
 *
 * Config injected by PHP via window.MQTT_CONFIG / window.OM_BANDS / window.OM_CONTS.
 */
(function () {
  'use strict';

  // ── Config ────────────────────────────────────────────────────────────────
  const BANDS = window.OM_BANDS || ['160m','80m','60m','40m','30m','20m','17m','15m','12m','10m','6m','2m','70cm'];
  const CONTS = window.OM_CONTS || ['EU','NA','SA','AF','AS','OC','AN'];

  const CFG = Object.assign({
    broker        : 'wss://mqtt.pskreporter.info:1886',
    topic         : 'pskr/filter/v2/+/+/+/+/+/+/+/+',
    prefix        : 'OM',
    windowSec     : 3600,
    maxLiveRows   : 50,
    pruneInterval : 30_000,   // ms
  }, window.MQTT_CONFIG || {});

  // ── Slovakia locator grid (west→east / north→south) ──────────────────────
  const SK_GRID = [
    ['JN89', 0, 0], ['JN99', 1, 0], ['KN09', 2, 0], ['KN19', 3, 0],
    ['JN88', 0, 1], ['JN98', 1, 1], ['KN08', 2, 1], ['KN18', 3, 1],
    ['JN87', 0, 2], ['JN97', 1, 2], ['KN07', 2, 2], ['KN17', 3, 2],
  ];
  const SK_SET = new Set(SK_GRID.map(([g]) => g));

  // Slovakia border — loaded async from web/assests/SVK.geo.json
  // Fallback (33 pts) renders immediately; replaced with detailed coords on load.
  let SK_BORDER = [
    [18.853144,49.49623],[18.909575,49.435846],[19.320713,49.571574],
    [19.825023,49.217125],[20.415839,49.431453],[20.887955,49.328772],
    [21.607808,49.470107],[22.558138,49.085738],[22.280842,48.825392],
    [22.085608,48.422264],[21.872236,48.319971],[20.801294,48.623854],
    [20.473562,48.56285], [20.239054,48.327567],[19.769471,48.202691],
    [19.661364,48.266615],[19.174365,48.111379],[18.777025,48.081768],
    [18.696513,47.880954],[17.857133,47.758429],[17.488473,47.867466],
    [16.979667,48.123497],[16.879983,48.470013],[16.960288,48.596982],
    [17.101985,48.816969],[17.545007,48.800019],[17.886485,48.903475],
    [17.913512,48.996493],[18.104973,49.043983],[18.170498,49.271515],
    [18.399994,49.315001],[18.554971,49.495015],[18.853144,49.49623],
  ];

  async function loadSkBorder() {
    try {
      const res  = await fetch('assests/SVK.geo.json');
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const json = await res.json();

      // Support FeatureCollection, Feature, or bare geometry
      const feat = json.type === 'FeatureCollection' ? json.features[0] : json;
      const geom = feat.geometry || feat;

      let coords;
      if (geom.type === 'Polygon') {
        coords = geom.coordinates[0];
      } else if (geom.type === 'MultiPolygon') {
        // pick the longest outer ring (mainland)
        coords = geom.coordinates
          .map(poly => poly[0])
          .sort((a, b) => b.length - a.length)[0];
      }

      if (coords && coords.length > 3) {
        SK_BORDER = coords;
        renderSkMap();
        console.info(`[mqtt_spots] SK border loaded: ${coords.length} pts`);
      }
    } catch (e) {
      console.warn('[mqtt_spots] SK border load failed, using fallback:', e.message);
    }
  }

  // ── State ─────────────────────────────────────────────────────────────────
  let allSpots  = [];   // TX spots (OM as sender)
  let rxSpots   = [];   // RX spots (OM as receiver) — used only for map
  let liveSpots = [];   // most-recent CFG.maxLiveRows for live table
  let windowSec = CFG.windowSec;

  // ── Geo / DX helpers ─────────────────────────────────────────────────────

  /** Maidenhead grid → {lat, lon} centre point */
  function gridToLatLon(grid) {
    if (!grid || grid.length < 2) return null;
    grid = grid.toUpperCase();
    let lon = (grid.charCodeAt(0) - 65) * 20 - 180;
    let lat = (grid.charCodeAt(1) - 65) * 10 - 90;
    if (grid.length >= 4 && /\d/.test(grid[2]) && /\d/.test(grid[3])) {
      lon += parseInt(grid[2], 10) * 2 + 1;      // centre of square
      lat += parseInt(grid[3], 10) + 0.5;
    } else {
      lon += 10;   // centre of field
      lat +=  5;
    }
    return { lat, lon };
  }

  /** Haversine distance in km */
  function haversine(lat1, lon1, lat2, lon2) {
    const R  = 6371;
    const dL = (lat2 - lat1) * Math.PI / 180;
    const dO = (lon2 - lon1) * Math.PI / 180;
    const a  = Math.sin(dL / 2) ** 2
             + Math.cos(lat1 * Math.PI / 180)
             * Math.cos(lat2 * Math.PI / 180)
             * Math.sin(dO / 2) ** 2;
    return Math.round(2 * R * Math.asin(Math.sqrt(a)));
  }

  /** True bearing from p1 → p2 (0–360°) */
  function bearing(lat1, lon1, lat2, lon2) {
    const toRad = x => x * Math.PI / 180;
    const dLon  = toRad(lon2 - lon1);
    const y = Math.sin(dLon) * Math.cos(toRad(lat2));
    const x = Math.cos(toRad(lat1)) * Math.sin(toRad(lat2))
            - Math.sin(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.cos(dLon);
    return ((Math.atan2(y, x) * 180 / Math.PI) + 360) % 360;
  }

  /** Distance (km) + bearing between two Maidenhead grids. Returns null if either invalid. */
  function gridDX(txGrid, rxGrid) {
    const p1 = gridToLatLon(txGrid);
    const p2 = gridToLatLon(rxGrid);
    if (!p1 || !p2) return null;
    return {
      km  : haversine(p1.lat, p1.lon, p2.lat, p2.lon),
      brg : Math.round(bearing(p1.lat, p1.lon, p2.lat, p2.lon)),
    };
  }

  // ── Continent helper (mirrors PHP locator_to_continent) ───────────────────
  function latLonToCont(lat, lon) {
    if (lat < -60)                                               return 'AN';
    if (lat < 15  && lon > 100)                                  return 'OC';
    if (lon > 60  && lat > -10)                                  return 'AS';
    if (lon >= -170 && lon <= -50  && lat > 10)                  return 'NA';
    if (lon >= -85  && lon <= -30  && lat <= 15)                 return 'SA';
    if (lon >= -20  && lon <= 55   && lat >= -40 && lat <= 38)   return 'AF';
    if (lon >= -30  && lon <= 65   && lat > 34)                  return 'EU';
    return '??';
  }

  function gridToCont(grid) {
    if (!grid || grid.length < 2) return '??';
    grid = grid.toUpperCase();
    let lon = (grid.charCodeAt(0) - 65) * 20 - 180;
    let lat = (grid.charCodeAt(1) - 65) * 10 - 90;
    if (grid.length >= 4 && /\d/.test(grid[2]) && /\d/.test(grid[3])) {
      lon += parseInt(grid[2], 10) * 2;
      lat += parseInt(grid[3], 10);
    }
    return latLonToCont(lat, lon);
  }

  // ── Topic / payload parsers ───────────────────────────────────────────────
  function parseTopic(topic) {
    const parts = topic.split('/');
    if (parts.length < 9 || parts[0] !== 'pskr') return null;
    const f = parts.slice(3); // drop pskr/filter/v2
    return {
      band    : f[0] || '',
      mode    : f[1] || '',
      sender  : f[2] || '',
      receiver: f[3] || '',
      txGrid  : f[4] || '',
      rxGrid  : f[5] || '',
      txDxcc  : f[6] || '',
      rxDxcc  : f[7] || '',
    };
  }

  function parseSnr(payload) {
    try {
      const raw = payload.toString().trim();
      const n   = parseInt(raw, 10);
      if (!isNaN(n) && String(n) === raw && n >= -40 && n <= 20) return n;
    } catch (_) {}
    return null;
  }

  // ── Matrix computation ────────────────────────────────────────────────────
  function buildMatrices() {
    const cutoff = Date.now() - windowSec * 1_000;
    const band = {};
    const loc  = {};

    for (const s of allSpots) {
      if (s.ts < cutoff) continue;
      const cont = gridToCont(s.rxGrid);

      // band × continent
      if (!band[s.band]) band[s.band] = {};
      band[s.band][cont] = (band[s.band][cont] || 0) + 1;

      // locator × continent (first 4 chars of txGrid)
      const grid = (s.txGrid || '??').toUpperCase().slice(0, 4);
      if (!loc[grid]) loc[grid] = {};
      loc[grid][cont] = (loc[grid][cont] || 0) + 1;
    }

    return { band, loc };
  }

  // ── Cell styling (mirrors PHP cell_classes) ───────────────────────────────
  function cellClasses(n) {
    if (n >= 31) return 'text-red-400 bg-red-950/30 font-bold';
    if (n >= 11) return 'text-amber-400 bg-amber-950/30';
    if (n >= 4)  return 'text-green-400 bg-green-950/40';
    return 'text-blue-400 bg-blue-950/40';
  }

  function cellHtml(n) {
    if (!n) return '<span class="text-gray-800">·</span>';
    return `<span class="inline-block rounded px-2 py-0.5 ${cellClasses(n)} text-sm font-semibold">${n}</span>`;
  }

  // ── Band × Continent renderer ─────────────────────────────────────────────
  function renderBandMatrix(bandMatrix) {
    const tbody = document.getElementById('band-tbody');
    if (!tbody) return;

    tbody.innerHTML = BANDS.map(band => {
      const row    = bandMatrix[band] || {};
      const rowSum = CONTS.reduce((s, c) => s + (row[c] || 0), 0);
      const active = rowSum > 0;

      const cells = CONTS.map(c =>
        `<td class="cell py-2 px-2 text-center border-r border-gray-800 last:border-r-0">${cellHtml(row[c] || 0)}</td>`
      ).join('');

      return `<tr class="band-row ${active ? '' : 'opacity-30'}">
        <td class="py-2 px-4 border-r border-gray-800 font-bold ${active ? 'text-sky-300' : 'text-gray-600'} text-right">${band}</td>
        ${cells}
        <td class="py-2 px-3 text-center text-gray-500 text-xs">${rowSum || ''}</td>
      </tr>`;
    }).join('');
  }

  // ── Locator × Continent renderer ──────────────────────────────────────────
  function renderLocMatrix(locMatrix) {
    const tbody = document.getElementById('loc-tbody');
    if (!tbody) return;

    const entries = Object.entries(locMatrix).sort((a, b) => {
      const sa = CONTS.reduce((s, c) => s + (a[1][c] || 0), 0);
      const sb = CONTS.reduce((s, c) => s + (b[1][c] || 0), 0);
      return sb - sa;
    });

    if (!entries.length) {
      tbody.innerHTML = `<tr><td colspan="${CONTS.length + 2}" class="py-4 text-center text-gray-600 text-xs">No OM stations heard yet in window</td></tr>`;
      return;
    }

    tbody.innerHTML = entries.map(([grid, row]) => {
      const rowSum = CONTS.reduce((s, c) => s + (row[c] || 0), 0);
      const cells  = CONTS.map(c =>
        `<td class="cell py-2 px-2 text-center border-r border-gray-800 last:border-r-0">${cellHtml(row[c] || 0)}</td>`
      ).join('');

      return `<tr class="band-row">
        <td class="py-2 px-4 border-r border-gray-800 font-bold text-sky-300 text-right">${grid}</td>
        ${cells}
        <td class="py-2 px-3 text-center text-gray-500 text-xs">${rowSum}</td>
      </tr>`;
    }).join('');
  }

  // ── Stats bar ─────────────────────────────────────────────────────────────
  function updateStats() {
    const cutoff = Date.now() - windowSec * 1_000;
    const count  = allSpots.filter(s => s.ts >= cutoff).length;

    const totalEl = document.getElementById('mqtt-total');
    if (totalEl) totalEl.textContent = count;

    const lastEl = document.getElementById('mqtt-last');
    if (lastEl && allSpots.length > 0) {
      lastEl.textContent = allSpots[0].utcStr + ' UTC';
    }
  }

  // ── Live spots table ──────────────────────────────────────────────────────
  function snrTd(snr) {
    if (snr == null) return '<td class="py-1.5 px-3 text-right text-gray-600">—</td>';
    const cls = snr > 0 ? 'text-green-400' : 'text-gray-400';
    return `<td class="py-1.5 px-3 text-right ${cls}">${snr > 0 ? '+' : ''}${snr} dB</td>`;
  }

  function renderLiveTable() {
    const tbody = document.getElementById('mqtt-tbody');
    if (!tbody) return;

    if (!liveSpots.length) {
      tbody.innerHTML = '<tr><td colspan="9" class="py-4 text-center text-gray-600 text-xs">Waiting for spots…</td></tr>';
    } else {
      tbody.innerHTML = liveSpots.map(s => {
        const cont   = gridToCont(s.rxGrid);
        const dxKm   = s.dx ? s.dx.km.toLocaleString()  : '—';
        const dxBrg  = s.dx ? s.dx.brg + '°'            : '';
        const dxCls  = s.dx && s.dx.km >= 5000 ? 'text-amber-300' : (s.dx ? 'text-gray-400' : 'text-gray-700');
        return `<tr class="hover:bg-gray-800/20">
          <td class="py-1.5 px-3 text-gray-500">${s.utcStr}</td>
          <td class="py-1.5 px-3 text-amber-400 font-semibold">${s.sender}</td>
          <td class="py-1.5 px-3 text-sky-300">${s.receiver}</td>
          <td class="py-1.5 px-3 text-center text-white">${s.band}</td>
          <td class="py-1.5 px-3 text-center text-gray-400">${s.mode}</td>
          <td class="py-1.5 px-3 text-center"><span class="text-green-400">${cont}</span></td>
          <td class="py-1.5 px-3 text-center text-gray-600 text-xs">${s.txGrid || '—'} → ${s.rxGrid || '—'}</td>
          <td class="py-1.5 px-3 text-center text-xs ${dxCls}">${dxKm}<span class="text-gray-700 ml-1">${dxBrg}</span></td>
          ${snrTd(s.snr)}
        </tr>`;
      }).join('');
    }

    const cnt = document.getElementById('mqtt-count');
    if (cnt) cnt.textContent = liveSpots.length;
  }

  // ── Status badge ──────────────────────────────────────────────────────────
  function setStatus(state) {
    const el = document.getElementById('mqtt-status');
    if (!el) return;
    const map = {
      connecting: ['● Connecting…', 'text-amber-400'],
      live      : ['● LIVE',        'text-green-400'],
      offline   : ['● Offline',     'text-red-500'],
    };
    const [text, cls] = map[state] || map.offline;
    el.className  = `text-xs font-mono ${cls}`;
    el.textContent = text;
  }

  // ── Slovakia locator tile map ─────────────────────────────────────────────

  function renderSkMap() {
    const el = document.getElementById('sk-map');
    if (!el) return;

    const cutoff = Date.now() - windowSec * 1_000;

    // ── SVG viewport (PAD leaves room for compass labels) ────────────────
    const PAD = 10;
    const W = 400 + PAD * 2, H = 200 + PAD * 2;
    const LON0 = 15.6, LON1 = 24.2;
    const LAT0 = 46.8, LAT1 = 50.5;
    const scX  = 400 / (LON1 - LON0);
    const scY  = 200 / (LAT1 - LAT0);

    const toX = lon => (lon - LON0) * scX + PAD;
    const toY = lat => (LAT1 - lat) * scY + PAD;
    const f1  = n   => n.toFixed(2);
    const px  = lon => toX(lon).toFixed(2);
    const py  = lat => toY(lat).toFixed(2);

    // ── Count TX / RX per tile ────────────────────────────────────────────
    const agg = {};
    SK_GRID.forEach(([g]) => { agg[g] = { tx: 0, rx: 0 }; });
    for (const s of allSpots) {
      if (s.ts < cutoff) continue;
      const g4 = (s.txGrid || '').toUpperCase().slice(0, 4);
      if (agg[g4]) agg[g4].tx++;
    }
    for (const s of rxSpots) {
      if (s.ts < cutoff) continue;
      const g4 = (s.omGrid || '').toUpperCase().slice(0, 4);
      if (agg[g4]) agg[g4].rx++;
    }

    // ── Tiles ─────────────────────────────────────────────────────────────
    const tileW = 2 * scX;
    const tileH = 1 * scY;

    const tiles = SK_GRID.map(([grid]) => {
      const lon0 = (grid.charCodeAt(0) - 65) * 20 - 180 + parseInt(grid[2], 10) * 2;
      const lat0 = (grid.charCodeAt(1) - 65) * 10 - 90  + parseInt(grid[3], 10);
      const x  = toX(lon0);
      const y  = toY(lat0 + 1);     // north edge of tile
      const cx = x + tileW / 2;
      const { tx, rx } = agg[grid];
      const active     = tx || rx;

      // Grid label — top-centre
      const labelY   = f1(y + tileH * 0.30);
      const gridFill = active ? '#9ca3af' : '#374151';
      const cxf      = f1(cx);

      // TX / RX rows centred — "TX" label + bold count as single tspan unit
      const txLblY = f1(y + tileH * 0.58);
      const rxLblY = f1(y + tileH * 0.82);

      return `<rect x="${f1(x)}" y="${f1(y)}" width="${f1(tileW)}" height="${f1(tileH)}"
                    fill="rgba(3,7,18,0.0)" stroke="#1f2937" stroke-width="0.8" rx="2"/>
              <text x="${cxf}" y="${labelY}" text-anchor="middle"
                    font-size="9" font-family="monospace" font-weight="bold" fill="${gridFill}">${grid}</text>
              ${tx ? `<text x="${cxf}" y="${txLblY}" text-anchor="middle" font-size="8" font-family="monospace">
                <tspan fill="#fb923c">TX </tspan><tspan fill="#f97316" font-weight="bold">${tx}</tspan>
              </text>` : ''}
              ${rx ? `<text x="${cxf}" y="${rxLblY}" text-anchor="middle" font-size="8" font-family="monospace">
                <tspan fill="#4ade80">RX </tspan><tspan fill="#22c55e" font-weight="bold">${rx}</tspan>
              </text>` : ''}`;
    }).join('\n');

    // ── Border ────────────────────────────────────────────────────────────
    const borderPts = SK_BORDER.map(([lon, lat]) => `${px(lon)},${py(lat)}`).join(' ');

    // ── Compass ───────────────────────────────────────────────────────────
    const compass = `
      <text x="${f1(W * 0.5)}" y="${f1(PAD - 2)}"  text-anchor="middle" dominant-baseline="auto" font-size="8" fill="#374151">N</text>
      <text x="${f1(W * 0.5)}" y="${f1(H - 2)}"    text-anchor="middle" dominant-baseline="auto" font-size="8" fill="#374151">S</text>
      <text x="${f1(PAD - 3)}" y="${f1(H * 0.5)}"  text-anchor="end"    dominant-baseline="middle" font-size="8" fill="#374151">W</text>
      <text x="${f1(W - PAD + 3)}" y="${f1(H * 0.5)}" text-anchor="start" dominant-baseline="middle" font-size="8" fill="#374151">E</text>`;

    el.innerHTML = `<svg viewBox="0 0 ${W} ${H}" xmlns="http://www.w3.org/2000/svg"
                         style="width:100%;height:auto;display:block;border-radius:0.5rem;">
      <rect width="${W}" height="${H}" fill="#030712"/>
      <polygon points="${borderPts}"
               fill="none" stroke="#9ca3af" stroke-width="1.5"
               stroke-linejoin="round" stroke-linecap="round"
               opacity="0.3"/>
      ${tiles}
      ${compass}
    </svg>`;
  }

  // ── Persistence (localStorage) ───────────────────────────────────────────
  const STORAGE_KEY      = 'om_prop_spots_v1';
  const STORAGE_MAX      = 10_000;   // max spots to persist (safety cap)
  const STORAGE_INTERVAL = 60_000;   // periodic save every 60 s

  function saveToStorage() {
    try {
      const payload = {
        tx: allSpots.slice(0, STORAGE_MAX),
        rx: rxSpots.slice(0, STORAGE_MAX),
      };
      localStorage.setItem(STORAGE_KEY, JSON.stringify(payload));
    } catch (e) {
      console.warn('[mqtt_spots] localStorage save failed:', e.name);
    }
  }

  function loadFromStorage() {
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) return;
      const stored = JSON.parse(raw);
      const cutoff = Date.now() - 6 * 3600 * 1_000;

      // Support both old format (array) and new format ({tx, rx})
      const txArr = Array.isArray(stored) ? stored : (stored.tx || []);
      const rxArr = Array.isArray(stored) ? []     : (stored.rx || []);

      allSpots  = txArr.filter(s => s.ts >= cutoff);
      rxSpots   = rxArr.filter(s => s.ts >= cutoff);
      liveSpots = allSpots.slice(0, CFG.maxLiveRows);

      console.info(`[mqtt_spots] restored ${allSpots.length} TX + ${rxSpots.length} RX spots`);
    } catch (e) {
      console.warn('[mqtt_spots] localStorage load failed:', e.name);
    }
  }

  // ── Prune old spots + refresh all ────────────────────────────────────────
  function refreshAll() {
    const { band, loc } = buildMatrices();
    renderBandMatrix(band);
    renderLocMatrix(loc);
    renderSkMap();
    updateStats();
  }

  function pruneOld() {
    const cutoff = Date.now() - windowSec * 1_000;
    const before = allSpots.length + rxSpots.length;
    allSpots = allSpots.filter(s => s.ts >= cutoff);
    rxSpots  = rxSpots.filter(s => s.ts >= cutoff);
    if (allSpots.length + rxSpots.length !== before) refreshAll();
  }

  // ── Window selector ───────────────────────────────────────────────────────
  function initWindowSelector() {
    const sel = document.getElementById('window-select');
    if (!sel) return;
    // Set initial value matching CFG.windowSec if option exists
    if ([...sel.options].some(o => parseInt(o.value, 10) === CFG.windowSec)) {
      sel.value = String(CFG.windowSec);
    }
    sel.addEventListener('change', () => {
      windowSec = parseInt(sel.value, 10);
      // Update window label in matrix headers
      const lbl = document.getElementById('band-window-label');
      if (lbl) lbl.textContent = sel.options[sel.selectedIndex].text;
      refreshAll();
    });
  }

  // ── MQTT connection ───────────────────────────────────────────────────────
  function connect() {
    if (typeof mqtt === 'undefined') {
      console.error('[mqtt_spots] mqtt.js not loaded');
      setStatus('offline');
      return;
    }

    setStatus('connecting');

    const client = mqtt.connect(CFG.broker, {
      clientId       : 'om-prop-web-' + Math.random().toString(16).slice(2, 10),
      clean          : true,
      reconnectPeriod: 5_000,
    });

    client.on('connect', () => {
      setStatus('live');
      client.subscribe(CFG.topic, { qos: 0 });
    });

    client.on('message', (topic, payload) => {
      const parsed = parseTopic(topic);
      if (!parsed) return;

      const isOmTx = parsed.sender.toUpperCase().startsWith(CFG.prefix);
      const isOmRx = parsed.receiver.toUpperCase().startsWith(CFG.prefix);
      if (!isOmTx && !isOmRx) return;

      const now = Date.now();

      if (isOmTx) {
        // OM as transmitter → full spot for matrices + live table
        const spot = {
          ...parsed,
          snr   : parseSnr(payload),
          dx    : gridDX(parsed.txGrid, parsed.rxGrid),
          ts    : now,
          utcStr: new Date(now).toISOString().slice(11, 19),
        };
        allSpots.unshift(spot);
        liveSpots.unshift(spot);
        if (liveSpots.length > CFG.maxLiveRows) liveSpots.length = CFG.maxLiveRows;
        renderLiveTable();
      }

      if (isOmRx && !isOmTx) {
        // OM as receiver → lightweight entry for map only
        const omGrid = (parsed.rxGrid || '').toUpperCase().slice(0, 4);
        if (SK_SET.has(omGrid)) {   // only store if in Slovakia squares
          rxSpots.unshift({ omGrid, ts: now });
        }
      }

      refreshAll();
    });

    client.on('reconnect', () => setStatus('connecting'));
    client.on('offline',   () => setStatus('offline'));
    client.on('error', err => {
      console.error('[mqtt_spots]', err);
      setStatus('offline');
    });
  }

  // ── Init ──────────────────────────────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', () => {
    initWindowSelector();
    loadFromStorage();            // restore persisted spots before first render
    refreshAll();                 // show restored data immediately (may be non-empty)
    renderLiveTable();
    loadSkBorder();               // async — fetches detailed border from assests/SVK.geo.json
    connect();
    setInterval(pruneOld,      CFG.pruneInterval);
    setInterval(saveToStorage, STORAGE_INTERVAL);
    window.addEventListener('beforeunload', saveToStorage);
  });
})();
