/* =========================================================================
   Panel administratora — logika front-endu (delegacja zdarzeń, bez inline JS).
   ========================================================================= */
(function () {
    'use strict';

    var ENDPOINT = 'index.php';
    var $ = function (id) { return document.getElementById(id); };

    // fetch z limitem czasu (domyślnie 30 s): zawieszone żądanie kończy się błędem (obsługuje go istniejący .catch)
    // zamiast wisieć w nieskończoność. Dłuższe limity tylko dla skanera logów i odczytu quoty (wiele wywołań doveadm).
    function tfetch(url, opts, ms) {
        opts = opts || {};
        if (typeof AbortController === 'undefined') return fetch(url, opts);
        var ctl = new AbortController();
        var timer = setTimeout(function () { ctl.abort(); }, ms || 30000);
        opts.signal = ctl.signal;
        return fetch(url, opts).then(function (r) { clearTimeout(timer); return r; }, function (e) { clearTimeout(timer); throw e; });
    }

    // Sort: users/reg/admin/audit = serwerowo (SQL); users.usage = klientowo
    // w obrębie strony (dane quoty doczytywane z Dovecot po renderze tabeli).
    // users.crit = JEDNA uporządkowana lista kryteriów sortowania w kolejności
    // klikania: {kind:'srv'|'ico', key, dir}. 'srv' = kolumny SQL (id, email,
    // created, last_login, reg_ip, quota), 'ico' = dane Dovecot (msgs, size).
    // Numer priorytetu = pozycja na liście; serwer dostaje podzbiór 'srv',
    // a klient dosortowuje stronę wg pełnej listy, gdy są kryteria 'ico'.
    var state = {
        users:  { page: 1, per: 20, crit: [{ kind: 'srv', key: 'id', dir: 'DESC' }], usage: null, filters: [] },
        reg:    { page: 1, per: 20, sort: [], search: '' },
        admin:  { page: 1, per: 20, sort: [], search: '' },
        audit:  { page: 1, per: 20, sort: [], search: '' }
    };
    var searchTimer = null;
    var sortLabels = {}; // nav -> col -> etykieta nagłówka

    function navSel(nav) {
        return nav === 'users' ? 'th.sortable:not([data-nav])' : 'th.sortable[data-nav="' + nav + '"]';
    }

    /* ---------------- Zakładki ---------------- */

    function showTab(id) {
        document.querySelectorAll('.tab').forEach(function (t) { t.classList.toggle('active', t.dataset.tab === id); });
        document.querySelectorAll('.tab-content').forEach(function (c) { c.classList.toggle('active', c.id === id); });
        if (history.pushState) history.pushState(null, '', '#' + id);
    }

    /* ---------------- Ładowanie list ---------------- */

    function sortParam(st) {
        if (!st.sort.length) return '';
        return '&sort=' + encodeURIComponent(st.sort.map(function (s) { return s.field + ':' + s.dir; }).join(','));
    }

    /* Rozbiór wyszukiwania kont: grupy po ";". Warunki liczbowe filtruje
       klient (dane Dovecot są tylko w przeglądarce):
         ">50%" / "użycie > 50%"  -> procent zapełnienia,
         "> 20 KiB" / "użycie >= 1 MiB" -> zajęty rozmiar,
         "quota > 1 GiB" -> limit skrzynki.
       Reszta idzie do serwera (e-mail / IP / daty / quota tekstowo). */
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
                if (m[5]) {                       // procent zapełnienia
                    filters.push({ target: 'pct', op: op, val: num });
                    return;
                }
                if (unit || kw) {                 // rozmiar w KiB (domyślna jednostka: MiB)
                    filters.push({
                        target: kw.indexOf('q') === 0 ? 'quota' : 'usage',
                        op: op,
                        val: num * (SIZE_KIB[unit] || 1024)
                    });
                    return;
                }
                if (m[2]) {                       // goły operator bez jednostki = procent
                    filters.push({ target: 'pct', op: op, val: num });
                    return;
                }
            }
            server.push(g);
        });
        return { server: server.join(';'), filters: filters };
    }

    /* Podzbiór kryteriów serwerowych z listy crit (dla zapytania SQL). */
    function usersSrvSort() {
        return state.users.crit
            .filter(function (c) { return c.kind === 'srv'; })
            .map(function (c) { return c.key + ':' + c.dir; })
            .join(',');
    }

    function loadUsers() {
        var st = state.users;
        var parsed = parseUsersSearch($('user-search-input').value.trim());
        st.filters = parsed.filters;

        var srv = usersSrvSort();
        var url = ENDPOINT + '?ajax_fetch_users=1&page=' + st.page + '&per=' + st.per
            + (srv ? '&sort=' + encodeURIComponent(srv) : '');
        if (parsed.server) url += '&search=' + encodeURIComponent(parsed.server);
        tfetch(url).then(function (r) { return r.json(); }).then(function (d) {
            $('users-tbody').innerHTML = d.html;
            $('users-pagination').innerHTML = d.pagination;
            $('total-results-text').innerHTML = 'Łącznie wyników: <b>' + d.total + '</b>';
            renderSortHeaders('users');
            // znane wartości od razu z cache (bez migotania "…"), potem
            // doveadm w tle zaktualizuje tylko te, które się zmieniły
            document.querySelectorAll('#users-tbody .usage-cell[data-email]').forEach(function (c) {
                var q = usageCache[c.dataset.email];
                if (q) renderUsageCell(c, q);
            });
            applyClientSorts();
            loadQuotaUsage();
        }).catch(function (e) { console.error(e); });
    }

    /* Czytelny rozmiar od bajtów do GiB (doveadm podaje KB). */
    function fmtKb(kb) {
        if (kb <= 0) return '0 B';
        if (kb < 1024) return kb + ' KiB';
        if (kb < 1048576) {
            var m = kb / 1024;
            return (m >= 100 ? Math.round(m) : Math.round(m * 10) / 10) + ' MiB';
        }
        var g = kb / 1048576;
        return (g >= 100 ? Math.round(g) : Math.round(g * 100) / 100) + ' GiB';
    }

    // Cache użycia per e-mail — utrzymuje wartości między przeładowaniami
    // tabeli i pozwala nie dotykać DOM, gdy nic się nie zmieniło.
    var usageCache = {};

    function sameUsage(a, b) {
        return a && b && a.used_kb === b.used_kb && a.limit_kb === b.limit_kb &&
            a.pct === b.pct && (a.msgs || 0) === (b.msgs || 0);
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

    /* Filtry liczbowe (procent / rozmiar / quota) — klientowe, bieżąca strona.
       Wiersze bez danych z Dovecot są chowane, dopóki filtr ich potrzebuje. */
    function applyUserFilters() {
        var crit = state.users.filters;
        document.querySelectorAll('#users-tbody tr').forEach(function (tr) {
            if (!crit.length) { tr.classList.remove('row-filtered'); return; }
            var cell = tr.querySelector('.usage-cell');
            var q = cell ? usageCache[cell.dataset.email] : null;
            var qbtn = tr.querySelector('[data-action="quota"]');
            var show = crit.every(function (c) {
                if (c.target === 'quota') {
                    if (!qbtn) return false;
                    return cmpOp(parseInt(qbtn.dataset.quota, 10) * 1024, c.op, c.val); // MiB -> KiB
                }
                if (!q) return false;
                return c.target === 'pct'
                    ? cmpOp(q.pct, c.op, c.val)
                    : cmpOp(q.used_kb, c.op, c.val);
            });
            tr.classList.toggle('row-filtered', !show);
        });
    }

    // Wykorzystanie quoty ładowane osobno (doveadm per konto) — tabela nie czeka.
    function loadQuotaUsage() {
        var cells = document.querySelectorAll('#users-tbody .usage-cell[data-email]');
        if (!cells.length) return;
        var emails = [];
        cells.forEach(function (c) { emails.push(c.dataset.email); });
        tfetch(ENDPOINT + '?ajax_quota_usage=1&emails=' + encodeURIComponent(emails.join(',')), null, 120000)
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var u = d.usage || {};
                cells.forEach(function (c) {
                    var email = c.dataset.email;
                    var q = u[email];
                    if (!q) {
                        delete usageCache[email];
                        delete c.dataset.usedKb;
                        delete c.dataset.msgCount;
                        c.innerHTML = '<span class="usage-na">—</span>';
                        return;
                    }
                    if (sameUsage(usageCache[email], q)) {  // bez zmian => bez dotykania DOM
                        usageCache[email] = q;
                        return;
                    }
                    usageCache[email] = q;
                    renderUsageCell(c, q);
                });
                applyClientSorts();
            })
            .catch(function () {
                cells.forEach(function (c) {
                    if (c.querySelector('.usage-wait')) c.innerHTML = '<span class="usage-na">—</span>';
                });
            });
    }

    function arrowSup(dir, n) {
        return ' <span class="contrast-color">' + (dir === 'ASC' ? '▲' : '▼') +
            '</span><sup class="contrast-color sub-style">' + n + '</sup>';
    }

    function renderSortHeaders(nav) {
        var labels = sortLabels[nav] || {};
        document.querySelectorAll(navSel(nav)).forEach(function (th) {
            th.innerHTML = labels[th.dataset.col] + ' <span class="sort-placeholder">⇅</span>';
        });

        if (nav === 'users') {
            // wspólna numeracja: pozycja na liście crit = priorytet
            state.users.crit.forEach(function (c, i) {
                if (c.kind !== 'srv') return;
                var th = document.querySelector(navSel('users') + '[data-col="' + c.key + '"]');
                if (th) th.innerHTML = labels[c.key] + arrowSup(c.dir, i + 1);
            });
            if (state.users.usage) {
                var uth = document.querySelector(navSel('users') + '[data-col="usage"]');
                if (uth) uth.innerHTML = labels.usage + ' <span class="contrast-color">' + (state.users.usage === 'ASC' ? '▲' : '▼') + '</span>';
            }
            renderQSortIcons();
            return;
        }

        state[nav].sort.forEach(function (s, i) {
            var th = document.querySelector(navSel(nav) + '[data-col="' + s.field + '"]');
            if (th) th.innerHTML = labels[s.field] + arrowSup(s.dir, i + 1);
        });
    }

    /* Ikony sortowania po danych Dovecot — na stałe w nagłówku Quota.
       Klik: brak → ▼ → ▲ → wyłączone. Numer priorytetu = pozycja na
       wspólnej liście kryteriów (kolumny i ikony numerowane razem,
       w kolejności klikania). */
    function renderQSortIcons() {
        var th = document.querySelector(navSel('users') + '[data-col="quota"]');
        if (!th) return;
        var crit = state.users.crit;
        var html = ' <span class="qsort-wrap">';
        [['msgs', 'fa-message', 'Sortuj po liczbie wiadomości'], ['size', 'fa-floppy-disk', 'Sortuj po zajętym rozmiarze']].forEach(function (def) {
            var idx = crit.findIndex(function (c) { return c.kind === 'ico' && c.key === def[0]; });
            var s = idx > -1 ? crit[idx] : null;
            html += '<span class="qsort-ico' + (s ? ' on' : '') + '" data-qsort="' + def[0] + '" title="' + def[2] + '">' +
                '<i class="fa-solid ' + def[1] + '"></i>' +
                (s ? arrowSup(s.dir, idx + 1) : '') +
                '</span>';
        });
        th.innerHTML += html + '</span>';
    }

    function simpleLoader(nav, tbodyId, pagId, totalId, totalLabel) {
        return function () {
            var st = state[nav];
            var map = { reg: 'ajax_fetch_reg_logs', admin: 'ajax_fetch_admin_logs', audit: 'ajax_fetch_audit_logs' };
            var url = ENDPOINT + '?' + map[nav] + '=1&page=' + st.page + '&per=' + st.per + sortParam(st);
            if (st.search) url += '&search=' + encodeURIComponent(st.search);
            tfetch(url)
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    $(tbodyId).innerHTML = d.html;
                    $(pagId).innerHTML = d.pagination;
                    if (totalId && $(totalId)) $(totalId).innerHTML = totalLabel + ' <b>' + d.total + '</b>';
                    renderSortHeaders(nav);
                }).catch(function (e) { console.error(e); });
        };
    }
    var loadReg   = simpleLoader('reg', 'reg-logs-tbody', 'reg-logs-pagination', 'reg-total-text', 'Łącznie zdarzeń:');
    var loadAdmin = simpleLoader('admin', 'admin-logs-tbody', 'admin-logs-pagination', 'admin-total-text', 'Łącznie logów:');
    var loadAudit = simpleLoader('audit', 'audit-logs-tbody', 'audit-logs-pagination', null, '');
    var loaders = { users: loadUsers, reg: loadReg, admin: loadAdmin, audit: loadAudit };

    /* ---------------- Sortowanie (serwerowe: users/reg/admin/audit) ---------------- */

    function changeSort(nav, field, multi) {
        // Kolumna "Użycie": sort klientowy bieżącej strony (wartości z Dovecot,
        // nie ma ich w SQL). Wyłącza się przy kliknięciu innej kolumny.
        if (nav === 'users' && field === 'usage') {
            state.users.crit = [];
            state.users.usage = state.users.usage === 'DESC' ? 'ASC' : 'DESC';
            renderSortHeaders('users');
            applyUsageSort();
            return;
        }

        // Konta: wspólna lista kryteriów (kolumny + ikony) w kolejności klikania.
        if (nav === 'users') {
            state.users.usage = null;
            var crit = state.users.crit;
            var i = crit.findIndex(function (c) { return c.kind === 'srv' && c.key === field; });
            if (multi) {
                if (i !== -1) crit[i].dir = crit[i].dir === 'ASC' ? 'DESC' : 'ASC';
                else crit.push({ kind: 'srv', key: field, dir: 'ASC' });
            } else {
                if (i === 0 && crit.length === 1) crit[0].dir = crit[0].dir === 'ASC' ? 'DESC' : 'ASC';
                else state.users.crit = [{ kind: 'srv', key: field, dir: 'ASC' }];
            }
            loadUsers();
            return;
        }

        var st = state[nav];
        var idx = st.sort.findIndex(function (s) { return s.field === field; });
        if (multi) {
            if (idx !== -1) st.sort[idx].dir = st.sort[idx].dir === 'ASC' ? 'DESC' : 'ASC';
            else st.sort.push({ field: field, dir: 'ASC' });
        } else {
            if (idx === 0) { st.sort[0].dir = st.sort[0].dir === 'ASC' ? 'DESC' : 'ASC'; st.sort = [st.sort[0]]; }
            else st.sort = [{ field: field, dir: 'ASC' }];
        }
        loaders[nav]();
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
        var rows = Array.prototype.slice.call(tbody.children);
        rows.sort(function (a, b) {
            var av = usageVal(a), bv = usageVal(b);
            if (av === null && bv === null) return 0;
            if (av === null) return 1;  // brak danych zawsze na końcu
            if (bv === null) return -1;
            return (av - bv) * dir;
        });
        rows.forEach(function (r) { tbody.appendChild(r); });
    }

    /* ---- Wspólny sort strony wg listy crit (kolumny + dane Dovecot) ---- */

    function qVal(tr, key) {
        var c = tr.querySelector('.usage-cell');
        if (!c) return null;
        var raw = key === 'msgs' ? c.dataset.msgCount : c.dataset.usedKb;
        if (raw === undefined) return null;
        var v = parseFloat(raw);
        return isNaN(v) ? null : v;
    }

    /* Wartość kryterium dla wiersza: kolumny czytamy z komórek strony. */
    function critVal(tr, c) {
        if (c.kind === 'ico') return qVal(tr, c.key);
        switch (c.key) {
            case 'id':         return parseInt(tr.children[0].textContent, 10);
            case 'email':      return tr.children[1].textContent.trim().toLowerCase();
            case 'created':    var v = tr.children[2].textContent.trim(); return v === '-' ? null : v;
            case 'last_login': var w = tr.children[3].textContent.trim(); return w === '-' ? null : w;
            case 'reg_ip':     var r = tr.children[4].textContent.trim(); return r === '-' ? null : r;
            case 'quota':
                var b = tr.querySelector('[data-action="quota"]');
                return b ? parseInt(b.dataset.quota, 10) : null;
        }
        return null;
    }

    /* Dosortowanie bieżącej strony wg PEŁNEJ listy kryteriów. Gdy lista ma
       tylko kolumny serwerowe, porządek z SQL jest już właściwy (i spójny
       między stronami) — wtedy nie ruszamy DOM. */
    function applyUnifiedSort() {
        var crit = state.users.crit;
        var tbody = $('users-tbody');
        if (!tbody || !crit.length) return;
        if (!crit.some(function (c) { return c.kind === 'ico'; })) return;
        var rows = Array.prototype.slice.call(tbody.children);
        rows.sort(function (a, b) {
            for (var i = 0; i < crit.length; i++) {
                var av = critVal(a, crit[i]), bv = critVal(b, crit[i]);
                if (av === null && bv === null) continue;
                if (av === null) return 1;   // brak danych na końcu
                if (bv === null) return -1;
                var cmp = (typeof av === 'number' && typeof bv === 'number')
                    ? av - bv
                    : String(av).localeCompare(String(bv), 'pl', { numeric: true });
                if (cmp !== 0) return cmp * (crit[i].dir === 'ASC' ? 1 : -1);
            }
            return 0;
        });
        rows.forEach(function (r) { tbody.appendChild(r); });
    }

    /* Klik w ikonę: brak → ▼ (malejąco) → ▲ (rosnąco) → wyłączona.
       Dokłada kryterium na koniec wspólnej listy (kolejność klikania). */
    function toggleQSortKey(key) {
        state.users.usage = null; // sort "Użycie" i lista kryteriów wykluczają się
        var crit = state.users.crit;
        var i = crit.findIndex(function (c) { return c.kind === 'ico' && c.key === key; });
        if (i === -1) crit.push({ kind: 'ico', key: key, dir: 'DESC' });
        else if (crit[i].dir === 'DESC') crit[i].dir = 'ASC';
        else crit.splice(i, 1);
        renderSortHeaders('users');
        applyUnifiedSort();
    }

    /* ---------------- Sortowanie klientowe tabeli banów ---------------- */

    function cellKey(tr, col) {
        var td = tr.children[col];
        if (!td) return '';
        if (td.dataset.sort !== undefined) {
            var n = parseFloat(td.dataset.sort);
            return isNaN(n) ? td.dataset.sort : n;
        }
        return td.textContent.trim().toLowerCase();
    }

    function sortBansTable(th) {
        var tbody = $('bans-tbody');
        if (!tbody) return;
        var col = parseInt(th.dataset.col, 10);
        var dir = th.dataset.dir === 'ASC' ? 'DESC' : 'ASC';
        var labels = sortLabels.bans || {};
        document.querySelectorAll('th.sortable[data-nav="bans"]').forEach(function (h) {
            delete h.dataset.dir;
            h.innerHTML = labels[h.dataset.col] + ' <span class="sort-placeholder">⇅</span>';
        });
        th.dataset.dir = dir;
        th.innerHTML = labels[String(col)] + ' <span class="contrast-color">' + (dir === 'ASC' ? '▲' : '▼') + '</span>';

        var mult = dir === 'ASC' ? 1 : -1;
        var rows = Array.prototype.slice.call(tbody.children);
        rows.sort(function (a, b) {
            var ka = cellKey(a, col), kb = cellKey(b, col);
            if (typeof ka === 'number' && typeof kb === 'number') return (ka - kb) * mult;
            return String(ka).localeCompare(String(kb), 'pl', { numeric: true }) * mult;
        });
        rows.forEach(function (r) { tbody.appendChild(r); });
    }

    /* ---------------- Wyszukiwanie ---------------- */

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

    /* Przycisk kasowania w polach wyszukiwania: klik uruchamia zdejmowanie
       znaków od końca w rosnącym tempie (jak przytrzymany backspace) aż do
       opróżnienia pola; input świeci w trakcie (klasa adv-clearing). */
    var advClear = { timer: null, input: null };

    function syncAdvClear(input) {
        var btn = document.querySelector('[data-adv-clear="' + input.id + '"]');
        if (btn) btn.style.display = input.value ? 'flex' : 'none';
    }

    function advClearStop() {
        if (advClear.timer) clearTimeout(advClear.timer);
        if (advClear.input) advClear.input.classList.remove('adv-clearing');
        advClear.timer = null;
        advClear.input = null;
    }

    function advClearTick(input, delay) {
        if (!input.value) {
            advClearStop();
            input.focus({ preventScroll: true });
            return;
        }
        input.value = input.value.slice(0, -1);
        input.dispatchEvent(new Event('input', { bubbles: true }));
        advClear.timer = setTimeout(function () {
            advClearTick(input, Math.max(12, Math.floor(delay * 0.70)));
        }, delay);
    }

    function advClearStart(input) {
        if (!input) return;
        advClearStop();
        advClear.input = input;
        input.classList.add('adv-clearing');
        advClearTick(input, 90);
    }

    /* ---------------- Filtr tabeli blokad (klientowy) ---------------- */

    function applyBansFilter() {
        var el = $('ban-search-input');
        var raw = el ? el.value.trim().toLowerCase() : '';
        // każdy warunek (rozdzielony ";") to FRAZA — "Na stałe" dopasowuje się
        // w całości, ze spacją, a nie jako dwa niezależne słowa
        var phrases = raw.split(';').map(function (s) { return s.trim(); }).filter(Boolean);
        document.querySelectorAll('#bans-tbody tr').forEach(function (tr) {
            if (!phrases.length) { tr.classList.remove('row-filtered'); return; }
            var titles = Array.prototype.map.call(tr.querySelectorAll('td[title]'), function (td) { return td.title; }).join(' ');
            var hay = (tr.textContent + ' ' + titles).toLowerCase().replace(/\s+/g, ' ');
            var ok = phrases.every(function (p) { return hay.indexOf(p) > -1; });
            tr.classList.toggle('row-filtered', !ok);
        });
    }

    /* ---------------- Indeks aktualnych blokad (dla skanera) ---------------- */

    // ip -> {type, jail, label}; budowany z tabeli banów w DOM, odświeżany
    // po każdym refreshBans. Skaner flaguje nim IP już zbanowane i pozwala
    // je odbanować bez wychodzenia z wyników.
    var bansIndex = {};

    function rebuildBansIndex() {
        bansIndex = {};
        document.querySelectorAll('#bans-tbody [data-action="manage-ban"]').forEach(function (b) {
            bansIndex[b.dataset.ip] = {
                type:  b.dataset.type,
                jail:  b.dataset.jail || '',
                label: b.dataset.type === 'local' ? 'blokada lokalna' : (b.dataset.jail || 'fail2ban')
            };
        });
    }

    /* ---------------- Modale ---------------- */

    function openModal(id) { $(id).style.display = 'block'; }
    function closeModal(id) { $(id).style.display = 'none'; }

    function showQuotaModal(id, email, mb, sendLimit) {
        $('quota_user_id').value = id;
        $('quota_user_email').value = email;
        var value = mb, unit = 'MiB';
        if (mb >= 1024 && mb % 1024 === 0) { value = mb / 1024; unit = 'GiB'; }
        $('quota_value').value = value;
        $('quota_unit').value = unit;
        $('quota_send_limit').value = (sendLimit === undefined || sendLimit === '') ? '' : sendLimit;
        loadSentToday(id);
        openModal('quotaModal');
    }

    // Wykorzystanie dziennego limitu wysyłki (licznik prowadzi policy Postfiksa).
    function loadSentToday(id) {
        var box = $('quota_sent_today');
        if (!box) return;
        box.innerHTML = '<i class="ri-loader-4-line spin"></i> Ładowanie…';
        tfetch(ENDPOINT + '?ajax_sent_today=1&id=' + encodeURIComponent(id))
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.ok) { box.innerHTML = '<span class="usage-na">—</span>'; return; }
                var limitTxt = d.limit > 0 ? d.limit : '∞';
                var src = d.individual ? 'limit indywidualny' : 'limit globalny';
                var cls = d.limit > 0 && d.sent >= d.limit ? 'crit' : (d.limit > 0 && d.sent >= d.limit * 0.8 ? 'warn' : 'ok');
                box.innerHTML = '<b class="sent-' + cls + '">' + d.sent + '</b> / ' + limitTxt +
                    ' wiadomości <span class="sent-src">(' + src + ')</span>';
            })
            .catch(function () { box.innerHTML = '<span class="usage-na">—</span>'; });
    }

    function showBanModal(email) {
        $('ban_user_email').value = email;
        var sel = $('ban_ip_select');
        sel.innerHTML = '<option value="">Ładowanie IP...</option>';
        tfetch('user_ips.php?email=' + encodeURIComponent(email))
            .then(function (r) { return r.json(); })
            .then(function (d) {
                sel.innerHTML = '<option value="">-- Wybierz IP --</option>';
                if (d.error) { sel.innerHTML = '<option value="">Brak IP (błąd)</option>'; return; }
                var added = new Set();
                var labels = { reg_ip: 'Rejestracja', first_login_ip: 'Pierwsze logowanie', prev_login_ip: 'Poprzednie logowanie', last_login_ip: 'Ostatnie logowanie' };
                if (d.flags) Object.keys(labels).forEach(function (k) {
                    var v = d.flags[k];
                    if (v && !added.has(v)) { addOpt(sel, v, v + ' (' + labels[k] + ')'); added.add(v); }
                });
                if (Array.isArray(d.recent)) d.recent.forEach(function (ip) {
                    if (ip && !added.has(ip)) { addOpt(sel, ip, ip); added.add(ip); }
                });
                if (sel.options.length === 1) sel.innerHTML = '<option value="">Brak znanych IP</option>';
            }).catch(function () { sel.innerHTML = '<option value="">Błąd pobierania IP</option>'; });
        openModal('banModal');
    }

    function showDirectBanModal(ip) {
        $('ban_user_email').value = 'N/A (Admin Log)';
        var sel = $('ban_ip_select');
        sel.innerHTML = '';
        addOpt(sel, ip, ip, true);
        openModal('banModal');
    }

    function showDeleteModal(id, email) {
        $('del_user_id_display').textContent = id;
        $('del_user_email_display').textContent = email;
        $('del_user_id_input').value = id;
        $('del_user_email_input').value = email;
        openModal('deleteUserModal');
    }

    function addOpt(sel, value, text, selected) {
        var o = document.createElement('option');
        o.value = value; o.textContent = text; if (selected) o.selected = true;
        sel.appendChild(o);
    }

    /* ---------------- POST + generyczne potwierdzenie ---------------- */

    // POST przez fetch (pole _ajax => serwer odpowiada JSON-em zamiast PRG).
    // Dzięki temu strona się nie przeładowuje i stan JS (np. wyniki skanera
    // podejrzanych IP) zostaje nietknięty.
    function postAjax(fields, cb) {
        var fd = new FormData();
        fields._csrf = document.body.dataset.csrf || '';
        fields._ajax = '1';
        Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
        tfetch(ENDPOINT, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (d) { if (cb) cb(d); })
            .catch(function () { if (cb) cb({ ok: false, error: 'Błąd połączenia z serwerem.' }); });
    }

    // Komunikat nad tabelą blokad (odpowiednik flasha dla akcji AJAX).
    function bansNote(ok, text) {
        var box = $('bans_ajax_msg');
        if (!box) return;
        box.innerHTML = '<div class="scan-note ' + (ok ? 'scan-note-ok' : 'scan-note-err') + '"><i class="' +
            (ok ? 'ri-shield-check-line' : 'ri-error-warning-line') + '"></i> ' + esc(text) + '</div>';
    }

    // Przeładowanie samej tabeli blokad + licznika w statystykach.
    function refreshBans() {
        tfetch(ENDPOINT + '?ajax_fetch_bans=1')
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var tb = $('bans-tbody');
                if (tb) tb.innerHTML = d.html;
                var stat = $('stat-banned-ips');
                if (stat) stat.textContent = d.count;
                var labels = sortLabels.bans || {};
                document.querySelectorAll('th.sortable[data-nav="bans"]').forEach(function (h) {
                    delete h.dataset.dir;
                    h.innerHTML = labels[h.dataset.col] + ' <span class="sort-placeholder">⇅</span>';
                });
                rebuildBansIndex();
                applyBansFilter();
                if (scan.agg && !scan.running) renderScanResults(); // odśwież flagi "już zbanowane"
            })
            .catch(function () {});
    }

    var confirmCb = null;
    function askConfirm(title, msg, onConfirm) {
        $('confirm_title').innerHTML = '<i class="ri-error-warning-line"></i> ' + title;
        $('confirm_msg').textContent = msg;
        confirmCb = onConfirm;
        openModal('confirmModal');
    }

    /* ---------------- Zarządzanie banem ---------------- */

    var currentBan = {};
    function openManageBan(data) {
        currentBan = { ip: data.ip, type: data.type, jail: data.jail || '' };
        $('mb_ip').textContent = data.ip;
        $('mb_type').textContent = data.type === 'local' ? 'Lokalny (baza panelu)' : 'Fail2Ban — jail: ' + currentBan.jail;
        // zmiana typu/jaila tylko dla banów Fail2Ban
        $('mb_change_wrap').style.display = data.type === 'local' ? 'none' : 'block';
        var dur = $('mb_duration');
        if (dur) dur.style.display = $('mb_target_jail').value === 'panel-tempban' ? '' : 'none';
        openModal('manageBanModal');
    }
    function manageUnban() {
        var b = currentBan;
        closeModal('manageBanModal');
        askConfirm('Odblokować IP?', 'Adres ' + b.ip + ' zostanie całkowicie odblokowany.', function () {
            postAjax({ unban_ip: 1, ip: b.ip, unban_type: b.type, jail: b.jail }, function (d) {
                bansNote(d.ok, d.ok ? d.msg : (d.error || 'Błąd odblokowania.'));
                if (d.ok) refreshBans();
            });
        });
    }
    function manageMove() {
        var b = currentBan;
        var target = $('mb_target_jail').value;
        var durSel = $('mb_duration');
        var isTemp = target === 'panel-tempban';
        var duration = isTemp && durSel ? durSel.value : '';
        var durLabel = isTemp && durSel ? durSel.options[durSel.selectedIndex].text : '';
        closeModal('manageBanModal');
        var txt = 'IP ' + b.ip + ' zostanie przeniesione: ' + b.jail + ' → ' + target + (isTemp ? ' (na ' + durLabel + ')' : '') + '.';
        askConfirm('Zmienić typ bana?', txt, function () {
            postAjax({ move_ban: 1, ip: b.ip, from_jail: b.jail, to_jail: target, duration: duration }, function (d) {
                bansNote(d.ok, d.ok ? d.msg : (d.error || 'Błąd przenoszenia.'));
                if (d.ok) refreshBans();
            });
        });
    }

    /* ---------------- Skaner podejrzanych IP ----------------
       Źródła skanowane sekwencyjnie (osobne żądania AJAX) — wyniki
       doładowują się na bieżąco, a licznik i pasek postępu pokazują
       stan. Ban prosto z listy (AJAX) nie przeładowuje strony i nie
       zamyka modalu, więc można banować kolejne IP z tej samej listy. */

    function esc(s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : s); return d.innerHTML; }

    var SCAN_SOURCES = [
        { key: 'ssh',     label: 'SSH' },
        { key: 'dovecot', label: 'Dovecot' },
        { key: 'postfix', label: 'Postfix SASL' },
        { key: 'db',      label: 'Baza panelu' }
    ];
    var scan = { running: false, finished: false, agg: null, banned: {}, failed: [], from: '', to: '' };

    function openScanner() {
        var f = $('scan_from'), t = $('scan_to');
        if (!f.value) { var d = new Date(); d.setDate(d.getDate() - 7); f.value = d.toISOString().slice(0, 10); }
        if (!t.value) { t.value = new Date().toISOString().slice(0, 10); }
        rebuildBansIndex();
        if (scan.agg && !scan.running) renderScanResults();
        openModal('scannerModal');
    }

    function runScan() {
        if (scan.running) return;
        scan.running = true;
        scan.finished = false;
        scan.agg = {};
        scan.failed = [];
        scan.from = $('scan_from').value;
        scan.to = $('scan_to').value;
        $('scan_run').disabled = true;
        $('scan_results').innerHTML = '<p class="empty-state-text">Na razie brak wyników…</p>';
        $('scan_status').style.display = 'block';
        var msg = $('scan_ban_msg');
        if (msg) msg.innerHTML = '';
        scanNext(0);
    }

    function renderScanSteps(activeIdx) {
        var parts = SCAN_SOURCES.map(function (s, i) {
            var cls, icon;
            if (scan.failed.indexOf(i) !== -1) { cls = 'fail'; icon = '<i class="ri-close-line"></i>'; }
            else if (i < activeIdx) { cls = 'done'; icon = '<i class="ri-check-line"></i>'; }
            else if (i === activeIdx && scan.running) { cls = 'active'; icon = '<i class="ri-loader-4-line spin"></i>'; }
            else { cls = 'pending'; icon = '<i class="ri-time-line"></i>'; }
            return '<span class="scan-step ' + cls + '">' + icon + ' ' + s.label + '</span>';
        });
        $('scan_steps').innerHTML = parts.join('');
    }

    function scanNext(i) {
        if (i >= SCAN_SOURCES.length) {
            scan.running = false;
            scan.finished = true;
            $('scan_run').disabled = false;
            $('scan_status_label').innerHTML = scan.failed.length
                ? '<i class="ri-error-warning-line"></i> Zakończono z błędami źródeł (' + scan.failed.length + ')'
                : '<i class="ri-checkbox-circle-line"></i> Przeszukano wszystkie źródła (' + esc(scan.from) + ' → ' + esc(scan.to) + ')';
            $('scan_progress_fill').style.width = '100%';
            renderScanSteps(SCAN_SOURCES.length);
            renderScanResults();
            return;
        }
        var src = SCAN_SOURCES[i];
        $('scan_status_label').innerHTML = '<i class="ri-loader-4-line spin"></i> Skanuję: <b>' + src.label + '</b> (' + (i + 1) + '/' + SCAN_SOURCES.length + ')';
        $('scan_progress_fill').style.width = Math.round(i / SCAN_SOURCES.length * 100) + '%';
        renderScanSteps(i);
        tfetch(ENDPOINT + '?ajax_scan=1&source=' + src.key + '&from=' + encodeURIComponent(scan.from) + '&to=' + encodeURIComponent(scan.to), null, 120000)
            .then(function (r) { return r.json(); })
            .then(function (d) {
                (d.pairs || []).forEach(function (p) {
                    var a = scan.agg[p.ip] = scan.agg[p.ip] || { total: 0, services: {} };
                    a.total += p.c;
                    a.services[p.svc] = (a.services[p.svc] || 0) + p.c;
                });
                renderScanResults();
                scanNext(i + 1);
            })
            .catch(function () {
                scan.failed.push(i);
                renderScanResults();
                scanNext(i + 1);
            });
    }

    function scanMin() { return parseInt($('scan_min').value, 10) || 1; }

    function scanRows() {
        var min = scanMin();
        var rows = [];
        Object.keys(scan.agg || {}).forEach(function (ip) {
            var d = scan.agg[ip];
            if (d.total >= min) rows.push({ ip: ip, total: d.total, services: d.services });
        });
        rows.sort(function (a, b) { return b.total - a.total; });
        return rows;
    }

    function scanActionCell(ip) {
        var b = scan.banned[ip];
        if (b) return '<span class="scan-banned"><i class="ri-checkbox-circle-line"></i> Zbanowano (' + esc(b) + ')</span>';
        // IP z aktywną blokadą — flaga + możliwość zdjęcia bana wprost z listy
        if (bansIndex[ip]) {
            return '<div class="scan-ban-controls">' +
                '<span class="scan-already" title="To IP jest już na liście blokad">' +
                '<i class="ri-shield-check-line"></i> Już zbanowane (' + esc(bansIndex[ip].label) + ')</span>' +
                '<button type="button" class="btn-action btn-manage" data-scan-unban="' + esc(ip) + '"><i class="ri-lock-unlock-line"></i> Odbanuj</button>' +
                '</div>';
        }
        return '<div class="scan-ban-controls">' +
            '<select class="scan-ban-sel" title="Rodzaj blokady">' +
                '<option value="perma">Na stałe</option>' +
                '<option value="3600">1 godz.</option>' +
                '<option value="21600">6 godz.</option>' +
                '<option value="86400">24 godz.</option>' +
                '<option value="259200">3 dni</option>' +
                '<option value="604800">7 dni</option>' +
                '<option value="2592000">30 dni</option>' +
                '<option value="7776000">3 miesiące</option>' +
            '</select>' +
            '<button type="button" class="btn-action btn-danger-action" data-scan-ban="' + esc(ip) + '"><i class="ri-forbid-line"></i> Zbanuj</button>' +
            '</div>';
    }

    function renderScanResults() {
        var box = $('scan_results');
        var rows = scanRows();
        $('scan_status_count').textContent = 'Znalezione: ' + rows.length + ' IP (próg ≥ ' + scanMin() + ')';
        if (!rows.length) {
            box.innerHTML = scan.finished
                ? '<p class="empty-state-text">Brak podejrzanych IP dla podanych kryteriów.</p>'
                : '<p class="empty-state-text">Na razie brak wyników…</p>';
            return;
        }
        var h = '<div class="table-responsive"><table><thead><tr><th>IP</th><th>Nieudane</th><th>Usługi (liczba)</th><th>Blokada</th></tr></thead><tbody>';
        rows.slice(0, 100).forEach(function (r) {
            var svc = Object.keys(r.services).map(function (s) { return s + ': ' + r.services[s]; }).join(', ');
            h += '<tr><td class="scan-ip"><strong>' + esc(r.ip) + '</strong></td><td>' + r.total + '</td>' +
                '<td class="scan-svc">' + esc(svc) + '</td><td>' + scanActionCell(r.ip) + '</td></tr>';
        });
        h += '</tbody></table></div>';
        if (rows.length > 100) h += '<div class="help-text">Pokazano 100 z ' + rows.length + ' IP — zawęź zakres dat lub podnieś próg.</div>';
        box.innerHTML = h;
    }

    function scanBan(ip, mode, label) {
        var body = new URLSearchParams();
        body.set('ajax_scan_ban', '1');
        body.set('ip', ip);
        body.set('mode', mode);
        body.set('_csrf', document.body.dataset.csrf || '');
        tfetch(ENDPOINT, { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var msg = $('scan_ban_msg');
                if (d.ok) {
                    scan.banned[ip] = d.label || label;
                    renderScanResults();
                    refreshBans();
                    if (msg) msg.innerHTML = '<div class="scan-note scan-note-ok"><i class="ri-shield-check-line"></i> ' +
                        'Zbanowano ' + esc(ip) + ' (' + esc(d.label || label) + '). Lista blokad na karcie została odświeżona.</div>';
                } else if (msg) {
                    msg.innerHTML = '<div class="scan-note scan-note-err"><i class="ri-error-warning-line"></i> ' + esc(d.error || 'Błąd banowania.') + '</div>';
                }
            })
            .catch(function () {
                var msg = $('scan_ban_msg');
                if (msg) msg.innerHTML = '<div class="scan-note scan-note-err"><i class="ri-error-warning-line"></i> Błąd połączenia przy banowaniu.</div>';
            });
    }

    /* ---------------- Modal "Dodaj użytkownika": walidacja ----------------
       Ta sama logika co przy rejestracji w portalu: live-sprawdzanie
       dostępności adresu, miernik siły hasła z listą wymogów i blokada
       submitu, gdy dane nie spełniają polityki (serwer i tak weryfikuje). */

    var PWD = {
        len:   parseInt(document.body.dataset.pwdMin, 10) || 0,
        upper: parseInt(document.body.dataset.pwdUpper, 10) || 0,
        digit: parseInt(document.body.dataset.pwdDigit, 10) || 0,
        spec:  parseInt(document.body.dataset.pwdSpec, 10) || 0
    };
    var NU_LOCAL_RE = /^[a-z0-9](?:[a-z0-9._-]{0,62}[a-z0-9])?$/i;
    var nuAvailTimer = null;
    var nuAvailState = ''; // '' | 'free' | 'taken' | 'alias' | 'reserved' | 'held' | 'invalid'

    function nuFormMsg(text) {
        var box = $('nu_form_msg');
        if (!box) return;
        box.innerHTML = text
            ? '<div class="scan-note scan-note-err"><i class="ri-error-warning-line"></i> ' + esc(text) + '</div>'
            : '';
    }

    function nuSetAvail(cls, text) {
        var el = $('nu_availability');
        if (!el) return;
        el.className = 'avail-status' + (cls ? ' ' + cls : '');
        el.textContent = text;
    }

    function nuCheckAvailability() {
        var local = ($('nu_local').value || '').trim();
        var domain = $('nu_domain').value;
        if (!local) { nuAvailState = ''; nuSetAvail('', ''); return; }
        if (!NU_LOCAL_RE.test(local)) {
            nuAvailState = 'invalid';
            nuSetAvail('taken', 'Niedozwolone znaki (dozwolone: litery, cyfry, . _ -)');
            return;
        }
        nuSetAvail('', 'Sprawdzanie...');
        tfetch(ENDPOINT + '?ajax_check_user=1&local=' + encodeURIComponent(local) + '&domain=' + encodeURIComponent(domain))
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.ok) {
                    nuAvailState = d.status;
                    nuSetAvail(d.status === 'free' ? 'free' : 'taken', d.msg);
                } else {
                    nuAvailState = 'invalid';
                    nuSetAvail('taken', d.msg || 'Błąd');
                }
            })
            .catch(function () { nuAvailState = ''; nuSetAvail('taken', 'Błąd sieci'); });
    }

    // --- Wspólne narzędzia siły hasła (modale: dodawanie konta i reset hasła) ---
    function pwdMeter(barId, reqsId, val) {
        var score = 0, max = 0;
        var rule = function (enabled, ok, req) {
            if (!enabled) return;
            max++;
            var li = document.querySelector('#' + reqsId + ' li[data-req="' + req + '"]');
            if (ok) { score++; if (li) li.classList.add('valid'); }
            else if (li) li.classList.remove('valid');
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

    function nuUpdateMeter() { pwdMeter('nu_pwd_bar', 'nu_pwd_reqs', $('nu_password').value); }
    function nuPwdValid(val) { return pwdValid(val); }

    /* Status zgodności haseł — jak przy rejestracji w portalu. */
    function nuCheckMatch() { pwdMatch($('nu_password').value, $('nu_password2').value, $('nu_pass_match')); }

    /** Zwraca opis błędu albo '' gdy formularz może iść na serwer. */
    function nuValidate() {
        var local = ($('nu_local').value || '').trim();
        if (!local) return 'Podaj nazwę konta.';
        if (!NU_LOCAL_RE.test(local)) return 'Niedozwolone znaki w nazwie konta (dozwolone: litery, cyfry, . _ -).';
        if (nuAvailState === 'taken') return 'Ten adres jest już zajęty.';
        if (nuAvailState === 'alias') return 'Ta nazwa jest źródłem aliasu — usuń alias, żeby założyć skrzynkę.';
        if ((nuAvailState === 'reserved' || nuAvailState === 'held') && !($('nu_allow_reserved') && $('nu_allow_reserved').checked)) {
            return 'Nazwa zarezerwowana lub niedawno usunięta — zaznacz zgodę pod polem adresu, jeśli to zamierzone.';
        }
        if (!nuPwdValid($('nu_password').value)) return 'Hasło nie spełnia wymogów bezpieczeństwa.';
        if ($('nu_password2') && $('nu_password').value !== $('nu_password2').value) return 'Hasła nie są identyczne.';
        return '';
    }

    function nuResetModal() {
        nuAvailState = '';
        nuSetAvail('', '');
        nuFormMsg('');
        if ($('nu_allow_reserved')) $('nu_allow_reserved').checked = false;
        var bar = $('nu_pwd_bar');
        if (bar) { bar.style.width = '0%'; bar.style.backgroundColor = ''; }
        document.querySelectorAll('#nu_pwd_reqs li').forEach(function (li) { li.classList.remove('valid'); });
        var match = $('nu_pass_match');
        if (match) { match.className = 'avail-status'; match.textContent = ''; }
    }

    /* ---------------- Modal "Zmiana hasła skrzynki" (reset przez admina) ---------------- */

    function rpFormMsg(text) {
        var box = $('rp_form_msg');
        if (!box) return;
        box.innerHTML = text
            ? '<div class="scan-note scan-note-err"><i class="ri-error-warning-line"></i> ' + esc(text) + '</div>'
            : '';
    }

    function showResetPassModal(id, email) {
        $('rp_user_id').value = id;
        $('rp_user_email_hidden').value = email;
        $('rp_user_email').value = email;
        $('rp_password').value = '';
        $('rp_password2').value = '';
        rpFormMsg('');
        var bar = $('rp_pwd_bar');
        if (bar) { bar.style.width = '0%'; bar.style.backgroundColor = ''; }
        document.querySelectorAll('#rp_pwd_reqs li').forEach(function (li) { li.classList.remove('valid'); });
        var match = $('rp_pass_match');
        if (match) { match.className = 'avail-status'; match.textContent = ''; }
        openModal('resetPassModal');
        var f = $('rp_password'); if (f) f.focus({ preventScroll: true });
    }

    function rpValidate() {
        if (!pwdValid($('rp_password').value)) return 'Hasło nie spełnia wymogów bezpieczeństwa.';
        if ($('rp_password').value !== $('rp_password2').value) return 'Hasła nie są identyczne.';
        return '';
    }

    /* ---------------- Delegacja: kliknięcia ---------------- */

    document.addEventListener('click', function (e) {
        // przyciski potwierdzenia / zarządzania banem (po id)
        if (e.target.closest('#confirm_ok')) {
            var cb = confirmCb; confirmCb = null; closeModal('confirmModal'); if (cb) cb(); return;
        }
        if (e.target.closest('#mb_unban_btn')) { manageUnban(); return; }
        if (e.target.closest('#mb_move_btn')) { manageMove(); return; }
        if (e.target.closest('#scan_run')) { runScan(); return; }
        // Odbanowanie prosto z wyników skanera (flaga "Już zbanowane")
        var sunb = e.target.closest('[data-scan-unban]');
        if (sunb) {
            var uip = sunb.dataset.scanUnban;
            var bi = bansIndex[uip] || { type: 'fail2ban', jail: '', label: 'fail2ban' };
            askConfirm('Odblokować IP?', 'Adres ' + uip + ' zostanie całkowicie odblokowany (' + bi.label + ').', function () {
                postAjax({ unban_ip: 1, ip: uip, unban_type: bi.type, jail: bi.jail }, function (d) {
                    var msg = $('scan_ban_msg');
                    if (msg) msg.innerHTML = '<div class="scan-note ' + (d.ok ? 'scan-note-ok' : 'scan-note-err') + '"><i class="' +
                        (d.ok ? 'ri-lock-unlock-line' : 'ri-error-warning-line') + '"></i> ' +
                        esc(d.ok ? d.msg : (d.error || 'Błąd odblokowania.')) + '</div>';
                    if (d.ok) refreshBans(); // odświeży bansIndex i flagi w wynikach
                });
            });
            return;
        }

        var sban = e.target.closest('[data-scan-ban]');
        if (sban) {
            var sip = sban.dataset.scanBan;
            var sel = sban.closest('.scan-ban-controls');
            sel = sel ? sel.querySelector('.scan-ban-sel') : null;
            var mode = sel ? sel.value : 'perma';
            var optLabel = sel ? sel.options[sel.selectedIndex].text : 'na stałe';
            var txt = mode === 'perma'
                ? 'IP ' + sip + ' zostanie trwale zablokowane (jail panel-permaban).'
                : 'IP ' + sip + ' zostanie zablokowane na ' + optLabel + ' (jail panel-tempban).';
            askConfirm('Zbanować IP?', txt, function () { scanBan(sip, mode, optLabel); });
            return;
        }

        var tab = e.target.closest('.tab[data-tab]');
        if (tab) { showTab(tab.dataset.tab); return; }

        // Ikony ukrytego trybu sortowania (wewnątrz nagłówka Quota) — muszą
        // złapać klik przed ogólną obsługą th.sortable.
        var qs = e.target.closest('[data-qsort]');
        if (qs) { toggleQSortKey(qs.dataset.qsort); return; }

        var th = e.target.closest('th.sortable[data-col]');
        if (th) {
            var nav = th.dataset.nav || 'users';
            if (nav === 'bans') { sortBansTable(th); return; }
            changeSort(nav, th.dataset.col, e.shiftKey);
            return;
        }

        var pag = e.target.closest('.pagination-btn[data-page]');
        if (pag) {
            var pnav = pag.dataset.nav;
            if (state[pnav]) { state[pnav].page = parseInt(pag.dataset.page, 10); loaders[pnav](); }
            return;
        }

        var advBtn = e.target.closest('[data-adv-clear]');
        if (advBtn) { advClearStart($(advBtn.dataset.advClear)); return; }

        var el = e.target.closest('[data-action]');
        if (!el) return;
        var a = el.dataset.action;
        if (a === 'search-ip')      triggerSearch(el.dataset.ip, e.shiftKey);
        else if (a === 'ban-ip')    showDirectBanModal(el.dataset.ip);
        else if (a === 'quota')     showQuotaModal(el.dataset.id, el.dataset.email, parseInt(el.dataset.quota, 10), el.dataset.sendLimit);
        else if (a === 'ban')       showBanModal(el.dataset.email);
        else if (a === 'add-user')  { nuResetModal(); openModal('addUserModal'); var nl = $('nu_local'); if (nl) nl.focus(); }
        else if (a === 'reset-pass') showResetPassModal(el.dataset.id, el.dataset.email);
        else if (a === 'manage-ban') openManageBan(el.dataset);
        else if (a === 'open-scanner') openScanner();
        else if (a === 'delete')    showDeleteModal(el.dataset.id, el.dataset.email);
        else if (a === 'refresh-audit') loadAudit();
        else if (a === 'modal-close') closeModal(el.dataset.modal);
    });

    // klik w tło modala zamyka
    document.addEventListener('mousedown', function (e) {
        if (e.target.classList && e.target.classList.contains('modal')) e.target.style.display = 'none';
    });

    // Ręczny ban: potwierdzenie + wysyłka AJAX (bez przeładowania strony —
    // wyniki skanera podejrzanych IP zostają w pamięci).
    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (f.classList && f.classList.contains('manual-ban-form')) {
            e.preventDefault();
            var ipEl = f.querySelector('[name="f2b_ip"]');
            var ip = (ipEl.value || '').trim();
            var jail = f.querySelector('[name="f2b_jail"]').value;
            if (!ip) { ipEl.focus(); return; }
            askConfirm('Zbanować IP?', 'Adres ' + ip + ' zostanie zbanowany w Fail2Ban (jail: ' + jail + ').', function () {
                postAjax({ f2b_ban: 1, f2b_ip: ip, f2b_jail: jail }, function (d) {
                    bansNote(d.ok, d.ok ? d.msg : (d.error || 'Błąd banowania.'));
                    if (d.ok) { ipEl.value = ''; refreshBans(); }
                });
            });
            return;
        }
        // Dodawanie użytkownika: walidacja jak przy rejestracji w portalu
        if (f.querySelector && f.querySelector('input[name="create_user"]')) {
            var err = nuValidate();
            if (err) {
                e.preventDefault();
                nuFormMsg(err);
            }
        }
        // Reset hasła skrzynki: ta sama polityka co przy dodawaniu konta
        if (f.querySelector && f.querySelector('input[name="reset_user_password"]')) {
            var rpErr = rpValidate();
            if (rpErr) {
                e.preventDefault();
                rpFormMsg(rpErr);
            }
        }
    });

    /* ---------------- Delegacja: zmiany/inputy ---------------- */

    document.addEventListener('change', function (e) {
        var per = e.target.closest('[data-per]');
        if (per) {
            var nav = per.dataset.per;
            state[nav].per = parseInt(per.value, 10);
            state[nav].page = 1;
            loaders[nav]();
        }
        if (e.target.id === 'ban_reason_select') {
            var custom = $('custom_reason_group');
            if (e.target.value === 'custom') { custom.style.display = 'block'; $('ban_reason_input').value = $('ban_custom_reason').value || 'Własny powód'; }
            else { custom.style.display = 'none'; $('ban_reason_input').value = e.target.value; }
        }
        if (e.target.id === 'nu_domain') {
            nuCheckAvailability();
        }
        if (e.target.id === 'mb_target_jail') {
            var dur = $('mb_duration');
            if (dur) dur.style.display = e.target.value === 'panel-tempban' ? '' : 'none';
        }
    });

    var logSearchTimer = null;

    document.addEventListener('input', function (e) {
        // widoczność przycisku kasowania przy każdym polu adv-search
        if (e.target.id && document.querySelector('[data-adv-clear="' + e.target.id + '"]')) {
            syncAdvClear(e.target);
        }
        if (e.target.id === 'user-search-input') {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function () { state.users.page = 1; loadUsers(); }, 500);
        }
        // wspólna wyszukiwarka logów: filtruje wszystkie trzy tabele naraz
        if (e.target.id === 'log-search-input') {
            clearTimeout(logSearchTimer);
            var val = e.target.value.trim();
            logSearchTimer = setTimeout(function () {
                ['reg', 'admin', 'audit'].forEach(function (nav) {
                    state[nav].search = val;
                    state[nav].page = 1;
                });
                loadReg(); loadAdmin(); loadAudit();
            }, 500);
        }
        // filtr blokad działa w locie (dane już w DOM)
        if (e.target.id === 'ban-search-input') {
            applyBansFilter();
        }
        if (e.target.id === 'ban_custom_reason') {
            $('ban_reason_input').value = e.target.value || 'Własny powód';
        }
        // Walidacja live w modalu "Dodaj użytkownika"
        if (e.target.id === 'nu_local') {
            nuFormMsg('');
            clearTimeout(nuAvailTimer);
            nuAvailTimer = setTimeout(nuCheckAvailability, 500);
        }
        if (e.target.id === 'nu_password') {
            nuFormMsg('');
            nuUpdateMeter();
            nuCheckMatch();
        }
        if (e.target.id === 'nu_password2') {
            nuFormMsg('');
            nuCheckMatch();
        }
        // Walidacja live w modalu resetu hasła
        if (e.target.id === 'rp_password') {
            rpFormMsg('');
            pwdMeter('rp_pwd_bar', 'rp_pwd_reqs', e.target.value);
            pwdMatch($('rp_password').value, $('rp_password2').value, $('rp_pass_match'));
        }
        if (e.target.id === 'rp_password2') {
            rpFormMsg('');
            pwdMatch($('rp_password').value, $('rp_password2').value, $('rp_pass_match'));
        }
        // Zmiana progu po skanie filtruje wyniki na żywo (bez ponownego skanu)
        if (e.target.id === 'scan_min' && scan.agg && !scan.running) {
            renderScanResults();
        }
    });

    /* ---------------- Motyw ---------------- */

    function initTheme() {
        var btn = $('themeToggle'), icon = $('themeIcon'), body = document.body;
        if (localStorage.getItem('theme') === 'light') body.classList.remove('dark-mode');
        var setIcon = function () {
            var dark = body.classList.contains('dark-mode');
            icon.className = dark ? 'ri-moon-line' : 'ri-sun-line';
        };
        setIcon();
        btn.addEventListener('click', function () {
            body.classList.toggle('dark-mode');
            localStorage.setItem('theme', body.classList.contains('dark-mode') ? 'dark' : 'light');
            setIcon();
        });
    }

    /* ---------------- Start ---------------- */

    document.querySelectorAll('th.sortable').forEach(function (th) {
        var nav = th.dataset.nav || 'users';
        (sortLabels[nav] = sortLabels[nav] || {})[th.dataset.col] = th.textContent.trim();
    });
    initTheme();
    rebuildBansIndex();
    var hash = (location.hash || '#settings').substring(1);
    if (document.getElementById(hash)) showTab(hash);
    loadUsers(); loadReg(); loadAdmin(); loadAudit();
})();
