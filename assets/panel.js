/* =========================================================================
   Panel administratora — logika front-endu (delegacja zdarzeń, bez inline JS).

   Nic nie przeładowuje strony. Każdy formularz (form[data-ajax]) idzie przez fetch() z polem _ajax i tokenem
   CSRF; serwer odpowiada JSON-em {ok, msg, error, refresh, values, data, done, auth, csrf}. Komunikaty są zawsze
   tekstem z serwera (powiadomienia w rogu nie przesuwają treści; błędy w oknach — w zarezerwowanym miejscu okna).
   Listy odświeżają się w miejscu: wysokość i szerokości kolumn są trzymane, więc tabela nie skacze.
   ========================================================================= */
(function () {
    'use strict';

    /* Teksty interfejsu z pliku języka (app/lang/<kod>/js.php, klucze js.<obszar>.*) — blok #i18n-data drukuje PHP. */
    var I18N = (function () {
        try { return JSON.parse(document.getElementById('i18n-data').textContent) || {}; } catch (e) { return {}; }
    })();
    var LANG = I18N.lang || document.documentElement.lang || 'pl';
    /* t(klucz, {nazwa: wartość}) — parametry :nazwa podstawiane (najdłuższe nazwy najpierw); brak klucza = sam klucz. */
    function t(key, params) {
        var dict = I18N.strings || {};
        var s = Object.prototype.hasOwnProperty.call(dict, key) ? String(dict[key]) : key;
        if (params) {
            Object.keys(params).sort(function (a, b) { return b.length - a.length; }).forEach(function (k) {
                s = s.split(':' + k).join(String(params[k]));
            });
        }
        return s;
    }
    /* Liczebnik: klucze <baza>.one / .few / .many (polski: 1 / 2–4, 22–24… / reszta), parametr :n. Formę wybiera
       Intl.PluralRules języka strony; formy spoza one/few (other, two…) idą do .many. */
    function plural(n, base, params) {
        var form;
        try { form = new Intl.PluralRules(LANG).select(n); } catch (e) { form = n === 1 ? 'one' : 'many'; }
        if (form !== 'one' && form !== 'few') form = 'many';
        return t(base + '.' + form, Object.assign({ n: n }, params || {}));
    }

    var ENDPOINT = 'index.php';
    var $ = function (id) { return document.getElementById(id); };
    var body = document.body;
    var REDUCED = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function esc(s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : String(s)); return d.innerHTML; }
    // do atrybutu w cudzysłowie (title="…", aria-label="…"): esc() nie zamienia cudzysłowu
    function escAttr(s) { return esc(s).replace(/"/g, '&quot;'); }

    /* ---------------- Sieć ---------------- */

    // fetch z limitem czasu (domyślnie 30 s); dłuższe limity dla skanera logów, quoty (doveadm) i zapisów przez helpery.
    function tfetch(url, opts, ms) {
        opts = opts || {};
        opts.credentials = 'same-origin';
        opts.headers = Object.assign({ 'X-Requested-With': 'fetch', 'Accept': 'application/json' }, opts.headers || {});
        if (typeof AbortController === 'undefined') return fetch(url, opts);
        var ctl = new AbortController();
        var timer = setTimeout(function () { ctl.abort(); }, ms || 30000);
        opts.signal = ctl.signal;
        return fetch(url, opts).then(function (r) { clearTimeout(timer); return r; }, function (e) { clearTimeout(timer); throw e; });
    }

    // JSON z odpowiedzi; 403 z {auth:true} = sesja wygasła (pokazujemy pasek i nie udajemy, że coś się zapisało)
    function readJson(r) {
        return r.json().catch(function () { return { ok: false, error: t('net.bad_response', { status: r.status }) }; })
            .then(function (d) {
                if (d && d.auth) sessionExpired(d.error);
                return d || {};
            });
    }

    function getJSON(url, ms) { return tfetch(url, null, ms).then(readJson); }

    function postFields(fields, ms) {
        var fd = fields instanceof FormData ? fields : new FormData();
        if (!(fields instanceof FormData)) Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
        fd.set('_csrf', body.dataset.csrf || '');
        fd.set('_ajax', '1');
        return tfetch(ENDPOINT, { method: 'POST', body: fd }, ms || 90000).then(readJson)
            .catch(function () { return { ok: false, error: t('net.offline_save') }; });
    }

    var expired = false;
    function sessionExpired(msg) {
        if (expired) return;
        expired = true;
        var b = $('session-banner');
        if (b) {
            if (msg) $('session-banner-text').textContent = msg;
            b.hidden = false;
        }
        document.querySelectorAll('.modal:not([hidden])').forEach(function (m) { closeModal(m.id, true); });
    }

    /* ---------------- Komunikaty po zapisie ----------------
       Jeden wzorzec dla wszystkich zapisów: wygląd klasycznego alertu (zielony / czerwony pas z lewą krawędzią), ale jako
       NAKŁADKA przy tym, co zapisano — nic nie przesuwa:
         - formularz w karcie (Bezpieczeństwo, ręczny ban…): u góry tej karty,
         - formularz na całą zakładkę (Ustawienia, Fail2Ban): u góry formularza,
         - akcje z okien i list (quota, usuwanie, bany…): pod zakładkami (#flash-dock, przyklejony do góry ekranu).
       Gdy to miejsce jest poza ekranem (np. długi formularz przewinięty do przycisku), komunikat idzie pod zakładki,
       które są zawsze widoczne. Sukces znika po 6 s, błąd po 12 s (pauza pod kursorem / fokusem), × zamyka od razu.
       Treść zawsze z serwera; role status / alert + aria-live dla czytników ekranu. */

    function flashHost(form) {
        var dock = $('flash-dock');
        if (!form) return dock;
        var box = form.closest('.settings-card') || form;
        var host = box.querySelector(':scope > .flash-host');
        if (!host) {
            host = document.createElement('div');
            host.className = 'flash-host';
            host.setAttribute('aria-live', 'polite');
            box.classList.add('has-flash-host');
            box.insertBefore(host, box.firstChild);
        }
        var r = box.getBoundingClientRect();
        return r.top >= 0 && r.top < window.innerHeight - 80 ? host : dock;
    }

    function flash(kind, text, host) {
        if (!text) return null;
        host = host || $('flash-dock');
        var el = document.createElement('div');
        el.className = 'alert flash ' + (kind === 'ok' ? 'alert-success' : 'alert-error');
        el.setAttribute('role', kind === 'ok' ? 'status' : 'alert');
        el.innerHTML = '<i class="' + (kind === 'ok' ? 'ri-checkbox-circle-line' : 'ri-error-warning-line') + '" aria-hidden="true"></i>' +
            '<div class="flash-text"></div><button type="button" class="flash-close" aria-label="' + escAttr(t('flash.close')) + '"><i class="ri-close-line" aria-hidden="true"></i></button>';
        el.querySelector('.flash-text').textContent = text;
        // nowy komunikat zastępuje stary tego samego rodzaju w tym miejscu (najwyżej dwa naraz: sukces + błąd)
        Array.prototype.forEach.call(host.children, function (c) { if (c.className === el.className) dismissFlash(c, true); });
        host.appendChild(el);
        armFlash(el, kind === 'ok' ? 6000 : 12000);
        return el;
    }

    function armFlash(el, ms) {
        var timer = null;
        var start = function () { clearTimeout(timer); timer = setTimeout(function () { dismissFlash(el); }, ms); };
        var stop = function () { clearTimeout(timer); };
        el.addEventListener('mouseenter', stop);
        el.addEventListener('mouseleave', start);
        el.addEventListener('focusin', stop);
        el.addEventListener('focusout', start);
        start();
    }

    function dismissFlash(el, now) {
        if (!el || !el.parentNode || el.classList.contains('is-leaving')) return;
        el.classList.add('is-leaving');
        setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, now || REDUCED ? 0 : 220);
    }

    // wynik zapisu: sukces i/lub błąd (np. konto usunięte, ale plików nie przeniesiono — oba komunikaty)
    function report(d, form) {
        var host = flashHost(form);
        if (d.msg) flash('ok', d.msg, host);
        if (d.error && !d.auth) flash('err', d.error, host);
    }

    // błąd bez kontekstu formularza (lista się nie wczytała, sesja…) — pod zakładkami
    function toast(kind, text) { return flash(kind, text, $('flash-dock')); }

    /* ---------------- Przyciski w toku ---------------- */

    function busy(btn, on, label) {
        if (!btn) return;
        if (on) {
            if (btn.dataset.busy === '1') return;
            btn.dataset.busy = '1';
            btn.dataset.html = btn.innerHTML;
            btn.style.minWidth = btn.offsetWidth + 'px';     // ta sama szerokość — nic obok się nie przesuwa
            btn.disabled = true;
            btn.setAttribute('aria-busy', 'true');
            btn.innerHTML = '<i class="ri-loader-4-line spin" aria-hidden="true"></i> ' + esc(label || btn.textContent.trim());
        } else {
            if (btn.dataset.busy !== '1') return;
            btn.innerHTML = btn.dataset.html;
            btn.disabled = false;
            btn.removeAttribute('aria-busy');
            delete btn.dataset.busy;
            // zostawiamy minWidth do końca chwili "Zapisano", potem zdejmujemy
            setTimeout(function () { if (btn.dataset.busy !== '1') btn.style.minWidth = ''; }, 1700);
        }
    }

    function flashSaved(btn) {
        if (!btn || REDUCED) return;
        var html = btn.innerHTML;
        btn.classList.add('is-saved');
        btn.innerHTML = '<i class="ri-check-line" aria-hidden="true"></i> ' + t('btn.saved');
        setTimeout(function () { btn.classList.remove('is-saved'); if (btn.dataset.busy !== '1') btn.innerHTML = html; }, 1500);
    }

    /* ---------------- Okna (dialogi) ---------------- */

    var modalStack = [];   // [{id, opener}]

    function focusables(root) {
        return Array.prototype.filter.call(root.querySelectorAll(
            'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]):not([hidden]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        ), function (el) { return el.offsetParent !== null || el === document.activeElement; });
    }

    function openModal(id, focusId) {
        var m = $(id);
        if (!m || !m.hidden) return;
        modalStack.push({ id: id, opener: document.activeElement });
        m.hidden = false;
        body.classList.add('modal-open');
        var target = (focusId && $(focusId)) || focusables(m).filter(function (el) {
            return !el.classList.contains('modal-close') && !el.readOnly;
        })[0] || m.querySelector('.modal-close');
        if (target) target.focus({ preventScroll: true });
    }

    function closeModal(id, force) {
        var m = $(id);
        if (!m || m.hidden) return;
        if (!force && m.querySelector('[aria-busy="true"]')) return;   // trwa zapis — okno zamknie odpowiedź
        m.hidden = true;
        var i = -1;
        modalStack.forEach(function (e, k) { if (e.id === id) i = k; });
        var entry = i > -1 ? modalStack.splice(i, 1)[0] : null;
        if (!modalStack.length) body.classList.remove('modal-open');
        if (entry && entry.opener && document.contains(entry.opener) && typeof entry.opener.focus === 'function') {
            entry.opener.focus({ preventScroll: true });
        } else if (topModal()) {
            // przycisk, który otworzył okno, zniknął (lista przerysowana) — fokus zostaje w oknie pod spodem
            var under = focusables(topModal()).filter(function (el) { return !el.classList.contains('modal-close'); })[0];
            if (under) under.focus({ preventScroll: true });
        }
    }

    function topModal() { return modalStack.length ? $(modalStack[modalStack.length - 1].id) : null; }

    // komunikat w oknie: w zarezerwowanym miejscu nad przyciskami (rozwija się płynnie)
    function formMsg(scope, text, kind) {
        var box = scope && (scope.classList && scope.classList.contains('form-msg') ? scope : scope.querySelector('.form-msg'));
        if (!box) return;
        if (!text) { box.classList.remove('is-open'); box.innerHTML = ''; return; }
        box.innerHTML = '<div class="scan-note ' + (kind === 'ok' ? 'scan-note-ok' : 'scan-note-err') + '"><i class="' +
            (kind === 'ok' ? 'ri-checkbox-circle-line' : 'ri-error-warning-line') + '" aria-hidden="true"></i> <span></span></div>';
        box.querySelector('span').textContent = text;
        box.classList.add('is-open');
    }

    /* ---------------- Zakładki ---------------- */

    var TABS = ['settings', 'users', 'bans', 'logs', 'fail2ban', 'security'];

    function showTab(id, focus) {
        if (TABS.indexOf(id) === -1) return;
        document.querySelectorAll('.tab[data-tab]').forEach(function (t) {
            var on = t.dataset.tab === id;
            t.classList.toggle('active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
            t.tabIndex = on ? 0 : -1;
            if (on && focus) t.focus();
            if (on && t.scrollIntoView && t.parentNode.scrollWidth > t.parentNode.clientWidth) {
                t.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: REDUCED ? 'auto' : 'smooth' });
            }
        });
        document.querySelectorAll('.tab-content').forEach(function (c) { c.classList.toggle('active', c.id === id); });
        // ?tab= zamiast #: odświeżenie (F5) otwiera tę samą zakładkę już po stronie serwera, bez mignięcia
        try { history.replaceState(null, '', '?tab=' + id); } catch (e) { /* np. file:// */ }
    }

    /* ---------------- Listy: stan ---------------- */

    // Sort: users/reg/admin/audit = serwerowo (SQL); users.usage = klientowo w obrębie strony (dane z Dovecot).
    // users.crit = JEDNA uporządkowana lista kryteriów w kolejności klikania: {kind:'srv'|'ico', key, dir}.
    var state = {
        users: { page: 1, per: 20, crit: [{ kind: 'srv', key: 'id', dir: 'DESC' }], filters: [] },
        reg:   { page: 1, per: 20, sort: [], search: '' },
        admin: { page: 1, per: 20, sort: [], search: '' },
        audit: { page: 1, per: 20, sort: [], search: '' }
    };
    var LIST = {
        users: { tbody: 'users-tbody', pag: 'users-pagination', total: 'total-results-text', label: t('list.total.users'), param: 'ajax_fetch_users' },
        reg:   { tbody: 'reg-logs-tbody', pag: 'reg-logs-pagination', total: 'reg-total-text', label: t('list.total.reg'), param: 'ajax_fetch_reg_logs' },
        admin: { tbody: 'admin-logs-tbody', pag: 'admin-logs-pagination', total: 'admin-total-text', label: t('list.total.admin'), param: 'ajax_fetch_admin_logs' },
        audit: { tbody: 'audit-logs-tbody', pag: 'audit-logs-pagination', total: 'audit-total-text', label: t('list.total.audit'), param: 'ajax_fetch_audit_logs' }
    };
    var seq = {};          // numer ostatniego zapytania listy: starsze odpowiedzi są pomijane
    var searchTimer = null;

    function navSel(nav) { return 'th.sortable[data-nav="' + nav + '"]'; }
    function wrapOf(nav) { return document.querySelector('.table-responsive[data-list="' + nav + '"]'); }

    /* Stabilny układ tabeli: przed podmianą wierszy zapamiętujemy wysokość kontenera i szerokości kolumn; po podmianie
       kolumny mogą tylko urosnąć, a kontener nie skurczy się w trakcie stronicowania (paginacja zostaje pod kursorem).
       Zmiana wyszukiwania / liczby na stronę zwalnia blokadę wysokości. */
    function holdLayout(nav, keepHeight) {
        var wrap = wrapOf(nav);
        if (!wrap) return function () {};
        var h = wrap.offsetHeight;
        var ths = wrap.querySelectorAll('thead th');
        var widths = Array.prototype.map.call(ths, function (th) { return th.offsetWidth; });
        if (h) wrap.style.minHeight = h + 'px';
        var timer = setTimeout(function () { wrap.classList.add('is-loading'); wrap.setAttribute('aria-busy', 'true'); }, 160);
        return function done() {
            clearTimeout(timer);
            wrap.classList.remove('is-loading');
            wrap.removeAttribute('aria-busy');
            Array.prototype.forEach.call(ths, function (th, i) {
                var w = Math.max(widths[i] || 0, parseFloat(th.style.minWidth) || 0);
                if (w && th.offsetWidth < w) th.style.minWidth = w + 'px';
            });
            if (!keepHeight) wrap.style.minHeight = '';
        };
    }

    function releaseWidths() {
        document.querySelectorAll('.table-responsive thead th').forEach(function (th) { th.style.minWidth = ''; });
    }

    function listUrl(nav) {
        var st = state[nav];
        var url = ENDPOINT + '?' + LIST[nav].param + '=1&page=' + st.page + '&per=' + st.per;
        if (nav === 'users') {
            var parsed = parseUsersSearch($('user-search-input').value.trim());
            st.filters = parsed.filters;
            var srv = usersSrvSort();
            if (srv) url += '&sort=' + encodeURIComponent(srv);
            if (parsed.server) url += '&search=' + encodeURIComponent(parsed.server);
        } else {
            if (st.sort.length) url += '&sort=' + encodeURIComponent(st.sort.map(function (s) { return s.field + ':' + s.dir; }).join(','));
            if (st.search) url += '&search=' + encodeURIComponent(st.search);
        }
        return url;
    }

    /**
     * Wczytaj stronę listy w miejscu.
     * opts.paging = true: zmiana strony (wysokość trzymana), opts.focusPage = przywróć fokus na paginacji,
     * opts.flash = id wiersza do podświetlenia (users).
     */
    function loadList(nav, opts) {
        opts = opts || {};
        var my = seq[nav] = (seq[nav] || 0) + 1;
        var release = holdLayout(nav, !!opts.paging);
        return getJSON(listUrl(nav)).then(function (d) {
            if (my !== seq[nav]) return;          // przyszła nowsza odpowiedź
            if (d.auth || typeof d.html !== 'string') {
                if (!d.auth) toast('err', d.error || t('list.load_failed'));
                return;
            }
            var tb = $(LIST[nav].tbody), ae = document.activeElement, keep = null;
            if (tb.contains(ae)) {               // fokus w wierszu: po przerysowaniu wraca na ten sam przycisk (albo sąsiedni wiersz)
                var tr = ae.closest('tr');
                keep = { idx: Array.prototype.indexOf.call(tb.children, tr), id: tr && tr.dataset.id, action: ae.dataset.action };
            }
            tb.innerHTML = d.html;
            if (keep) {
                var row = (keep.id && tb.querySelector('tr[data-id="' + keep.id + '"]')) || tb.children[Math.min(keep.idx, tb.children.length - 1)];
                var target = row && ((keep.action && row.querySelector('[data-action="' + keep.action + '"]')) || row.querySelector('button'));
                if (target) target.focus({ preventScroll: true });
            }
            $(LIST[nav].pag).innerHTML = d.pagination;
            var tot = $(LIST[nav].total);
            if (tot) tot.innerHTML = LIST[nav].label + ' <b>' + (d.total | 0) + '</b>';
            if (d.page) state[nav].page = d.page;
            renderSortHeaders(nav);
            if (nav === 'users') afterUsersRendered(opts.flash);
            if (opts.focusPage) {
                var act = $(LIST[nav].pag).querySelector('.pagination-btn.active');
                if (act) act.focus({ preventScroll: true });
            }
        }).catch(function () {
            if (my === seq[nav]) toast('err', t('list.load_offline'));
        }).then(function () { if (my === seq[nav]) release(); });
    }

    var loadUsers = function (o) { return loadList('users', o); };
    var loadReg   = function (o) { return loadList('reg', o); };
    var loadAdmin = function (o) { return loadList('admin', o); };
    var loadAudit = function (o) { return loadList('audit', o); };
    var loaders = { users: loadUsers, reg: loadReg, admin: loadAdmin, audit: loadAudit };

    function afterUsersRendered(flashId) {
        // znane wartości od razu z cache (bez migotania "…"), potem doveadm w tle zaktualizuje te, które się zmieniły
        document.querySelectorAll('#users-tbody .usage-cell[data-email]').forEach(function (c) {
            var q = usageCache[c.dataset.email];
            if (q) renderUsageCell(c, q);
        });
        applyClientSorts();
        loadQuotaUsage();
        if (flashId) flashRow('users-tbody', '[data-id="' + flashId + '"]');
    }

    function flashRow(tbodyId, sel) {
        var tr = $(tbodyId) && $(tbodyId).querySelector(sel);
        if (!tr) return;
        tr.classList.remove('row-flash');
        void tr.offsetWidth;
        tr.classList.add('row-flash');
    }

    /* ---------------- Liczniki ---------------- */

    function loadStats() {
        getJSON(ENDPOINT + '?ajax_stats=1').then(function (d) {
            if (!d.ok) return;
            setStat('stat-users', d.total_users);
            setStat('stat-today', d.today_registrations);
            setStat('stat-failed', d.failed_logins_24h);
        }).catch(function () {});
    }

    function setStat(id, v) {
        var el = $(id);
        if (!el || v === undefined || String(v) === el.textContent) return;
        el.textContent = v;
        el.classList.remove('is-changed');
        void el.offsetWidth;
        el.classList.add('is-changed');
    }

    /* Serwer mówi, co się zmieniło — odświeżamy tylko to (dziennik audytowy po każdym udanym zapisie). */
    function refreshAfter(d, extra) {
        var r = (d && d.refresh) || [];
        if (r.indexOf('users') > -1) loadUsers({ paging: true, flash: extra && extra.flash });
        if (r.indexOf('bans') > -1) refreshBans();
        if (r.indexOf('stats') > -1) loadStats();
        if ((d && (d.ok || d.done)) || r.indexOf('audit') > -1) loadAudit({ paging: true });
    }

    /* ---------------- Wyszukiwanie kont ---------------- */

    /* Rozbiór wyszukiwania kont: grupy po ";". Warunki liczbowe filtruje klient (dane Dovecot są tylko w przeglądarce):
         ">50%" / "użycie > 50%"  -> procent zapełnienia,  "> 20 KiB" / "użycie >= 1 MiB" -> zajęty rozmiar,
         "quota > 1 GiB" -> limit skrzynki.  Reszta idzie do serwera (e-mail / IP / daty / quota tekstowo). */
    var SIZE_KIB = { b: 1 / 1024, kib: 1, kb: 1, mib: 1024, mb: 1024, gib: 1048576, gb: 1048576 };

    function parseUsersSearch(raw) {
        var server = [], filters = [];
        raw.split(';').forEach(function (g) {
            g = g.trim();
            if (!g) return;
            var m = g.match(/^(quota|u[żz]ycie|usage)?\s*(>=|<=|>|<|=)?\s*(\d+(?:[.,]\d+)?)\s*(b|kib|kb|mib|mb|gib|gb)?\s*(%)?$/i);
            if (m) {
                var kw = m[1] ? m[1].toLowerCase() : '';
                var op = m[2] || '=';
                var num = parseFloat(m[3].replace(',', '.'));
                var unit = m[4] ? m[4].toLowerCase() : '';
                if (m[5]) { filters.push({ target: 'pct', op: op, val: num }); return; }
                if (unit || kw) {
                    filters.push({ target: kw.indexOf('q') === 0 ? 'quota' : 'usage', op: op, val: num * (SIZE_KIB[unit] || 1024) });
                    return;
                }
                if (m[2]) { filters.push({ target: 'pct', op: op, val: num }); return; }
            }
            server.push(g);
        });
        return { server: server.join(';'), filters: filters };
    }

    function usersSrvSort() {
        return state.users.crit.filter(function (c) { return c.kind === 'srv'; })
            .map(function (c) { return c.key + ':' + c.dir; }).join(',');
    }

    /* ---------------- Użycie skrzynek (Dovecot) ---------------- */

    function fmtKb(kb) {
        if (kb <= 0) return t('size.b', { n: 0 });
        if (kb < 1024) return t('size.kib', { n: kb });
        if (kb < 1048576) { var m = kb / 1024; return t('size.mib', { n: m >= 100 ? Math.round(m) : Math.round(m * 10) / 10 }); }
        var g = kb / 1048576;
        return t('size.gib', { n: g >= 100 ? Math.round(g) : Math.round(g * 100) / 100 });
    }

    var usageCache = {};

    function sameUsage(a, b) {
        return a && b && a.used_kb === b.used_kb && a.limit_kb === b.limit_kb && a.pct === b.pct && (a.msgs || 0) === (b.msgs || 0);
    }

    function renderUsageCell(c, q) {
        var cls = q.pct >= 90 ? 'crit' : q.pct >= 70 ? 'warn' : 'ok';
        var limit = q.limit_kb > 0 ? fmtKb(q.limit_kb) : '∞';
        var msgs = q.msgs || 0;
        c.dataset.usedKb = q.used_kb;
        c.dataset.msgCount = msgs;
        c.innerHTML = '<div class="usage-wrap" title="' + escAttr(fmtKb(q.used_kb) + ' / ' + limit + ' · ' + plural(msgs, 'usage.msgs')) + '">' +
            '<div class="usage-bar"><div class="usage-fill ' + cls + '" style="width:' + q.pct + '%"></div></div>' +
            '<span class="usage-txt">' + esc(fmtKb(q.used_kb)) + ' · ' + q.pct + '%</span></div>';
    }

    function applyClientSorts() {
        applyUnifiedSort();
        applyUserFilters();
    }

    function cmpOp(actual, op, expected) {
        switch (op) {
            case '>':  return actual >  expected;
            case '<':  return actual <  expected;
            case '>=': return actual >= expected;
            case '<=': return actual <= expected;
            default:   return actual === expected;
        }
    }

    function userRows() { return Array.prototype.slice.call(document.querySelectorAll('#users-tbody tr:not(.empty-row)')); }

    function applyUserFilters() {
        var crit = state.users.filters;
        userRows().forEach(function (tr) {
            if (!crit.length) { tr.classList.remove('row-filtered'); return; }
            var cell = tr.querySelector('.usage-cell');
            var q = cell ? usageCache[cell.dataset.email] : null;
            var qbtn = tr.querySelector('[data-action="quota"]');
            var show = crit.every(function (c) {
                if (c.target === 'quota') return qbtn ? cmpOp(parseInt(qbtn.dataset.quota, 10) * 1024, c.op, c.val) : false;
                if (!q) return false;
                return c.target === 'pct' ? cmpOp(q.pct, c.op, c.val) : cmpOp(q.used_kb, c.op, c.val);
            });
            tr.classList.toggle('row-filtered', !show);
        });
    }

    function loadQuotaUsage() {
        var cells = document.querySelectorAll('#users-tbody .usage-cell[data-email]');
        if (!cells.length) return;
        var emails = [];
        cells.forEach(function (c) { emails.push(c.dataset.email); });
        getJSON(ENDPOINT + '?ajax_quota_usage=1&emails=' + encodeURIComponent(emails.join(',')), 120000)
            .then(function (d) {
                var u = d.usage || {};
                cells.forEach(function (c) {
                    if (!document.contains(c)) return;
                    var email = c.dataset.email;
                    var q = u[email];
                    if (!q) {
                        delete usageCache[email];
                        delete c.dataset.usedKb;
                        delete c.dataset.msgCount;
                        c.innerHTML = '<span class="usage-na" title="' + escAttr(t('usage.no_data')) + '">—</span>';
                        return;
                    }
                    if (sameUsage(usageCache[email], q) && c.querySelector('.usage-wrap')) { usageCache[email] = q; return; }
                    usageCache[email] = q;
                    renderUsageCell(c, q);
                });
                applyClientSorts();
            })
            .catch(function () {
                cells.forEach(function (c) { if (c.querySelector('.usage-wait')) c.innerHTML = '<span class="usage-na">—</span>'; });
            });
    }


    /* ---------------- Sortowanie ----------------
       Jedna lista kryteriów dla kont (kolumny SQL + dane Dovecot: "Użycie" = zajęte miejsce, ikonka koperty =
       liczba wiadomości). Klik = sortuj tylko po tym (drugi klik odwraca), Shift+klik = dołóż / odwróć. Wskaźnik to
       ikona o stałej szerokości; numer priorytetu pokazuje się dopiero przy sortowaniu po kilku kolumnach. */

    function indHtml(dir, n) {
        return '<i class="' + (dir === 'ASC' ? 'ri-arrow-up-line' : 'ri-arrow-down-line') + ' contrast-color"></i>' +
            (n ? '<span class="sort-n">' + n + '</span>' : '');
    }
    var PLACEHOLDER = '<i class="ri-arrow-up-down-line sort-placeholder"></i>';

    function setInd(th, html, ariaDir) {
        var ind = th.querySelector('.sort-ind');
        if (ind) ind.innerHTML = html;
        if (ariaDir) th.setAttribute('aria-sort', ariaDir === 'ASC' ? 'ascending' : 'descending');
        else th.removeAttribute('aria-sort');
    }

    // kolumna "Użycie" sortuje po kluczu Dovecot 'size'
    function critTh(c) {
        if (c.kind === 'ico' && c.key === 'msgs') return null;
        return document.querySelector(navSel('users') + '[data-col="' + (c.kind === 'ico' ? 'usage' : c.key) + '"]');
    }

    function renderSortHeaders(nav) {
        document.querySelectorAll(navSel(nav)).forEach(function (th) { setInd(th, PLACEHOLDER, null); });

        if (nav === 'users') {
            var crit = state.users.crit, multi = crit.length > 1;
            crit.forEach(function (c, i) {
                var th = critTh(c);
                if (th) setInd(th, indHtml(c.dir, multi ? i + 1 : 0), i === 0 ? c.dir : null);
            });
            renderQSortIcons();
            return;
        }
        if (nav === 'bans') return;
        var many = state[nav].sort.length > 1;
        state[nav].sort.forEach(function (s, i) {
            var th = document.querySelector(navSel(nav) + '[data-col="' + s.field + '"]');
            if (th) setInd(th, indHtml(s.dir, many ? i + 1 : 0), i === 0 ? s.dir : null);
        });
    }

    function renderQSortIcons() {
        var crit = state.users.crit;
        document.querySelectorAll('.qsort-ico[data-qsort]').forEach(function (ico) {
            var idx = crit.findIndex(function (c) { return c.kind === 'ico' && c.key === ico.dataset.qsort; });
            var s = idx > -1 ? crit[idx] : null;
            ico.classList.toggle('on', !!s);
            ico.setAttribute('aria-pressed', s ? 'true' : 'false');
            var n = ico.querySelector('.qsort-n');
            if (n) n.innerHTML = s ? indHtml(s.dir, crit.length > 1 ? idx + 1 : 0) : '';
        });
    }

    /* Konta: kind 'srv' (SQL — nowa strona z serwera) albo 'ico' (Dovecot — przestawienie wierszy bieżącej strony).
       Dane Dovecot zaczynają malejąco (największe najpierw), kolumny SQL rosnąco. */
    function sortUsers(kind, key, multi) {
        var before = usersSrvSort();
        var crit = state.users.crit;
        var first = kind === 'ico' ? 'DESC' : 'ASC';
        var i = crit.findIndex(function (c) { return c.kind === kind && c.key === key; });
        var flip = function (c) { c.dir = c.dir === 'ASC' ? 'DESC' : 'ASC'; };
        if (multi) {
            if (i !== -1) flip(crit[i]); else crit.push({ kind: kind, key: key, dir: first });
        } else if (i === 0 && crit.length === 1) {
            flip(crit[0]);
        } else {
            state.users.crit = [{ kind: kind, key: key, dir: first }];
        }
        renderSortHeaders('users');
        if (usersSrvSort() !== before) loadUsers({ paging: true });
        else applyUnifiedSort();
    }

    function changeSort(nav, field, multi) {
        if (nav === 'users') {
            if (field === 'usage') sortUsers('ico', 'size', multi);
            else sortUsers('srv', field, multi);
            return;
        }
        var st = state[nav];
        var idx = st.sort.findIndex(function (s) { return s.field === field; });
        if (multi) {
            if (idx !== -1) st.sort[idx].dir = st.sort[idx].dir === 'ASC' ? 'DESC' : 'ASC';
            else st.sort.push({ field: field, dir: 'ASC' });
        } else if (idx === 0) {
            st.sort[0].dir = st.sort[0].dir === 'ASC' ? 'DESC' : 'ASC';
            st.sort = [st.sort[0]];
        } else {
            st.sort = [{ field: field, dir: 'ASC' }];
        }
        renderSortHeaders(nav);
        loaders[nav]({ paging: true });
    }

    function qVal(tr, key) {
        var c = tr.querySelector('.usage-cell');
        if (!c) return null;
        var raw = key === 'msgs' ? c.dataset.msgCount : c.dataset.usedKb;
        if (raw === undefined) return null;
        var v = parseFloat(raw);
        return isNaN(v) ? null : v;
    }

    function critVal(tr, c) {
        if (c.kind === 'ico') return qVal(tr, c.key);
        var cell = function (i) { var v = tr.children[i] ? tr.children[i].textContent.trim() : ''; return v === '-' || v === '' ? null : v; };
        switch (c.key) {
            case 'id':         return parseInt(tr.children[0].textContent, 10);
            case 'email':      return (cell(1) || '').toLowerCase();
            case 'created':    return cell(2);
            case 'last_login': return cell(3);
            case 'reg_ip':     return cell(4);
            case 'quota':      var b = tr.querySelector('[data-action="quota"]'); return b ? parseInt(b.dataset.quota, 10) : null;
        }
        return null;
    }

    /* Dosortowanie bieżącej strony wg PEŁNEJ listy kryteriów — tylko gdy są wśród nich dane Dovecot (same kolumny SQL
       przychodzą już posortowane z serwera, spójnie między stronami). */
    function applyUnifiedSort() {
        var crit = state.users.crit;
        var tbody = $('users-tbody');
        if (!tbody || !crit.length || !crit.some(function (c) { return c.kind === 'ico'; })) return;
        var rows = userRows();
        rows.sort(function (a, b) {
            for (var i = 0; i < crit.length; i++) {
                var av = critVal(a, crit[i]), bv = critVal(b, crit[i]);
                if (av === null && bv === null) continue;
                if (av === null) return 1;
                if (bv === null) return -1;
                var cmp = (typeof av === 'number' && typeof bv === 'number') ? av - bv
                    : String(av).localeCompare(String(bv), LANG, { numeric: true });
                if (cmp !== 0) return cmp * (crit[i].dir === 'ASC' ? 1 : -1);
            }
            return 0;
        });
        rows.forEach(function (r) { tbody.appendChild(r); });
    }


    /* ---------------- Tabela blokad (sort i filtr w przeglądarce) ---------------- */

    var bansSort = null;   // {col, dir}

    function cellKey(tr, col) {
        var td = tr.children[col];
        if (!td) return '';
        if (td.dataset.sort !== undefined) { var n = parseFloat(td.dataset.sort); return isNaN(n) ? td.dataset.sort : n; }
        return td.textContent.trim().toLowerCase();
    }

    function sortBansTable(col, toggle) {
        var tbody = $('bans-tbody');
        if (!tbody) return;
        if (toggle) bansSort = { col: col, dir: bansSort && bansSort.col === col && bansSort.dir === 'ASC' ? 'DESC' : 'ASC' };
        if (!bansSort) return;
        document.querySelectorAll(navSel('bans')).forEach(function (h) {
            setInd(h, parseInt(h.dataset.col, 10) === bansSort.col ? indHtml(bansSort.dir, 0) : PLACEHOLDER,
                parseInt(h.dataset.col, 10) === bansSort.col ? bansSort.dir : null);
        });
        var mult = bansSort.dir === 'ASC' ? 1 : -1;
        var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr:not(.empty-row)'));
        rows.sort(function (a, b) {
            var ka = cellKey(a, bansSort.col), kb = cellKey(b, bansSort.col);
            if (typeof ka === 'number' && typeof kb === 'number') return (ka - kb) * mult;
            return String(ka).localeCompare(String(kb), LANG, { numeric: true }) * mult;
        });
        rows.forEach(function (r) { tbody.appendChild(r); });
    }

    function applyBansFilter() {
        var el = $('ban-search-input');
        var raw = el ? el.value.trim().toLowerCase() : '';
        var phrases = raw.split(';').map(function (s) { return s.trim(); }).filter(Boolean);
        document.querySelectorAll('#bans-tbody tr:not(.empty-row)').forEach(function (tr) {
            if (!phrases.length) { tr.classList.remove('row-filtered'); return; }
            var titles = Array.prototype.map.call(tr.querySelectorAll('td[title]'), function (td) { return td.title; }).join(' ');
            var hay = (tr.textContent + ' ' + titles).toLowerCase().replace(/\s+/g, ' ');
            tr.classList.toggle('row-filtered', !phrases.every(function (p) { return hay.indexOf(p) > -1; }));
        });
    }

    var bansIndex = {};   // ip -> {type, jail, label} — skaner flaguje IP już zbanowane

    function rebuildBansIndex() {
        bansIndex = {};
        document.querySelectorAll('#bans-tbody [data-action="manage-ban"]').forEach(function (b) {
            bansIndex[b.dataset.ip] = { type: b.dataset.type, jail: b.dataset.jail || '', label: b.dataset.type === 'local' ? t('ban.local_label') : (b.dataset.jail || 'fail2ban') };
        });
    }

    function refreshBans() {
        var release = holdLayout('bans', false);
        return getJSON(ENDPOINT + '?ajax_fetch_bans=1').then(function (d) {
            if (typeof d.html !== 'string') return;
            $('bans-tbody').innerHTML = d.html;
            if ($('stat-banned-ips')) setStat('stat-banned-ips', d.count);
            rebuildBansIndex();
            sortBansTable(bansSort ? bansSort.col : 0, false);
            applyBansFilter();
            if (scan.agg && !scan.running) renderScanResults();
        }).catch(function () {}).then(release);
    }

    /* ---------------- Wyszukiwanie: przycisk "wyczyść" ---------------- */

    function triggerSearch(val, shift) {
        var input = $('user-search-input');
        if (shift) {
            var terms = input.value.split(/[\s,;]+/).filter(function (t) { return t; });
            var i = terms.indexOf(val);
            if (i > -1) terms.splice(i, 1); else terms.push(val);
            input.value = terms.join(' ');
        } else {
            input.value = val;
        }
        syncAdvClear(input);
        state.users.page = 1;
        showTab('users');
        loadUsers();
    }

    var advClear = { timer: null, input: null };

    function syncAdvClear(input) {
        var btn = document.querySelector('[data-adv-clear="' + input.id + '"]');
        if (btn) btn.hidden = !input.value;
    }

    function advClearStop() {
        if (advClear.timer) clearTimeout(advClear.timer);
        if (advClear.input) advClear.input.classList.remove('adv-clearing');
        advClear.timer = null;
        advClear.input = null;
    }

    function advClearTick(input, delay) {
        if (!input.value) { advClearStop(); input.focus({ preventScroll: true }); return; }
        input.value = input.value.slice(0, -1);
        input.dispatchEvent(new Event('input', { bubbles: true }));
        advClear.timer = setTimeout(function () { advClearTick(input, Math.max(12, Math.floor(delay * 0.70))); }, delay);
    }

    function advClearStart(input) {
        if (!input) return;
        advClearStop();
        if (REDUCED) { input.value = ''; input.dispatchEvent(new Event('input', { bubbles: true })); input.focus(); return; }
        advClear.input = input;
        input.classList.add('adv-clearing');
        advClearTick(input, 90);
    }

    /* ---------------- Okna z danymi wiersza ---------------- */

    function addOpt(sel, value, text, selected) {
        var o = document.createElement('option');
        o.value = value; o.textContent = text; if (selected) o.selected = true;
        sel.appendChild(o);
    }

    function showQuotaModal(id, email, mb, sendLimit) {
        $('quota_user_id').value = id;
        $('quota_user_email').value = email;
        var value = mb, unit = 'MiB';
        if (mb >= 1024 && mb % 1024 === 0) { value = mb / 1024; unit = 'GiB'; }
        $('quota_value').value = value;
        $('quota_unit').value = unit;
        $('quota_send_limit').value = (sendLimit === undefined || sendLimit === '') ? '' : sendLimit;
        formMsg($('quotaModal'), '');
        loadSentToday(id);
        openModal('quotaModal', 'quota_value');
    }

    function loadSentToday(id) {
        var box = $('quota_sent_today');
        if (!box) return;
        box.innerHTML = '<i class="ri-loader-4-line spin" aria-hidden="true"></i> ' + t('common.loading');
        getJSON(ENDPOINT + '?ajax_sent_today=1&id=' + encodeURIComponent(id))
            .then(function (d) {
                if (!d.ok) { box.innerHTML = '<span class="usage-na">—</span>'; return; }
                var limitTxt = d.limit > 0 ? d.limit : '∞';
                var src = d.individual ? t('quota.limit_individual') : t('quota.limit_global');
                var cls = d.limit > 0 && d.sent >= d.limit ? 'crit' : (d.limit > 0 && d.sent >= d.limit * 0.8 ? 'warn' : 'ok');
                box.innerHTML = '<b class="sent-' + cls + '">' + (d.sent | 0) + '</b> / ' + esc(limitTxt) +
                    ' ' + t('quota.recipients') + ' <span class="sent-src">(' + src + ')</span>';
            })
            .catch(function () { box.innerHTML = '<span class="usage-na">—</span>'; });
    }

    function showBanModal(email) {
        $('ban_user_email').value = email;
        formMsg($('banModal'), '');
        var sel = $('ban_ip_select');
        sel.innerHTML = '<option value="">' + t('ban.ip.loading') + '</option>';
        tfetch('user_ips.php?email=' + encodeURIComponent(email)).then(readJson)
            .then(function (d) {
                sel.innerHTML = '<option value="">' + t('ban.ip.choose') + '</option>';
                if (d.error) { sel.innerHTML = '<option value="">' + t('ban.ip.error') + '</option>'; return; }
                var added = {};
                var labels = { reg_ip: t('ban.ip.reg'), first_login_ip: t('ban.ip.first_login'), prev_login_ip: t('ban.ip.prev_login'), last_login_ip: t('ban.ip.last_login') };
                if (d.flags) Object.keys(labels).forEach(function (k) {
                    var v = d.flags[k];
                    if (v && !added[v]) { addOpt(sel, v, v + ' (' + labels[k] + ')'); added[v] = 1; }
                });
                if (Array.isArray(d.recent)) d.recent.forEach(function (ip) { if (ip && !added[ip]) { addOpt(sel, ip, ip); added[ip] = 1; } });
                if (sel.options.length === 1) sel.innerHTML = '<option value="">' + t('ban.ip.none') + '</option>';
            }).catch(function () { sel.innerHTML = '<option value="">' + t('ban.ip.fetch_failed') + '</option>'; });
        openModal('banModal', 'ban_ip_select');
    }

    function showDirectBanModal(ip) {
        $('ban_user_email').value = t('ban.direct_email');
        formMsg($('banModal'), '');
        var sel = $('ban_ip_select');
        sel.innerHTML = '';
        addOpt(sel, ip, ip, true);
        openModal('banModal', 'ban_duration');
    }

    function showDeleteModal(id, email) {
        $('del_user_id_display').textContent = id;
        $('del_user_email_display').textContent = email;
        $('del_user_id_input').value = id;
        $('del_user_email_input').value = email;
        $('del_admin_pass').value = '';
        formMsg($('deleteUserModal'), '');
        openModal('deleteUserModal', 'del_admin_pass');
    }

    /* ---------------- Potwierdzenie (z działaniem w toku) ---------------- */

    var confirmAction = null;

    /**
     * o.run() zwraca Promise z odpowiedzią serwera. Okno zostaje otwarte (przycisk w toku) do odpowiedzi;
     * błąd pokazuje się w oknie, sukces zamyka je i trafia do powiadomień.
     */
    function askConfirm(o) {
        $('confirm_title').innerHTML = '<i class="' + (o.danger ? 'ri-alarm-warning-line' : 'ri-error-warning-line') + '" aria-hidden="true"></i> ' + esc(o.title);
        $('confirm_msg').textContent = o.msg;
        var ok = $('confirm_ok');
        ok.className = 'btn' + (o.danger ? ' btn-danger' : '');
        ok.innerHTML = '<i class="ri-check-line" aria-hidden="true"></i> ' + esc(o.okLabel || t('confirm.ok'));
        formMsg($('confirmModal'), '');
        confirmAction = o;
        openModal('confirmModal', 'confirm_ok');
    }

    function runConfirm() {
        var o = confirmAction;
        if (!o) return;
        var ok = $('confirm_ok');
        busy(ok, true, o.busyLabel || t('btn.running'));
        Promise.resolve(o.run()).then(function (d) {
            busy(ok, false);
            d = d || {};
            if (d.auth) return;
            if (d.ok || d.done) {
                confirmAction = null;
                closeModal('confirmModal', true);
                var under = topModal();      // potwierdzenie z wnętrza innego okna (np. usunięcie aliasu) — komunikat tam
                if (under) formMsg(under, d.error || d.msg, d.error ? 'err' : 'ok');
                else report(d, o.form);
                if (o.after) o.after(d);
                refreshAfter(d);
            } else {
                formMsg($('confirmModal'), d.error || t('op.failed'));
            }
        });
    }

    /* ---------------- Zarządzanie banem ---------------- */

    var currentBan = {};
    function openManageBan(data) {
        currentBan = { ip: data.ip, type: data.type, jail: data.jail || '' };
        $('mb_ip').textContent = data.ip;
        $('mb_type').textContent = data.type === 'local' ? t('ban.type.local') : t('ban.type.f2b', { jail: currentBan.jail });
        $('mb_change_wrap').hidden = data.type === 'local';
        $('mb_duration').hidden = $('mb_target_jail').value !== 'panel-tempban';
        openModal('manageBanModal', data.type === 'local' ? 'mb_unban_btn' : 'mb_target_jail');
    }

    function manageUnban() {
        var b = currentBan;
        closeModal('manageBanModal');
        askConfirm({
            title: t('unban.title'), msg: t('unban.msg', { ip: b.ip }), okLabel: t('unban.ok'), busyLabel: t('unban.busy'),
            run: function () { return postFields({ unban_ip: 1, ip: b.ip, unban_type: b.type, jail: b.jail }); }
        });
    }

    function manageMove() {
        var b = currentBan;
        var target = $('mb_target_jail').value;
        var durSel = $('mb_duration');
        var isTemp = target === 'panel-tempban';
        var duration = isTemp ? durSel.value : '';
        var durLabel = isTemp ? durSel.options[durSel.selectedIndex].text : '';
        closeModal('manageBanModal');
        askConfirm({
            title: t('move.title'), okLabel: t('move.ok'), busyLabel: t('move.busy'),
            msg: isTemp ? t('move.msg_temp', { ip: b.ip, from: b.jail, to: target, duration: durLabel })
                : t('move.msg', { ip: b.ip, from: b.jail, to: target }),
            run: function () { return postFields({ move_ban: 1, ip: b.ip, from_jail: b.jail, to_jail: target, duration: duration }); }
        });
    }

    /* ---------------- Skaner podejrzanych IP ---------------- */

    var SCAN_SOURCES = [
        { key: 'ssh', label: 'SSH' }, { key: 'dovecot', label: 'Dovecot' },
        { key: 'postfix', label: 'Postfix SASL' }, { key: 'db', label: t('scan.source.db') }
    ];
    var scan = { running: false, finished: false, agg: null, banned: {}, failed: [], from: '', to: '' };

    function openScanner() {
        var f = $('scan_from'), t = $('scan_to');
        if (!f.value) { var d = new Date(); d.setDate(d.getDate() - 7); f.value = d.toISOString().slice(0, 10); }
        if (!t.value) { t.value = new Date().toISOString().slice(0, 10); }
        rebuildBansIndex();
        if (scan.agg && !scan.running) renderScanResults();
        openModal('scannerModal', 'scan_run');
    }

    function scanNote(ok, text) {
        var msg = $('scan_ban_msg');
        if (msg) msg.innerHTML = '<div class="scan-note ' + (ok ? 'scan-note-ok' : 'scan-note-err') + '"><i class="' +
            (ok ? 'ri-shield-check-line' : 'ri-error-warning-line') + '" aria-hidden="true"></i> ' + esc(text) + '</div>';
    }

    function runScan() {
        if (scan.running) return;
        scan.running = true;
        scan.finished = false;
        scan.agg = {};
        scan.failed = [];
        scan.from = $('scan_from').value;
        scan.to = $('scan_to').value;
        busy($('scan_run'), true, t('scan.busy'));
        $('scan_results').innerHTML = '<p class="empty-state-text">' + t('scan.no_results_yet') + '</p>';
        $('scan_status').hidden = false;
        $('scan_ban_msg').innerHTML = '';
        scanNext(0);
    }

    function renderScanSteps(activeIdx) {
        $('scan_steps').innerHTML = SCAN_SOURCES.map(function (s, i) {
            var cls, icon;
            if (scan.failed.indexOf(i) !== -1) { cls = 'fail'; icon = '<i class="ri-close-line" aria-hidden="true"></i>'; }
            else if (i < activeIdx) { cls = 'done'; icon = '<i class="ri-check-line" aria-hidden="true"></i>'; }
            else if (i === activeIdx && scan.running) { cls = 'active'; icon = '<i class="ri-loader-4-line spin" aria-hidden="true"></i>'; }
            else { cls = 'pending'; icon = '<i class="ri-time-line" aria-hidden="true"></i>'; }
            return '<span class="scan-step ' + cls + '">' + icon + ' ' + s.label + '</span>';
        }).join('');
    }

    function scanNext(i) {
        if (i >= SCAN_SOURCES.length) {
            scan.running = false;
            scan.finished = true;
            busy($('scan_run'), false);
            $('scan_status_label').innerHTML = scan.failed.length
                ? '<i class="ri-error-warning-line" aria-hidden="true"></i> ' + t('scan.done_errors', { n: scan.failed.length })
                : '<i class="ri-checkbox-circle-line" aria-hidden="true"></i> ' + t('scan.done_all', { from: esc(scan.from), to: esc(scan.to) });
            $('scan_progress_fill').style.width = '100%';
            renderScanSteps(SCAN_SOURCES.length);
            renderScanResults();
            return;
        }
        var src = SCAN_SOURCES[i];
        $('scan_status_label').innerHTML = '<i class="ri-loader-4-line spin" aria-hidden="true"></i> ' +
            t('scan.scanning', { source: src.label, current: i + 1, total: SCAN_SOURCES.length });
        $('scan_progress_fill').style.width = Math.round(i / SCAN_SOURCES.length * 100) + '%';
        renderScanSteps(i);
        getJSON(ENDPOINT + '?ajax_scan=1&source=' + src.key + '&from=' + encodeURIComponent(scan.from) + '&to=' + encodeURIComponent(scan.to), 120000)
            .then(function (d) {
                if (d.auth) { scan.running = false; busy($('scan_run'), false); return; }
                (d.pairs || []).forEach(function (p) {
                    var a = scan.agg[p.ip] = scan.agg[p.ip] || { total: 0, services: {} };
                    a.total += p.c;
                    a.services[p.svc] = (a.services[p.svc] || 0) + p.c;
                });
                if (d.error) scan.failed.push(i);
                renderScanResults();
                scanNext(i + 1);
            })
            .catch(function () { scan.failed.push(i); renderScanResults(); scanNext(i + 1); });
    }

    function scanMin() { return parseInt($('scan_min').value, 10) || 1; }

    function scanRows() {
        var min = scanMin(), rows = [];
        Object.keys(scan.agg || {}).forEach(function (ip) {
            var d = scan.agg[ip];
            if (d.total >= min) rows.push({ ip: ip, total: d.total, services: d.services });
        });
        rows.sort(function (a, b) { return b.total - a.total; });
        return rows;
    }

    function scanActionCell(ip) {
        var b = scan.banned[ip];
        if (b) return '<span class="scan-banned"><i class="ri-checkbox-circle-line" aria-hidden="true"></i> ' + t('scan.banned', { label: esc(b) }) + '</span>';
        if (bansIndex[ip]) {
            return '<div class="scan-ban-controls"><span class="scan-already" title="' + escAttr(t('scan.already_title')) + '">' +
                '<i class="ri-shield-check-line" aria-hidden="true"></i> ' + t('scan.already', { label: esc(bansIndex[ip].label) }) + '</span>' +
                '<button type="button" class="btn-action btn-manage" data-scan-unban="' + esc(ip) + '"><i class="ri-lock-unlock-line" aria-hidden="true"></i> ' + t('scan.unban') + '</button></div>';
        }
        return '<div class="scan-ban-controls"><select class="scan-ban-sel" aria-label="' + escAttr(t('scan.ban_kind', { ip: ip })) + '">' +
            '<option value="perma">' + t('scan.dur.perma') + '</option><option value="3600">' + t('scan.dur.1h') + '</option><option value="21600">' + t('scan.dur.6h') + '</option>' +
            '<option value="86400">' + t('scan.dur.24h') + '</option><option value="259200">' + t('scan.dur.3d') + '</option><option value="604800">' + t('scan.dur.7d') + '</option>' +
            '<option value="2592000">' + t('scan.dur.30d') + '</option><option value="7776000">' + t('scan.dur.3m') + '</option></select>' +
            '<button type="button" class="btn-action btn-danger-action" data-scan-ban="' + esc(ip) + '"><i class="ri-forbid-line" aria-hidden="true"></i> ' + t('scan.ban') + '</button></div>';
    }

    function renderScanResults() {
        var box = $('scan_results');
        var rows = scanRows();
        $('scan_status_count').textContent = t('scan.count', { n: rows.length, min: scanMin() });
        if (!rows.length) {
            box.innerHTML = '<p class="empty-state-text">' + (scan.finished ? t('scan.none') : t('scan.no_results_yet')) + '</p>';
            return;
        }
        var h = '<div class="table-responsive"><table><thead><tr><th scope="col">' + t('scan.col.ip') + '</th><th scope="col">' + t('scan.col.failed') +
            '</th><th scope="col">' + t('scan.col.services') + '</th><th scope="col">' + t('scan.col.block') + '</th></tr></thead><tbody>';
        rows.slice(0, 100).forEach(function (r) {
            var svc = Object.keys(r.services).map(function (s) { return s + ': ' + r.services[s]; }).join(', ');
            h += '<tr><td class="scan-ip"><strong>' + esc(r.ip) + '</strong></td><td>' + r.total + '</td>' +
                '<td class="scan-svc">' + esc(svc) + '</td><td>' + scanActionCell(r.ip) + '</td></tr>';
        });
        h += '</tbody></table></div>';
        if (rows.length > 100) h += '<div class="help-text">' + t('scan.shown', { shown: 100, n: rows.length }) + '</div>';
        box.innerHTML = h;
    }

    function scanBan(btn, ip, mode, label) {
        busy(btn, true, t('ban.busy'));
        postFields({ ajax_scan_ban: '1', ip: ip, mode: mode }).then(function (d) {
            busy(btn, false);
            if (d.auth) return;
            if (d.ok) {
                scan.banned[ip] = d.label || label;
                renderScanResults();
                refreshBans();
                loadAudit({ paging: true });
                scanNote(true, t('scan.banned_note', { ip: ip, label: d.label || label }));
            } else {
                scanNote(false, d.error || t('scan.ban_failed'));
            }
        });
    }

    /* ---------------- Okno "Dodaj użytkownika" ---------------- */

    var PWD = {
        len:   parseInt(body.dataset.pwdMin, 10) || 0,
        upper: parseInt(body.dataset.pwdUpper, 10) || 0,
        digit: parseInt(body.dataset.pwdDigit, 10) || 0,
        spec:  parseInt(body.dataset.pwdSpec, 10) || 0
    };
    var NU_LOCAL_RE = /^[a-z0-9](?:[a-z0-9._-]{0,62}[a-z0-9])?$/i;
    var nuAvailTimer = null;
    var nuSeq = 0;
    var nuName = null;   // ostatnia ocena nazwy z serwera: {status, msg, detail, override, aliases, email}

    function nuSetAvail(cls, text) {
        var el = $('nu_availability');
        el.className = 'avail-status' + (cls ? ' ' + cls : '');
        el.textContent = text;
    }

    /* Ocena nazwy z serwera: co jest nie tak, czy zgoda coś zmienia, i wyjście (usuń alias / pokaż konto). */
    function renderNameStatus(n) {
        nuName = n;
        var reason = $('nu_reason'), actions = $('nu_reason_actions'), allow = $('nu_allow_wrap');
        var cls = n.status === 'free' ? 'free' : (n.override ? 'warn' : 'taken');
        nuSetAvail(cls, (n.status === 'free' ? '✓ ' : n.override ? '⚠ ' : '✗ ') + n.msg);
        actions.innerHTML = '';
        if (n.status === 'free' || !n.detail) {
            reason.hidden = true;
        } else {
            $('nu_reason_text').textContent = n.detail;
            reason.className = 'name-reason name-reason--' + (n.override ? 'warn' : 'block');
            reason.hidden = false;
            if (n.status === 'alias') {
                (n.aliases || []).forEach(function (a) {
                    var b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'btn btn-small btn-danger';
                    b.dataset.action = 'delete-alias';
                    b.dataset.aliasId = a.id;
                    b.dataset.aliasSource = a.source;
                    b.dataset.aliasDest = a.destination;
                    b.innerHTML = '<i class="ri-delete-bin-line" aria-hidden="true"></i> ';
                    b.appendChild(document.createTextNode(t('alias.delete.button', { source: a.source, dest: a.destination })));
                    actions.appendChild(b);
                });
            } else if (n.status === 'taken') {
                var s = document.createElement('button');
                s.type = 'button';
                s.className = 'btn btn-small btn-secondary';
                s.dataset.action = 'show-user';
                s.dataset.email = n.email || '';
                s.innerHTML = '<i class="ri-search-line" aria-hidden="true"></i> ' + t('users.new.show_user');
                actions.appendChild(s);
            }
        }
        var wasOn = !allow.hidden;
        allow.hidden = !n.override;
        if (!n.override || !wasOn) $('nu_allow_reserved').checked = false;
    }

    function nuResetName() {
        nuName = null;
        nuSetAvail('', '');
        $('nu_reason').hidden = true;
        $('nu_reason_actions').innerHTML = '';
        $('nu_allow_wrap').hidden = true;
        $('nu_allow_reserved').checked = false;
    }

    function nuCheckAvailability() {
        var local = ($('nu_local').value || '').trim();
        var domain = $('nu_domain').value;
        var my = ++nuSeq;
        if (!local) { nuResetName(); return Promise.resolve(); }
        if (!NU_LOCAL_RE.test(local)) {
            nuResetName();
            nuName = { status: 'invalid' };
            nuSetAvail('taken', '✗ ' + t('users.new.invalid_chars'));
            return Promise.resolve();
        }
        nuSetAvail('loading', t('checking'));
        return getJSON(ENDPOINT + '?ajax_check_user=1&local=' + encodeURIComponent(local) + '&domain=' + encodeURIComponent(domain))
            .then(function (d) {
                if (my !== nuSeq || d.auth) return;
                if (d.ok) renderNameStatus(d);
                else { nuResetName(); nuName = { status: 'invalid' }; nuSetAvail('taken', '✗ ' + (d.msg || t('error'))); }
            })
            .catch(function () { if (my === nuSeq) { nuName = null; nuSetAvail('taken', t('users.new.offline')); } });
    }

    function pwdMeter(barId, reqsId, val) {
        var score = 0, max = 0;
        var rule = function (enabled, ok, req) {
            if (!enabled) return;
            max++;
            var li = document.querySelector('#' + reqsId + ' li[data-req="' + req + '"]');
            if (ok) { score++; if (li) li.classList.add('valid'); } else if (li) li.classList.remove('valid');
        };
        rule(PWD.len,   val.length >= PWD.len,    'len');
        rule(PWD.upper, /[A-Z]/.test(val),        'up');
        rule(PWD.digit, /\d/.test(val),           'dig');
        rule(PWD.spec,  /[^a-zA-Z0-9]/.test(val), 'spec');
        var bar = $(barId);
        if (!bar) return;
        var pct = Math.min(100, (score / (max || 1)) * 100);
        bar.style.width = pct + '%';
        bar.style.backgroundColor = pct < 40 ? 'var(--status-error)' : pct < 80 ? 'var(--status-warning)' : 'var(--status-success)';
    }

    function pwdValid(val) {
        if (PWD.len && val.length < PWD.len) return false;
        if (PWD.upper && !/[A-Z]/.test(val)) return false;
        if (PWD.digit && !/\d/.test(val)) return false;
        if (PWD.spec && !/[^a-zA-Z0-9]/.test(val)) return false;
        return true;
    }

    function pwdMatch(v1, v2, statusEl) {
        if (!statusEl) return;
        if (!v2) { statusEl.className = 'avail-status'; statusEl.textContent = ''; return; }
        if (v1 === v2) { statusEl.className = 'avail-status free'; statusEl.textContent = t('pwd.match'); }
        else { statusEl.className = 'avail-status taken'; statusEl.textContent = t('pwd.mismatch'); }
    }

    function resetPwdUi(barId, reqsId, matchId) {
        var bar = $(barId);
        if (bar) { bar.style.width = '0%'; bar.style.backgroundColor = ''; }
        document.querySelectorAll('#' + reqsId + ' li').forEach(function (li) { li.classList.remove('valid'); });
        var m = $(matchId);
        if (m) { m.className = 'avail-status'; m.textContent = ''; }
    }

    /** Opis błędu albo '' gdy formularz może iść na serwer (serwer i tak sprawdza wszystko jeszcze raz). */
    function nuValidate() {
        var local = ($('nu_local').value || '').trim();
        if (!local) return ['nu_local', t('users.new.name_required')];
        if (!NU_LOCAL_RE.test(local)) return ['nu_local', t('users.new.invalid_name')];
        if (nuName && nuName.status === 'taken') return ['nu_local', t('users.new.exists')];
        if (nuName && nuName.status === 'alias') return ['nu_local', t('users.new.is_alias')];
        if (nuName && nuName.override && !$('nu_allow_reserved').checked) return ['nu_allow_reserved', t('users.new.override_hint', { msg: nuName.msg })];
        if (!pwdValid($('nu_password').value)) return ['nu_password', t('pwd.weak')];
        if ($('nu_password').value !== $('nu_password2').value) return ['nu_password2', t('pwd.not_identical')];
        return null;
    }

    function openAddUser() {
        var f = $('addUserModal').querySelector('form');
        f.reset();
        nuResetName();
        formMsg($('addUserModal'), '');
        resetPwdUi('nu_pwd_bar', 'nu_pwd_reqs', 'nu_pass_match');
        openModal('addUserModal', 'nu_local');
    }

    function deleteAlias(btn) {
        var id = btn.dataset.aliasId, src = btn.dataset.aliasSource, dst = btn.dataset.aliasDest;
        askConfirm({
            title: t('alias.delete.title'), danger: true, okLabel: t('alias.delete.ok'), busyLabel: t('alias.delete.busy'),
            msg: t('alias.delete.msg', { source: src, dest: dst }),
            run: function () { return postFields({ delete_alias: 1, alias_id: id, alias_source: src }); },
            after: function () {
                // nazwa jest teraz "tylko" zarezerwowana: fokus na zgodzie (albo na nazwie), okno zostaje otwarte
                nuCheckAvailability().then(function () {
                    var wrap = $('nu_allow_wrap');
                    (wrap.hidden ? $('nu_local') : $('nu_allow_reserved')).focus({ preventScroll: true });
                });
            }
        });
    }

    function showResetPassModal(id, email) {
        $('rp_user_id').value = id;
        $('rp_user_email_hidden').value = email;
        $('rp_user_email').value = email;
        $('rp_password').value = '';
        $('rp_password2').value = '';
        formMsg($('resetPassModal'), '');
        resetPwdUi('rp_pwd_bar', 'rp_pwd_reqs', 'rp_pass_match');
        openModal('resetPassModal', 'rp_password');
    }

    function rpValidate() {
        if (!pwdValid($('rp_password').value)) return ['rp_password', t('pwd.weak')];
        if ($('rp_password').value !== $('rp_password2').value) return ['rp_password2', t('pwd.not_identical')];
        return null;
    }

    /* ---------------- Formularze: zapis przez fetch ---------------- */

    function applyValues(form, values) {
        if (!values) return;
        Object.keys(values).forEach(function (name) {
            var v = values[name];
            document.querySelectorAll('[data-bind="' + name + '"]').forEach(function (el) { el.textContent = v; });
            document.querySelectorAll('[data-placeholder-bind="' + name + '"]').forEach(function (el) { el.placeholder = t('settings.placeholder_global', { value: v }); });
            var el = form.elements.namedItem(name);
            if (!el || el.type === 'hidden') return;
            if (el.type === 'checkbox') el.checked = !!v;
            else el.value = v;
        });
    }

    function preValidate(form) {
        var err = null;
        if (form.elements.namedItem('create_user')) err = nuValidate();
        else if (form.elements.namedItem('reset_user_password')) err = rpValidate();
        else if (form.elements.namedItem('change_password') && form.elements.namedItem('new_password').value !== form.elements.namedItem('confirm_password').value) {
            err = ['cp_confirm', t('pwd.new_not_identical')];
        }
        if (!err) return true;
        var modal = form.closest('.modal');
        if (modal) formMsg(modal, err[1]); else flash('err', err[1], flashHost(form));
        var f = $(err[0]);
        if (f) f.focus();
        return false;
    }

    /* Potwierdzenia przed wysłaniem (ręczny ban, ustawienie limitu wszystkim kontom). */
    function needsConfirm(form, go) {
        if (form.dataset.confirm === 'ban') {
            var ip = (form.elements.namedItem('f2b_ip').value || '').trim();
            var jail = form.elements.namedItem('f2b_jail').value;
            if (!ip) { form.elements.namedItem('f2b_ip').focus(); return true; }
            askConfirm({
                title: t('ban.title'), danger: true, okLabel: t('ban.ok'), busyLabel: t('ban.busy'),
                msg: t('ban.msg', { ip: ip, jail: jail }),
                run: function () { return go(); },
                form: form,
                after: function () { form.elements.namedItem('f2b_ip').value = ''; }
            });
            return true;
        }
        var chk = form.dataset.confirmCheck && form.elements.namedItem(form.dataset.confirmCheck);
        if (chk && chk.checked) {
            askConfirm({
                title: t('quota.all.title'), okLabel: t('quota.all.ok'), busyLabel: t('btn.saving'),
                msg: t('quota.all.msg', { value: form.elements.namedItem('mailbox_quota_value').value, unit: form.elements.namedItem('mailbox_quota_unit').value }),
                run: function () { return go(); },
                form: form,
                after: function (d) { applyValues(form, d.values); afterSettingsSaved(form, d); }
            });
            return true;
        }
        // Potwierdzenie z tekstami z szablonu (data-confirm-title / -msg / -ok / -busy), np. usunięcie języka.
        if (form.dataset.confirm === 'generic') {
            askConfirm({
                title: form.dataset.confirmTitle || '', danger: true, okLabel: form.dataset.confirmOk, busyLabel: form.dataset.confirmBusy,
                msg: form.dataset.confirmMsg || '',
                run: function () { return go(); },
                form: form,
                after: function (d) { afterLangOp(form, d); }
            });
            return true;
        }
        return false;
    }

    /* Zapis ustawień: sekret CAPTCHA nigdy nie wraca do przeglądarki — po zapisie pole jest puste, a podpowiedź mówi,
       czy dla danego dostawcy sekret jest zapisany. */
    function afterSettingsSaved(form, d) {
        var set = (d.data && d.data.captcha_secret_set) || {};
        Object.keys(set).forEach(function (pid) {
            var sec = form.elements.namedItem(pid + '_secret_key');
            if (sec && set[pid]) sec.placeholder = sec.dataset.placeholderSet || sec.placeholder;
        });
        captchaSwitch(form.querySelector('[data-captcha-switch]'));
    }

    /* Ustawienia → CAPTCHA: widoczne są tylko pola kluczy wybranego dostawcy. */
    function captchaSwitch(sel) {
        if (!sel) return;
        document.querySelectorAll('.captcha-keys[data-captcha-for]').forEach(function (box) {
            box.toggleAttribute('data-inactive', box.dataset.captchaFor !== sel.value);
        });
    }

    /* Języki: po każdej operacji serwer odsyła świeże wiersze tabeli; formularze kopii / wgrania czyszczą się po sukcesie. */
    /* Przechowywanie logów: notki pod tabelami logów i "ostatnie sprzątanie" od razu po zapisie / czyszczeniu. */
    function afterRetention(d) {
        if (!d || !d.data) return;
        if (typeof d.data.retention_note === 'string') {
            document.querySelectorAll('.log-retention-note span').forEach(function (el) { el.textContent = d.data.retention_note; });
        }
        var last = $('retentionLast');
        if (last && typeof d.data.retention_last === 'string') last.textContent = d.data.retention_last;
    }

    function afterLangOp(form, d) {
        if (!d || !d.data || typeof d.data.languages_html !== 'string') return;
        var rows = $('lang-rows');
        if (rows) rows.innerHTML = d.data.languages_html;
        // przełącznik języka w nagłówku (pusty, gdy włączony jest tylko jeden język) — od razu, bez przeładowania
        var slot = $('langSwitchSlot');
        if (slot && typeof d.data.lang_switch_html === 'string') slot.innerHTML = d.data.lang_switch_html;
        // lista "język źródłowy" kopii: dodane / usunięte języki
        var src = $('lang_dup_from');
        if (src && Array.isArray(d.data.lang_sources)) {
            var keep = src.value;
            src.innerHTML = '';
            d.data.lang_sources.forEach(function (o) {
                var opt = document.createElement('option');
                opt.value = o[0];
                opt.textContent = o[1];
                src.appendChild(opt);
            });
            if (d.data.lang_sources.some(function (o) { return o[0] === keep; })) src.value = keep;
        }
        if (d.ok && form && form.hasAttribute('data-reset-on-success')) form.reset();
    }

    function submitAjax(form, submitter) {
        if (expired) { toast('err', t('session.expired')); return; }
        if (!preValidate(form)) return;
        var btn = submitter || form.querySelector('[type="submit"]');
        var modal = form.closest('.modal');
        var send = function () {
            var fd = new FormData(form);
            fd.delete('username');
            return postFields(fd, 120000);
        };
        if (needsConfirm(form, send)) return;

        busy(btn, true, form.dataset.busyLabel || t('btn.saving'));
        if (modal) formMsg(modal, '');
        send().then(function (d) {
            busy(btn, false);
            if (d.auth) return;
            applyValues(form, d.values);
            if (form.elements.namedItem('update_settings')) afterSettingsSaved(form, d);

            if (modal) {
                if (d.ok || d.done) {
                    closeModal(modal.id, true);
                    report(d);
                    if (form.elements.namedItem('create_user')) form.reset();
                } else {
                    formMsg(modal, d.error || t('op.failed'));
                    if (d.data && d.data.name) renderNameStatus(Object.assign({ email: '' }, d.data.name));
                    var pass = form.querySelector('input[type="password"]');
                    if (form.elements.namedItem('delete_user_confirm') && pass) pass.focus();
                }
            } else {
                if (d.ok) flashSaved(btn);
                report(d, form);
                afterLangOp(form, d);
                afterRetention(d);
            }
            refreshAfter(d, { flash: d.data && d.data.id });
        });
    }

    /* ---------------- Delegacja: kliknięcia ---------------- */

    document.addEventListener('click', function (e) {
        var tg = e.target;
        if (tg.closest('.flash-close')) { dismissFlash(tg.closest('.flash'), true); return; }
        if (tg.closest('#confirm_ok')) { runConfirm(); return; }
        if (tg.closest('#mb_unban_btn')) { manageUnban(); return; }
        if (tg.closest('#mb_move_btn')) { manageMove(); return; }
        if (tg.closest('#scan_run')) { runScan(); return; }

        var sunb = tg.closest('[data-scan-unban]');
        if (sunb) {
            var uip = sunb.dataset.scanUnban;
            var bi = bansIndex[uip] || { type: 'fail2ban', jail: '', label: 'fail2ban' };
            askConfirm({
                title: t('unban.title'), okLabel: t('unban.ok'), busyLabel: t('unban.busy'),
                msg: t('unban.msg_label', { ip: uip, label: bi.label }),
                run: function () { return postFields({ unban_ip: 1, ip: uip, unban_type: bi.type, jail: bi.jail }); },
                after: function (d) { scanNote(true, d.msg); }
            });
            return;
        }
        var sban = tg.closest('[data-scan-ban]');
        if (sban) {
            var sip = sban.dataset.scanBan;
            var sel = sban.closest('.scan-ban-controls').querySelector('.scan-ban-sel');
            var mode = sel ? sel.value : 'perma';
            var optLabel = sel ? sel.options[sel.selectedIndex].text : t('scan.perma_label');
            scanBan(sban, sip, mode, optLabel);
            return;
        }

        var tab = tg.closest('.tab[data-tab]');
        if (tab) { showTab(tab.dataset.tab); return; }

        var qs = tg.closest('[data-qsort]');
        if (qs) { sortUsers('ico', qs.dataset.qsort, e.shiftKey); return; }

        var th = tg.closest('th.sortable[data-col]');
        if (th) {
            if (th.dataset.nav === 'bans') sortBansTable(parseInt(th.dataset.col, 10), true);
            else changeSort(th.dataset.nav, th.dataset.col, e.shiftKey);
            return;
        }

        var pag = tg.closest('.pagination-btn[data-page]');
        if (pag) {
            if (pag.disabled || pag.classList.contains('active')) return;
            var pnav = pag.dataset.nav;
            if (state[pnav]) {
                state[pnav].page = parseInt(pag.dataset.page, 10);
                loaders[pnav]({ paging: true, focusPage: document.activeElement === pag });
            }
            return;
        }

        var advBtn = tg.closest('[data-adv-clear]');
        if (advBtn) { advClearStart($(advBtn.dataset.advClear)); return; }

        var el = tg.closest('[data-action]');
        if (!el) return;
        var a = el.dataset.action;
        if (a === 'search-ip')        triggerSearch(el.dataset.ip, e.shiftKey);
        else if (a === 'ban-ip')      showDirectBanModal(el.dataset.ip);
        else if (a === 'quota')       showQuotaModal(el.dataset.id, el.dataset.email, parseInt(el.dataset.quota, 10), el.dataset.sendLimit);
        else if (a === 'ban')         showBanModal(el.dataset.email);
        else if (a === 'add-user')    openAddUser();
        else if (a === 'reset-pass')  showResetPassModal(el.dataset.id, el.dataset.email);
        else if (a === 'manage-ban')  openManageBan(el.dataset);
        else if (a === 'open-scanner') openScanner();
        else if (a === 'delete')      showDeleteModal(el.dataset.id, el.dataset.email);
        else if (a === 'delete-alias') deleteAlias(el);
        else if (a === 'show-user')   { closeModal('addUserModal', true); triggerSearch(el.dataset.email, false); }
        else if (a === 'refresh-audit') { busy(el, true, t('btn.refreshing')); loadAudit({ paging: true }).then(function () { busy(el, false); }); }
        else if (a === 'modal-close') closeModal(el.dataset.modal);
    });

    // klik w tło okna zamyka je (tylko gdy i wciśnięcie, i puszczenie było na tle — przeciągnięcie zaznaczenia nie zamyka)
    var downOnBackdrop = null;
    document.addEventListener('mousedown', function (e) { downOnBackdrop = e.target.classList && e.target.classList.contains('modal') ? e.target : null; });
    document.addEventListener('click', function (e) {
        if (downOnBackdrop && e.target === downOnBackdrop) closeModal(downOnBackdrop.id);
        downOnBackdrop = null;
    });

    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (f.matches && f.matches('form[data-ajax]')) {
            e.preventDefault();
            submitAjax(f, e.submitter);
        }
    });

    /* ---------------- Klawiatura ---------------- */

    document.addEventListener('keydown', function (e) {
        var m = topModal();
        if (m) {
            if (e.key === 'Escape') { e.preventDefault(); closeModal(m.id); return; }
            if (e.key === 'Tab') {                    // fokus nie wychodzi z otwartego okna
                var f = focusables(m);
                if (!f.length) return;
                var first = f[0], last = f[f.length - 1];
                if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
                else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
                else if (!m.contains(document.activeElement)) { e.preventDefault(); first.focus(); }
            }
            return;
        }
        var tab = e.target.closest && e.target.closest('.tab[role="tab"]');
        if (tab && ['ArrowLeft', 'ArrowRight', 'Home', 'End'].indexOf(e.key) > -1) {
            var i = TABS.indexOf(tab.dataset.tab);
            var n = e.key === 'Home' ? 0 : e.key === 'End' ? TABS.length - 1 : (i + (e.key === 'ArrowRight' ? 1 : TABS.length - 1)) % TABS.length;
            e.preventDefault();
            showTab(TABS[n], true);
            return;
        }
        var th = e.target.closest && e.target.closest('th.sortable');
        if (th && (e.key === 'Enter' || e.key === ' ') && e.target === th) {
            e.preventDefault();
            if (th.dataset.nav === 'bans') sortBansTable(parseInt(th.dataset.col, 10), true);
            else changeSort(th.dataset.nav, th.dataset.col, e.shiftKey);
        }
    });

    /* ---------------- Zmiany / wpisywanie ---------------- */

    document.addEventListener('change', function (e) {
        if (e.target.matches && e.target.matches('[data-captcha-switch]')) captchaSwitch(e.target);
        var per = e.target.closest('[data-per]');
        if (per) {
            var nav = per.dataset.per;
            state[nav].per = parseInt(per.value, 10);
            state[nav].page = 1;
            releaseWidths();
            loaders[nav]();
        }
        if (e.target.id === 'ban_reason_select') {
            var custom = $('custom_reason_group');
            if (e.target.value === 'custom') { custom.hidden = false; $('ban_reason_input').value = $('ban_custom_reason').value || t('ban.custom_reason'); $('ban_custom_reason').focus(); }
            else { custom.hidden = true; $('ban_reason_input').value = e.target.value; }
        }
        if (e.target.id === 'nu_domain') nuCheckAvailability();
        if (e.target.id === 'mb_target_jail') $('mb_duration').hidden = e.target.value !== 'panel-tempban';
        if (e.target.id === 'nu_allow_reserved') formMsg($('addUserModal'), '');
    });

    var logSearchTimer = null;

    document.addEventListener('input', function (e) {
        var id = e.target.id;
        if (id && document.querySelector('[data-adv-clear="' + id + '"]')) syncAdvClear(e.target);
        if (id === 'user-search-input') {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function () { state.users.page = 1; loadUsers(); }, 400);
        }
        if (id === 'log-search-input') {
            clearTimeout(logSearchTimer);
            var val = e.target.value.trim();
            logSearchTimer = setTimeout(function () {
                ['reg', 'admin', 'audit'].forEach(function (nav) { state[nav].search = val; state[nav].page = 1; });
                loadReg(); loadAdmin(); loadAudit();
            }, 400);
        }
        if (id === 'ban-search-input') applyBansFilter();
        if (id === 'ban_custom_reason') $('ban_reason_input').value = e.target.value || t('ban.custom_reason');
        if (id === 'nu_local') {
            formMsg($('addUserModal'), '');
            clearTimeout(nuAvailTimer);
            nuSetAvail('', '');
            nuAvailTimer = setTimeout(nuCheckAvailability, 400);
        }
        if (id === 'nu_password' || id === 'nu_password2') {
            formMsg($('addUserModal'), '');
            pwdMeter('nu_pwd_bar', 'nu_pwd_reqs', $('nu_password').value);
            pwdMatch($('nu_password').value, $('nu_password2').value, $('nu_pass_match'));
        }
        if (id === 'rp_password' || id === 'rp_password2') {
            formMsg($('resetPassModal'), '');
            pwdMeter('rp_pwd_bar', 'rp_pwd_reqs', $('rp_password').value);
            pwdMatch($('rp_password').value, $('rp_password2').value, $('rp_pass_match'));
        }
        if (id === 'del_admin_pass') formMsg($('deleteUserModal'), '');
        if (id === 'scan_min' && scan.agg && !scan.running) renderScanResults();
    });

    /* ---------------- Motyw ---------------- */

    function initTheme() {
        var btn = $('themeToggle'), icon = $('themeIcon');
        var apply = function (light) {
            body.classList.toggle('dark-mode', !light);
            icon.className = light ? 'ri-sun-line' : 'ri-moon-line';
            btn.setAttribute('aria-pressed', light ? 'true' : 'false');
        };
        // dawny wybór zapisany tylko w localStorage (starsze wersje) -> przenieś do ciasteczka (serwer renderuje motyw)
        try { if (localStorage.getItem('theme') === 'light' && document.cookie.indexOf('mp_theme=') === -1) { apply(true); saveTheme(true); } } catch (e) { /* prywatny tryb */ }
        btn.addEventListener('click', function () {
            var light = body.classList.contains('dark-mode');
            apply(light);
            saveTheme(light);
        });
    }

    function saveTheme(light) {
        var path = location.pathname.replace(/[^/]*$/, '') || '/';
        document.cookie = 'mp_theme=' + (light ? 'light' : 'dark') + '; path=' + path + '; max-age=31536000; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
        try { localStorage.setItem('theme', light ? 'light' : 'dark'); } catch (e) { /* prywatny tryb */ }
    }

    /* ---------------- Start ---------------- */

    window.addEventListener('resize', (function () {
        var timer = null;
        return function () { clearTimeout(timer); timer = setTimeout(releaseWidths, 250); };
    })());
    initTheme();
    rebuildBansIndex();
    renderQSortIcons();
    var hash = (location.hash || '').substring(1);
    if (TABS.indexOf(hash) > -1) showTab(hash);           // stare zakładki (#users) -> ?tab=users
    afterUsersRendered(null);                              // pierwsza strona kont jest już w HTML — dociągamy tylko użycie

    /* Przełącznik języka (<details>): zamyka się klawiszem Esc (fokus wraca na przycisk) i kliknięciem poza nim. */
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var open = document.querySelector('.lang-switch[open]');
        if (open) { open.open = false; open.querySelector('summary').focus(); }
    });
    document.addEventListener('click', function (e) {
        document.querySelectorAll('.lang-switch[open]').forEach(function (d) { if (!d.contains(e.target)) d.open = false; });
    });
})();
