/* =========================================================================
   Portal — logika front-endu (bez inline-JS; obsługa zdarzeń przez delegację).
   Konfiguracja przekazywana przez data-* na <body> (CSP: script-src 'self').
   ========================================================================= */
(function () {
    'use strict';

    var cfg = document.body.dataset;
    var CSRF = cfg.csrf || '';
    var PWD = {
        len:   parseInt(cfg.pwdMin, 10) || 0,
        upper: parseInt(cfg.pwdUpper, 10) || 0,
        digit: parseInt(cfg.pwdDigit, 10) || 0,
        spec:  parseInt(cfg.pwdSpec, 10) || 0
    };

    var ENDPOINT = 'portal.php';

    /* ---------- Pomocnicze ---------- */

    function $(id) { return document.getElementById(id); }

    // fetch z limitem czasu (30 s): zawieszone żądanie kończy się błędem zamiast wisieć w nieskończoność.
    function tfetch(url, opts) {
        opts = opts || {};
        if (typeof AbortController === 'undefined') return fetch(url, opts);
        var ctl = new AbortController();
        var timer = setTimeout(function () { ctl.abort(); }, 30000);
        opts.signal = ctl.signal;
        return fetch(url, opts).then(function (r) { clearTimeout(timer); return r; }, function (e) { clearTimeout(timer); throw e; });
    }

    function resetCaptcha() {
        if (window.grecaptcha) {
            try { grecaptcha.reset(); } catch (e) { /* instancja niegotowa */ }
        }
    }

    function showMessage(msg, type) {
        var el = $('global-message');
        if (!el) return;
        el.textContent = msg;
        el.className = 'msg-box ' + (type === 'error' ? 'msg-err' : type === 'success' ? 'msg-ok' : '');
        el.style.display = 'block';
    }

    function clearMessage() {
        var el = $('global-message');
        if (el) el.style.display = 'none';
    }

    function post(data) {
        var fd = data instanceof FormData ? data : new FormData();
        if (!(data instanceof FormData)) {
            Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
        }
        fd.append('_csrf', CSRF);
        return tfetch(ENDPOINT, { method: 'POST', body: fd }).then(function (r) { return r.json(); });
    }

    /* ---------- Animacje kroków ---------- */

    /* Karta zmienia zawartość między krokami o różnych wysokościach — bez
       animacji wysokość skakała (duży layout shift = "szarpnięcie").
       freezeCardHeight przed podmianą + animateCardHeight po niej dają
       płynny morph zsynchronizowany z przesuwaniem treści. */
    function freezeCardHeight(card) {
        if (!card) return;
        card.style.height = card.offsetHeight + 'px';
        card.classList.add('card-h-anim');
    }

    function animateCardHeight(card) {
        if (!card) return;
        var from = card.style.height;
        card.style.height = 'auto';
        var to = card.offsetHeight;
        card.style.height = from;
        void card.offsetWidth;
        card.style.height = to + 'px';
    }

    function clearCardHeight(card) {
        if (!card) return;
        card.classList.remove('card-h-anim');
        card.style.height = '';
    }

    /* Przejście kroków to jeden nakładający się morph (jak przełączanie kart
       logowanie<->rejestracja): stary krok odpływa scale+blur jako absolutna
       nakładka, nowy w tym samym czasie wyłania się pod spodem, a karta
       płynnie zmienia wysokość. Bez faz sekwencyjnych = bez szarpnięć. */
    function animateStep(currentId, nextId, direction) {
        return new Promise(function (resolve) {
            var current = $(currentId);
            var next = $(nextId);
            var card = current.closest('.card');
            var switchLink = card ? card.querySelector('.switch-link') : null;

            freezeCardHeight(card);

            // stary krok przechodzi na pozycję absolutną — od tej chwili
            // wysokość karty wyznacza już nowy krok
            current.classList.add('step-leaving');

            if (switchLink) {
                if (direction === 'forward') {
                    switchLink.style.opacity = '0';
                    switchLink.style.display = 'none';
                } else {
                    switchLink.style.display = 'block';
                    switchLink.style.opacity = '0';
                }
            }

            next.classList.remove('fade-in');
            next.classList.add('step-enter');
            next.style.display = 'block';

            animateCardHeight(card);
            void next.offsetWidth;
            next.classList.remove('step-enter');
            if (switchLink && direction === 'back') switchLink.style.opacity = '1';

            setTimeout(function () {
                current.classList.remove('step-leaving');
                current.style.display = 'none';
                clearCardHeight(card);
                resolve();
            }, 420);
        });
    }

    /* ---------- Krok 1: identyfikacja ---------- */

    function checkIdentity(form) {
        clearMessage();
        var btn = form.querySelector('button[type="submit"]');
        var label = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Sprawdzanie...';

        post(new FormData(form)).then(function (data) {
            if (data.ok) {
                var dyn = $('step-dynamic');
                dyn.innerHTML = data.html;
                // fade-in z szablonu zdublowałby się z morphem kroku
                if (dyn.firstElementChild) dyn.firstElementChild.classList.remove('fade-in');
                dyn.style.display = 'none';
                animateStep('step-identity', 'step-dynamic', 'forward').then(function () {
                    if (data.focus_id && $(data.focus_id)) $(data.focus_id).focus({ preventScroll: true });
                });
            } else {
                showMessage(data.msg, 'error');
                resetCaptcha();
                if (data.reload) setTimeout(function () { location.reload(); }, 1500);
            }
        }).catch(function () {
            showMessage('Błąd połączenia', 'error');
        }).finally(function () {
            btn.disabled = false;
            btn.textContent = label;
        });
    }

    /* ---------- Logowanie (hasło) ---------- */

    function handleLogin(form) {
        clearMessage();
        var btn = form.querySelector('button[type="submit"]');
        var label = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Logowanie...';

        post(new FormData(form)).then(function (data) {
            if (data.ok && data.redirect) {
                window.location.href = data.redirect;
                return;
            }
            btn.disabled = false;
            btn.textContent = label;
            if (data.reset) {
                resetCaptcha();
                goBack(true).then(function () { showMessage(data.msg, 'error'); });
            } else {
                showMessage(data.msg, 'error');
                if (data.reload) setTimeout(function () { location.reload(); }, 2000);
            }
        }).catch(function () {
            showMessage('Błąd serwera', 'error');
            btn.disabled = false;
            btn.textContent = label;
        });
    }

    function goBack(keepMessage) {
        if (!keepMessage) clearMessage();
        resetCaptcha();
        return animateStep('step-dynamic', 'step-identity', 'back').then(function () {
            $('step-dynamic').innerHTML = '';
            if ($('inpEmail')) $('inpEmail').focus({ preventScroll: true });
        });
    }

    /* ---------- Rejestracja ---------- */

    function resetRegistrationUI() {
        var bar = $('pwdBar');
        if (bar) { bar.style.width = '0%'; bar.style.backgroundColor = ''; }
        document.querySelectorAll('.pwd-reqs li').forEach(function (li) { li.classList.remove('valid'); });
        ['pass-match-status', 'availability-status'].forEach(function (id) {
            var el = $(id);
            if (el) { el.className = ''; el.textContent = ''; }
        });
        var rm = $('register-message');
        if (rm) rm.style.display = 'none';
    }

    function handleRegister(form) {
        var rm = $('register-message');
        if (rm) rm.style.display = 'none';
        clearMessage();

        var btn = form.querySelector('button[type="submit"]');
        var label = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Tworzenie konta...';

        var fd = new FormData(form);
        fd.append('ajax_action', 'register');

        post(fd).then(function (data) {
            if (data.ok) {
                form.reset();
                resetRegistrationUI();
                showMessage(data.msg, 'success');
                setTimeout(function () { switchView('login'); }, 1500);
            } else if (rm) {
                rm.textContent = data.msg;
                rm.className = 'msg-box msg-err'; rm.style.display = 'block';
            }
            if (!data.ok) resetCaptcha();
        }).catch(function () {
            if (rm) { rm.textContent = 'Błąd połączenia z serwerem'; rm.className = 'msg-box msg-err'; rm.style.display = 'block'; }
        }).finally(function () {
            btn.disabled = false;
            btn.textContent = label;
        });
    }

    /* ---------- Przełączanie login <-> rejestracja ---------- */

    function switchView(view) {
        var login = $('card-login');
        var reg = $('card-register');
        var entering = view === 'register' ? reg : login;
        var leaving = view === 'register' ? login : reg;

        leaving.classList.remove('active');
        leaving.classList.add('leaving');
        entering.classList.remove('leaving');
        entering.classList.add('active');
        setTimeout(function () { leaving.classList.remove('leaving'); }, 500);
    }

    /* ---------- Walidacja rejestracji (live) ---------- */

    function initRegistrationValidators() {
        var fieldLocal = $('fieldLocal');
        var fieldDomain = $('fieldDomain');
        var fieldPass = $('fieldPass');
        var fieldPass2 = $('fieldPass2');

        if (fieldLocal && fieldDomain) {
            var timer;
            var statusDiv = $('availability-status');
            var check = function () {
                var local = fieldLocal.value.trim();
                if (!local) { statusDiv.textContent = ''; statusDiv.className = ''; return; }
                if (!/^[a-z0-9](?:[a-z0-9._-]{0,62}[a-z0-9])?$/i.test(local)) {
                    statusDiv.textContent = 'Niedozwolone znaki';
                    statusDiv.className = 'status-bad show';
                    return;
                }
                statusDiv.textContent = 'Sprawdzanie...';
                statusDiv.className = 'status-load show';
                post({ ajax_action: 'check_availability', local: local, domain: fieldDomain.value })
                    .then(function (data) {
                        if (data.ok) {
                            statusDiv.textContent = data.msg;
                            statusDiv.className = (data.status === 'free' ? 'status-ok' : 'status-bad') + ' show';
                        } else {
                            statusDiv.textContent = data.msg || 'Błąd';
                            statusDiv.className = 'status-bad show';
                        }
                    })
                    .catch(function () {
                        statusDiv.textContent = 'Błąd sieci';
                        statusDiv.className = 'status-bad show';
                    });
            };
            fieldLocal.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(check, 500); });
            fieldDomain.addEventListener('change', check);
        }

        if (fieldPass) {
            var bar = $('pwdBar');
            fieldPass.addEventListener('input', function () {
                var val = fieldPass.value, score = 0, max = 0;
                var rule = function (enabled, ok, id) {
                    if (!enabled) return;
                    max++;
                    var el = $(id);
                    if (ok) { score++; if (el) el.classList.add('valid'); }
                    else if (el) el.classList.remove('valid');
                };
                rule(PWD.len,   val.length >= PWD.len,       'reqLen');
                rule(PWD.upper, /[A-Z]/.test(val),           'reqUp');
                rule(PWD.digit, /\d/.test(val),              'reqDig');
                rule(PWD.spec,  /[^a-zA-Z0-9]/.test(val),    'reqSpec');
                if (!max) max = 1;
                var pct = Math.min(100, (score / max) * 100);
                if (bar) {
                    bar.style.width = pct + '%';
                    var colorVar = pct < 40 ? '--status-error' : pct < 80 ? '--status-warning' : '--status-success';
                    bar.style.backgroundColor = 'var(' + colorVar + ')';
                }
            });
        }

        if (fieldPass && fieldPass2) {
            var matchStatus = $('pass-match-status');
            var mtimer;
            var checkMatch = function () {
                if (!fieldPass2.value) { matchStatus.textContent = ''; matchStatus.className = ''; return; }
                if (fieldPass.value === fieldPass2.value) {
                    matchStatus.textContent = 'Hasła są zgodne';
                    matchStatus.className = 'status-ok show';
                } else {
                    matchStatus.textContent = 'Hasła różnią się';
                    matchStatus.className = 'status-bad show';
                }
            };
            fieldPass.addEventListener('input', function () { clearTimeout(mtimer); mtimer = setTimeout(checkMatch, 300); });
            fieldPass2.addEventListener('input', function () { clearTimeout(mtimer); mtimer = setTimeout(checkMatch, 300); });
        }
    }

    /* ---------- Delegacja zdarzeń ---------- */

    document.addEventListener('submit', function (e) {
        var t = e.target;
        if (t.id === 'form-identity') { e.preventDefault(); checkIdentity(t); }
        else if (t.id === 'form-register') { e.preventDefault(); handleRegister(t); }
        else if (t.matches('[data-form="login"]')) { e.preventDefault(); handleLogin(t); }
    });

    document.addEventListener('click', function (e) {
        var el = e.target.closest('[data-action]');
        if (!el) return;
        var action = el.dataset.action;
        if (action === 'back') { e.preventDefault(); goBack(false); }
        else if (action === 'switch') { e.preventDefault(); switchView(el.dataset.view); }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') return;
        var el = e.target.closest('[data-action][role="button"]');
        if (el) { e.preventDefault(); el.click(); }
    });

    initRegistrationValidators();
})();
