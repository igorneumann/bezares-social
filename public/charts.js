/*
 * Gráficos propios, sin librerías (la política de seguridad solo permite scripts de la propia web).
 * Cada gráfico es un elemento con data-chart (JSON) que la página escribe en el servidor:
 *   { "type": "line" | "bar" | "stack" | "spark",
 *     "labels": ["1/11", "2/11", …],
 *     "series": [{ "name": "Este periodo", "data": [3, 5, …], "color": 1, "dash": false }, …],
 *     "fmt": "int" | "pct" | "eur" | "dur",       formato de las cifras (por defecto, enteros)
 *     "ratio": [1, 0, "Apertura"],                 opcional: en el tooltip, serie 1 entre serie 0
 *     "label": "Texto para lectores de pantalla" }
 * Colores: --ch1 … --ch8 (categorías en orden fijo), --ch-m (comparación), --ch-grid, --ch-axis y --ch-bg.
 * Al pasar el ratón (o tocar) se ven las cifras de cada punto; debajo va una tabla oculta para lectores de pantalla.
 */
(function () {
  'use strict';
  var NS = 'http://www.w3.org/2000/svg';
  var nf = new Intl.NumberFormat('es-ES');

  function cssVar(el, name, def) {
    var v = getComputedStyle(el).getPropertyValue(name).trim();
    return v || def;
  }
  function fmt(v, f) {
    if (v === null || v === undefined || isNaN(v)) return '—';
    if (f === 'pct') return nf.format(Math.round(v * 10) / 10) + '%';
    if (f === 'eur') return nf.format(Math.round(v)) + ' €';
    if (f === 'dur') {
      var s = Math.round(v);
      if (s < 60) return s + ' s';
      if (s < 3600) return Math.round(s / 60) + ' min';
      if (s < 172800) return nf.format(Math.round(s / 360) / 10) + ' h';
      return nf.format(Math.round(s / 8640) / 10) + ' días';
    }
    return nf.format(Math.round(v));
  }
  function short(v, f) { // eje Y: 1.200 → 1,2 mil
    if (f === 'pct' || f === 'dur') return fmt(v, f);
    var a = Math.abs(v);
    if (a >= 1e6) return nf.format(Math.round(v / 1e5) / 10) + ' M';
    if (a >= 1e4) return nf.format(Math.round(v / 100) / 10) + ' mil';
    return fmt(v, f === 'eur' ? 'int' : f) + (f === 'eur' ? ' €' : '');
  }
  function niceMax(max) { // techo «redondo» y 4 divisiones
    if (max <= 0) return { max: 4, step: 1 };
    var raw = max / 4, p = Math.pow(10, Math.floor(Math.log10(raw))), m = raw / p;
    var step = (m <= 1 ? 1 : m <= 2 ? 2 : m <= 2.5 ? 2.5 : m <= 5 ? 5 : 10) * p;
    if (step < 1 && max > 4) step = 1;
    return { max: step * Math.ceil(max / step), step: step };
  }
  function el(name, attrs, parent) {
    var e = document.createElementNS(NS, name);
    for (var k in attrs) e.setAttribute(k, attrs[k]);
    if (parent) parent.appendChild(e);
    return e;
  }
  function color(box, s, i) {
    if (s.color === 'muted') return cssVar(box, '--ch-m', '#898781');
    var n = typeof s.color === 'number' ? s.color : i + 1;
    return cssVar(box, '--ch' + n, ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948'][(n - 1) % 8]);
  }
  function roundTop(x, y, w, h, r) { // barra con las esquinas de arriba redondeadas (4 px) y la base recta
    r = Math.min(r, w / 2, h);
    if (h <= 0) return '';
    return 'M' + x + ',' + (y + h) + 'V' + (y + r) + 'Q' + x + ',' + y + ' ' + (x + r) + ',' + y + 'H' + (x + w - r) + 'Q' + (x + w) + ',' + y + ' ' + (x + w) + ',' + (y + r) + 'V' + (y + h) + 'Z';
  }

  function render(box) {
    var d;
    try { d = JSON.parse(box.getAttribute('data-chart')); } catch (e) { return; }
    var labels = d.labels || [], series = (d.series || []).filter(function (s) { return s && s.data; });
    var type = d.type || 'line', f = d.fmt || 'int', spark = type === 'spark';
    box.innerHTML = '';
    box.classList.add('chx');
    if (!series.length || !labels.length) { box.innerHTML = '<p class="chx-empty">Sin datos en este periodo.</p>'; return; }

    // Leyenda (solo con dos o más series)
    if (!spark && series.length > 1) {
      var lg = document.createElement('div');
      lg.className = 'chx-legend';
      series.forEach(function (s, i) {
        var it = document.createElement('span');
        var sw = document.createElement('i');
        sw.className = type === 'line' ? 'ln' + (s.dash ? ' dash' : '') : 'sq';
        sw.style.background = color(box, s, i);
        if (s.dash) sw.style.background = 'repeating-linear-gradient(90deg,' + color(box, s, i) + ' 0 5px,transparent 5px 8px)';
        it.appendChild(sw);
        it.appendChild(document.createTextNode(s.name || ''));
        lg.appendChild(it);
      });
      box.appendChild(lg);
    }

    var W = Math.max(120, box.clientWidth), H = +box.getAttribute('data-h') || (spark ? 34 : 200);
    var n = labels.length;
    var maxV = 0;
    if (type === 'stack') {
      for (var j = 0; j < n; j++) {
        var t = 0;
        series.forEach(function (s) { t += +s.data[j] || 0; });
        maxV = Math.max(maxV, t);
      }
    } else {
      series.forEach(function (s) { s.data.forEach(function (v) { maxV = Math.max(maxV, +v || 0); }); });
    }
    var scale = spark ? { max: maxV || 1, step: 0 } : niceMax(maxV);
    var padL = spark ? 1 : Math.max(26, short(scale.max, f).length * 6.6 + 10), padR = spark ? 1 : 8, padT = spark ? 3 : 8, padB = spark ? 3 : 22;
    var iw = W - padL - padR, ih = H - padT - padB;
    var svg = el('svg', { width: W, height: H, viewBox: '0 0 ' + W + ' ' + H, role: 'img', 'aria-label': d.label || '' }, null);
    var grid = cssVar(box, '--ch-grid', '#e1e0d9'), axis = cssVar(box, '--ch-axis', '#c3c2b7'), muted = cssVar(box, '--ch-m', '#898781'), bg = cssVar(box, '--ch-bg', '#fff');
    var y = function (v) { return padT + ih - (v / scale.max) * ih; };
    var band = iw / n;
    var xc = function (i) { return type === 'line' || spark ? padL + (n === 1 ? iw / 2 : i * iw / (n - 1)) : padL + band * i + band / 2; };

    if (!spark) {
      // Rejilla y eje Y
      for (var v = 0; v <= scale.max + 1e-9; v += scale.step) {
        el('line', { x1: padL, x2: W - padR, y1: y(v), y2: y(v), stroke: v === 0 ? axis : grid, 'stroke-width': 1 }, svg);
        var tl = el('text', { x: padL - 6, y: y(v) + 3.5, 'text-anchor': 'end', fill: muted, 'font-size': 11 }, svg);
        tl.textContent = short(v, f);
      }
      // Eje X: como mucho unas 8 etiquetas
      var every = Math.max(1, Math.ceil(n / Math.max(2, Math.floor(iw / 64))));
      labels.forEach(function (l, i) {
        // cada «every» etiquetas; la última solo si no queda pegada a la anterior
        if (i % every !== 0 && (i !== n - 1 || (n - 1) % every < every * 0.6)) return;
        var tx = el('text', { x: xc(i), y: H - 6, 'text-anchor': 'middle', fill: muted, 'font-size': 11 }, svg);
        tx.textContent = l;
      });
    }

    // Marcas
    if (type === 'line' || spark) {
      series.forEach(function (s, i) {
        var pts = s.data.map(function (v, k) { return xc(k).toFixed(1) + ',' + y(+v || 0).toFixed(1); }).join(' ');
        el('polyline', { points: pts, fill: 'none', stroke: color(box, s, i), 'stroke-width': 2, 'stroke-linejoin': 'round', 'stroke-linecap': 'round', 'stroke-dasharray': s.dash ? '5 4' : 'none' }, svg);
      });
    } else if (type === 'bar') {
      var gw = Math.min(band * 0.72, 18 * series.length + 4), bw = Math.max(2, (gw - 2 * (series.length - 1)) / series.length);
      series.forEach(function (s, i) {
        s.data.forEach(function (v, k) {
          var h = ih - (y(+v || 0) - padT), x0 = padL + band * k + (band - gw) / 2 + i * (bw + 2);
          el('path', { d: roundTop(x0, y(+v || 0), bw, h, 4), fill: color(box, s, i) }, svg);
        });
      });
    } else if (type === 'stack') {
      var sw2 = Math.min(band * 0.6, 34);
      for (var k = 0; k < n; k++) {
        var acc = 0, last = -1;
        series.forEach(function (s, i) { if (+s.data[k]) last = i; });
        series.forEach(function (s, i) {
          var v2 = +s.data[k] || 0;
          if (!v2) return;
          var top = y(acc + v2), h2 = y(acc) - top, x1 = padL + band * k + (band - sw2) / 2;
          var r = el('path', { d: i === last ? roundTop(x1, top, sw2, h2, 4) : 'M' + x1 + ',' + top + 'h' + sw2 + 'v' + h2 + 'h' + -sw2 + 'Z', fill: color(box, s, i) }, svg);
          if (acc > 0) el('line', { x1: x1, x2: x1 + sw2, y1: y(acc), y2: y(acc), stroke: bg, 'stroke-width': 2 }, svg); // separación de 2 px
          acc += v2;
          r.setAttribute('data-k', k);
        });
      }
    }
    box.appendChild(svg);
    if (spark) return;

    // Capa de lectura: línea vertical (o banda) y tooltip con las cifras del punto
    var cross = el('line', { y1: padT, y2: padT + ih, stroke: axis, 'stroke-width': 1, visibility: 'hidden' }, svg);
    var dots = series.map(function (s, i) { return type === 'line' ? el('circle', { r: 4, fill: color(box, s, i), stroke: bg, 'stroke-width': 2, visibility: 'hidden' }, svg) : null; });
    var hl = el('rect', { y: padT, height: ih, fill: axis, opacity: 0.18, visibility: 'hidden' }, svg);
    svg.insertBefore(hl, svg.firstChild);
    var tip = document.createElement('div');
    tip.className = 'chx-tip';
    tip.hidden = true;
    box.appendChild(tip);
    var hit = el('rect', { x: padL, y: padT, width: iw, height: ih, fill: 'transparent' }, svg);
    function show(evt) {
      var r = svg.getBoundingClientRect(), px = (evt.touches ? evt.touches[0].clientX : evt.clientX) - r.left;
      var i = type === 'line' ? Math.round((px - padL) / (n === 1 ? 1 : iw / (n - 1))) : Math.floor((px - padL) / band);
      i = Math.max(0, Math.min(n - 1, i));
      var x = xc(i);
      if (type === 'line') {
        cross.setAttribute('x1', x); cross.setAttribute('x2', x); cross.setAttribute('visibility', 'visible');
        dots.forEach(function (c, k) { c.setAttribute('cx', x); c.setAttribute('cy', y(+series[k].data[i] || 0)); c.setAttribute('visibility', 'visible'); });
      } else {
        hl.setAttribute('x', padL + band * i); hl.setAttribute('width', band); hl.setAttribute('visibility', 'visible');
      }
      var html = '<b>' + esc(labels[i]) + '</b>', tot = 0;
      series.forEach(function (s, k) {
        tot += +s.data[i] || 0;
        html += '<span><i style="background:' + color(box, s, k) + '"></i>' + esc(s.name || '') + ' <em>' + fmt(s.data[i], f) + '</em></span>';
      });
      if (type === 'stack' && series.length > 1) html += '<span class="foot">Total <em>' + fmt(tot, f) + '</em></span>';
      if (d.ratio) {
        var a = +series[d.ratio[0]].data[i] || 0, b = +series[d.ratio[1]].data[i] || 0;
        html += '<span class="foot">' + esc(d.ratio[2] || '') + ' <em>' + (b ? fmt(a / b * 100, 'pct') : '—') + '</em></span>';
      }
      tip.innerHTML = html;
      tip.hidden = false;
      var tw = tip.offsetWidth, left = x + 12 + tw > W ? x - tw - 12 : x + 12;
      tip.style.left = Math.max(0, left) + 'px';
      tip.style.top = (box.querySelector('.chx-legend') ? box.querySelector('.chx-legend').offsetHeight : 0) + padT + 'px';
    }
    function hide() {
      tip.hidden = true;
      cross.setAttribute('visibility', 'hidden');
      hl.setAttribute('visibility', 'hidden');
      dots.forEach(function (c) { if (c) c.setAttribute('visibility', 'hidden'); });
    }
    hit.addEventListener('mousemove', show);
    hit.addEventListener('touchstart', show, { passive: true });
    hit.addEventListener('mouseleave', hide);

    // Tabla con las mismas cifras, para lectores de pantalla
    var tb = '<table class="chx-sr"><caption>' + esc(d.label || '') + '</caption><thead><tr><th></th>' + series.map(function (s) { return '<th>' + esc(s.name || '') + '</th>'; }).join('') + '</tr></thead><tbody>';
    labels.forEach(function (l, i) { tb += '<tr><th>' + esc(l) + '</th>' + series.map(function (s) { return '<td>' + fmt(s.data[i], f) + '</td>'; }).join('') + '</tr>'; });
    box.insertAdjacentHTML('beforeend', tb + '</tbody></table>');
  }
  function esc(s) {
    return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; });
  }

  function all() { document.querySelectorAll('[data-chart]').forEach(render); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', all); else all();
  // Se vuelven a dibujar al cambiar el ancho (y al cambiar el tema claro/oscuro)
  var timer = 0, lastW = window.innerWidth;
  window.addEventListener('resize', function () {
    if (window.innerWidth === lastW) return;
    lastW = window.innerWidth;
    clearTimeout(timer);
    timer = setTimeout(all, 150);
  });
  if (window.MutationObserver) {
    new MutationObserver(function () { clearTimeout(timer); timer = setTimeout(all, 50); }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
  }
})();
