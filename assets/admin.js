/* Dealer editor: draggable pin, hours rows, photo gallery and logo pickers. */
jQuery(function ($) {
  const cfg = window.hwDealerAdmin || {};

  // ---------- Hours ----------
  function syncDay($tr) {
    const open = $tr.find(".hwd-open").prop("checked");
    $tr.find(".hwd-times").prop("hidden", !open).find("input").prop("disabled", !open);
    $tr.find(".hwd-closed").prop("hidden", open);
  }
  $(".hwd-hours tr").each(function () { syncDay($(this)); });
  $(".hwd-hours").on("change", ".hwd-open", function () { syncDay($(this).closest("tr")); });
  $("#hwd-copy-mon").on("click", function () {
    const $mon = $('.hwd-hours tr[data-day="mon"]');
    const open = $mon.find(".hwd-open").prop("checked");
    const times = $mon.find('input[type="time"]').map(function () { return this.value; }).get();
    ["tue", "wed", "thu", "fri"].forEach(day => {
      const $tr = $(`.hwd-hours tr[data-day="${day}"]`);
      $tr.find(".hwd-open").prop("checked", open);
      $tr.find('input[type="time"]').each(function (i) { this.value = times[i]; });
      syncDay($tr);
    });
  });

  // ---------- Gallery ----------
  const $gal = $("#hwd-gallery"), $ids = $("#hwd-gallery-ids"), MAX = 10;
  const syncIds = () => $ids.val($gal.children().map(function () { return $(this).data("id"); }).get().join(","));
  $gal.sortable({ update: syncIds, tolerance: "pointer" });
  $gal.on("click", ".hwd-remove", function () { $(this).closest("li").remove(); syncIds(); });
  let galFrame;
  $("#hwd-add-photos").on("click", function () {
    galFrame ||= wp.media({ title: "Add photos", button: { text: "Add to gallery" }, library: { type: "image" }, multiple: "add" });
    // The frame is reused: start each time with nothing ticked, or photos removed since come back.
    galFrame.off("open").on("open", () => galFrame.state().get("selection").reset());
    galFrame.off("select").on("select", function () {
      let dropped = 0;
      const have = new Set($ids.val().split(",").filter(Boolean).map(Number));
      galFrame.state().get("selection").each(att => {
        const a = att.toJSON();
        if (have.has(a.id)) return;
        if ($gal.children().length >= MAX) { dropped++; return; }
        const src = (a.sizes && (a.sizes.thumbnail || a.sizes.medium) || a).url;
        $gal.append($('<li>').attr("data-id", a.id).data("id", a.id)
          .append($("<img>").attr({ src, alt: "" }))
          .append('<button type="button" class="hwd-remove" aria-label="Remove photo">×</button>'));
      });
      syncIds();
      if (dropped) alert("A dealer can have up to " + MAX + " photos. " + dropped + (dropped === 1 ? " wasn't" : " weren't") + " added.");
    });
    galFrame.open();
  });

  // ---------- Logo ----------
  let logoFrame;
  $("#hwd-pick-logo").on("click", function () {
    logoFrame ||= wp.media({ title: "Dealer logo", button: { text: "Use as logo" }, library: { type: "image" }, multiple: false });
    logoFrame.off("select").on("select", function () {
      const a = logoFrame.state().get("selection").first().toJSON();
      const src = (a.sizes && (a.sizes.medium || a.sizes.thumbnail) || a).url;
      $("#hwd-logo").val(a.id);
      $("#hwd-logo-preview").html($("<img>").attr({ src, alt: "" }).css({ maxWidth: "100%", height: "auto" }));
      $("#hwd-pick-logo").text("Change logo");
      $("#hwd-clear-logo").prop("hidden", false);
    });
    logoFrame.open();
  });
  $("#hwd-clear-logo").on("click", function () {
    $("#hwd-logo").val(""); $("#hwd-logo-preview").empty();
    $("#hwd-pick-logo").text("Choose logo"); $(this).prop("hidden", true);
  });

  // ---------- Map pin ----------
  const mapEl = document.getElementById("hwd-map");
  if (!mapEl) return;
  if (!cfg.key) {
    mapEl.innerHTML = '<p class="hwd-map-msg">Add a Google browser key in Dealers &gt; Settings to see and drag the pin here.</p>';
    return;
  }
  const $lat = $("#hwd-lat"), $lng = $("#hwd-lng"), $manual = $("#hwd-manual");
  function setManual(on) {
    $manual.val(on ? "1" : "");
    $(".hwd-manual-on").prop("hidden", !on);
    $(".hwd-manual-off").prop("hidden", on);
  }

  ((g) => { var h, a, k, p = "The Google Maps JavaScript API", c = "google", l = "importLibrary", q = "__ib__", m = document, b = window; b = b[c] || (b[c] = {}); var d = b.maps || (b.maps = {}), r = new Set, e = new URLSearchParams, u = () => h || (h = new Promise(async (f, n) => { await (a = m.createElement("script")); e.set("libraries", [...r] + ""); for (k in g) e.set(k.replace(/[A-Z]/g, t => "_" + t[0].toLowerCase()), g[k]); e.set("callback", c + ".maps." + q); a.src = `https://maps.${c}apis.com/maps/api/js?` + e; d[q] = f; a.onerror = () => h = n(Error(p + " could not load.")); a.nonce = m.querySelector("script[nonce]")?.nonce || ""; m.head.append(a); })); d[l] ? console.warn(p + " only loads once. Ignoring:", g) : d[l] = (f, ...n) => r.add(f) && u().then(() => d[l](f, ...n)); })({ key: cfg.key, v: "weekly" });

  Promise.all([google.maps.importLibrary("maps"), google.maps.importLibrary("marker")]).then(([{ Map: GMap }, { AdvancedMarkerElement }]) => {
    const has = $lat.val() !== "" && $lng.val() !== "";
    const pos = has ? { lat: +$lat.val(), lng: +$lng.val() } : { lat: cfg.center[0], lng: cfg.center[1] };
    const map = new GMap(mapEl, { center: pos, zoom: has ? 15 : cfg.zoom, mapId: cfg.mapId, streetViewControl: false, mapTypeControl: true, fullscreenControl: false });
    const marker = new AdvancedMarkerElement({ map: has ? map : null, position: pos, gmpDraggable: true, title: "Drag to move the pin" });
    marker.addListener("dragend", () => {
      const p = marker.position;
      $lat.val((+p.lat).toFixed(7)); $lng.val((+p.lng).toFixed(7));
      setManual(true);
    });
    // Without a pin yet, a click on the map places one by hand.
    map.addListener("click", e => {
      if (marker.map) return;
      marker.position = e.latLng; marker.map = map;
      $lat.val(e.latLng.lat().toFixed(7)); $lng.val(e.latLng.lng().toFixed(7));
      setManual(true);
    });
    $("#hwd-reset-pin").on("click", () => {
      setManual(false);
      $(".hwd-manual-off").text("The pin will move to the address when you save.");
    });
  }).catch(err => {
    mapEl.innerHTML = '<p class="hwd-map-msg">The map could not load. Check the browser key in Dealers &gt; Settings.</p>';
    console.error(err);
  });
});

// Ask before trashing or permanently deleting dealers (list screen and editor).
jQuery(function ($) {
  if (!window.hwDealerDelete) return;
  const name = el => $(el).closest("tr").find(".row-title").first().text().trim() || $("#title").val() || "this dealer";
  $(document).on("click", "a.submitdelete", function (e) {
    const permanent = /action=delete\b/.test(this.href);
    const msg = permanent
      ? `Permanently delete "${name(this)}"? This can't be undone.`
      : `Are you sure you want to delete "${name(this)}"?\n\nIt goes to the trash, where an administrator can restore it.`;
    if (!confirm(msg)) e.preventDefault();
  });
  $(document).on("click", "#delete_all", function (e) {
    if (!confirm("Permanently delete every dealer in the trash? This can't be undone.")) e.preventDefault();
  });
  $(document).on("submit", "#posts-filter", function (e) {
    const action = $(this).find('select[name="action"]').val() !== "-1" ? $(this).find('select[name="action"]').val() : $(this).find('select[name="action2"]').val();
    if (action !== "trash" && action !== "delete") return;
    const names = $(this).find('input[name="post[]"]:checked').map(function () { return $(this).closest("tr").find(".row-title").first().text().trim(); }).get();
    if (!names.length) return;
    const list = names.length > 8 ? names.slice(0, 8).join("\n") + `\n…and ${names.length - 8} more` : names.join("\n");
    const msg = action === "delete"
      ? `Permanently delete these ${names.length} dealers? This can't be undone.\n\n${list}`
      : `Are you sure you want to delete these ${names.length} dealers?\n\n${list}`;
    if (!confirm(msg)) e.preventDefault();
  });
});
