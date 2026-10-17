/*
 * Pont Capacitor pour l'application Android UVMASTER.
 *
 * Chargé sur toutes les pages des panneaux /master et /agent, mais totalement inactif
 * dans un navigateur classique (window.Capacitor n'existe que dans l'APK).
 *
 * Il couvre ce que la WebView Android ne fait pas seule :
 *   - téléchargements (PDF / Excel Livewire en blob, liens target=_blank) ;
 *   - ouverture des liens externes dans le navigateur système ;
 *   - bouton retour physique ;
 *   - masquage du splash screen ;
 *   - bouton "Prendre une photo" sur les champs de fichier qui acceptent des images.
 */
(function () {
    'use strict';

    var cap = window.Capacitor;
    if (!cap || typeof cap.isNativePlatform !== 'function' || !cap.isNativePlatform()) return;
    if (window.__uvmasterBridge) return;
    window.__uvmasterBridge = true;

    var plugins = {};
    function plugin(name) {
        if (!plugins[name]) {
            plugins[name] = (cap.Plugins && cap.Plugins[name]) || cap.registerPlugin(name);
        }
        return plugins[name];
    }

    var ownHost = location.hostname.replace(/^www\./, '');
    function isInternalUrl(url) {
        var host = url.hostname.replace(/^www\./, '');
        return host === ownHost || host.slice(-(ownHost.length + 1)) === '.' + ownHost;
    }

    function toUrl(href) {
        try { return new URL(href, location.href); } catch (e) { return null; }
    }

    /* ---------- Splash screen ---------- */

    function hideSplash() {
        try { plugin('SplashScreen').hide(); } catch (e) { /* le masquage automatique prend le relais */ }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', hideSplash);
    } else {
        hideSplash();
    }

    /* ---------- Téléchargements ---------- */

    // Livewire révoque l'URL du blob juste après le clic : on garde le Blob à sa création.
    var blobs = {};
    var originalCreateObjectURL = URL.createObjectURL.bind(URL);
    URL.createObjectURL = function (obj) {
        var url = originalCreateObjectURL(obj);
        if (obj instanceof Blob) blobs[url] = obj;
        return url;
    };

    var EXTENSIONS = {
        'application/pdf': 'pdf',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': 'xlsx',
        'application/vnd.ms-excel': 'xls',
        'text/csv': 'csv',
        'image/jpeg': 'jpg',
        'image/png': 'png',
    };

    function cleanName(name) {
        return String(name).replace(/[\\/:*?"<>|]+/g, '_').trim() || 'fichier';
    }

    function filenameFrom(response, url, type) {
        var disposition = response && response.headers.get('Content-Disposition');
        if (disposition) {
            var star = /filename\*=(?:UTF-8'')?([^;]+)/i.exec(disposition);
            if (star) { try { return cleanName(decodeURIComponent(star[1].replace(/"/g, ''))); } catch (e) { /* suite */ } }
            var plain = /filename="?([^";]+)"?/i.exec(disposition);
            if (plain) return cleanName(plain[1]);
        }
        var last = decodeURIComponent((url.pathname.split('/').pop() || '')).trim();
        if (last) return cleanName(/\.[a-z0-9]{2,4}$/i.test(last) ? last : last + '.' + (EXTENSIONS[type] || 'bin'));
        return 'fichier.' + (EXTENSIONS[type] || 'bin');
    }

    function blobToBase64(blob) {
        return new Promise(function (resolve, reject) {
            var reader = new FileReader();
            reader.onload = function () { resolve(String(reader.result).split(',')[1] || ''); };
            reader.onerror = function () { reject(reader.error); };
            reader.readAsDataURL(blob);
        });
    }

    // Écrit le fichier dans le cache de l'appli puis ouvre la feuille de partage Android
    // (visionneuse PDF, Excel, Drive, WhatsApp...).
    function saveAndOpen(blob, filename) {
        return blobToBase64(blob).then(function (data) {
            return plugin('Filesystem').writeFile({ path: 'downloads/' + filename, data: data, directory: 'CACHE', recursive: true });
        }).then(function (file) {
            return plugin('Share').share({ title: filename, url: file.uri, dialogTitle: filename });
        });
    }

    function fail(error) {
        var message = (error && error.message) || String(error || '');
        // Fermer la feuille de partage sans choisir d'application n'est pas une erreur.
        if (/cancel/i.test(message)) return;
        window.alert('Impossible de télécharger le fichier. ' + message);
    }

    function downloadBlob(blob, filename) {
        saveAndOpen(blob, cleanName(filename)).catch(fail);
    }

    function downloadUrl(url, suggestedName) {
        fetch(url.href, { credentials: 'include' }).then(function (response) {
            if (!response.ok) {
                // Le serveur peut expliquer l'échec (JSON {message}) : on l'affiche plutôt qu'un simple code.
                return response.text().then(function (body) {
                    var detail = '';
                    try { detail = (JSON.parse(body) || {}).message || ''; } catch (e) { /* page d'erreur HTML */ }
                    throw new Error(detail || 'Erreur serveur (' + response.status + ').');
                });
            }
            var type = (response.headers.get('Content-Type') || '').split(';')[0].trim().toLowerCase();
            // Une vraie page (et non un fichier) : on la charge dans l'appli.
            if (type === 'text/html') { location.assign(url.href); return null; }
            return response.blob().then(function (blob) {
                return saveAndOpen(blob, suggestedName || filenameFrom(response, url, type));
            });
        }).catch(fail);
    }

    function openExternal(url) {
        try { plugin('Browser').open({ url: url.href }); } catch (e) { window.open(url.href, '_system'); }
    }

    // Retourne true si le lien a été pris en charge ici.
    function handleAnchor(anchor) {
        var href = anchor.getAttribute('href');
        if (!href || href.charAt(0) === '#' || /^(javascript|mailto|tel|sms):/i.test(href)) return false;

        if (/^blob:/i.test(href)) {
            var blob = blobs[anchor.href];
            if (!blob) return false;
            downloadBlob(blob, anchor.getAttribute('download') || 'fichier');
            return true;
        }

        var url = toUrl(href);
        if (!url || !/^https?:$/.test(url.protocol)) return false;

        if (!isInternalUrl(url)) {
            openExternal(url);
            return true;
        }

        if (anchor.hasAttribute('download')) {
            downloadUrl(url, anchor.getAttribute('download') ? cleanName(anchor.getAttribute('download')) : null);
            return true;
        }

        if (anchor.target === '_blank') {
            downloadUrl(url, null);
            return true;
        }

        return false;
    }

    // Clics programmatiques (ex. lien invisible de Livewire pour streamDownload).
    var originalClick = HTMLAnchorElement.prototype.click;
    HTMLAnchorElement.prototype.click = function () {
        if (handleAnchor(this)) return;
        return originalClick.apply(this, arguments);
    };

    // Clics de l'utilisateur.
    document.addEventListener('click', function (event) {
        if (event.defaultPrevented || event.button !== 0) return;
        var anchor = event.target && event.target.closest ? event.target.closest('a[href]') : null;
        if (anchor && handleAnchor(anchor)) {
            event.preventDefault();
            event.stopPropagation();
        }
    }, true);

    var originalOpen = window.open;
    window.open = function (href, target, features) {
        var url = href ? toUrl(href) : null;
        if (url && /^https?:$/.test(url.protocol)) {
            if (isInternalUrl(url)) downloadUrl(url, null); else openExternal(url);
            return null;
        }
        return originalOpen.call(window, href, target, features);
    };

    /* ---------- Bouton retour Android ---------- */

    // Onglets de l'interface mobile : retour = accueil, puis confirmation de sortie.
    var TAB_ROOTS = ['/m/ventes', '/m/agents', '/m/caisse', '/m/plus'];

    function currentPath() {
        return location.pathname.replace(/\/+$/, '') || '/';
    }

    function atRoot() {
        var path = currentPath();
        return path === '/' || path === '/m' || path === '/m/login' || path === '/master' || path === '/master/login' || path === '/agent' || path === '/agent/login';
    }

    function goHome() {
        if (window.Livewire && typeof window.Livewire.navigate === 'function') window.Livewire.navigate('/m');
        else location.assign('/m');
    }

    try {
        plugin('App').addListener('backButton', function (event) {
            // 1. Feuille du bas ouverte dans l'interface mobile : on la ferme.
            if (typeof window.mobileBackHandler === 'function' && window.mobileBackHandler()) return;
            // 2. Fenêtre modale Filament ouverte : on la ferme au lieu de quitter la page.
            if (document.querySelector('.fi-modal.fi-modal-open')) {
                document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', code: 'Escape', keyCode: 27, bubbles: true }));
                return;
            }
            // 3. Racine d'un onglet : retour à l'accueil.
            if (TAB_ROOTS.indexOf(currentPath()) !== -1) { goHome(); return; }
            // 4. Page intermédiaire : retour dans l'historique.
            if (!atRoot() && (event.canGoBack || window.history.length > 1)) {
                window.history.back();
                return;
            }
            // 5. Accueil : confirmation avant de quitter.
            if (window.confirm("Voulez-vous quitter l'application ?")) {
                plugin('App').exitApp();
            }
        });
    } catch (e) { /* sans le plugin App, Capacitor garde son comportement par défaut */ }

    /* ---------- Retour vers l'interface mobile depuis les écrans Filament ---------- */

    function addAppLink() {
        if (currentPath().indexOf('/master') !== 0 && currentPath().indexOf('/agent') !== 0) return;
        if (document.getElementById('uvmaster-app-link')) return;
        if (currentPath() === '/master/login' || currentPath() === '/agent/login') return;
        var link = document.createElement('a');
        link.id = 'uvmaster-app-link';
        link.href = '/m';
        link.textContent = "← Application";
        link.style.cssText = 'position:fixed;left:14px;bottom:calc(env(safe-area-inset-bottom,0px) + 14px);z-index:60;padding:10px 16px;border-radius:999px;background:#0b6b3a;color:#fff;font:600 14px system-ui,sans-serif;text-decoration:none;box-shadow:0 4px 14px rgba(0,0,0,.25)';
        document.body.appendChild(link);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', addAppLink); else addAppLink();

    /* ---------- Photo d'un justificatif (champs fichier acceptant des images) ---------- */

    function takePhotoInto(input) {
        plugin('Camera').getPhoto({ quality: 80, resultType: 'uri', source: 'CAMERA', saveToGallery: false, correctOrientation: true })
            .then(function (photo) { return fetch(photo.webPath); })
            .then(function (response) { return response.blob(); })
            .then(function (blob) {
                var type = blob.type || 'image/jpeg';
                var file = new File([blob], 'photo-' + Date.now() + '.' + (EXTENSIONS[type] || 'jpg'), { type: type });
                var transfer = new DataTransfer();
                transfer.items.add(file);
                input.files = transfer.files;
                input.dispatchEvent(new Event('change', { bubbles: true }));
            })
            .catch(function (error) {
                var message = (error && error.message) || '';
                if (/cancel/i.test(message)) return;
                // Le plugin renvoie des messages en anglais : on les remplace par une consigne utile.
                if (/denied|permission/i.test(message)) {
                    window.alert("Photo impossible : l'accès à l'appareil photo est refusé. Autorisez-le dans les réglages du téléphone (Applications > UVMASTER > Autorisations > Appareil photo), puis réessayez.");
                    return;
                }
                window.alert("Photo impossible. Réessayez, ou choisissez une image dans la galerie.");
            });
    }

    function makePhotoButton(input) {
        var button = document.createElement('button');
        button.type = 'button';
        button.setAttribute('data-uvmaster-camera', '');
        button.textContent = '📷 Prendre une photo';
        button.style.cssText = 'margin:8px 0;padding:10px 14px;font-size:14px;font-weight:600;border:1px solid rgba(128,128,128,.5);border-radius:10px;background:transparent;color:inherit;';
        button.addEventListener('click', function () { takePhotoInto(input); });
        return button;
    }

    // Champs FilePond (panneau Filament).
    function addCameraButton(root) {
        var input = root.querySelector('input[type="file"]');
        var accept = input && input.getAttribute('accept');
        if (!accept || accept.indexOf('image/') === -1 || input.disabled) return;
        if (root.previousElementSibling && root.previousElementSibling.hasAttribute('data-uvmaster-camera')) return;
        root.parentNode.insertBefore(makePhotoButton(input), root);
    }

    // Champs de fichier de l'interface mobile : <input type="file" data-camera>.
    function addCameraButtonToPlainInput(input) {
        var anchor = input.closest('label') || input;
        if (anchor.previousElementSibling && anchor.previousElementSibling.hasAttribute('data-uvmaster-camera')) return;
        anchor.parentNode.insertBefore(makePhotoButton(input), anchor);
    }

    var scheduled = false;
    function scanFileInputs() {
        scheduled = false;
        var roots = document.querySelectorAll('.filepond--root');
        for (var i = 0; i < roots.length; i++) addCameraButton(roots[i]);
        var plain = document.querySelectorAll('input[type="file"][data-camera]');
        for (var j = 0; j < plain.length; j++) addCameraButtonToPlainInput(plain[j]);
    }
    new MutationObserver(function () {
        if (!scheduled) { scheduled = true; window.requestAnimationFrame(scanFileInputs); }
    }).observe(document.documentElement, { childList: true, subtree: true });
    scanFileInputs();

    // Filament ouvre son menu latéral au premier chargement, même sur téléphone : il recouvre la page (écrans
    // « version complète »). On le referme sur petit écran, au démarrage et après chaque navigation.
    function closeSidebarOnPhones() {
        if (window.innerWidth >= 1024) return;
        var store = window.Alpine && typeof window.Alpine.store === 'function' ? window.Alpine.store('sidebar') : null;
        if (store && store.isOpen) store.close();
    }
    document.addEventListener('alpine:initialized', closeSidebarOnPhones);
    document.addEventListener('livewire:navigated', closeSidebarOnPhones);
    window.setTimeout(closeSidebarOnPhones, 400);
})();
