<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vérification 2FA - {{ config('branding.name') }}</title>
    @vite('resources/css/app.css')
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center">
    <div class="max-w-md w-full mx-4">
        <div class="bg-white rounded-xl shadow-lg p-8">
            <!-- Header -->
            <div class="text-center mb-8">
                <div class="w-16 h-16 bg-primary-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <svg class="w-8 h-8 text-primary-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                    </svg>
                </div>
                <h1 class="text-2xl font-bold text-gray-900">Authentification à deux facteurs</h1>
                <p class="text-gray-600 mt-2">Entrez le code de votre application d'authentification</p>
            </div>

            <!-- Errors -->
            @if ($errors->any())
                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-6">
                    @foreach ($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </div>
            @endif

            <!-- Warning -->
            @if (session('warning'))
                <div class="bg-yellow-50 border border-yellow-200 text-yellow-700 px-4 py-3 rounded-lg mb-6">
                    {{ session('warning') }}
                </div>
            @endif

            <!-- OTP Form -->
            <form method="POST" action="{{ route('2fa.verify') }}" id="otpForm">
                @csrf
                <div class="mb-6">
                    <label for="code" class="block text-sm font-medium text-gray-700 mb-2">
                        Code à 6 chiffres
                    </label>
                    <input
                        type="text"
                        name="code"
                        id="code"
                        maxlength="6"
                        pattern="[0-9]{6}"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        class="w-full px-4 py-3 text-center text-2xl tracking-widest border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                        placeholder="000000"
                        autofocus
                    >
                </div>
                <label class="flex items-center gap-2 mb-4 text-sm text-gray-700">
                    <input type="checkbox" name="trust_device" value="1" checked>
                    Faire confiance à cet appareil pendant {{ (int) config('security.authentication.2fa_trusted_device_days', 30) }} jours
                </label>
                <button
                    type="submit"
                    class="w-full bg-primary-600 hover:bg-primary-700 text-white font-semibold py-3 px-4 rounded-lg transition duration-200"
                >
                    Vérifier
                </button>
            </form>

            <!-- Divider -->
            <div class="relative my-6">
                <div class="absolute inset-0 flex items-center">
                    <div class="w-full border-t border-gray-300"></div>
                </div>
                <div class="relative flex justify-center text-sm">
                    <span class="px-2 bg-white text-gray-500">ou</span>
                </div>
            </div>

            <!-- Recovery Code Form -->
            <div>
                <button
                    type="button"
                    onclick="document.getElementById('recoveryForm').classList.toggle('hidden')"
                    class="w-full text-gray-600 hover:text-gray-900 text-sm font-medium"
                >
                    Utiliser un code de récupération
                </button>

                <form
                    method="POST"
                    action="{{ route('2fa.verify.recovery') }}"
                    id="recoveryForm"
                    class="mt-4 hidden"
                >
                    @csrf
                    <div class="mb-4">
                        <label for="recovery_code" class="block text-sm font-medium text-gray-700 mb-2">
                            Code de récupération
                        </label>
                        <input
                            type="text"
                            name="recovery_code"
                            id="recovery_code"
                            class="w-full px-4 py-3 text-center font-mono border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                            placeholder="XXXX-XXXX-XXXX"
                        >
                    </div>
                    <button
                        type="submit"
                        class="w-full bg-gray-600 hover:bg-gray-700 text-white font-semibold py-3 px-4 rounded-lg transition duration-200"
                    >
                        Utiliser le code de récupération
                    </button>
                </form>
            </div>

            <!-- Logout -->
            <div class="mt-6 text-center">
                <form method="POST" action="{{ route('filament.admin.auth.logout') }}">
                    @csrf
                    <button type="submit" class="text-gray-500 hover:text-gray-700 text-sm">
                        Se déconnecter
                    </button>
                </form>
            </div>
        </div>

        <!-- Help Text -->
        <p class="text-center text-gray-500 text-sm mt-6">
            Ouvrez votre application d'authentification (Google Authenticator, Authy, etc.)
            et entrez le code affiché pour {{ config('branding.name') }}.
        </p>
    </div>

    <script>
        // Auto-submit when 6 digits are entered
        document.getElementById('code').addEventListener('input', function(e) {
            this.value = this.value.replace(/[^0-9]/g, '');
            if (this.value.length === 6) {
                document.getElementById('otpForm').submit();
            }
        });
    </script>

    <style>
        .bg-primary-100 { background-color: #dbeafe; }
        .bg-primary-600 { background-color: #2563eb; }
        .bg-primary-700 { background-color: #1d4ed8; }
        .text-primary-600 { color: #2563eb; }
        .focus\:ring-primary-500:focus { --tw-ring-color: #3b82f6; }
        .focus\:border-primary-500:focus { border-color: #3b82f6; }
    </style>
</body>
</html>
