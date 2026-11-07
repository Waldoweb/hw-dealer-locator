/*
 * HW Dealer Locator finder.
 * Data and settings come from the JSON in #hwdl-data (see includes/shortcode.php).
 * All dealers arrive at once; distance sorting and filtering happen here.
 */
(() => {
  const root = document.getElementById("hwdl");
  let cfg = null;
  try { cfg = JSON.parse(document.getElementById("hwdl-data").textContent); } catch (e) {}
  if (!cfg || !root) return;

  const DEALERS = cfg.dealers || [];
  const L = cfg.labels;
  const UNIT = cfg.units === "km" ? "km" : "mi";
  const RADII = [...cfg.radii, "any"];
  const LABEL_ZOOM = 10;
  const state = { origin: null, radius: cfg.radii.includes(cfg.radius) ? cfg.radius : cfg.radii[1] || cfg.radii[0], selectedId: null, hotId: null };

  const $ = id => document.getElementById(id);
  const byId = new Map(DEALERS.map(d => [d.id, d]));

  // ---------- Helpers ----------
  const esc = s => String(s ?? "").replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  const icon = p => `<svg class="hwdl-i" viewBox="0 0 24 24" aria-hidden="true">${p}</svg>`;
  const IC = {
    dir: '<path d="M12 2 22 12 12 22 2 12z"/><path d="M9 14v-2.5a1 1 0 0 1 1-1h5M13 8.5l2 2-2 2"/>',
    phone: '<path d="M5 3h3l2 5-2.5 1.5a11 11 0 0 0 7 7L16 14l5 2v3a2 2 0 0 1-2 2A17 17 0 0 1 3 5a2 2 0 0 1 2-2z"/>',
    globe: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
    copy: '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V5a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h3"/>',
    pin: '<path d="M12 21s-7-6.2-7-12a7 7 0 0 1 14 0c0 5.8-7 12-7 12z"/><circle cx="12" cy="9" r="2.5"/>',
    clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    box: '<path d="M3 7l9-4 9 4-9 4-9-4z"/><path d="M3 7v10l9 4 9-4V7"/>',
    note: '<path d="M4 4h16v12H8l-4 4z"/>',
    search: '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>'
  };
  const STAR = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2.5l2.9 6 6.6.8-4.9 4.6 1.3 6.5L12 17.2 6.1 20.4l1.3-6.5L2.5 9.3l6.6-.8z"/></svg>';
  const initials = n => n.replace(/&/g, "").split(/\s+/).filter(w => /^[\p{L}\p{N}]/u.test(w)).slice(0, 2).map(w => w[0]).join("").toUpperCase();
  const count = n => `${n} ${n === 1 ? L.one : L.many}`;

  const distance = (a, b) => {
    const r = Math.PI / 180, dLat = (b.lat - a.lat) * r, dLng = (b.lng - a.lng) * r;
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(a.lat * r) * Math.cos(b.lat * r) * Math.sin(dLng / 2) ** 2;
    return (UNIT === "km" ? 6371 : 3958.8) * 2 * Math.asin(Math.sqrt(h));
  };
  const fmtDist = x => `${x < 10 ? x.toFixed(1) : Math.round(x)} ${UNIT}`;
  const toMeters = r => r * (UNIT === "km" ? 1000 : 1609.344);

  const address = d => [d.addr, d.city, [d.st, d.zip].filter(Boolean).join(" ")].filter(Boolean).join(", ");
  const dirUrl = d => `https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(address(d) || `${d.lat},${d.lng}`)}`;

  // ---------- Hours ----------
  const DAYS = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];
  const fmtT = m => { const h = Math.floor(m / 60) % 24, mm = m % 60, ap = h >= 12 ? "PM" : "AM", h12 = h % 12 || 12; return mm ? `${h12}:${String(mm).padStart(2, "0")} ${ap}` : `${h12} ${ap}`; };
  const fmtCache = new Map();
  // Day of week and minutes past midnight at the dealer, in its own time zone.
  function localNow(tz) {
    let f = fmtCache.get(tz);
    if (f === undefined) {
      try { f = new Intl.DateTimeFormat("en-US", { timeZone: tz, weekday: "short", hour: "numeric", minute: "numeric", hourCycle: "h23" }); }
      catch (e) { f = null; }
      fmtCache.set(tz, f);
    }
    const now = new Date();
    if (!f) return { day: now.getDay(), m: now.getHours() * 60 + now.getMinutes() };
    const p = Object.fromEntries(f.formatToParts(now).map(x => [x.type, x.value]));
    return { day: ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"].indexOf(p.weekday), m: (+p.hour % 24) * 60 + +p.minute };
  }
  function status(d) {
    if (!d.hours) return null;
    const { day, m } = localNow(d.tz), t = d.hours[day];
    if (t && m >= t[0] && m < t[1]) return { open: true, text: `Closes ${fmtT(t[1])}`, day };
    for (let i = 0; i < 8; i++) {
      const dd = (day + i) % 7, h = d.hours[dd];
      if (!h || (i === 0 && m >= h[0])) continue;
      const when = i === 0 ? fmtT(h[0]) : i === 1 ? `${fmtT(h[0])} tomorrow` : `${fmtT(h[0])} ${DAYS[dd].slice(0, 3)}`;
      return { open: false, text: `Opens ${when}`, day };
    }
    return { open: false, text: "", day };
  }
  const statusHTML = s => s ? `<span class="${s.open ? "hwdl-open" : "hwdl-closed"}">${s.open ? "Open" : "Closed"}</span>${s.text ? ` · ${esc(s.text)}` : ""}` : "";

  // ---------- Toast / copy ----------
  let toastTimer;
  function toast(msg) { const t = $("hwdl-toast"); t.textContent = msg; t.hidden = false; clearTimeout(toastTimer); toastTimer = setTimeout(() => { t.hidden = true; }, 4200); }
  async function copy(text, msg) {
    try { await navigator.clipboard.writeText(text); toast(msg); } catch (e) { toast(text); }
  }

  // ---------- Results ----------
  function results() {
    let list = DEALERS.map(d => ({ d, dist: state.origin ? distance(state.origin, d) : null }));
    if (state.origin) {
      const all = list.length;
      if (state.radius !== "any") list = list.filter(r => r.dist <= state.radius);
      list.sort((a, b) => a.dist - b.dist);
      list.beyond = all - list.length;
    } else {
      list.sort((a, b) => (a.d.st || "").localeCompare(b.d.st || "") || a.d.name.localeCompare(b.d.name));
    }
    return list;
  }

  function renderRadius() {
    const rr = $("hwdl-radius");
    rr.hidden = !state.origin;
    if (!state.origin) return;
    rr.innerHTML = `<span class="hwdl-radius-label" id="hwdl-radius-label">Within</span><div class="hwdl-seg" role="group" aria-labelledby="hwdl-radius-label">` + RADII.map(r =>
      `<button type="button" class="hwdl-chip" data-r="${r}" aria-pressed="${state.radius === r}">${r === "any" ? "Any Distance" : `${r} ${UNIT}`}</button>`).join("") + `</div>`;
  }

  function renderList(res = results()) {
    const n = res.length, o = state.origin, list = $("hwdl-list");
    $("hwdl-count").textContent = o && state.radius !== "any" ? `${count(n)} within ${state.radius} ${UNIT}` : count(n);
    if (!n) {
      if (!o) { list.innerHTML = `<li class="hwdl-empty"><p>No ${esc(L.many)} to show yet.</p></li>`; return; }
      const next = RADII.find(r => r !== "any" && r > state.radius) || "any";
      const closest = Math.min(...DEALERS.map(d => distance(o, d)));
      list.innerHTML = `<li class="hwdl-empty"><h3>No ${esc(L.many)} within ${state.radius} ${UNIT}</h3>
        <p>The closest one is ${fmtDist(closest)} from ${esc(o.label)}.</p>
        <button type="button" class="hwdl-btn" data-r="${next}">Search ${next === "any" ? "any distance" : `${next} ${UNIT}`}</button></li>`;
      return;
    }
    list.innerHTML = res.map(({ d, dist }) => {
      const s = status(d);
      const meta = [esc([d.city, d.st].filter(Boolean).join(", ")), d.note ? esc(d.note) : statusHTML(s)].filter(Boolean).join(" · ");
      return `<li class="hwdl-item${d.id === state.selectedId ? " is-selected" : ""}${d.id === state.hotId ? " is-hot" : ""}" data-id="${d.id}">
        <div>
          <button type="button" class="hwdl-item-title" data-open="${d.id}">${esc(d.name)}</button>${d.top ? `<span class="hwdl-badge">${STAR}${esc(L.top)}</span>` : ""}
          <div class="hwdl-item-meta">${meta}</div>
          ${d.show ? `<span class="hwdl-ptag">${esc(L.display)}</span>` : ""}
        </div>
        <div class="hwdl-item-side">
          ${dist != null ? `<span class="hwdl-dist">${fmtDist(dist)}</span>` : ""}
          <a class="hwdl-icon-btn" href="${esc(dirUrl(d))}" target="_blank" rel="noopener" aria-label="Directions to ${esc(d.name)}" title="Directions">${icon(IC.dir)}</a>
        </div>
      </li>`;
    }).join("");
    if (o && res.beyond && state.radius !== "any") {
      list.insertAdjacentHTML("beforeend", `<li class="hwdl-empty"><p>${count(res.beyond)} farther than ${state.radius} ${UNIT}.</p><button type="button" class="hwdl-btn ghost" data-r="any">Show all distances</button></li>`);
    }
  }

  // ---------- Map ----------
  let map, clusterer, circle, meMarker, AdvancedMarker, LatLngBounds;
  const markers = new Map();

  function loadGoogle() {
    // Google's dynamic library loader (https://developers.google.com/maps/documentation/javascript/load-maps-js-api).
    if (window.google?.maps?.importLibrary) return Promise.resolve();
    ((g) => { var h, a, k, p = "The Google Maps JavaScript API", c = "google", l = "importLibrary", q = "__ib__", m = document, b = window; b = b[c] || (b[c] = {}); var d = b.maps || (b.maps = {}), r = new Set, e = new URLSearchParams, u = () => h || (h = new Promise(async (f, n) => { await (a = m.createElement("script")); e.set("libraries", [...r] + ""); for (k in g) e.set(k.replace(/[A-Z]/g, t => "_" + t[0].toLowerCase()), g[k]); e.set("callback", c + ".maps." + q); a.src = `https://maps.${c}apis.com/maps/api/js?` + e; d[q] = f; a.onerror = () => h = n(Error(p + " could not load.")); a.nonce = m.querySelector("script[nonce]")?.nonce || ""; m.head.append(a); })); d[l] ? console.warn(p + " only loads once. Ignoring:", g) : d[l] = (f, ...n) => r.add(f) && u().then(() => d[l](f, ...n)); })({ key: cfg.key, v: "weekly" });
    return Promise.resolve();
  }

  function pinElement(d) {
    const el = document.createElement("div");
    el.className = "hwdl-pin";
    el.dataset.id = d.id;
    const hole = d.top
      ? '<path class="hole" d="M14 7.2l1.9 3.9 4.3.5-3.2 3 .8 4.2-3.8-2.1-3.8 2.1.8-4.2-3.2-3 4.3-.5z"/>'
      : '<circle class="hole" cx="14" cy="13.5" r="4.6"/>';
    el.innerHTML = `<svg viewBox="0 0 28 38" aria-hidden="true"><path class="body" d="M14 1C7 1 1.5 6.4 1.5 13.3 1.5 22.6 14 37 14 37s12.5-14.4 12.5-23.7C26.5 6.4 21 1 14 1z"/>${hole}</svg><span class="hwdl-pin-label">${esc(d.name)}</span>`;
    el.addEventListener("mouseenter", () => setHot(d.id));
    el.addEventListener("mouseleave", () => setHot(null));
    return el;
  }

  async function initMap() {
    const mapEl = $("hwdl-map");
    if (!cfg.key) { mapEl.innerHTML = `<div class="hwdl-map-error">The map needs a Google browser key (Dealers &gt; Settings).</div>`; return; }
    try {
      await loadGoogle();
      const [{ Map: GMap, Circle }, marker, core] = await Promise.all([
        google.maps.importLibrary("maps"), google.maps.importLibrary("marker"), google.maps.importLibrary("core")
      ]);
      AdvancedMarker = marker.AdvancedMarkerElement;
      LatLngBounds = core.LatLngBounds;
      map = new GMap(mapEl, {
        center: { lat: cfg.center[0], lng: cfg.center[1] }, zoom: cfg.zoom, mapId: cfg.mapId,
        // A mouse wheel zooms on computers; touch screens need two fingers, so a swipe still scrolls the page.
        gestureHandling: matchMedia("(pointer: fine)").matches ? "greedy" : "cooperative", streetViewControl: false, mapTypeControl: false, fullscreenControl: false,
        clickableIcons: false
      });
      circle = new Circle({ map: null, clickable: false, strokeOpacity: .6, strokeWeight: 1.5, fillOpacity: .07 });

      for (const d of DEALERS) {
        const m = new AdvancedMarker({ position: { lat: d.lat, lng: d.lng }, content: pinElement(d), title: d.name, gmpClickable: true, zIndex: d.top ? 2 : 1 });
        m.addEventListener("gmp-click", () => openDetail(d.id, { pan: false }));
        markers.set(d.id, m);
      }
      clusterer = new markerClusterer.MarkerClusterer({
        map,
        markers: [...markers.values()],
        algorithmOptions: { maxZoom: LABEL_ZOOM + 1 },
        onClusterClick: (e, cluster, m) => m.fitBounds(cluster.bounds, 60),
        renderer: {
          render: ({ count: n, position }) => {
            const el = document.createElement("div");
            el.className = "hwdl-cluster";
            el.textContent = n;
            return new AdvancedMarker({ position, content: el, title: `${count(n)}. Zoom in.`, zIndex: 1000 + n });
          }
        }
      });
      map.addListener("zoom_changed", () => root.classList.toggle("show-labels", map.getZoom() >= LABEL_ZOOM));
      applyAccent();
      syncMap(true);
      const want = +new URLSearchParams(location.search).get("dealer");
      if (want && byId.has(want)) openDetail(want);
    } catch (e) {
      console.error(e);
      mapEl.innerHTML = `<div class="hwdl-map-error">The map could not load. The list still works.</div>`;
    }
  }

  function applyAccent() {
    if (!circle) return;
    const accent = getComputedStyle(root).getPropertyValue("--hwdl-accent").trim() || "#1c4bad";
    circle.setOptions({ strokeColor: accent, fillColor: accent });
  }

  // Show only the markers in the current results, draw the radius and "you are here".
  function syncMap(fit) {
    if (!map) return;
    const res = results();
    clusterer.clearMarkers(true);
    clusterer.addMarkers(res.map(r => markers.get(r.d.id)), true);
    clusterer.render();
    const o = state.origin;
    if (o && state.radius !== "any") { circle.setCenter(o); circle.setRadius(toMeters(state.radius)); circle.setMap(map); }
    else circle.setMap(null);
    if (o) {
      if (!meMarker) { const el = document.createElement("div"); el.className = "hwdl-me"; meMarker = new AdvancedMarker({ content: el, zIndex: 3000, title: o.label }); }
      meMarker.position = o; meMarker.map = map;
    } else if (meMarker) meMarker.map = null;
    if (fit) fitResults(res);
  }

  function fitResults(res = results()) {
    if (!map) return;
    const b = new LatLngBounds();
    res.forEach(r => b.extend(r.d));
    const o = state.origin;
    if (o) {
      b.extend(o);
      if (state.radius !== "any" && circle.getBounds()) b.union(circle.getBounds());
    }
    if (b.isEmpty()) { map.setCenter({ lat: cfg.center[0], lng: cfg.center[1] }); map.setZoom(cfg.zoom); return; }
    if (res.length === 1 && !o) { map.setCenter(res[0].d); map.setZoom(12); return; }
    map.fitBounds(b, 48);
  }

  function markSelected() {
    markers.forEach((m, id) => {
      m.content.classList.toggle("is-selected", id === state.selectedId);
      m.zIndex = id === state.selectedId ? 2000 : byId.get(id).top ? 2 : 1;
    });
  }

  function setHot(id) {
    state.hotId = id;
    root.querySelectorAll(".hwdl-item").forEach(li => li.classList.toggle("is-hot", +li.dataset.id === id));
    markers.forEach((m, mid) => m.content.classList.toggle("is-hot", mid === id));
  }

  // ---------- Details ----------
  function detailHTML(d) {
    const dist = state.origin ? distance(state.origin, d) : null, s = status(d);
    const sub = [d.top ? `<span class="hwdl-badge">${STAR}${esc(L.top)}</span>` : esc(L.regular)];
    if (dist != null) sub.push(`${fmtDist(dist)} from ${esc(state.origin.label)}`);
    const acts = [`<a class="hwdl-act primary" href="${esc(dirUrl(d))}" target="_blank" rel="noopener"><span class="circ">${icon(IC.dir)}</span>Directions</a>`];
    if (d.phone) acts.push(`<a class="hwdl-act" href="tel:${esc(d.phone.replace(/[^\d+]/g, ""))}" data-act="call" data-id="${d.id}"><span class="circ">${icon(IC.phone)}</span>Call</a>`);
    if (d.web) acts.push(`<a class="hwdl-act" href="${esc(d.web)}" target="_blank" rel="noopener"><span class="circ">${icon(IC.globe)}</span>Website</a>`);
    acts.push(`<button type="button" class="hwdl-act" data-act="copy" data-id="${d.id}"><span class="circ">${icon(IC.copy)}</span>Copy address</button>`);

    let hours = "";
    if (d.note) hours = `<div class="hwdl-fact">${icon(IC.clock)}<div>${esc(d.note)}</div></div>`;
    else if (d.hours) {
      const order = [1, 2, 3, 4, 5, 6, 0];
      hours = `<div class="hwdl-fact">${icon(IC.clock)}<div><span class="${s.open ? "hwdl-open" : "hwdl-closed"}">${s.open ? "Open now" : "Closed now"}</span>
        <table class="hwdl-hours">${order.map(i => `<tr class="${i === s.day ? "today" : ""}"><td>${DAYS[i]}</td><td>${d.hours[i] ? `${fmtT(d.hours[i][0])} – ${fmtT(d.hours[i][1])}` : "Closed"}</td></tr>`).join("")}</table></div></div>`;
    }

    return `<div class="hwdl-detail">
      <div class="hwdl-detail-head">
        <div class="hwdl-avatar" aria-hidden="true">${esc(initials(d.name))}</div>
        <div>
          <h2 id="hwdl-detail-title">${esc(d.name)}</h2>
          <p class="hwdl-sub">${sub.join(" · ")}</p>
          ${s ? `<p class="hwdl-sub">${statusHTML(s)}</p>` : ""}
        </div>
      </div>
      ${d.photos ? `<div class="hwdl-gallery">${d.photos.map((p, i) => `<button type="button" class="hwdl-thumb" data-photo="${i}" aria-label="Photo ${i + 1} of ${d.photos.length}"><img src="${esc(p.t)}" alt="${esc(p.alt)}" loading="lazy"></button>`).join("")}</div>` : ""}
      <div class="hwdl-actions" style="--n:${acts.length}">${acts.join("")}</div>
      <div class="hwdl-facts">
        ${address(d) ? `<div class="hwdl-fact">${icon(IC.pin)}<div>${esc(address(d))}</div></div>` : ""}
        ${d.phone ? `<div class="hwdl-fact">${icon(IC.phone)}<div><a href="tel:${esc(d.phone.replace(/[^\d+]/g, ""))}">${esc(d.phone)}</a></div></div>` : ""}
        ${hours}
        ${d.show ? `<div class="hwdl-fact">${icon(IC.box)}<div><span class="hwdl-ptag">${esc(L.display)}</span></div></div>` : ""}
        ${d.notes ? `<div class="hwdl-fact">${icon(IC.note)}<p class="hwdl-notes">${esc(d.notes)}</p></div>` : ""}
      </div>
      ${d.url ? `<a class="hwdl-more" href="${esc(d.url)}">More about ${esc(d.name)} →</a>` : ""}
    </div>`;
  }

  const dialog = $("hwdl-dialog");
  const phone = matchMedia("(max-width: 760px)");
  let current = null;
  function openDetail(id, { pan = true } = {}) {
    const d = byId.get(id);
    if (!d) return;
    current = d;
    state.selectedId = id;
    $("hwdl-dialog-body").innerHTML = detailHTML(d);
    if (!dialog.open) phone.matches ? dialog.showModal() : dialog.show();
    dialog.scrollTop = 0;
    renderList();
    markSelected();
    if (map) showBesideCard(d, pan);
  }
  // On desktop the card covers the map's left side: keep the dealer's pin in view to its right.
  // A pin that was clicked stays put unless the card would hide it.
  function showBesideCard(d, force) {
    if (map.getZoom() < 9) { map.setZoom(9); force = true; }
    const card = phone.matches ? null : dialog.getBoundingClientRect();
    if (!force && card) {
      const pin = markers.get(d.id).content.getBoundingClientRect(), view = $("hwdl-map").getBoundingClientRect();
      const visible = pin.width && pin.left > card.right + 8 && pin.right < view.right && pin.top > view.top && pin.bottom < view.bottom;
      if (visible) return;
    }
    map.panTo(d);
    if (card) map.panBy(-(card.width + 12) / 2, 0);
  }
  // A non-modal dialog doesn't close on Escape by itself.
  root.addEventListener("keydown", e => { if (e.key === "Escape" && dialog.open && !phone.matches) dialog.close(); });
  function onDialogClosed() {
    const had = state.selectedId;
    state.selectedId = null;
    current = null;
    renderList();
    markSelected();
    if (had) root.querySelector(`[data-open="${had}"]`)?.focus({ preventScroll: true });
  }
  dialog.addEventListener("close", onDialogClosed);
  dialog.addEventListener("click", e => { if (e.target === dialog) dialog.close(); });
  $("hwdl-dialog-close").addEventListener("click", () => dialog.close());
  dialog.addEventListener("click", e => {
    const a = e.target.closest("[data-act]");
    const ph = e.target.closest("[data-photo]");
    if (ph && current) { openPhoto(current, +ph.dataset.photo); return; }
    if (!a || !current) return;
    if (a.dataset.act === "copy") copy(address(current), "Address copied.");
    // On a computer a tel: link usually does nothing useful, so copy the number instead.
    if (a.dataset.act === "call" && matchMedia("(hover: hover) and (pointer: fine)").matches) {
      e.preventDefault();
      copy(current.phone, `Copied ${current.phone}.`);
    }
  });

  // ---------- Lightbox ----------
  const lb = $("hwdl-lightbox"), lbImg = $("hwdl-lb-img");
  let lbDealer = null, lbIndex = 0;
  function showPhoto(i) {
    const ps = lbDealer.photos, p = ps[(i + ps.length) % ps.length];
    lbIndex = (i + ps.length) % ps.length;
    lbImg.src = p.f; lbImg.alt = p.alt || "";
    $("hwdl-lb-cap").textContent = `${lbDealer.name} · ${lbIndex + 1} of ${ps.length}`;
    $("hwdl-lb-prev").hidden = $("hwdl-lb-next").hidden = ps.length < 2;
  }
  function openPhoto(d, i) { lbDealer = d; showPhoto(i); lb.showModal(); }
  $("hwdl-lb-prev").addEventListener("click", () => showPhoto(lbIndex - 1));
  $("hwdl-lb-next").addEventListener("click", () => showPhoto(lbIndex + 1));
  $("hwdl-lb-close").addEventListener("click", () => lb.close());
  lb.addEventListener("click", e => { if (e.target === lb || e.target.tagName === "FIGURE") lb.close(); });
  lb.addEventListener("keydown", e => {
    if (e.key === "ArrowLeft") showPhoto(lbIndex - 1);
    if (e.key === "ArrowRight") showPhoto(lbIndex + 1);
  });
  let swipeX = null;
  lb.addEventListener("pointerdown", e => { swipeX = e.clientX; });
  lb.addEventListener("pointerup", e => {
    if (swipeX == null) return;
    const dx = e.clientX - swipeX;
    swipeX = null;
    if (Math.abs(dx) > 50 && lbDealer.photos.length > 1) showPhoto(lbIndex + (dx < 0 ? 1 : -1));
  });

  // ---------- Search ----------
  const q = $("hwdl-q"), sug = $("hwdl-suggest");
  let options = [], active = -1, places = null, session = null, seq = 0, timer;

  async function placesLib() {
    if (places !== null) return places;
    try { places = await google.maps.importLibrary("places"); } catch (e) { places = false; }
    return places;
  }

  async function fetchPlaces(text) {
    const lib = map && await placesLib();
    if (!lib) return [];
    session ||= new lib.AutocompleteSessionToken();
    try {
      const { suggestions } = await lib.AutocompleteSuggestion.fetchAutocompleteSuggestions({
        input: text, sessionToken: session, includedRegionCodes: cfg.countries, includedPrimaryTypes: ["(regions)"]
      });
      return suggestions.filter(s => s.placePrediction).slice(0, 5).map(s => ({ type: "place", p: s.placePrediction }));
    } catch (e) {
      console.warn("Places autocomplete failed", e);
      return [];
    }
  }

  function dealerMatches(v) {
    const words = v.split(/\s+/).filter(Boolean);
    return DEALERS.filter(d => { const n = d.name.toLowerCase(); return words.every(w => n.includes(w)); })
      .slice(0, 4).map(d => ({ type: "dealer", d }));
  }

  function suggest() {
    const v = q.value.trim().toLowerCase();
    $("hwdl-clear").hidden = !q.value;
    clearTimeout(timer);
    if (!v) { closeSuggest(); return; }
    const mine = ++seq;
    const dealers = dealerMatches(v);
    renderSuggest([], dealers, true);
    timer = setTimeout(async () => {
      const pl = v.length >= 2 ? await fetchPlaces(q.value.trim()) : [];
      if (mine === seq) renderSuggest(pl, dealers, false);
    }, 180);
  }

  function renderSuggest(pl, dealers, pending) {
    options = [...pl, ...dealers];
    if (!options.length) {
      if (pending) return;
      sug.innerHTML = `<li class="grp" role="presentation">No matches. Try a city, state or ZIP code.</li>`;
      sug.hidden = false; q.setAttribute("aria-expanded", "true"); active = -1;
      return;
    }
    active = 0;
    let html = "", i = 0;
    if (pl.length) html += `<li class="grp" role="presentation">Places</li>` + pl.map(o => `<li class="opt" role="option" id="hwdl-opt${i}" data-i="${i++}">${icon(IC.pin)}<span>${esc(o.p.mainText?.toString() || o.p.text.toString())}</span><small>${esc(o.p.secondaryText?.toString() || "")}</small></li>`).join("");
    if (dealers.length) html += `<li class="grp" role="presentation">${esc(L.many.charAt(0).toUpperCase() + L.many.slice(1))}</li>` + dealers.map(o => `<li class="opt" role="option" id="hwdl-opt${i}" data-i="${i++}">${icon(IC.search)}<span>${esc(o.d.name)}</span><small>${esc([o.d.city, o.d.st].filter(Boolean).join(", "))}</small></li>`).join("");
    if (pl.length) html += `<li class="powered" role="presentation">Powered by Google</li>`;
    sug.innerHTML = html; sug.hidden = false; q.setAttribute("aria-expanded", "true"); markActive();
  }
  function markActive() {
    sug.querySelectorAll(".opt").forEach(o => o.setAttribute("aria-selected", String(+o.dataset.i === active)));
    if (active >= 0) q.setAttribute("aria-activedescendant", `hwdl-opt${active}`); else q.removeAttribute("aria-activedescendant");
  }
  function closeSuggest() { sug.hidden = true; q.setAttribute("aria-expanded", "false"); active = -1; markActive(); }

  async function choose(o) {
    closeSuggest();
    if (!o) return;
    if (o.type === "dealer") { q.value = ""; $("hwdl-clear").hidden = true; openDetail(o.d.id); return; }
    try {
      const place = o.p.toPlace();
      await place.fetchFields({ fields: ["location", "displayName", "formattedAddress"] });
      session = null; // A session ends when a place is picked.
      const label = o.p.mainText?.toString() ? [o.p.mainText.toString(), shortRegion(o.p.secondaryText?.toString())].filter(Boolean).join(", ") : place.formattedAddress;
      setOrigin({ lat: place.location.lat(), lng: place.location.lng(), label });
    } catch (e) {
      console.warn(e);
      toast("That place could not be looked up. Try another search.");
    }
  }
  // "OH, USA" => "OH"; "Ohio, USA" => "Ohio".
  const shortRegion = s => (s || "").replace(/,?\s*(USA|United States|Canada)$/i, "").split(",").pop().trim();

  q.addEventListener("input", suggest);
  q.addEventListener("focus", () => { if (q.value.trim() && sug.hidden && !state.origin) suggest(); });
  q.addEventListener("keydown", e => {
    if (e.key === "ArrowDown" && options.length) { if (sug.hidden) suggest(); active = (active + 1) % options.length; markActive(); e.preventDefault(); }
    else if (e.key === "ArrowUp" && options.length) { active = (active - 1 + options.length) % options.length; markActive(); e.preventDefault(); }
    else if (e.key === "Enter") { e.preventDefault(); if (!sug.hidden && options.length) choose(options[active] || options[0]); }
    else if (e.key === "Escape") closeSuggest();
  });
  sug.addEventListener("mousedown", e => { const o = e.target.closest(".opt"); if (o) { e.preventDefault(); choose(options[+o.dataset.i]); } });
  q.addEventListener("blur", () => setTimeout(closeSuggest, 150));

  function setOrigin(o) {
    state.origin = o;
    q.value = o.label;
    $("hwdl-clear").hidden = false;
    if (dialog.open) dialog.close();
    renderRadius(); renderList(); syncMap(true);
    $("hwdl-results").scrollTop = 0;
  }
  $("hwdl-clear").addEventListener("click", () => {
    state.origin = null; q.value = ""; $("hwdl-clear").hidden = true; closeSuggest();
    renderRadius(); renderList(); syncMap(true); q.focus();
  });
  $("hwdl-locate").addEventListener("click", () => {
    const btn = $("hwdl-locate");
    if (!navigator.geolocation) { toast("This browser can't share its location. Search for a city or ZIP instead."); return; }
    btn.classList.add("is-busy");
    navigator.geolocation.getCurrentPosition(
      pos => { btn.classList.remove("is-busy"); setOrigin({ lat: pos.coords.latitude, lng: pos.coords.longitude, label: "your location" }); q.value = "Your location"; },
      err => { btn.classList.remove("is-busy"); toast(err.code === 1 ? "Location is turned off for this site. Search for a city or ZIP instead." : "Your location couldn't be found. Search for a city or ZIP instead."); },
      { enableHighAccuracy: false, timeout: 12000, maximumAge: 600000 }
    );
  });

  // ---------- List and radius events ----------
  root.addEventListener("click", e => {
    const r = e.target.closest("[data-r]");
    if (r) { state.radius = r.dataset.r === "any" ? "any" : +r.dataset.r; renderRadius(); renderList(); syncMap(true); }
  });
  $("hwdl-list").addEventListener("click", e => {
    if (e.target.closest("a")) return;
    const li = e.target.closest(".hwdl-item");
    if (li) openDetail(+li.dataset.id);
  });
  $("hwdl-list").addEventListener("mouseover", e => { const li = e.target.closest(".hwdl-item"); if (li && +li.dataset.id !== state.hotId) setHot(+li.dataset.id); });
  $("hwdl-list").addEventListener("mouseleave", () => setHot(null));
  $("hwdl-fit").addEventListener("click", () => fitResults());

  renderRadius();
  renderList();
  // Load the map once the finder is near the screen, so pages that put it low don't pay for it upfront.
  if ("IntersectionObserver" in window) {
    const io = new IntersectionObserver(es => { if (es.some(x => x.isIntersecting)) { io.disconnect(); initMap(); } }, { rootMargin: "400px" });
    io.observe(root);
  } else initMap();
})();
