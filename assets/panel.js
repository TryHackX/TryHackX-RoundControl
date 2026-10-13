/* =========================================================================
   Panel administratora — logika front-endu (delegacja zdarzeń, bez inline JS).

   1.2.0: nic nie przeładowuje strony. Każdy formularz (form[data-ajax]) idzie przez fetch() z polem _ajax i tokenem
   CSRF; serwer odpowiada JSON-em {ok, msg, error, refresh, values, data, done, auth, csrf}. Komunikaty są zawsze
   tekstem z serwera (powiadomienia w rogu nie przesuwają treści; błędy w oknach — w zarezerwowanym miejscu okna).
   Listy odświeżają się w miejscu: wysokość i szerokości kolumn są trzymane, więc tabela nie skacze.
   ========================================================================= */
(function () {
    'use strict';

    var ENDPOINT = 'index.php';
    var $ = function (id) { return document.getElementById(id); };
    var body = document.body;
    var REDUCED = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function esc(s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : String(s)); return d.innerHTML; }

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
        return r.json().catch(function () { return { ok: false, error: 'Serwer zwrócił nieoczekiwaną odpowiedź (HTTP ' + r.status + ').' }; })
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
            .catch(function () { return { ok: false, error: 'Brak połączenia z serwerem — nic nie zostało zapisane. Spróbuj ponownie.' }; });
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

    /* ---------------- Powiadomienia (nakładka, bez przesuwania treści) ---------------- */

    function toast(kind, text) {
        if (!text) return;
        var box = $('toasts');
        var el = document.createElement('div');
        el.className = 'toast toast--' + (kind === 'ok' ? 'ok' : 'err');
        el.setAttribute('role', kind === 'ok' ? 'status' : 'alert');
        el.innerHTML = '<i class="' + (kind === 'ok' ? 'ri-checkbox-circle-line' : 'ri-error-warning-line') + '" aria-hidden="true"></i>' +
            '<div class="toast-text"></div><button type="button" class="toast-close" aria-label="Zamknij powiadomienie"><i class="ri-close-line" aria-hidden="true"></i></button>';
        el.querySelector('.toast-text').textContent = text;
        box.appendChild(el);
        while (box.children.length > 4) box.removeChild(box.firstElementChild);
        if (kind === 'ok') armToast(el, 7000);
        return el;
    }

    // sukces znika sam (pauza, gdy kursor/fokus na nim); błąd zostaje, aż ktoś go zamknie
    function armToast(el, ms) {
        var t = null;
        var start = function () { t = setTimeout(function () { dismissToast(el); }, ms); };
        var stop = function () { clearTimeout(t); };
        el.addEventListener('mouseenter', stop);
        el.addEventListener('mouseleave', start);
        el.addEventListener('focusin', stop);
        el.addEventListener('focusout', start);
        start();
    }

    function dismissToast(el) {
        if (!el || !el.parentNode) return;
        el.classList.add('is-leaving');
        setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, REDUCED ? 0 : 200);
    }

    // wynik zapisu: sukces i/lub błąd (np. konto usunięte, ale plików nie przeniesiono — oba komunikaty)
    function report(d) {
        if (d.msg) toast('ok', d.msg);
        if (d.error && !d.auth) toast('err', d.error);
    }

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
        btn.innerHTML = '<i class="ri-check-line" aria-hidden="true"></i> Zapisano';
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
        users: { page: 1, per: 20, crit: [{ kind: 'srv', key: 'id', dir: 'DESC' }], usage: null, filters: [] },
        reg:   { page: 1, per: 20, sort: [], search: '' },
        admin: { page: 1, per: 20, sort: [], search: '' },
        audit: { page: 1, per: 20, sort: [], search: '' }
    };
    var LIST = {
        users: { tbody: 'users-tbody', pag: 'users-pagination', total: 'total-results-text', label: 'Łącznie kont:', param: 'ajax_fetch_users' },
        reg:   { tbody: 'reg-logs-tbody', pag: 'reg-logs-pagination', total: 'reg-total-text', label: 'Łącznie zdarzeń:', param: 'ajax_fetch_reg_logs' },
        admin: { tbody: 'admin-logs-tbody', pag: 'admin-logs-pagination', total: 'admin-total-text', label: 'Łącznie logów:', param: 'ajax_fetch_admin_logs' },
        audit: { tbody: 'audit-logs-tbody', pag: 'audit-logs-pagination', total: 'audit-total-text', label: 'Łącznie wpisów:', param: 'ajax_fetch_audit_logs' }
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
                if (!d.auth) toast('err', d.error || 'Nie udało się wczytać listy.');
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
            if (my === seq[nav]) toast('err', 'Nie udało się wczytać listy — sprawdź połączenie.');
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
        if (kb <= 0) return '0 B';
        if (kb < 1024) return kb + ' KiB';
        if (kb < 1048576) { var m = kb / 1024; return (m >= 100 ? Math.round(m) : Math.round(m * 10) / 10) + ' MiB'; }
        var g = kb / 1048576;
        return (g >= 100 ? Math.round(g) : Math.round(g * 100) / 100) + ' GiB';
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
        c.innerHTML = '<div class="usage-wrap" title="' + fmtKb(q.used_kb) + ' / ' + limit + ' · ' + msgs + ' wiadomości">' +
            '<div class="usage-bar"><div class="usage-fill ' + cls + '" style="width:' + q.pct + '%"></div></div>' +
            '<span class="usage-txt">' + fmtKb(q.used_kb) + ' · ' + q.pct + '%</span></div>';
    }

    function applyClientSorts() {
        if (state.users.usage) applyUsageSort();
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
                        c.innerHTML = '<span class="usage-na" title="Brak danych z Dovecot">—</span>';
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

    /* ---------------- Sortowanie ---------------- */

    function indHtml(dir, n) {
        return '<span class="contrast-color">' + (dir === 'ASC' ? '▲' : '▼') + '</span>' +
            (n ? '<sup class="contrast-color sub-style">' + n + '</sup>' : '');
    }
    var PLACEHOLDER = '<span class="sort-placeholder">⇅</span>';

    function setInd(th, html, ariaDir) {
        var ind = th.querySelector('.sort-ind');
        if (ind) ind.innerHTML = html;
        if (ariaDir) th.setAttribute('aria-sort', ariaDir === 'ASC' ? 'ascending' : 'descending');
        else th.removeAttribute('aria-sort');
    }

    function renderSortHeaders(nav) {
        document.querySelectorAll(navSel(nav)).forEach(function (th) { setInd(th, PLACEHOLDER, null); });

        if (nav === 'users') {
            state.users.crit.forEach(function (c, i) {
                if (c.kind !== 'srv') return;
                var th = document.querySelector(navSel('users') + '[data-col="' + c.key + '"]');
                if (th) setInd(th, indHtml(c.dir, i + 1), i === 0 ? c.dir : null);
            });
            if (state.users.usage) {
                var uth = document.querySelector(navSel('users') + '[data-col="usage"]');
                if (uth) setInd(uth, indHtml(state.users.usage, 0), state.users.usage);
            }
            renderQSortIcons();
            return;
        }
        if (nav === 'bans') return;
        state[nav].sort.forEach(function (s, i) {
            var th = document.querySelector(navSel(nav) + '[data-col="' + s.field + '"]');
            if (th) setInd(th, indHtml(s.dir, i + 1), i === 0 ? s.dir : null);
        });
    }

    /* Ikony sortowania po danych Dovecot (w nagłówku Quota). Klik: brak → ▼ → ▲ → wyłączone. */
    function renderQSortIcons() {
        var crit = state.users.crit;
        document.querySelectorAll('.qsort-ico[data-qsort]').forEach(function (ico) {
            var idx = crit.findIndex(function (c) { return c.kind === 'ico' && c.key === ico.dataset.qsort; });
            var s = idx > -1 ? crit[idx] : null;
            ico.classList.toggle('on', !!s);
            ico.setAttribute('aria-pressed', s ? 'true' : 'false');
            var n = ico.querySelector('.qsort-n');
            if (n) n.innerHTML = s ? indHtml(s.dir, idx + 1) : '';
        });
    }

    function changeSort(nav, field, multi) {
        if (nav === 'users' && field === 'usage') {
            state.users.crit = [];
            state.users.usage = state.users.usage === 'DESC' ? 'ASC' : 'DESC';
            renderSortHeaders('users');
            applyUsageSort();
            return;
        }
        if (nav === 'users') {
            state.users.usage = null;
            var crit = state.users.crit;
            var i = crit.findIndex(function (c) { return c.kind === 'srv' && c.key === field; });
            if (multi) {
                if (i !== -1) crit[i].dir = crit[i].dir === 'ASC' ? 'DESC' : 'ASC';
                else crit.push({ kind: 'srv', key: field, dir: 'ASC' });
            } else if (i === 0 && crit.length === 1) {
                crit[0].dir = crit[0].dir === 'ASC' ? 'DESC' : 'ASC';
            } else {
                state.users.crit = [{ kind: 'srv', key: field, dir: 'ASC' }];
            }
            renderSortHeaders('users');
            loadUsers({ paging: true });
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

    function usageVal(tr) {
        var c = tr.querySelector('.usage-cell');
        if (!c || c.dataset.usedKb === undefined) return null;
        var v = parseFloat(c.dataset.usedKb);
        return isNaN(v) ? null : v;
    }

    function applyUsageSort() {
        var tbody = $('users-tbody');
        if (!tbody) return;
        var dir = state.users.usage === 'ASC' ? 1 : -1;
        var rows = userRows();
        rows.sort(function (a, b) {
            var av = usageVal(a), bv = usageVal(b);
            if (av === null && bv === null) return 0;
            if (av === null) return 1;
            if (bv === null) return -1;
            return (av - bv) * dir;
        });
        rows.forEach(function (r) { tbody.appendChild(r); });
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
        var t = function (i) { var v = tr.children[i] ? tr.children[i].textContent.trim() : ''; return v === '-' || v === '' ? null : v; };
        switch (c.key) {
            case 'id':         return parseInt(tr.children[0].textContent, 10);
            case 'email':      return (t(1) || '').toLowerCase();
            case 'created':    return t(2);
            case 'last_login': return t(3);
            case 'reg_ip':     return t(4);
            case 'quota':      var b = tr.querySelector('[data-action="quota"]'); return b ? parseInt(b.dataset.quota, 10) : null;
        }
        return null;
    }

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
                    : String(av).localeCompare(String(bv), 'pl', { numeric: true });
                if (cmp !== 0) return cmp * (crit[i].dir === 'ASC' ? 1 : -1);
            }
            return 0;
        });
        rows.forEach(function (r) { tbody.appendChild(r); });
    }

    function toggleQSortKey(key) {
        state.users.usage = null;
        var crit = state.users.crit;
        var i = crit.findIndex(function (c) { return c.kind === 'ico' && c.key === key; });
        if (i === -1) crit.push({ kind: 'ico', key: key, dir: 'DESC' });
        else if (crit[i].dir === 'DESC') crit[i].dir = 'ASC';
        else crit.splice(i, 1);
        renderSortHeaders('users');
        applyUnifiedSort();
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
            return String(ka).localeCompare(String(kb), 'pl', { numeric: true }) * mult;
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
            bansIndex[b.dataset.ip] = { type: b.dataset.type, jail: b.dataset.jail || '', label: b.dataset.type === 'local' ? 'blokada lokalna' : (b.dataset.jail || 'fail2ban') };
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
        box.innerHTML = '<i class="ri-loader-4-line spin" aria-hidden="true"></i> Ładowanie…';
        getJSON(ENDPOINT + '?ajax_sent_today=1&id=' + encodeURIComponent(id))
            .then(function (d) {
                if (!d.ok) { box.innerHTML = '<span class="usage-na">—</span>'; return; }
                var limitTxt = d.limit > 0 ? d.limit : '∞';
                var src = d.individual ? 'limit indywidualny' : 'limit globalny';
                var cls = d.limit > 0 && d.sent >= d.limit ? 'crit' : (d.limit > 0 && d.sent >= d.limit * 0.8 ? 'warn' : 'ok');
                box.innerHTML = '<b class="sent-' + cls + '">' + (d.sent | 0) + '</b> / ' + esc(limitTxt) +
                    ' odbiorców <span class="sent-src">(' + src + ')</span>';
            })
            .catch(function () { box.innerHTML = '<span class="usage-na">—</span>'; });
    }

    function showBanModal(email) {
        $('ban_user_email').value = email;
        formMsg($('banModal'), '');
        var sel = $('ban_ip_select');
        sel.innerHTML = '<option value="">Ładowanie IP…</option>';
        tfetch('user_ips.php?email=' + encodeURIComponent(email)).then(readJson)
            .then(function (d) {
                sel.innerHTML = '<option value="">-- Wybierz IP --</option>';
                if (d.error) { sel.innerHTML = '<option value="">Brak IP (błąd)</option>'; return; }
                var added = {};
                var labels = { reg_ip: 'Rejestracja', first_login_ip: 'Pierwsze logowanie', prev_login_ip: 'Poprzednie logowanie', last_login_ip: 'Ostatnie logowanie' };
                if (d.flags) Object.keys(labels).forEach(function (k) {
                    var v = d.flags[k];
                    if (v && !added[v]) { addOpt(sel, v, v + ' (' + labels[k] + ')'); added[v] = 1; }
                });
                if (Array.isArray(d.recent)) d.recent.forEach(function (ip) { if (ip && !added[ip]) { addOpt(sel, ip, ip); added[ip] = 1; } });
                if (sel.options.length === 1) sel.innerHTML = '<option value="">Brak znanych IP</option>';
            }).catch(function () { sel.innerHTML = '<option value="">Błąd pobierania IP</option>'; });
        openModal('banModal', 'ban_ip_select');
    }

    function showDirectBanModal(ip) {
        $('ban_user_email').value = 'N/A (log logowania administratorów)';
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
        ok.innerHTML = '<i class="ri-check-line" aria-hidden="true"></i> ' + esc(o.okLabel || 'Potwierdź');
        formMsg($('confirmModal'), '');
        confirmAction = o;
        openModal('confirmModal', 'confirm_ok');
    }

    function runConfirm() {
        var o = confirmAction;
        if (!o) return;
        var ok = $('confirm_ok');
        busy(ok, true, o.busyLabel || 'Wykonywanie…');
        Promise.resolve(o.run()).then(function (d) {
            busy(ok, false);
            d = d || {};
            if (d.auth) return;
            if (d.ok || d.done) {
                confirmAction = null;
                closeModal('confirmModal', true);
                report(d);
                if (o.after) o.after(d);
                refreshAfter(d);
            } else {
                formMsg($('confirmModal'), d.error || 'Operacja się nie powiodła.');
            }
        });
    }

    /* ---------------- Zarządzanie banem ---------------- */

    var currentBan = {};
    function openManageBan(data) {
        currentBan = { ip: data.ip, type: data.type, jail: data.jail || '' };
        $('mb_ip').textContent = data.ip;
        $('mb_type').textContent = data.type === 'local' ? 'Lokalny (baza panelu)' : 'Fail2Ban — jail: ' + currentBan.jail;
        $('mb_change_wrap').hidden = data.type === 'local';
        $('mb_duration').hidden = $('mb_target_jail').value !== 'panel-tempban';
        openModal('manageBanModal', data.type === 'local' ? 'mb_unban_btn' : 'mb_target_jail');
    }

    function manageUnban() {
        var b = currentBan;
        closeModal('manageBanModal');
        askConfirm({
            title: 'Odblokować IP?', msg: 'Adres ' + b.ip + ' zostanie całkowicie odblokowany.', okLabel: 'Odblokuj', busyLabel: 'Odblokowywanie…',
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
            title: 'Zmienić typ bana?', okLabel: 'Przenieś', busyLabel: 'Przenoszenie…',
            msg: 'IP ' + b.ip + ' zostanie przeniesione: ' + b.jail + ' → ' + target + (isTemp ? ' (na ' + durLabel + ')' : '') + '.',
            run: function () { return postFields({ move_ban: 1, ip: b.ip, from_jail: b.jail, to_jail: target, duration: duration }); }
        });
    }

    /* ---------------- Skaner podejrzanych IP ---------------- */

    var SCAN_SOURCES = [
        { key: 'ssh', label: 'SSH' }, { key: 'dovecot', label: 'Dovecot' },
        { key: 'postfix', label: 'Postfix SASL' }, { key: 'db', label: 'Baza panelu' }
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
        busy($('scan_run'), true, 'Skanowanie…');
        $('scan_results').innerHTML = '<p class="empty-state-text">Na razie brak wyników…</p>';
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
                ? '<i class="ri-error-warning-line" aria-hidden="true"></i> Zakończono z błędami źródeł (' + scan.failed.length + ')'
                : '<i class="ri-checkbox-circle-line" aria-hidden="true"></i> Przeszukano wszystkie źródła (' + esc(scan.from) + ' → ' + esc(scan.to) + ')';
            $('scan_progress_fill').style.width = '100%';
            renderScanSteps(SCAN_SOURCES.length);
            renderScanResults();
            return;
        }
        var src = SCAN_SOURCES[i];
        $('scan_status_label').innerHTML = '<i class="ri-loader-4-line spin" aria-hidden="true"></i> Skanuję: <b>' + src.label + '</b> (' + (i + 1) + '/' + SCAN_SOURCES.length + ')';
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
        if (b) return '<span class="scan-banned"><i class="ri-checkbox-circle-line" aria-hidden="true"></i> Zbanowano (' + esc(b) + ')</span>';
        if (bansIndex[ip]) {
            return '<div class="scan-ban-controls"><span class="scan-already" title="To IP jest już na liście blokad">' +
                '<i class="ri-shield-check-line" aria-hidden="true"></i> Już zbanowane (' + esc(bansIndex[ip].label) + ')</span>' +
                '<button type="button" class="btn-action btn-manage" data-scan-unban="' + esc(ip) + '"><i class="ri-lock-unlock-line" aria-hidden="true"></i> Odbanuj</button></div>';
        }
        return '<div class="scan-ban-controls"><select class="scan-ban-sel" aria-label="Rodzaj blokady dla ' + esc(ip) + '">' +
            '<option value="perma">Na stałe</option><option value="3600">1 godz.</option><option value="21600">6 godz.</option>' +
            '<option value="86400">24 godz.</option><option value="259200">3 dni</option><option value="604800">7 dni</option>' +
            '<option value="2592000">30 dni</option><option value="7776000">3 miesiące</option></select>' +
            '<button type="button" class="btn-action btn-danger-action" data-scan-ban="' + esc(ip) + '"><i class="ri-forbid-line" aria-hidden="true"></i> Zbanuj</button></div>';
    }

    function renderScanResults() {
        var box = $('scan_results');
        var rows = scanRows();
        $('scan_status_count').textContent = 'Znalezione: ' + rows.length + ' IP (próg ≥ ' + scanMin() + ')';
        if (!rows.length) {
            box.innerHTML = scan.finished ? '<p class="empty-state-text">Brak podejrzanych IP dla podanych kryteriów.</p>'
                : '<p class="empty-state-text">Na razie brak wyników…</p>';
            return;
        }
        var h = '<div class="table-responsive"><table><thead><tr><th scope="col">IP</th><th scope="col">Nieudane</th><th scope="col">Usługi (liczba)</th><th scope="col">Blokada</th></tr></thead><tbody>';
        rows.slice(0, 100).forEach(function (r) {
            var svc = Object.keys(r.services).map(function (s) { return s + ': ' + r.services[s]; }).join(', ');
            h += '<tr><td class="scan-ip"><strong>' + esc(r.ip) + '</strong></td><td>' + r.total + '</td>' +
                '<td class="scan-svc">' + esc(svc) + '</td><td>' + scanActionCell(r.ip) + '</td></tr>';
        });
        h += '</tbody></table></div>';
        if (rows.length > 100) h += '<div class="help-text">Pokazano 100 z ' + rows.length + ' IP — zawęź zakres dat lub podnieś próg.</div>';
        box.innerHTML = h;
    }

    function scanBan(btn, ip, mode, label) {
        busy(btn, true, 'Banowanie…');
        postFields({ ajax_scan_ban: '1', ip: ip, mode: mode }).then(function (d) {
            busy(btn, false);
            if (d.auth) return;
            if (d.ok) {
                scan.banned[ip] = d.label || label;
                renderScanResults();
                refreshBans();
                loadAudit({ paging: true });
                scanNote(true, 'Zbanowano ' + ip + ' (' + (d.label || label) + '). Lista blokad na karcie została odświeżona.');
            } else {
                scanNote(false, d.error || 'Błąd banowania.');
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
                    b.appendChild(document.createTextNode('Usuń alias ' + a.source + ' → ' + a.destination));
                    actions.appendChild(b);
                });
            } else if (n.status === 'taken') {
                var s = document.createElement('button');
                s.type = 'button';
                s.className = 'btn btn-small btn-secondary';
                s.dataset.action = 'show-user';
                s.dataset.email = n.email || '';
                s.innerHTML = '<i class="ri-search-line" aria-hidden="true"></i> Pokaż na liście kont';
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
            nuSetAvail('taken', '✗ Niedozwolone znaki (litery, cyfry, . _ -; bez kropki ani myślnika na brzegach)');
            return Promise.resolve();
        }
        nuSetAvail('loading', 'Sprawdzanie…');
        return getJSON(ENDPOINT + '?ajax_check_user=1&local=' + encodeURIComponent(local) + '&domain=' + encodeURIComponent(domain))
            .then(function (d) {
                if (my !== nuSeq || d.auth) return;
                if (d.ok) renderNameStatus(d);
                else { nuResetName(); nuName = { status: 'invalid' }; nuSetAvail('taken', '✗ ' + (d.msg || 'Błąd')); }
            })
            .catch(function () { if (my === nuSeq) { nuName = null; nuSetAvail('taken', 'Brak połączenia — nie sprawdzono'); } });
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
        if (v1 === v2) { statusEl.className = 'avail-status free'; statusEl.textContent = 'Hasła są zgodne'; }
        else { statusEl.className = 'avail-status taken'; statusEl.textContent = 'Hasła różnią się'; }
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
        if (!local) return ['nu_local', 'Podaj nazwę konta.'];
        if (!NU_LOCAL_RE.test(local)) return ['nu_local', 'Niedozwolone znaki w nazwie konta (dozwolone: litery, cyfry, . _ -).'];
        if (nuName && nuName.status === 'taken') return ['nu_local', 'Konto o tym adresie już istnieje.'];
        if (nuName && nuName.status === 'alias') return ['nu_local', 'To adres aliasu — skrzynki o tej nazwie nie da się założyć (szczegóły i przycisk usunięcia aliasu pod polem adresu).'];
        if (nuName && nuName.override && !$('nu_allow_reserved').checked) return ['nu_allow_reserved', nuName.msg + ' — zaznacz zgodę pod polem adresu, jeśli to zamierzone.'];
        if (!pwdValid($('nu_password').value)) return ['nu_password', 'Hasło nie spełnia wymogów bezpieczeństwa.'];
        if ($('nu_password').value !== $('nu_password2').value) return ['nu_password2', 'Hasła nie są identyczne.'];
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
            title: 'Usunąć alias?', danger: true, okLabel: 'Usuń alias', busyLabel: 'Usuwanie…',
            msg: 'Alias ' + src + ' → ' + dst + ' zniknie z bazy: poczta na ' + src + ' przestanie trafiać do ' + dst +
                ' i do czasu założenia skrzynki ' + src + ' będzie odrzucana. Panel nie odtworzy aliasu (pełny wiersz zostanie w dzienniku audytowym).',
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
        if (!pwdValid($('rp_password').value)) return ['rp_password', 'Hasło nie spełnia wymogów bezpieczeństwa.'];
        if ($('rp_password').value !== $('rp_password2').value) return ['rp_password2', 'Hasła nie są identyczne.'];
        return null;
    }

    /* ---------------- Formularze: zapis przez fetch ---------------- */

    function applyValues(form, values) {
        if (!values) return;
        Object.keys(values).forEach(function (name) {
            var v = values[name];
            document.querySelectorAll('[data-bind="' + name + '"]').forEach(function (el) { el.textContent = v; });
            document.querySelectorAll('[data-placeholder-bind="' + name + '"]').forEach(function (el) { el.placeholder = 'puste = globalny (' + v + ')'; });
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
            err = ['cp_confirm', 'Nowe hasła nie są identyczne.'];
        }
        if (!err) return true;
        var modal = form.closest('.modal');
        if (modal) formMsg(modal, err[1]); else toast('err', err[1]);
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
                title: 'Zbanować IP?', danger: true, okLabel: 'Zbanuj', busyLabel: 'Banowanie…',
                msg: 'Adres ' + ip + ' zostanie zbanowany w Fail2Ban (jail: ' + jail + ').',
                run: function () { return go(); },
                after: function () { form.elements.namedItem('f2b_ip').value = ''; }
            });
            return true;
        }
        var chk = form.dataset.confirmCheck && form.elements.namedItem(form.dataset.confirmCheck);
        if (chk && chk.checked) {
            askConfirm({
                title: 'Ustawić limit wszystkim kontom?', okLabel: 'Zapisz i zastosuj', busyLabel: 'Zapisywanie…',
                msg: 'Domyślny limit (' + form.elements.namedItem('mailbox_quota_value').value + ' ' + form.elements.namedItem('mailbox_quota_unit').value +
                    ') zostanie od razu ustawiony WSZYSTKIM kontom poza admin@ — także tym, które mają dziś inny limit.',
                run: function () { return go(); },
                after: function (d) { applyValues(form, d.values); afterSettingsSaved(form, d); }
            });
            return true;
        }
        return false;
    }

    function afterSettingsSaved(form, d) {
        var sec = form.elements.namedItem('recaptcha_secret_key');
        if (sec && d.data && d.data.recaptcha_secret_set) sec.placeholder = sec.dataset.placeholderSet || sec.placeholder;
    }

    function submitAjax(form, submitter) {
        if (expired) { toast('err', 'Sesja wygasła — zaloguj się ponownie.'); return; }
        if (!preValidate(form)) return;
        var btn = submitter || form.querySelector('[type="submit"]');
        var modal = form.closest('.modal');
        var send = function () {
            var fd = new FormData(form);
            fd.delete('username');
            return postFields(fd, 120000);
        };
        if (needsConfirm(form, send)) return;

        busy(btn, true, form.dataset.busyLabel || 'Zapisywanie…');
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
                    formMsg(modal, d.error || 'Operacja się nie powiodła.');
                    if (d.data && d.data.name) renderNameStatus(Object.assign({ email: '' }, d.data.name));
                    var pass = form.querySelector('input[type="password"]');
                    if (form.elements.namedItem('delete_user_confirm') && pass) pass.focus();
                }
            } else {
                if (d.ok) flashSaved(btn);
                report(d);
            }
            refreshAfter(d, { flash: d.data && d.data.id });
        });
    }

    /* ---------------- Delegacja: kliknięcia ---------------- */

    document.addEventListener('click', function (e) {
        var t = e.target;
        if (t.closest('.toast-close')) { dismissToast(t.closest('.toast')); return; }
        if (t.closest('#confirm_ok')) { runConfirm(); return; }
        if (t.closest('#mb_unban_btn')) { manageUnban(); return; }
        if (t.closest('#mb_move_btn')) { manageMove(); return; }
        if (t.closest('#scan_run')) { runScan(); return; }

        var sunb = t.closest('[data-scan-unban]');
        if (sunb) {
            var uip = sunb.dataset.scanUnban;
            var bi = bansIndex[uip] || { type: 'fail2ban', jail: '', label: 'fail2ban' };
            askConfirm({
                title: 'Odblokować IP?', okLabel: 'Odblokuj', busyLabel: 'Odblokowywanie…',
                msg: 'Adres ' + uip + ' zostanie całkowicie odblokowany (' + bi.label + ').',
                run: function () { return postFields({ unban_ip: 1, ip: uip, unban_type: bi.type, jail: bi.jail }); },
                after: function (d) { scanNote(true, d.msg); }
            });
            return;
        }
        var sban = t.closest('[data-scan-ban]');
        if (sban) {
            var sip = sban.dataset.scanBan;
            var sel = sban.closest('.scan-ban-controls').querySelector('.scan-ban-sel');
            var mode = sel ? sel.value : 'perma';
            var optLabel = sel ? sel.options[sel.selectedIndex].text : 'na stałe';
            scanBan(sban, sip, mode, optLabel);
            return;
        }

        var tab = t.closest('.tab[data-tab]');
        if (tab) { showTab(tab.dataset.tab); return; }

        var qs = t.closest('[data-qsort]');
        if (qs) { toggleQSortKey(qs.dataset.qsort); return; }

        var th = t.closest('th.sortable[data-col]');
        if (th) {
            if (th.dataset.nav === 'bans') sortBansTable(parseInt(th.dataset.col, 10), true);
            else changeSort(th.dataset.nav, th.dataset.col, e.shiftKey);
            return;
        }

        var pag = t.closest('.pagination-btn[data-page]');
        if (pag) {
            if (pag.disabled || pag.classList.contains('active')) return;
            var pnav = pag.dataset.nav;
            if (state[pnav]) {
                state[pnav].page = parseInt(pag.dataset.page, 10);
                loaders[pnav]({ paging: true, focusPage: document.activeElement === pag });
            }
            return;
        }

        var advBtn = t.closest('[data-adv-clear]');
        if (advBtn) { advClearStart($(advBtn.dataset.advClear)); return; }

        var el = t.closest('[data-action]');
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
        else if (a === 'refresh-audit') { busy(el, true, 'Odświeżanie…'); loadAudit({ paging: true }).then(function () { busy(el, false); }); }
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
            if (e.target.value === 'custom') { custom.hidden = false; $('ban_reason_input').value = $('ban_custom_reason').value || 'Własny powód'; $('ban_custom_reason').focus(); }
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
        if (id === 'ban_custom_reason') $('ban_reason_input').value = e.target.value || 'Własny powód';
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
        // dawny wybór zapisany tylko w localStorage (sprzed 1.2.0) -> przenieś do ciasteczka (serwer renderuje motyw)
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
        var t = null;
        return function () { clearTimeout(t); t = setTimeout(releaseWidths, 250); };
    })());
    document.querySelectorAll('#toasts .toast--ok').forEach(function (el) { armToast(el, 9000); });
    initTheme();
    rebuildBansIndex();
    renderQSortIcons();
    var hash = (location.hash || '').substring(1);
    if (TABS.indexOf(hash) > -1) showTab(hash);           // stare zakładki (#users) -> ?tab=users
    afterUsersRendered(null);                              // pierwsza strona kont jest już w HTML — dociągamy tylko użycie
})();
