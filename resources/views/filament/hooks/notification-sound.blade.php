{{-- Joue un bref bip quand le nombre de notifications non lues de la
cloche du topbar augmente. Le son est synthétisé via Web Audio (pas de
fichier audio à charger), donc fonctionne offline / sans CORS. --}}
<script>
    (function () {
        function readBadgeCount(button) {
            var badge = button.querySelector('.fi-icon-btn-badge-ctn');
            if (!badge) return 0;
            var n = parseInt(badge.textContent.trim(), 10);
            return Number.isNaN(n) ? 0 : n;
        }

        function playBeep() {
            try {
                var Ctx = window.AudioContext || window.webkitAudioContext;
                var ctx = new Ctx();
                var oscillator = ctx.createOscillator();
                var gain = ctx.createGain();

                oscillator.type = 'sine';
                oscillator.frequency.setValueAtTime(880, ctx.currentTime);
                gain.gain.setValueAtTime(0.15, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.35);

                oscillator.connect(gain);
                gain.connect(ctx.destination);

                oscillator.start();
                oscillator.stop(ctx.currentTime + 0.35);
                oscillator.onended = function () { ctx.close(); };
            } catch (e) {
                // Web Audio indisponible (ex: navigateur sans interaction
                // utilisateur préalable) : on n'affiche rien, ce n'est pas
                // critique.
            }
        }

        function watchBell(button) {
            if (button.dataset.notifSoundBound) return;
            button.dataset.notifSoundBound = '1';
            button.dataset.notifLastCount = String(readBadgeCount(button));

            var observer = new MutationObserver(function () {
                var current = readBadgeCount(button);
                var last = parseInt(button.dataset.notifLastCount, 10) || 0;

                if (current > last) {
                    playBeep();
                }

                button.dataset.notifLastCount = String(current);
            });

            observer.observe(button, { childList: true, subtree: true, characterData: true });
        }

        function init() {
            document.querySelectorAll('.fi-topbar-database-notifications-btn').forEach(watchBell);
        }

        document.addEventListener('DOMContentLoaded', init);
        document.addEventListener('livewire:navigated', init);
        document.addEventListener('livewire:init', init);
    })();
</script>
