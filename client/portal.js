/*
 * Support portal widgets for public websites. Served by PortalController (which prepends `var APP_PORTAL = {...}` with
 * the site's branding), so one script serves every project. No dependencies, no build step, no cookies.
 *
 * Embed (the script may sit anywhere, mounts run on DOMContentLoaded):
 *   <div data-support-portal></div>     sign in with email + password: tickets, replies, new ticket
 *   <div data-contact-form></div>       contact form for visitors
 *   <div data-feedback-form></div>      quick feedback form (bug / feature / praise)
 *   <script src="/portal/widget.js" defer></script>
 *
 * Tokens live in sessionStorage (cleared when the tab closes). All text is set with textContent, never innerHTML.
 */
(function () {
  'use strict';
  var C = window.APP_PORTAL || {};
  var API = (C.apiBase || '/api/v1').replace(/\/$/, '');
  var KEY = 'app-portal-session';

  var STATUS = { open: 'Open', in_progress: 'In progress', planned: 'Planned', resolved: 'Resolved', closed: 'Closed' };
  var TYPES = { bug: 'Bug report', feature: 'Feature request', feedback: 'Feedback' };

  // ---------------------------------------------------------------- helpers
  function h(tag, attrs) {
    var el = document.createElement(tag);
    attrs = attrs || {};
    Object.keys(attrs).forEach(function (k) {
      var v = attrs[k];
      if (v === false || v == null) return;
      if (k === 'class') el.className = v;
      else if (k.slice(0, 2) === 'on') el.addEventListener(k.slice(2), v);
      else el.setAttribute(k, v === true ? '' : v);
    });
    for (var i = 2; i < arguments.length; i++) append(el, arguments[i]);
    return el;
  }
  function append(el, c) {
    if (c == null || c === false) return;
    if (Array.isArray(c)) c.forEach(function (x) { append(el, x); });
    else el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
  }
  function clear(el) { while (el.firstChild) el.removeChild(el.firstChild); }
  function fmt(iso) {
    try { return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }); } catch (e) { return iso || ''; }
  }

  var seq = 0;
  function field(label, input, hint) {
    var id = 'ap-f' + ++seq;
    input.id = id;
    return h('div', { class: 'ap-field' }, h('label', { for: id }, label), input, hint ? h('small', {}, hint) : null);
  }

  // ---------------------------------------------------------------- styles
  function injectStyles() {
    if (document.getElementById('ap-styles')) return;
    var brand = C.brandColor || '#2f4c63';
    var text = C.brandColorText || '#ffffff';
    var css = [
      '.ap{--ap-brand:' + brand + ';--ap-on-brand:' + text + ';--ap-bg:#fff;--ap-fg:#1b2127;--ap-muted:#5d6975;--ap-line:#dbe1e6;--ap-soft:#f4f6f8;--ap-bad:#b3261e;--ap-ok:#1e7a46;',
      'font:16px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:var(--ap-fg);background:var(--ap-bg);border:1px solid var(--ap-line);border-radius:14px;padding:24px;max-width:640px;margin:0 auto;box-sizing:border-box}',
      '@media (prefers-color-scheme:dark){.ap.ap-auto{--ap-bg:#171c21;--ap-fg:#eef1f4;--ap-muted:#9aa6b2;--ap-line:#2b343c;--ap-soft:#1f262c;--ap-bad:#ff8a80;--ap-ok:#6fd39a}}',
      '.ap *{box-sizing:border-box}.ap h3{margin:0 0 4px;font-size:1.25rem}.ap p{margin:0 0 12px}.ap .ap-muted{color:var(--ap-muted);font-size:.9rem}',
      '.ap-brand{display:flex;align-items:center;gap:10px;margin-bottom:16px}.ap-brand img{height:32px;width:auto;border-radius:6px}',
      '.ap-field{margin:0 0 14px}.ap-field label{display:block;font-weight:600;margin-bottom:4px;font-size:.92rem}.ap-field small{display:block;color:var(--ap-muted);margin-top:4px}',
      '.ap input,.ap select,.ap textarea{width:100%;font:inherit;color:inherit;background:var(--ap-bg);border:1px solid var(--ap-line);border-radius:8px;padding:10px 12px}',
      '.ap textarea{min-height:120px;resize:vertical}.ap input:focus,.ap select:focus,.ap textarea:focus{outline:2px solid var(--ap-brand);outline-offset:1px}',
      '.ap input[type=checkbox]{width:auto;margin-right:8px}.ap .ap-check{display:flex;align-items:flex-start;font-size:.92rem}',
      '.ap-btn{font:inherit;font-weight:600;cursor:pointer;border:1px solid var(--ap-brand);background:var(--ap-brand);color:var(--ap-on-brand);border-radius:8px;padding:10px 18px}',
      '.ap-btn:disabled{opacity:.6;cursor:default}.ap-btn.ap-ghost{background:transparent;color:var(--ap-fg);border-color:var(--ap-line)}',
      '.ap-row{display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between;margin-bottom:14px}',
      '.ap-msg{border-radius:8px;padding:10px 12px;margin:0 0 14px;font-size:.92rem}.ap-msg.bad{background:color-mix(in srgb,var(--ap-bad) 12%,transparent);color:var(--ap-bad)}.ap-msg.ok{background:color-mix(in srgb,var(--ap-ok) 12%,transparent);color:var(--ap-ok)}',
      '.ap-list{list-style:none;margin:0;padding:0;border:1px solid var(--ap-line);border-radius:10px;overflow:hidden}',
      '.ap-list li+li{border-top:1px solid var(--ap-line)}.ap-item{display:block;width:100%;text-align:left;font:inherit;color:inherit;background:transparent;border:0;padding:12px 14px;cursor:pointer}',
      '.ap-item:hover{background:var(--ap-soft)}.ap-item strong{display:block}.ap-item span{color:var(--ap-muted);font-size:.85rem}',
      '.ap-pill{display:inline-block;border-radius:999px;padding:1px 10px;font-size:.78rem;font-weight:600;background:var(--ap-soft);border:1px solid var(--ap-line);margin-left:8px}.ap-pill.wait{background:var(--ap-brand);color:var(--ap-on-brand);border-color:var(--ap-brand)}',
      '.ap-thread{display:flex;flex-direction:column;gap:10px;margin:14px 0}.ap-bubble{max-width:88%;padding:10px 12px;border-radius:12px;background:var(--ap-soft);white-space:pre-wrap;word-wrap:break-word}',
      '.ap-bubble.you{align-self:flex-end;background:var(--ap-brand);color:var(--ap-on-brand)}.ap-bubble small{display:block;opacity:.75;margin-top:4px;font-size:.75rem}',
      '.ap-hp{position:absolute!important;left:-9999px!important;height:0;overflow:hidden}',
      '.ap-tabs{display:flex;gap:6px;margin-bottom:12px}.ap-tabs .ap-btn{padding:6px 14px}.ap-tabs .ap-btn[aria-pressed=false]{background:transparent;color:var(--ap-fg);border-color:var(--ap-line)}',
    ].join('');
    var style = document.createElement('style');
    style.id = 'ap-styles';
    style.textContent = css;
    document.head.appendChild(style);
  }

  // ---------------------------------------------------------------- api
  function request(method, path, body, token) {
    var headers = { Accept: 'application/json' };
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    if (token) headers.Authorization = 'Bearer ' + token;
    return fetch(API + path, { method: method, headers: headers, body: body === undefined ? undefined : JSON.stringify(body), credentials: 'omit' })
      .then(function (res) {
        return res.text().then(function (txt) {
          var data = null;
          try { data = txt ? JSON.parse(txt) : null; } catch (e) { /* not JSON */ }
          if (res.ok) return data;
          var err = new Error((data && (data.detail || data.title)) || 'Something went wrong (' + res.status + ').');
          err.status = res.status;
          err.code = data && data.code;
          err.fields = data && data.errors;
          throw err;
        });
      });
  }

  function fieldErrors(err) {
    if (!err.fields) return err.message;
    var parts = [];
    Object.keys(err.fields).forEach(function (k) { parts.push([].concat(err.fields[k]).join(' ')); });
    return parts.length ? parts.join(' ') : err.message;
  }

  // ---------------------------------------------------------------- turnstile
  var turnstileLoad;
  function loadTurnstile() {
    if (!C.turnstileSiteKey) return Promise.resolve(null);
    if (window.turnstile) return Promise.resolve(window.turnstile);
    if (!turnstileLoad) {
      turnstileLoad = new Promise(function (resolve) {
        var s = document.createElement('script');
        s.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
        s.async = true;
        s.onload = function () { resolve(window.turnstile); };
        s.onerror = function () { resolve(null); };
        document.head.appendChild(s);
      });
    }
    return turnstileLoad;
  }
  /** Returns { el, token() , reset() }; token() is '' until solved (or when there is no site key). */
  function captcha() {
    var el = h('div', { class: 'ap-field' });
    var state = { token: '', id: null };
    loadTurnstile().then(function (t) {
      if (!t) return;
      state.id = t.render(el, {
        sitekey: C.turnstileSiteKey,
        callback: function (v) { state.token = v; },
        'expired-callback': function () { state.token = ''; },
      });
    });
    return {
      el: el,
      token: function () { return state.token; },
      reset: function () { state.token = ''; if (window.turnstile && state.id != null) window.turnstile.reset(state.id); },
    };
  }

  // ---------------------------------------------------------------- shell
  function shell(root, title, lead) {
    injectStyles();
    clear(root);
    var box = h('div', { class: 'ap ap-auto' });
    var brand = h('div', { class: 'ap-brand' }, C.logoUrl ? h('img', { src: C.logoUrl, alt: '' }) : null, h('div', {}, h('h3', {}, title), lead ? h('div', { class: 'ap-muted' }, lead) : null));
    box.appendChild(brand);
    root.appendChild(box);
    return box;
  }
  function banner(kind, text) { return h('div', { class: 'ap-msg ' + kind, role: kind === 'bad' ? 'alert' : 'status' }, text); }
  function withBusy(btn, label, fn) {
    var original = btn.textContent;
    btn.disabled = true;
    btn.textContent = label;
    return fn().then(function (r) { btn.disabled = false; btn.textContent = original; return r; }, function (e) { btn.disabled = false; btn.textContent = original; throw e; });
  }

  // ---------------------------------------------------------------- contact + feedback
  function mountContact(root) { mountPublicForm(root, 'contact'); }
  function mountFeedback(root) { mountPublicForm(root, 'feedback'); }

  function mountPublicForm(root, kind) {
    var isFeedback = kind === 'feedback';
    var box = shell(root, isFeedback ? 'Send feedback' : 'Contact ' + (C.name || 'us'), isFeedback ? 'Tell us what is working, what is broken, or what you would like to see.' : 'We reply by email.');
    var notice = h('div');
    var name = h('input', { type: 'text', autocomplete: 'name', required: true, maxlength: 255 });
    var email = h('input', { type: 'email', autocomplete: 'email', required: true });
    var type = h('select');
    [['praise', 'Praise'], ['bug', 'Something is broken'], ['feature', 'Feature request']].forEach(function (o) { type.appendChild(h('option', { value: o[0] }, o[1])); });
    var subject = h('input', { type: 'text', maxlength: isFeedback ? 200 : 255, required: !isFeedback });
    var message = h('textarea', { required: true, maxlength: 10000 });
    var consent = h('input', { type: 'checkbox', required: true });
    var trap = h('input', { type: 'text', tabindex: '-1', autocomplete: 'off', 'aria-hidden': 'true' });
    var cap = captcha();
    var submit = h('button', { class: 'ap-btn', type: 'submit' }, 'Send');

    var form = h('form', { novalidate: true },
      field('Your name', name), field('Email', email),
      isFeedback ? field('Type', type) : null,
      field(isFeedback ? 'Subject (optional)' : 'Subject', subject),
      field('Message', message),
      h('div', { class: 'ap-hp' }, trap),
      h('label', { class: 'ap-check ap-field' }, consent, h('span', {}, 'I agree to ' + (C.name || 'the team') + ' storing my details to reply to this message.', C.privacyUrl ? [' ', h('a', { href: C.privacyUrl }, 'Privacy policy')] : null)),
      cap.el,
      h('div', {}, submit));
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      clear(notice);
      if (trap.value) return; // honeypot
      if (!form.checkValidity()) { form.reportValidity(); return; }
      if (C.turnstileSiteKey && !cap.token()) { notice.appendChild(banner('bad', 'Please complete the verification check.')); return; }
      var body = {
        name: name.value.trim(), email: email.value.trim(), message: message.value.trim(),
        pageUrl: location.href.slice(0, 2048), gdprConsent: true, turnstileToken: cap.token() || undefined,
      };
      if (isFeedback) { body.type = type.value; if (subject.value.trim()) body.subject = subject.value.trim(); } else { body.subject = subject.value.trim(); }
      withBusy(submit, 'Sending…', function () { return request('POST', isFeedback ? '/feedback' : '/contact', body); }).then(function () {
        clear(box);
        box.appendChild(h('h3', {}, 'Thank you'));
        box.appendChild(h('p', {}, isFeedback ? 'Your feedback has been sent.' : 'Your message has been sent. We will reply by email.'));
      }, function (e) { cap.reset(); notice.appendChild(banner('bad', fieldErrors(e))); });
    });
    box.appendChild(notice);
    box.appendChild(form);
  }

  // ---------------------------------------------------------------- portal
  function mountPortal(root) {
    var session = null;
    try { session = JSON.parse(sessionStorage.getItem(KEY) || 'null'); } catch (e) { session = null; }
    var view = { name: 'list', filter: 'open', id: null };

    function save(s) { session = s; try { if (s) sessionStorage.setItem(KEY, JSON.stringify(s)); else sessionStorage.removeItem(KEY); } catch (e) { /* storage blocked */ } }
    function signOut() {
      var s = session;
      save(null);
      if (s) request('POST', '/auth/sign-out', { refreshToken: s.refreshToken }, s.accessToken).catch(function () {});
      render();
    }

    /** Runs an authed call; on 401 tries the refresh token once, then falls back to the sign-in screen. */
    function authed(method, path, body) {
      return request(method, path, body, session && session.accessToken).catch(function (e) {
        if (e.status !== 401 || !session) throw e;
        return request('POST', '/auth/refresh', { refreshToken: session.refreshToken }).then(function (r) {
          save(r.session || r);
          return request(method, path, body, session.accessToken);
        }).catch(function () { save(null); render(); throw e; });
      });
    }

    function render() {
      if (!session) return signIn();
      if (view.name === 'new') return newTicket();
      if (view.name === 'ticket') return ticket();
      return list();
    }

    // sign in ------------------------------------------------
    function signIn(mfaToken, notice) {
      var box = shell(root, 'Your support requests', 'Sign in with your ' + (C.name || '') + ' account to see your tickets and replies.');
      var out = h('div');
      if (notice) out.appendChild(banner('ok', notice));
      var email = h('input', { type: 'email', autocomplete: 'username', required: true });
      var password = h('input', { type: 'password', autocomplete: 'current-password', required: true });
      var code = h('input', { type: 'text', inputmode: 'numeric', autocomplete: 'one-time-code', required: true, maxlength: 20 });
      var cap = mfaToken ? null : captcha();
      var submit = h('button', { class: 'ap-btn', type: 'submit' }, mfaToken ? 'Verify' : 'Sign in');
      var form = h('form', { novalidate: true },
        mfaToken ? field('Authenticator code', code, 'Or one of your recovery codes.') : [field('Email', email), field('Password', password), cap.el],
        h('div', {}, submit),
        mfaToken ? null : h('p', { class: 'ap-muted', style: 'margin-top:14px' }, 'Forgot your password? Use the reset link in the ' + (C.name || '') + ' app.'));
      form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        clear(out);
        if (!form.checkValidity()) { form.reportValidity(); return; }
        if (!mfaToken && C.turnstileSiteKey && !cap.token()) { out.appendChild(banner('bad', 'Please complete the verification check.')); return; }
        var call = mfaToken
          ? request('POST', '/auth/mfa/verify', { mfaToken: mfaToken, code: code.value.trim() })
          : request('POST', '/auth/sign-in', { email: email.value.trim(), password: password.value, captchaToken: cap.token() || undefined });
        withBusy(submit, 'Signing in…', function () { return call; }).then(function (r) {
          if (r.mfaRequired) return signIn(r.mfaToken);
          if (r.confirmationRequired) return signIn(null, 'Confirm your email address first, then sign in.');
          save(r.session);
          view = { name: 'list', filter: 'open', id: null };
          render();
        }, function (e) { if (cap) cap.reset(); out.appendChild(banner('bad', e.code === 'invalid_credentials' ? 'That email or password is not right.' : fieldErrors(e))); });
      });
      box.appendChild(out);
      box.appendChild(form);
    }

    function topBar(box, title, actions) {
      box.appendChild(h('div', { class: 'ap-row' }, h('strong', {}, title), h('div', {}, actions)));
    }

    // list ---------------------------------------------------
    function list() {
      var box = shell(root, 'Your support requests');
      var body = h('div');
      var tabs = h('div', { class: 'ap-tabs' },
        ['open', 'closed'].map(function (f) {
          return h('button', { class: 'ap-btn', type: 'button', 'aria-pressed': String(view.filter === f), onclick: function () { view.filter = f; list(); } }, f === 'open' ? 'Open' : 'Closed');
        }));
      topBar(box, '', [
        h('button', { class: 'ap-btn', type: 'button', onclick: function () { view.name = 'new'; render(); } }, 'New request'), ' ',
        h('button', { class: 'ap-btn ap-ghost', type: 'button', onclick: signOut }, 'Sign out'),
      ]);
      box.appendChild(tabs);
      box.appendChild(body);
      body.appendChild(h('p', { class: 'ap-muted' }, 'Loading…'));
      authed('GET', '/tickets?status=' + view.filter).then(function (r) {
        clear(body);
        var items = r.tickets || [];
        if (!items.length) { body.appendChild(h('p', { class: 'ap-muted' }, view.filter === 'open' ? 'You have no open requests.' : 'No closed requests.')); return; }
        body.appendChild(h('ul', { class: 'ap-list' }, items.map(function (t) {
          return h('li', {}, h('button', { class: 'ap-item', type: 'button', onclick: function () { view = { name: 'ticket', filter: view.filter, id: t.id }; render(); } },
            h('strong', {}, '#' + t.number + ' ' + t.title, h('span', { class: 'ap-pill' + (t.awaitingSupport ? ' wait' : '') }, t.awaitingSupport ? 'Awaiting reply' : (STATUS[t.status] || t.status))),
            h('span', {}, (TYPES[t.type] || t.type) + ' · updated ' + fmt(t.updatedAt))));
        })));
      }, function (e) { clear(body); body.appendChild(banner('bad', e.message)); });
    }

    // ticket -------------------------------------------------
    function ticket() {
      var box = shell(root, 'Request');
      var body = h('div');
      box.appendChild(body);
      body.appendChild(h('p', { class: 'ap-muted' }, 'Loading…'));
      function show(t) {
        clear(body);
        var active = t.status !== 'resolved' && t.status !== 'closed';
        var note = h('textarea', { maxlength: 5000, required: true, 'aria-label': 'Add a note' });
        var send = h('button', { class: 'ap-btn', type: 'submit' }, active ? 'Send' : 'Send and reopen');
        var out = h('div');
        var toggle = h('button', { class: 'ap-btn ap-ghost', type: 'button' }, active ? 'Close request' : 'Reopen');
        toggle.addEventListener('click', function () {
          withBusy(toggle, '…', function () { return authed('POST', '/tickets/' + t.id + '/status', { status: active ? 'closed' : 'open' }); }).then(show, function (e) { out.appendChild(banner('bad', e.message)); });
        });
        body.appendChild(h('div', { class: 'ap-row' },
          h('button', { class: 'ap-btn ap-ghost', type: 'button', onclick: function () { view.name = 'list'; render(); } }, '← All requests'), toggle));
        body.appendChild(h('h3', {}, '#' + t.number + ' ' + t.title, h('span', { class: 'ap-pill' }, STATUS[t.status] || t.status)));
        body.appendChild(h('div', { class: 'ap-muted' }, (TYPES[t.type] || t.type) + ' · opened ' + fmt(t.createdAt)));
        var thread = h('div', { class: 'ap-thread' }, h('div', { class: 'ap-bubble you' }, t.description, h('small', {}, 'You · ' + fmt(t.createdAt))));
        (t.messages || []).forEach(function (m) {
          thread.appendChild(h('div', { class: 'ap-bubble' + (m.from === 'you' ? ' you' : '') }, m.body, h('small', {}, (m.from === 'you' ? 'You' : (C.name || 'Support')) + ' · ' + fmt(m.createdAt))));
        });
        body.appendChild(thread);
        var form = h('form', {}, field('Add a note', note), out, h('div', {}, send));
        form.addEventListener('submit', function (ev) {
          ev.preventDefault();
          clear(out);
          if (!note.value.trim()) return;
          withBusy(send, 'Sending…', function () { return authed('POST', '/tickets/' + t.id + '/messages', { body: note.value.trim() }); }).then(show, function (e) { out.appendChild(banner('bad', fieldErrors(e))); });
        });
        body.appendChild(form);
      }
      authed('GET', '/tickets/' + view.id).then(show, function (e) { clear(body); body.appendChild(banner('bad', e.message)); });
    }

    // new ticket ---------------------------------------------
    function newTicket() {
      var box = shell(root, 'New request');
      var out = h('div');
      var type = h('select', {}, Object.keys(TYPES).map(function (k) { return h('option', { value: k }, TYPES[k]); }));
      var title = h('input', { type: 'text', required: true, maxlength: 150, minlength: 3 });
      var desc = h('textarea', { required: true, maxlength: 5000, minlength: 10 });
      var submit = h('button', { class: 'ap-btn', type: 'submit' }, 'Send request');
      var form = h('form', { novalidate: true }, field('Type', type), field('Title', title), field('Details', desc, 'The more detail, the faster we can help.'), out,
        h('div', { class: 'ap-row' }, h('button', { class: 'ap-btn ap-ghost', type: 'button', onclick: function () { view.name = 'list'; render(); } }, 'Cancel'), submit));
      form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        clear(out);
        if (!form.checkValidity()) { form.reportValidity(); return; }
        withBusy(submit, 'Sending…', function () { return authed('POST', '/tickets', { type: type.value, title: title.value.trim(), description: desc.value.trim(), platform: 'web' }); })
          .then(function (t) { view = { name: 'ticket', filter: 'open', id: t.id }; render(); }, function (e) { out.appendChild(banner('bad', fieldErrors(e))); });
      });
      box.appendChild(form);
    }

    render();
  }

  // ---------------------------------------------------------------- boot
  function boot() {
    [['[data-support-portal]', mountPortal], ['[data-contact-form]', mountContact], ['[data-feedback-form]', mountFeedback]].forEach(function (p) {
      var nodes = document.querySelectorAll(p[0]);
      for (var i = 0; i < nodes.length; i++) if (!nodes[i].getAttribute('data-ap-mounted')) { nodes[i].setAttribute('data-ap-mounted', '1'); p[1](nodes[i]); }
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
  window.AppPortal = { mount: boot };
})();
