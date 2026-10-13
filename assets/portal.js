/* =========================================================================
   Portal — logika front-endu (bez inline-JS; obsługa zdarzeń przez delegację).
   Konfiguracja przekazywana przez data-* na <body> (CSP: script-src 'self').

   1.2.0 — logowanie: adres -> hasło -> (tylko administrator ze skrzynką) kafelki Webmail / Panel Admina.
   Serwer odpowiada na złe hasło tak samo dla każdego adresu; front niczego nie zgaduje o koncie.
   ========================================================================= */
(function () {
    'use strict';

    var cfg = document.body.dataset;
    var CSRF = cfg.csrf || '';
    var RC_ON = cfg.recaptcha === '1';
    var PWD = {
        len:   parseInt(cfg.pwdMin, 10) || 0,
        upper: parseInt(cfg.pwdUpper, 10) || 0,
        digit: parseInt(cfg.pwdDigit, 10) || 0,
        spec:  parseInt(cfg.pwdSpec, 10) || 0
    };
    var ENDPOINT = 'portal.php';
    var REDUCED = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var STEP_MS = REDUCED ? 0 : 420;

    var flow = { email: '', choiceKey: '' };   // klucz do hasła dla kafelka Webmail — tylko w pamięci tej strony

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

    function post(data) {
        var fd = data instanceof FormData ? data : new FormData();
        if (!(data instanceof FormData)) {
            Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
        }
        fd.append('_csrf', CSRF);
        return tfetch(ENDPOINT, {
            method: 'POST', body: fd, credentials: 'same-origin',
            headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' }
        }).then(function (r) { return r.json(); });
    }

    function resetCaptcha() {
        if (window.grecaptcha) {
            try { grecaptcha.reset(); } catch (e) { /* instancja niegotowa */ }
        }
    }

    /* Komunikat w karcie: miejsce rozwija się płynnie (bez skoku), treść czyta czytnik ekranu (aria-live). */
    function showMessage(msg, type, boxId) {
        var el = $(boxId || 'global-message');
        if (!el) return;
        el.querySelector('.msg-inner').textContent = msg;
        el.classList.remove('msg-err', 'msg-ok');
        el.classList.add(type === 'success' ? 'msg-ok' : 'msg-err', 'is-open');
    }

    function clearMessage(boxId) {
        var el = $(boxId || 'global-message');
        if (el) el.classList.remove('is-open');
    }

    /* Przycisk w trakcie żądania: ta sama szerokość, spinner, aria-busy, bez podwójnego wysłania. */
    function busy(btn, on, label) {
        if (!btn) return;
        if (on) {
            if (btn.dataset.busy === '1') return;
            btn.dataset.busy = '1';
            btn.dataset.label = btn.innerHTML;
            btn.style.minWidth = btn.offsetWidth + 'px';
            btn.disabled = true;
            btn.setAttribute('aria-busy', 'true');
            btn.innerHTML = '<span class="spinner" aria-hidden="true"></span> ' + (label || btn.textContent.trim());
        } else {
            if (btn.dataset.busy !== '1') return;
            btn.innerHTML = btn.dataset.label;
            btn.disabled = false;
            btn.removeAttribute('aria-busy');
            btn.style.minWidth = '';
            delete btn.dataset.busy;
        }
    }

    function hint(id, text) {
        var el = $(id);
        if (!el) return;
        el.textContent = text || '';
        el.classList.toggle('is-on', !!text);
    }

    /* ---------- Animacje kroków ---------- */

    /* Karta zmienia zawartość między krokami o różnych wysokościach — bez animacji wysokość skakała.
       freezeCardHeight przed podmianą + animateCardHeight po niej dają płynny morph zsynchronizowany z treścią. */
    function freezeCardHeight(card) {
        if (!card || REDUCED) return;
        card.style.height = card.offsetHeight + 'px';
        card.classList.add('card-h-anim');
    }

    function animateCardHeight(card) {
        if (!card || REDUCED) return;
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

    function visibleStep() {
        return ['step-choice', 'step-dynamic', 'step-identity'].filter(function (id) { return !$(id).hidden; })[0] || 'step-identity';
    }

    /* Jeden nakładający się morph: stary krok odpływa (absolutna nakładka), nowy wyłania się pod spodem,
       karta płynnie zmienia wysokość. */
    function animateStep(currentId, nextId, direction) {
        return new Promise(function (resolve) {
            var current = $(currentId);
            var next = $(nextId);
            if (current === next) { resolve(); return; }
            var card = current.closest('.card');
            var switchLink = card ? card.querySelector('.switch-link') : null;

            freezeCardHeight(card);
            current.classList.add('step-leaving');
            current.setAttribute('aria-hidden', 'true');

            if (switchLink) {
                switchLink.hidden = nextId !== 'step-identity';
            }

            next.classList.add('step-enter');
            next.hidden = false;
            next.removeAttribute('aria-hidden');

            animateCardHeight(card);
            void next.offsetWidth;
            next.classList.remove('step-enter');

            setTimeout(function () {
                current.classList.remove('step-leaving');
                current.hidden = true;
                current.removeAttribute('aria-hidden');
                clearCardHeight(card);
                resolve();
            }, STEP_MS);
        });
    }

    function focusLater(id) {
        var el = id && $(id);
        if (el && !el.disabled) el.focus({ preventScroll: true });
    }

    /* ---------- Krok 1: adres ---------- */

    function checkIdentity(form) {
        clearMessage();
        var btn = form.querySelector('button[type="submit"]');
        var email = ($('inpEmail').value || '').trim();
        if (!email) { showMessage('Podaj adres e-mail', 'error'); $('inpEmail').focus(); return; }
        busy(btn, true, 'Sprawdzanie…');

        post(new FormData(form)).then(function (data) {
            if (data.ok) {
                flow.email = email.toLowerCase();
                var dyn = $('step-dynamic');
                dyn.innerHTML = data.html;
                animateStep('step-identity', 'step-dynamic', 'forward').then(function () { focusLater(data.focus_id); });
            } else {
                showMessage(data.msg, 'error');
                resetCaptcha();
                if (data.reload) setTimeout(function () { location.reload(); }, 1500);
            }
        }).catch(function () {
            showMessage('Brak połączenia z serwerem — spróbuj ponownie.', 'error');
        }).finally(function () { busy(btn, false); });
    }

    /* ---------- Krok 2: hasło ---------- */

    function handleLogin(form) {
        clearMessage();
        hint('loginHint', '');
        var btn = form.querySelector('button[type="submit"]');
        var pass = form.querySelector('[name="password"]');
        if (!pass.value) { hint('loginHint', 'Podaj hasło'); pass.focus(); return; }
        busy(btn, true, 'Logowanie…');

        post(new FormData(form)).then(function (data) {
            if (data.ok && data.redirect) {
                window.location.href = data.redirect;
                return;   // przycisk zostaje "w toku" aż do przejścia
            }
            if (data.ok && data.step === 'choose') {
                flow.choiceKey = data.choice_key || '';
                $('step-choice').innerHTML = data.html;
                // kafelek wyłączony przez serwer zostaje wyłączony także po błędzie wyboru drugiego
                document.querySelectorAll('#step-choice .service-card[disabled]').forEach(function (t) { t.dataset.disabled = '1'; });
                busy(btn, false);
                animateStep('step-dynamic', 'step-choice', 'forward').then(function () { focusLater(data.focus_id); });
                return;
            }
            busy(btn, false);
            if (data.reset) {
                retryOrBack(data.msg, pass);
            } else {
                hint('loginHint', data.msg);
                if (data.reload) setTimeout(function () { location.reload(); }, 2000);
            }
        }).catch(function () {
            busy(btn, false);
            hint('loginHint', 'Brak połączenia z serwerem — spróbuj ponownie.');
        });
    }

    /* Złe hasło: serwer zapomniał rozpoczęte logowanie (tak samo dla każdego adresu). Bez reCAPTCHA zaczynamy je od
       nowa w tle i zostajemy na ekranie hasła; z reCAPTCHA trzeba wrócić do adresu (nowy token). */
    function retryOrBack(msg, pass) {
        if (RC_ON || !flow.email) {
            goBack(true).then(function () { showMessage(msg, 'error'); });
            return;
        }
        post({ ajax_action: 'check_identity', email: flow.email }).then(function (data) {
            if (!data.ok) {
                goBack(true).then(function () { showMessage(data.msg || msg, 'error'); });
                return;
            }
            if (pass) { pass.value = ''; pass.focus({ preventScroll: true }); }
            hint('loginHint', msg);
            var field = pass && pass.closest('.pwd-field');
            if (field && !REDUCED) { field.classList.remove('shake'); void field.offsetWidth; field.classList.add('shake'); }
        }).catch(function () {
            goBack(true).then(function () { showMessage(msg, 'error'); });
        });
    }

    /* ---------- Krok 3: kafelki (administrator) ---------- */

    function chooseTarget(tile) {
        var target = tile.dataset.target;
        var tiles = document.querySelectorAll('#step-choice .service-card');
        tiles.forEach(function (t) { t.disabled = true; });
        tile.classList.add('is-busy');
        tile.setAttribute('aria-busy', 'true');
        hint('choiceHint', target === 'admin' ? 'Otwieram panel…' : 'Otwieram webmail…');

        post({ ajax_action: 'choose_target', target: target, key: target === 'webmail' ? flow.choiceKey : '' }).then(function (data) {
            if (data.ok && data.redirect) {
                flow.choiceKey = '';
                window.location.href = data.redirect;
                return;
            }
            flow.choiceKey = '';
            if (data.reset) {
                goBack(true, true).then(function () { showMessage(data.msg, 'error'); });
            } else {
                tiles.forEach(function (t) { t.disabled = t.dataset.disabled === '1'; });
                tile.classList.remove('is-busy');
                tile.removeAttribute('aria-busy');
                hint('choiceHint', data.msg || 'Błąd');
                if (data.reload) setTimeout(function () { location.reload(); }, 2000);
            }
        }).catch(function () {
            tiles.forEach(function (t) { t.disabled = t.dataset.disabled === '1'; });
            tile.classList.remove('is-busy');
            tile.removeAttribute('aria-busy');
            hint('choiceHint', 'Brak połączenia z serwerem — spróbuj ponownie.');
        });
    }

    /* Wróć do kroku "adres". Serwer zapomina rozpoczęte logowanie (także zaszyfrowane hasło dla kafelka). */
    function goBack(keepMessage, serverForgot) {
        if (!keepMessage) clearMessage();
        resetCaptcha();
        flow.choiceKey = '';
        if (!serverForgot) post({ ajax_action: 'cancel_login' }).catch(function () {});
        var from = visibleStep();
        return animateStep(from, 'step-identity', 'back').then(function () {
            $('step-dynamic').innerHTML = '';
            $('step-dynamic').hidden = true;
            $('step-choice').innerHTML = '';
            $('step-choice').hidden = true;
            var inp = $('inpEmail');
            if (inp) { inp.focus({ preventScroll: true }); inp.select(); }
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
        clearMessage('register-message');
    }

    function handleRegister(form) {
        clearMessage('register-message');
        clearMessage();
        var btn = form.querySelector('button[type="submit"]');
        busy(btn, true, 'Tworzenie konta…');

        var fd = new FormData(form);
        fd.append('ajax_action', 'register');
        var made = ((form.querySelector('[name="local"]').value || '') + '@' + (form.querySelector('[name="domain"]').value || '')).toLowerCase();

        post(fd).then(function (data) {
            if (data.ok) {
                form.reset();
                resetRegistrationUI();
                showMessage(data.msg + ' Zaloguj się nowym adresem.', 'success');
                if ($('inpEmail')) $('inpEmail').value = made;
                setTimeout(function () { switchView('login'); }, 1200);
            } else {
                showMessage(data.msg, 'error', 'register-message');
                resetCaptcha();
            }
        }).catch(function () {
            showMessage('Brak połączenia z serwerem — spróbuj ponownie.', 'error', 'register-message');
        }).finally(function () { busy(btn, false); });
    }

    /* ---------- Przełączanie login <-> rejestracja ---------- */

    function switchView(view) {
        var login = $('card-login');
        var reg = $('card-register');
        var entering = view === 'register' ? reg : login;
        var leaving = view === 'register' ? login : reg;

        leaving.classList.remove('active');
        leaving.classList.add('leaving');
        leaving.inert = true;
        entering.classList.remove('leaving');
        entering.classList.add('active');
        entering.inert = false;
        setTimeout(function () { leaving.classList.remove('leaving'); }, REDUCED ? 0 : 500);
        var first = view === 'register' ? $('fieldLocal') : (visibleStep() === 'step-identity' ? $('inpEmail') : null);
        if (first) setTimeout(function () { first.focus({ preventScroll: true }); }, REDUCED ? 0 : 350);
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
            var seq = 0;
            var check = function () {
                var local = fieldLocal.value.trim();
                if (!local) { statusDiv.textContent = ''; statusDiv.className = ''; return; }
                if (!/^[a-z0-9](?:[a-z0-9._-]{0,62}[a-z0-9])?$/i.test(local)) {
                    statusDiv.textContent = 'Niedozwolone znaki (litery, cyfry, . _ -; bez znaku na brzegach)';
                    statusDiv.className = 'status-bad show';
                    return;
                }
                statusDiv.textContent = 'Sprawdzanie…';
                statusDiv.className = 'status-load show';
                var my = ++seq;
                post({ ajax_action: 'check_availability', local: local, domain: fieldDomain.value })
                    .then(function (data) {
                        if (my !== seq) return;      // starsza odpowiedź po nowszej — pomijamy
                        if (data.ok) {
                            statusDiv.textContent = data.msg;
                            statusDiv.className = (data.status === 'free' ? 'status-ok' : 'status-bad') + ' show';
                        } else {
                            statusDiv.textContent = data.msg || 'Błąd';
                            statusDiv.className = 'status-bad show';
                        }
                    })
                    .catch(function () {
                        if (my !== seq) return;
                        statusDiv.textContent = 'Brak połączenia';
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

    /* ---------- Pokaż / ukryj hasło, Caps Lock ---------- */

    function togglePassword(btn) {
        var inp = $(btn.dataset.for);
        if (!inp) return;
        var show = inp.type === 'password';
        inp.type = show ? 'text' : 'password';
        btn.setAttribute('aria-pressed', show ? 'true' : 'false');
        btn.setAttribute('aria-label', show ? 'Ukryj hasło' : 'Pokaż hasło');
        btn.classList.toggle('is-on', show);
        inp.focus({ preventScroll: true });
    }

    function capsLock(e) {
        if (!e.getModifierState || e.target.id !== 'inpPassword') return;
        var on = e.getModifierState('CapsLock');
        var el = $('loginHint');
        if (!el) return;
        if (on && !el.textContent) { hint('loginHint', 'Caps Lock jest włączony'); el.dataset.caps = '1'; }
        else if (!on && el.dataset.caps === '1') { hint('loginHint', ''); delete el.dataset.caps; }
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
        if (!el || el.disabled) return;
        var action = el.dataset.action;
        if (action === 'back') { e.preventDefault(); goBack(false); }
        else if (action === 'switch') { e.preventDefault(); switchView(el.dataset.view); }
        else if (action === 'toggle-pwd') { e.preventDefault(); togglePassword(el); }
        else if (action === 'choose') { e.preventDefault(); chooseTarget(el); }
    });

    document.addEventListener('keydown', function (e) {
        // strzałki przełączają między kafelkami
        var tile = e.target.closest && e.target.closest('.service-card');
        if (tile && ['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].indexOf(e.key) !== -1) {
            var all = Array.prototype.filter.call(document.querySelectorAll('#step-choice .service-card'), function (t) { return !t.disabled; });
            var i = all.indexOf(tile);
            var next = all[(i + (e.key === 'ArrowLeft' || e.key === 'ArrowUp' ? all.length - 1 : 1)) % all.length];
            if (next) { e.preventDefault(); next.focus(); }
            return;
        }
        if (e.key === 'Escape' && visibleStep() !== 'step-identity' && $('card-login').classList.contains('active')) {
            goBack(false);
        }
        capsLock(e);
    });
    document.addEventListener('keyup', capsLock);

    $('card-register').inert = true;
    initRegistrationValidators();
})();
