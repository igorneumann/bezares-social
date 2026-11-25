/* Bezares Social MKT — interacción del panel (sin dependencias). */
(function () {
  'use strict';
  var root = document.documentElement;
  root.classList.remove('no-js');
  root.classList.add('js');

  document.addEventListener('click', function (e) {
    var t = e.target;
    // Mostrar / ocultar contraseña
    var pw = t.closest('.pw-toggle');
    if (pw) {
      var input = document.getElementById(pw.getAttribute('data-target'));
      if (input) {
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        pw.textContent = show ? 'Ocultar' : 'Mostrar';
        input.focus();
      }
      return;
    }
    // Ventanas de instrucciones
    var open = t.closest('[data-dialog]');
    if (open) {
      var dlg = document.getElementById(open.getAttribute('data-dialog'));
      if (dlg && dlg.showModal) dlg.showModal();
      return;
    }
    if (t.closest('[data-close]')) {
      var d = t.closest('dialog');
      if (d) d.close();
      return;
    }
    if (t.tagName === 'DIALOG') { // clic en el fondo
      t.close();
      return;
    }
    // Copiar texto
    var cp = t.closest('[data-copy]');
    if (cp) {
      var area = document.getElementById(cp.getAttribute('data-copy'));
      if (area && navigator.clipboard) {
        navigator.clipboard.writeText(area.value).then(function () {
          var old = cp.textContent;
          cp.textContent = '✓ Copiado';
          setTimeout(function () { cp.textContent = old; }, 1500);
        });
      }
      return;
    }
    // Confirmación
    var cf = t.closest('[data-confirm]');
    if (cf && !e.defaultPrevented && !window.confirm(cf.getAttribute('data-confirm'))) {
      e.preventDefault();
    }
  });

  // Indicador de trabajo al enviar formularios largos (generar, publicar, probar…)
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form.hasAttribute('data-busy-form')) return;
    var btn = e.submitter;
    var msg = btn && btn.getAttribute('data-busy');
    if (!msg) return;
    // El botón desactivado no se envía: se copia su valor a un campo oculto.
    if (btn.name) {
      var h = document.createElement('input');
      h.type = 'hidden';
      h.name = btn.name;
      h.value = btn.value;
      form.appendChild(h);
    }
    form.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
    var busy = document.getElementById('busy');
    if (busy) {
      document.getElementById('busy-msg').textContent = msg;
      busy.hidden = false;
    }
  });
  // Al volver con el botón «atrás», ocultar el indicador
  window.addEventListener('pageshow', function (e) {
    if (!e.persisted) return;
    var busy = document.getElementById('busy');
    if (busy) busy.hidden = true;
    document.querySelectorAll('[data-busy-form] button').forEach(function (b) { b.disabled = false; });
    var t = document.getElementById('post_text');
    if (t) t.dispatchEvent(new Event('input'));
  });

  // Selector de periodo: envía al cambiar
  document.querySelectorAll('form[data-autosubmit] select').forEach(function (s) {
    s.addEventListener('change', function () { s.form.submit(); });
  });

  // ¿El texto ya enlaza a alguna página de la misma web? (igual que PostText::links_to_site)
  window.bzsLinksToSite = function (text, url) {
    var host;
    try { host = new URL(url).hostname.replace(/^www\./i, ''); } catch (e) { return false; }
    return new RegExp('https?://(?:www\\.)?' + host.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '/[^\\s/]', 'i').test(text);
  };

  // Contadores de caracteres (X cuenta los enlaces como 23 y los emojis como 2)
  function tweetLength(text) {
    text = text.replace(/https?:\/\/\S+/g, '12345678901234567890123');
    var n = 0;
    for (var ch of text) { n += ch.codePointAt(0) > 0x2FFF ? 2 : 1; }
    return n;
  }
  document.querySelectorAll('textarea[data-count]').forEach(function (ta) {
    var out = document.querySelector('.counter[data-for="' + ta.id + '"]');
    var max = parseInt(ta.getAttribute('data-count'), 10);
    var isTweet = ta.hasAttribute('data-tweet');
    function update() {
      if (!out) return;
      var text = ta.value;
      var n;
      if (isTweet) {
        n = tweetLength(text);
      } else {
        n = Array.from(text).length;
      }
      out.textContent = n + ' / ' + max + ' caracteres';
      out.classList.toggle('over', n > max);
    }
    ta.addEventListener('input', update);
    update();
  });

  // Los botones de publicar se activan cuando hay texto en el post (el tweet solo se copia)
  var pubs = document.querySelectorAll('button[value="publish"], button[value="schedule"]');
  if (pubs.length) {
    var postText = document.getElementById('post_text');
    var check = function () {
      var has = !!postText && postText.value.trim() !== '';
      pubs.forEach(function (b) { b.disabled = !has; });
    };
    if (postText) postText.addEventListener('input', check);
    check();
  }
  // Botones que exigen un campo relleno («Aplicar cambios», «Programar»). El aviso se quita al pulsar
  // cualquier otro botón: si no, el campo vacío bloquearía también «Publicar ahora».
  document.addEventListener('click', function (e) {
    var b = e.target.closest('button');
    if (!b || !b.form) return;
    b.form.querySelectorAll('button[data-requires]').forEach(function (r) {
      var f = document.getElementById(r.getAttribute('data-requires'));
      if (f) f.setCustomValidity('');
    });
    var field = b.hasAttribute('data-requires') && document.getElementById(b.getAttribute('data-requires'));
    if (field && !field.value.trim()) {
      e.preventDefault();
      field.setCustomValidity(b.getAttribute('data-requires-msg') || 'Rellena este campo');
      field.reportValidity();
    }
  }, true);
  document.querySelectorAll('button[data-requires]').forEach(function (r) {
    var f = document.getElementById(r.getAttribute('data-requires'));
    if (f) f.addEventListener('input', function () { f.setCustomValidity(''); });
  });
})();

/* Vista previa estilo LinkedIn: se actualiza mientras se escribe. Se construye con nodos de texto (nunca HTML del usuario). */
(function () {
  'use strict';
  var box = document.getElementById('li-preview');
  if (!box) return;
  var textEl = document.getElementById('lip-text');
  var moreBtn = document.getElementById('lip-more');
  var media = document.getElementById('lip-media');
  var post = document.getElementById('post_text');
  var photo = document.getElementById('photo');
  var link = document.querySelector('input[name="news_url"]');
  var addLink = document.querySelector('input[name="add_link"]');
  var title = box.getAttribute('data-title') || '';
  var expanded = false;

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }

  // Texto con saltos de línea, #hashtags y enlaces en azul como en LinkedIn.
  function renderText(text) {
    textEl.textContent = '';
    if (!text.trim()) {
      textEl.appendChild(el('span', 'lip-empty', 'El texto del post aparecerá aquí cuando lo generes o lo escribas.'));
      return;
    }
    var re = /(https?:\/\/\S+|#[\p{L}\p{N}_]+|\n)/gu;
    var last = 0, m;
    while ((m = re.exec(text))) {
      if (m.index > last) textEl.appendChild(document.createTextNode(text.slice(last, m.index)));
      var tok = m[0];
      if (tok === '\n') textEl.appendChild(document.createElement('br'));
      else textEl.appendChild(el('span', tok[0] === '#' ? 'tag' : 'url', tok));
      last = m.index + tok.length;
    }
    if (last < text.length) textEl.appendChild(document.createTextNode(text.slice(last)));
  }

  function host(u) {
    try { return new URL(u).hostname.replace(/^www\./, ''); } catch (e) { return u; }
  }

  function update() {
    var text = post ? post.value : '';
    var img = photo ? photo.value.trim() : '';
    var url = link ? link.value : '';
    var withLink = addLink && addLink.checked && url;
    var hasImg = /^https:\/\/\S+$/i.test(img);
    // Mismo criterio que al publicar: con foto, el enlace va al final del texto; sin foto, como tarjeta.
    if (withLink && hasImg && !window.bzsLinksToSite(text, url) && text.trim()) text += '\n\n🔗 ' + url;
    renderText(text);

    textEl.classList.toggle('clamp', !expanded);
    moreBtn.hidden = expanded || textEl.scrollHeight <= textEl.clientHeight + 2;

    media.textContent = '';
    if (hasImg) {
      var im = el('img');
      im.alt = '';
      im.src = img;
      media.appendChild(im);
    } else if (withLink) {
      var card = el('div', 'lip-card');
      card.appendChild(el('div', 'lip-card-img', '📰'));
      var info = el('div');
      info.appendChild(el('b', null, title || url));
      info.appendChild(el('span', null, host(url)));
      card.appendChild(info);
      media.appendChild(card);
    }
  }

  moreBtn.addEventListener('click', function () { expanded = true; update(); });
  if (post) post.addEventListener('input', update);
  if (photo) photo.addEventListener('input', update);
  if (addLink) addLink.addEventListener('change', update);
  update();
})();

/* Respuestas y menciones: aviso de las nuevas (menú, pestaña y, si se activa, notificación del navegador) y propuesta con IA. */
(function () {
  'use strict';
  var title = document.title;
  var words = { respuestas: ['respuesta nueva', 'respuestas nuevas'], menciones: ['mención nueva', 'menciones nuevas'] };
  var badges = {};
  document.querySelectorAll('.nav-new[data-new]').forEach(function (b) {
    badges[b.getAttribute('data-new')] = { el: b, latest: parseInt(b.getAttribute('data-latest'), 10) || 0, n: parseInt(b.textContent, 10) || 0 };
  });
  var keys = Object.keys(badges);
  function show() {
    var total = 0;
    keys.forEach(function (k) {
      badges[k].el.textContent = badges[k].n;
      badges[k].el.hidden = !badges[k].n;
      total += badges[k].n;
    });
    document.title = (total ? '(' + total + ') ' : '') + title;
  }
  if (keys.length) {
    show();
    var notify = function (news) {
      var text = news.map(function (k) { return badges[k].n + ' ' + words[k][badges[k].n === 1 ? 0 : 1]; }).join(' y ');
      var href = '/' + news[0] + '?f=nuevas';
      var old = document.querySelector('.toast');
      if (old) old.remove();
      var t = document.createElement('a');
      t.className = 'toast';
      t.href = href;
      t.textContent = '💬 ' + text;
      document.body.appendChild(t);
      // Sin el contenido: la notificación puede verse con la pantalla bloqueada.
      if ('Notification' in window && Notification.permission === 'granted') {
        try {
          var nt = new Notification('Bezares Social MKT', { body: text, tag: 'bzs-nuevas', icon: '/favicon.svg' });
          nt.onclick = function () { window.focus(); location.href = href; };
        } catch (e) { /* algunos navegadores móviles no lo permiten */ }
      }
    };
    var timer = setInterval(function () {
      fetch('/respuestas/estado', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) {
        if (r.status === 401) { clearInterval(timer); return null; } // sesión cerrada
        return r.ok ? r.json() : null;
      }).then(function (d) {
        if (!d) return;
        var news = [];
        keys.forEach(function (k) {
          if (!d[k] || typeof d[k].n !== 'number') return;
          badges[k].n = d[k].n;
          if (d[k].n && d[k].latest > badges[k].latest) news.push(k);
          badges[k].latest = Math.max(badges[k].latest, d[k].latest);
        });
        show();
        if (news.length) notify(news);
      }).catch(function () { /* sin conexión: se reintenta */ });
    }, 60000);
  }

  var on = document.getElementById('notif-on');
  if (on) {
    if (!('Notification' in window) || Notification.permission !== 'default') on.hidden = true;
    on.addEventListener('click', function () {
      Notification.requestPermission().then(function (p) { if (p !== 'default') on.hidden = true; });
    });
  }

  document.addEventListener('click', function (e) {
    var b = e.target.closest('button[data-ai]');
    if (!b) return;
    var area = document.getElementById(b.getAttribute('data-ai'));
    if (!area || (area.value.trim() && !window.confirm('¿Sustituir lo que has escrito por la propuesta de ChatGPT?'))) return;
    var msg = b.parentNode.querySelector('.ai-msg');
    var label = b.textContent;
    var data = new FormData();
    data.append('csrf', b.form.querySelector('input[name=csrf]').value);
    data.append('id', b.form.querySelector('input[name=id]').value);
    data.append('action', 'ai');
    b.disabled = true;
    b.textContent = 'Generando…';
    msg.textContent = '';
    fetch(b.form.getAttribute('action'), { method: 'POST', body: data, credentials: 'same-origin' }).then(function (r) {
      return r.json();
    }).then(function (d) {
      if (d.text) {
        area.value = d.text;
        area.dispatchEvent(new Event('input'));
        area.focus();
      } else {
        msg.textContent = d.error || 'No se ha podido generar.';
      }
    }).catch(function () {
      msg.textContent = 'No se ha podido generar. Recarga la página y vuelve a intentarlo.';
    }).then(function () {
      b.disabled = false;
      b.textContent = label;
    });
  });
})();

/* Imagen con IA: se encarga, se consulta su estado y se pone como foto. */
(function () {
  'use strict';
  var btn = document.getElementById('img-gen');
  if (!btn) return;
  var photo = document.getElementById('photo');
  var msg = document.getElementById('img-msg');
  var form = btn.closest('form');
  var label = btn.textContent;
  var timer = null;
  var run = 0;
  function say(text, bad) {
    msg.textContent = text;
    msg.classList.toggle('worse', !!bad);
  }
  function end(text, bad) {
    clearInterval(timer);
    timer = null;
    run += 1; // las respuestas pendientes de este encargo ya no cuentan
    btn.disabled = false;
    btn.textContent = label;
    say(text, bad);
  }
  btn.addEventListener('click', function () {
    if (timer) return;
    if (photo.value.trim() && !window.confirm('¿Sustituir la foto actual por la imagen nueva?')) return;
    var bytes = new Uint8Array(16);
    window.crypto.getRandomValues(bytes);
    var id = Array.from(bytes).map(function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
    var mine = run;
    var started = Date.now();
    var data = new FormData();
    data.append('id', id);
    ['csrf', 'title', 'source', 'post_text'].forEach(function (k) {
      var f = form.querySelector('[name="' + k + '"]');
      data.append(k, f ? f.value : '');
    });
    btn.disabled = true;
    btn.textContent = 'Generando…';
    say('Generando la imagen… No cierres ni cambies de página.');
    fetch('/imagen', { method: 'POST', body: data, credentials: 'same-origin' }).then(function (r) {
      return r.json();
    }).then(function (d) {
      if (mine === run && d.error) end(d.error, true);
    }).catch(function () { /* respuesta cortada: el estado dirá si sigue en marcha */ });
    timer = setInterval(function () {
      var secs = Math.round((Date.now() - started) / 1000);
      if (secs > 320) { end('La imagen ha tardado demasiado. Vuelve a intentarlo.', true); return; }
      say('Generando la imagen… ' + secs + ' s. No cierres ni cambies de página.');
      fetch('/imagen/estado?id=' + id, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) {
        return r.json();
      }).then(function (d) {
        if (mine !== run) return;
        if (d.status === 'done') {
          photo.value = d.url;
          photo.dispatchEvent(new Event('input'));
          end('✓ Imagen lista: la tienes en la vista previa y en «Enlace de la foto».');
        } else if (d.status === 'error') {
          end(d.error, true);
        } else if (d.status === 'unknown' && secs > 20) {
          end('El servidor no ha recibido el encargo. Recarga la página y vuelve a intentarlo.', true);
        }
      }).catch(function () { /* sin conexión: se reintenta */ });
    }, 3000);
  });
  // Enviar el formulario a mitad perdería la imagen
  form.addEventListener('submit', function (e) {
    if (timer && !window.confirm('La imagen se está generando. Si continúas, no se usará. ¿Continuar?')) {
      e.preventDefault();
      e.stopImmediatePropagation();
    }
  }, true);
})();
