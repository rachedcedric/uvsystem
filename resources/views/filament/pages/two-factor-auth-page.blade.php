<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Status Card --}}
        <x-filament::section>
            <x-slot name="heading">
                🔐 Statut de l'Authentification à Deux Facteurs
            </x-slot>
            
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                    @if($is2FAEnabled)
                        <div class="w-12 h-12 rounded-full bg-success-100 dark:bg-success-900/20 flex items-center justify-center">
                            @svg("heroicon-o-shield-check", "w-6 h-6 text-success-600")
                        </div>
                        <div>
                            <p class="font-medium text-success-600">2FA Activé</p>
                            <p class="text-sm text-gray-500">Votre compte est protégé</p>
                        </div>
                    @else
                        <div class="w-12 h-12 rounded-full bg-danger-100 dark:bg-danger-900/20 flex items-center justify-center">
                            @svg("heroicon-o-shield-exclamation", "w-6 h-6 text-danger-600")
                        </div>
                        <div>
                            <p class="font-medium text-danger-600">2FA Non Activé</p>
                            <p class="text-sm text-gray-500">Votre compte n'est pas protégé par 2FA</p>
                        </div>
                    @endif
                </div>
                
                @if(!$is2FAEnabled && !$showSetup)
                    <x-filament::button wire:click="startSetup" color="success">
                        @svg("heroicon-o-plus", "w-4 h-4 mr-2")
                        Activer 2FA
                    </x-filament::button>
                @endif
            </div>
        </x-filament::section>

        {{-- Setup Form --}}
        @if($showSetup)
            <x-filament::section>
                <x-slot name="heading">
                    📱 Configuration du 2FA
                </x-slot>
                
                <div class="space-y-6">
                    <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-6">
                        <h4 class="font-medium mb-4">Étape 1: Scannez le QR Code</h4>
                        <p class="text-sm text-gray-500 mb-4">
                            Utilisez Google Authenticator, Authy ou une autre application 2FA pour scanner ce code:
                        </p>
                        <div class="flex justify-center bg-white p-4 rounded-lg w-fit mx-auto">
                            {!! $qrCode !!}
                        </div>
                        <p class="text-xs text-gray-400 mt-4 text-center">
                            Clé secrète: <code class="bg-gray-200 dark:bg-gray-700 px-2 py-1 rounded">{{ $secret }}</code>
                        </p>
                    </div>

                    <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-6">
                        <h4 class="font-medium mb-4">Étape 2: Entrez le code de vérification</h4>
                        <div class="max-w-xs">
                            <x-filament::input.wrapper>
                                <x-filament::input
                                    type="text"
                                    wire:model="code"
                                    placeholder="000000"
                                    maxlength="6"
                                    class="text-center text-2xl tracking-widest"
                                />
                            </x-filament::input.wrapper>
                            @error('code')
                                <p class="text-danger-600 text-sm mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="flex gap-4">
                        <x-filament::button wire:click="confirmSetup" color="success">
                            @svg("heroicon-o-check", "w-4 h-4 mr-2")
                            Confirmer et Activer
                        </x-filament::button>
                        <x-filament::button wire:click="cancelSetup" color="gray">
                            Annuler
                        </x-filament::button>
                    </div>
                </div>
            </x-filament::section>
        @endif

        {{-- Recovery Codes --}}
        @if($is2FAEnabled && $recoveryCodes)
            <x-filament::section>
                <x-slot name="heading">
                    🔑 Codes de Récupération
                </x-slot>
                
                <div class="space-y-4">
                    <div class="bg-warning-50 dark:bg-warning-900/20 border border-warning-200 dark:border-warning-800 rounded-lg p-4">
                        <div class="flex items-start gap-3">
                            @svg("heroicon-o-exclamation-triangle", "w-5 h-5 text-warning-600 flex-shrink-0 mt-0.5")
                            <div>
                                <p class="text-sm text-warning-800 dark:text-warning-200 font-medium">
                                    Conservez ces codes en lieu sûr!
                                </p>
                                <p class="text-xs text-warning-600 dark:text-warning-400 mt-1">
                                    Chaque code ne peut être utilisé qu'une seule fois pour récupérer l'accès à votre compte.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        @foreach($recoveryCodes as $recoveryCode)
                            <code class="bg-gray-100 dark:bg-gray-800 px-3 py-2 rounded text-sm font-mono text-center">
                                {{ $recoveryCode }}
                            </code>
                        @endforeach
                    </div>

                    <div class="flex gap-4 mt-4">
                        <x-filament::button wire:click="regenerateRecoveryCodes" color="gray">
                            @svg("heroicon-o-arrow-path", "w-4 h-4 mr-2")
                            Régénérer les codes
                        </x-filament::button>
                    </div>
                </div>
            </x-filament::section>

            {{-- Disable 2FA --}}
            <x-filament::section>
                <x-slot name="heading">
                    ⚠️ Zone de Danger
                </x-slot>
                
                <div class="flex items-center justify-between">
                    <div>
                        <p class="font-medium text-danger-600">Désactiver l'authentification à deux facteurs</p>
                        <p class="text-sm text-gray-500">Votre compte sera moins sécurisé</p>
                    </div>
                    <x-filament::button 
                        wire:click="disable2FA" 
                        color="danger"
                        wire:confirm="Êtes-vous sûr de vouloir désactiver le 2FA?"
                    >
                        Désactiver 2FA
                    </x-filament::button>
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
