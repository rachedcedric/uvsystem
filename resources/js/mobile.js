/*
 * Comportements "application" de l'interface mobile (/m).
 * JavaScript simple, sans dépendance : feuilles du bas, onglets, tirer pour
 * rafraîchir, retour haptique, toasts, bandeau hors-ligne, splash.
 * Les plugins Capacitor sont lus sur window.Capacitor (injecté par l'APK) ;
 * dans un navigateur classique tout reste fonctionnel sans eux.
 */
import { PushNotifications } from '@capacitor/push-notifications';

(function () {
    'use strict';

    var cap = window.Capacitor;
    var native = !!(cap && typeof cap.isNativePlatform === 'function' && cap.isNativePlatform());

    function plugin(name) {
        if (!native) return null;
        return (cap.Plugins && cap.Plugins[name]) || (cap.registerPlugin ? cap.registerPlugin(name) : null);
    }

    /* ------------------------------------------------------------ haptics */

    function pref(key, fallback) {
        try {
            var v = localStorage.getItem(key);
            return v === null ? fallback : v === '1';
        } catch (e) { return fallback; }
    }

    function setPref(key, on) {
        try { localStorage.setItem(key, on ? '1' : '0'); } catch (e) { /* préférence non conservée */ }
    }

    function haptic(kind) {
        if (!pref('m-haptics', true)) return;
        try {
            var h = plugin('Haptics');
            if (h) {
                if (kind === 'success') h.notification({ type: 'SUCCESS' });
                else if (kind === 'error') h.notification({ type: 'ERROR' });
                else h.impact({ style: kind === 'medium' ? 'MEDIUM' : 'LIGHT' });
                return;
            }
            if (navigator.vibrate) navigator.vibrate(kind === 'medium' ? 18 : 8);
        } catch (e) { /* retour haptique indisponible : sans importance */ }
    }

    /* --------------------------------------------------------------- sons */
    // Sons synthétisés (Web Audio) : aucun fichier à charger, donc instantanés et hors-ligne.

    var audio = null;

    function audioContext() {
        if (audio) return audio;
        var Ctx = window.AudioContext || window.webkitAudioContext;
        if (!Ctx) return null;
        try { audio = new Ctx(); } catch (e) { audio = null; }
        return audio;
    }

    var SOUNDS = {
        notify: [{ f: 880, t: 0, d: 0.24 }, { f: 1174.66, t: 0.17, d: 0.55 }],
        success: [{ f: 659.25, t: 0, d: 0.14 }, { f: 987.77, t: 0.11, d: 0.32 }],
        error: [{ f: 233.08, t: 0, d: 0.17, type: 'triangle' }, { f: 174.61, t: 0.15, d: 0.34, type: 'triangle' }],
        tap: [{ f: 1600, t: 0, d: 0.035, v: 0.07 }]
    };

    function sound(kind, force) {
        if (!force && !pref('m-sound', true)) return false;
        if (kind === 'tap' && !force && !pref('m-sound-keys', false)) return false;

        var ctx = audioContext();
        if (!ctx) return false;
        try {
            if (ctx.state === 'suspended' && ctx.resume) ctx.resume();
            var now = ctx.currentTime;
            (SOUNDS[kind] || []).forEach(function (n) {
                [[1, 1], [2, 0.22]].forEach(function (partial) {
                    var osc = ctx.createOscillator();
                    var gain = ctx.createGain();
                    var level = (n.v || 0.17) * partial[1];
                    osc.type = n.type || 'sine';
                    osc.frequency.setValueAtTime(n.f * partial[0], now + n.t);
                    gain.gain.setValueAtTime(0.0001, now + n.t);
                    gain.gain.exponentialRampToValueAtTime(level, now + n.t + 0.012);
                    gain.gain.exponentialRampToValueAtTime(0.0001, now + n.t + n.d);
                    osc.connect(gain);
                    gain.connect(ctx.destination);
                    osc.start(now + n.t);
                    osc.stop(now + n.t + n.d + 0.05);
                });
            });
            return true;
        } catch (e) { return false; }
    }

    // Etat des notifications push de cet appareil (écran « Diagnostic des notifications »).
    function syncDeviceStatus() {
        var el = document.querySelector('[data-device-push]');
        if (!el) return;
        var text = !native ? 'Navigateur : pas de notifications push (réservées à l\'application Android).'
            : (firebaseAvailable() ? 'Cet appareil : Firebase intégré à l\'application, push disponible.'
                : 'Cet appareil : application construite sans google-services.json — pas de push (cloche et sons seulement).');
        el.textContent = text;
        el.setAttribute('data-ok', native && firebaseAvailable() ? '1' : '0');
    }

    function syncPrefs() {
        document.querySelectorAll('[data-pref]').forEach(function (el) {
            var on = pref(el.getAttribute('data-pref'), el.getAttribute('data-default') === '1');
            el.classList.toggle('is-on', on);
            el.setAttribute('aria-checked', on ? 'true' : 'false');
            var label = el.querySelector('[data-pref-label]');
            if (label) label.textContent = on ? 'Activé' : 'Désactivé';
        });
    }

    /* -------------------------------------------------------------- toast */

    var toastTimer;
    function toast(message, opts) {
        var el = document.getElementById('m-toast');
        if (!el || !message) return;
        el.querySelector('[data-toast-text]').textContent = message;
        if (opts && opts.url) el.setAttribute('data-url', opts.url); else el.removeAttribute('data-url');
        el.setAttribute('data-show', '');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { el.removeAttribute('data-show'); }, opts && opts.url ? 6000 : 3200);
    }

    window.addEventListener('m-toast', function (e) {
        var detail = e.detail || {};
        toast(Array.isArray(detail) ? detail[0] && detail[0].message : detail.message);
        var isError = detail.type === 'error';
        haptic(isError ? 'error' : 'success');
        sound(isError ? 'error' : 'success');
    });

    // Nouvelle notification détectée par le serveur (surveillance toutes les 30 s).
    window.addEventListener('m-notify', function (e) {
        var detail = e.detail || {};
        if (Array.isArray(detail)) detail = detail[0] || {};
        var more = detail.count > 1 ? ' (+' + (detail.count - 1) + ')' : '';
        toast((detail.title || 'Nouvelle notification') + more, { url: detail.url });
        haptic('success');
        sound('notify');
    });

    function openUrl(url) {
        try {
            var u = new URL(url, location.origin);
            if (u.origin !== location.origin) return;
            if (window.Livewire && typeof window.Livewire.navigate === 'function') window.Livewire.navigate(u.pathname + u.search);
            else location.assign(u.href);
        } catch (e) { /* lien invalide */ }
    }

    /* -------------------------------------------------------------- sheets */

    function openSheet(id) {
        var sheet = document.getElementById(id);
        var backdrop = document.getElementById('m-backdrop');
        if (!sheet || !backdrop) return;
        sheet.setAttribute('data-open', '');
        backdrop.setAttribute('data-open', '');
        haptic('light');
    }

    function closeSheets() {
        var closed = false;
        document.querySelectorAll('.m-sheet[data-open]').forEach(function (s) {
            s.removeAttribute('data-open');
            closed = true;
        });
        var backdrop = document.getElementById('m-backdrop');
        if (backdrop) backdrop.removeAttribute('data-open');
        return closed;
    }

    // Graphiques en barres : toucher une barre affiche son montant exact (jamais abrégé).
    function formatMoney(amount) {
        return String(Math.round(amount)).replace(/\B(?=(\d{3})+(?!\d))/g, '\u00a0') + '\u00a0FCFA';
    }

    function selectBar(col) {
        var box = col.closest('.m-group');
        if (!box) return;
        box.querySelectorAll('.m-bars__col.is-selected').forEach(function (c) { c.classList.remove('is-selected'); });
        col.classList.add('is-selected');
        var readout = box.querySelector('[data-bars-readout]');
        if (readout && readout.firstElementChild && readout.lastElementChild) {
            readout.firstElementChild.textContent = col.getAttribute('data-label') || '';
            readout.lastElementChild.textContent = formatMoney(Number(col.getAttribute('data-amount')) || 0);
        }
    }

    document.addEventListener('keydown', function (e) {
        var col = e.target.closest && e.target.closest('.m-bars__col[data-amount]');
        if (col && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); selectBar(col); }
    });

    document.addEventListener('click', function (e) {
        var bar = e.target.closest && e.target.closest('.m-bars__col[data-amount]');
        if (bar) { selectBar(bar); haptic('light'); return; }
        var opener = e.target.closest && e.target.closest('[data-sheet-open]');
        if (opener) { e.preventDefault(); openSheet(opener.getAttribute('data-sheet-open')); return; }
        if (e.target.closest && (e.target.closest('[data-sheet-close]') || e.target.id === 'm-backdrop')) { closeSheets(); return; }
        var prefToggle = e.target.closest && e.target.closest('[data-pref]');
        if (prefToggle) {
            var key = prefToggle.getAttribute('data-pref');
            var next = !pref(key, prefToggle.getAttribute('data-default') === '1');
            setPref(key, next);
            syncPrefs();
            if (next) { haptic('light'); if (key === 'm-sound') sound('success'); }
            return;
        }
        var test = e.target.closest && e.target.closest('[data-sound-test]');
        if (test) { sound(test.getAttribute('data-sound-test'), true); haptic('light'); return; }
        var banner = e.target.closest && e.target.closest('#m-toast[data-url]');
        if (banner) { openUrl(banner.getAttribute('data-url')); banner.removeAttribute('data-show'); return; }
        if (e.target.closest && e.target.closest('.m-tab, [data-haptic]')) { haptic('light'); sound('tap'); }
    });

    // Consulté par capacitor-bridge.js avant de gérer le bouton retour Android.
    window.mobileBackHandler = function () {
        return closeSheets();
    };

    /* ---------------------------------------------------------------- tabs */

    function updateTabs() {
        var path = location.pathname.replace(/\/+$/, '') || '/';
        document.querySelectorAll('.m-tab[data-match]').forEach(function (tab) {
            var match = tab.getAttribute('data-match');
            var active = tab.hasAttribute('data-exact')
                ? path === match
                : path === match || path.indexOf(match + '/') === 0;
            tab.classList.toggle('is-active', active);
            if (active) tab.setAttribute('aria-current', 'page'); else tab.removeAttribute('aria-current');
        });
    }

    /* -------------------------------------------------- pull to refresh */

    var ptr = { startY: 0, dy: 0, tracking: false, busy: false };
    var THRESHOLD = 78;

    function indicator() { return document.getElementById('m-ptr'); }

    function refreshPage() {
        var el = indicator();
        if (!el || ptr.busy || !window.Livewire) return;
        ptr.busy = true;
        el.setAttribute('data-spin', '');
        el.style.transform = 'translateY(14px)';
        var done = function () {
            ptr.busy = false;
            el.removeAttribute('data-spin');
            el.removeAttribute('data-active');
            el.style.transform = '';
        };
        try {
            Promise.all(window.Livewire.all().map(function (c) { return c.$refresh(); })).then(function () { setTimeout(done, 350); }, done);
        } catch (e) { done(); }
        haptic('medium');
    }

    document.addEventListener('touchstart', function (e) {
        if (ptr.busy || window.scrollY > 0 || e.touches.length !== 1) return;
        if (document.querySelector('.m-sheet[data-open]') || e.target.closest('[data-no-ptr], input, textarea, select')) return;
        ptr.startY = e.touches[0].clientY;
        ptr.tracking = true;
        ptr.dy = 0;
    }, { passive: true });

    document.addEventListener('touchmove', function (e) {
        if (!ptr.tracking) return;
        ptr.dy = e.touches[0].clientY - ptr.startY;
        var el = indicator();
        if (!el) return;
        if (ptr.dy <= 0) { el.removeAttribute('data-active'); return; }
        var pull = Math.min(ptr.dy, 120) * 0.55;
        el.setAttribute('data-active', '');
        el.style.transform = 'translateY(' + (pull - 52) + 'px) rotate(' + ptr.dy * 2 + 'deg)';
    }, { passive: true });

    document.addEventListener('touchend', function () {
        if (!ptr.tracking) return;
        ptr.tracking = false;
        var el = indicator();
        if (ptr.dy >= THRESHOLD) { refreshPage(); return; }
        if (el) { el.removeAttribute('data-active'); el.style.transform = ''; }
    });

    /* --------------------------------------------------------- hors-ligne */

    var connectionCheckId = 0;
    var connectionController = null;
    var connectionTimeout = null;
    var connectionRetry = null;
    var connectionFailures = 0;

    function setOffline(show) {
        var el = document.getElementById('m-offline');
        if (!el) return;
        if (show) el.setAttribute('data-show', ''); else el.removeAttribute('data-show');
    }

    // « Hors connexion » seulement quand le téléphone n'atteint vraiment pas le serveur :
    //  - le système dit lui-même qu'il n'y a pas de réseau (certain, affiché tout de suite) ;
    //  - ou deux contrôles d'affilée échouent (une réponse lente ou un réseau qui se rétablit au retour
    //    d'une autre appli ne doit pas déclencher la bande). Toute réponse du serveur, même une erreur 5xx,
    //    prouve que la connexion fonctionne : ce n'est alors pas un problème de connexion.
    function syncOnline() {
        if (!document.getElementById('m-offline')) return;
        clearTimeout(connectionRetry);

        if (navigator.onLine === false) {
            connectionFailures = 2;
            setOffline(true);
            return;
        }

        var checkId = ++connectionCheckId;
        if (connectionController) connectionController.abort();
        clearTimeout(connectionTimeout);

        connectionController = new AbortController();
        var controller = connectionController;
        connectionTimeout = setTimeout(function () { controller.abort(); }, 10000);

        fetch('/m-ping?_=' + Date.now(), { cache: 'no-store', credentials: 'omit', signal: controller.signal })
            .then(function () {
                if (checkId !== connectionCheckId) return;
                connectionFailures = 0;
                setOffline(false);
            })
            .catch(function () {
                if (checkId !== connectionCheckId) return;
                connectionFailures += 1;
                if (connectionFailures >= 2) setOffline(true);
                else connectionRetry = setTimeout(syncOnline, 4000);
            })
            .finally(function () {
                if (checkId !== connectionCheckId) return;
                clearTimeout(connectionTimeout);
                connectionController = null;
                connectionTimeout = null;
            });
    }
    window.addEventListener('online', function () { connectionFailures = 0; setOffline(false); syncOnline(); });
    window.addEventListener('offline', syncOnline);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) syncOnline();
    });
    setInterval(syncOnline, 30000);

    /* -------------------------------------------- notifications push */

    var pushInitialized = false;
    var pushTokenKey = 'uvmaster-push-token';

    function savePushToken(token) {
        if (!token) return;

        try { localStorage.setItem(pushTokenKey, token); } catch (e) { /* le plugin peut toujours réémettre le token */ }
        registerPushToken(token);
    }

    function registerPushToken(token) {
        var csrf = document.querySelector('meta[name="csrf-token"]');
        if (!token || !csrf || document.documentElement.dataset.pushEnabled !== 'true') return;

        fetch('/m/push-token', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf.content
            },
            body: JSON.stringify({ token: token, platform: 'android' })
        }).then(function (response) {
            if (!response.ok) throw new Error('Push token registration failed: ' + response.status);
        }).catch(function (error) {
            console.error('[UVMASTER push] Impossible d’enregistrer cet appareil.', error);
        });
    }

    // Vrai seulement si l'APK embarque Firebase (google-services.json). Sinon PushNotifications.register()
    // fait planter l'application : on n'y touche pas.
    function firebaseAvailable() {
        try { return !!(window.UvmasterNative && window.UvmasterNative.hasFirebase()); } catch (e) { return false; }
    }

    function syncPushNotifications() {
        if (!native || document.documentElement.dataset.pushEnabled !== 'true' || !firebaseAvailable()) return;
        if (pushInitialized) {
            try { registerPushToken(localStorage.getItem(pushTokenKey)); } catch (e) { /* le listener registration fournit le token */ }
            return;
        }
        pushInitialized = true;

        PushNotifications.addListener('registration', function (token) {
            savePushToken(token.value);
        });
        PushNotifications.addListener('registrationError', function (error) {
            console.error('[UVMASTER push] Enregistrement Firebase échoué.', error);
        });
        PushNotifications.addListener('pushNotificationReceived', function (notification) {
            // Application au premier plan : Android n'affiche pas de bannière système, on la fabrique.
            var data = notification.data || {};
            toast(notification.title || notification.body, { url: data.url });
            haptic('success');
            sound('notify');
        });
        PushNotifications.addListener('pushNotificationActionPerformed', function (event) {
            var target = event.notification && event.notification.data && event.notification.data.url;
            if (!target) return;

            var url;
            try { url = new URL(target, location.origin); } catch (e) { return; }
            if (url.origin === location.origin && url.pathname.indexOf('/m/') === 0) location.assign(url.href);
        });

        Promise.resolve().then(function () {
            return PushNotifications.createChannel({
                id: 'debt-alerts-v2',
                name: 'Dettes agents',
                description: 'Alertes et rappels de dettes des agents',
                importance: 5,
                sound: 'uvmaster_alert',
                vibration: true,
                visibility: 1
            });
        }).then(function () {
            return PushNotifications.checkPermissions();
        }).then(function (permission) {
            if (permission.receive === 'granted') return permission;
            return PushNotifications.requestPermissions();
        }).then(function (permission) {
            if (permission.receive !== 'granted') return;

            try { registerPushToken(localStorage.getItem(pushTokenKey)); } catch (e) { /* le listener registration fournit le token */ }
            return PushNotifications.register();
        }).catch(function (error) {
            console.error('[UVMASTER push] Initialisation des notifications impossible.', error);
            pushInitialized = false;
        });
    }

    /* -------------------------------------------------------------- splash */

    var started = performance.now();

    function hideNativeSplash() {
        try { var s = plugin('SplashScreen'); if (s) s.hide(); } catch (e) { /* le masquage auto prend le relais */ }
    }

    function hideSplash() {
        hideNativeSplash();
        var el = document.getElementById('m-splash');
        try { sessionStorage.setItem('m-launched', '1'); } catch (e) { /* stockage indisponible */ }
        if (!el) return;
        el.setAttribute('data-hide', '');
        setTimeout(function () { document.documentElement.classList.add('m-booted'); }, 350);
    }

    function bootSplash() {
        var el = document.getElementById('m-splash');
        if (!el || document.documentElement.classList.contains('m-booted')) { hideNativeSplash(); return; }
        var fontsReady = document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve();
        var capped = new Promise(function (resolve) { setTimeout(resolve, 1800); });
        Promise.race([fontsReady, capped]).then(function () {
            var wait = Math.max(0, 1000 - (performance.now() - started));
            setTimeout(hideSplash, wait);
        });
    }

    /* ---------------------------------------------------------------- saisie / clavier */

    // Champs de saisie texte (hors cases, fichiers, boutons, curseurs, couleurs).
    var FIELD_SELECTOR = 'input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=file]):not([type=submit]):not([type=button]):not([type=range]):not([type=color]), textarea';

    function formFields(form) {
        return Array.prototype.filter.call(form.querySelectorAll(FIELD_SELECTOR), function (el) {
            return !el.disabled && !el.readOnly && el.type !== 'date' && el.type !== 'time' && el.type !== 'datetime-local' && el.getClientRects().length > 0;
        });
    }

    /**
     * Touche d'action du clavier : « Suivant » tant qu'il reste un champ, « OK » sur le dernier, « Rechercher » sur
     * une recherche, retour à la ligne dans un champ multiligne. Calculé ici (et pas champ par champ dans les vues)
     * pour couvrir aussi les formulaires générés (CRUD) et rester juste après chaque rendu Livewire.
     */
    function syncEnterKeyHints() {
        Array.prototype.forEach.call(document.querySelectorAll('form'), function (form) {
            var fields = formFields(form);
            fields.forEach(function (el, i) {
                if (el.hasAttribute('enterkeyhint') && el.getAttribute('data-ekh') !== 'auto') return; // choix explicite d'une vue
                var hint = el.tagName === 'TEXTAREA' ? 'enter' : (el.type === 'search' ? 'search' : (i < fields.length - 1 ? 'next' : 'done'));
                if (el.getAttribute('enterkeyhint') !== hint) el.setAttribute('enterkeyhint', hint);
                el.setAttribute('data-ekh', 'auto');
            });
        });
        Array.prototype.forEach.call(document.querySelectorAll('input[type=search]:not([enterkeyhint])'), function (el) {
            el.setAttribute('enterkeyhint', 'search');
            el.setAttribute('data-ekh', 'auto');
        });
    }

    // « Suivant » : Entrée passe au champ suivant au lieu d'envoyer le formulaire ; sur le dernier champ on laisse
    // l'envoi normal (Livewire) puis on ferme le clavier. Recherche : Entrée ferme simplement le clavier.
    document.addEventListener('keydown', function (event) {
        var el = event.target;
        if (event.key !== 'Enter' || event.isComposing || !el || !el.matches || !el.matches(FIELD_SELECTOR) || el.tagName === 'TEXTAREA') return;

        if (el.type === 'search') { event.preventDefault(); el.blur(); return; }

        var form = el.form;
        if (!form) return;
        var fields = formFields(form);
        var index = fields.indexOf(el);

        if (index !== -1 && index < fields.length - 1) {
            event.preventDefault();
            fields[index + 1].focus();
        } else {
            setTimeout(function () { if (document.activeElement === el) el.blur(); }, 50);
        }
    });

    // Champ actif toujours visible : on le centre dans la zone laissée libre par le clavier (visualViewport) et par
    // la barre d'enregistrement. Deux passes : le clavier met un instant à s'ouvrir et à redimensionner la page.
    function revealField(el) {
        var run = function () {
            if (document.activeElement !== el || !el.scrollIntoView) return;
            var viewport = window.visualViewport;
            var top = viewport ? viewport.offsetTop : 0;
            var height = viewport ? viewport.height : window.innerHeight;
            var submit = document.querySelector('.m-submit');
            var reserved = submit ? submit.getBoundingClientRect().height : 0;
            var rect = el.getBoundingClientRect();
            if (rect.top < top + 64 || rect.bottom > top + height - reserved - 12) {
                el.scrollIntoView({ block: 'center', behavior: 'smooth' });
            }
        };
        setTimeout(run, 150);
        setTimeout(run, 400);
    }

    document.addEventListener('focusin', function (event) {
        if (event.target && event.target.matches && event.target.matches(FIELD_SELECTOR) && event.target.closest('.m-main')) revealField(event.target);
    });

    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', function () {
            var active = document.activeElement;
            if (active && active.matches && active.matches(FIELD_SELECTOR) && active.closest('.m-main')) revealField(active);
        });
    }

    // Livewire remplace des morceaux de page : on recalcule les touches d'action après chaque changement de structure.
    var hintTimer = null;
    if (window.MutationObserver) {
        new MutationObserver(function () {
            clearTimeout(hintTimer);
            hintTimer = setTimeout(syncEnterKeyHints, 80);
        }).observe(document.documentElement, { childList: true, subtree: true });
    }

    /* ---------------------------------------------------------------- init */

    document.addEventListener('livewire:navigated', function () {
        // Livewire remplace <html> à chaque navigation : on rétablit l'état « déjà lancé ».
        try { if (sessionStorage.getItem('m-launched')) document.documentElement.classList.add('m-booted'); } catch (e) { /* stockage indisponible */ }
        closeSheets();
        updateTabs();
        syncPrefs();
        syncDeviceStatus();
        syncPushNotifications();
        syncEnterKeyHints();
        showFlash();
        var main = document.querySelector('.m-main');
        if (main) {
            main.classList.remove('m-page-enter');
            void main.offsetWidth;
            main.classList.add('m-page-enter');
        }
    });

    // Session ou jeton expiré (419) : Livewire affichait une boîte native en anglais (« This page has expired »).
    // Sur la connexion il n'y a rien à perdre : on recharge tout de suite ; ailleurs on demande, en français.
    document.addEventListener('livewire:init', function () {
        if (!window.Livewire || typeof window.Livewire.hook !== 'function') return;
        window.Livewire.hook('request', function (request) {
            request.fail(function (failure) {
                if (failure.status !== 419) return;
                failure.preventDefault();
                if (/\/login\/?$/.test(location.pathname) || window.confirm('Votre session a expiré. Recharger la page ?')) {
                    location.reload();
                }
            });
        });
    });

    function showFlash() {
        var flash = document.querySelector('[data-m-flash]');
        if (flash) {
            toast(flash.getAttribute('data-m-flash'));
            haptic('success');
            flash.remove();
        }
    }

    function init() {
        showFlash();
        updateTabs();
        syncOnline();
        syncPrefs();
        syncDeviceStatus();
        syncPushNotifications();
        syncEnterKeyHints();
        bootSplash();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();

    window.MobileUI = { toast: toast, haptic: haptic, sound: sound, openSheet: openSheet, closeSheets: closeSheets, refresh: refreshPage };
})();
