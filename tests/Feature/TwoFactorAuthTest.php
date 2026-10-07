<?php

use App\Models\User;
use App\Services\TwoFactorAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('TwoFactorAuthService', function () {
    beforeEach(function () {
        $this->service = new TwoFactorAuthService;
        $this->user = User::factory()->create([
            'two_factor_enabled' => false,
            'two_factor_secret' => null,
        ]);
    });

    describe('generateSecretKey()', function () {
        it('generates a valid secret key', function () {
            $secret = $this->service->generateSecretKey();

            expect($secret)->toBeString()
                ->and(strlen($secret))->toBeGreaterThan(10);
        });
    });

    describe('generateRecoveryCodes()', function () {
        it('generates 8 recovery codes by default', function () {
            $codes = $this->service->generateRecoveryCodes();

            expect($codes)->toBeArray()
                ->and(count($codes))->toBe(8);
        });

        it('generates codes with dashes in correct format', function () {
            $codes = $this->service->generateRecoveryCodes();

            foreach ($codes as $code) {
                // Check that code contains dashes and is uppercase
                expect($code)->toContain('-')
                    ->and($code)->toEqual(strtoupper($code));
            }
        });
    });

    describe('enable()', function () {
        it('enables 2FA with valid OTP', function () {
            $secret = $this->service->generateSecretKey();

            // Generate valid OTP for testing
            $google2fa = new \PragmaRX\Google2FA\Google2FA;
            $validOtp = $google2fa->getCurrentOtp($secret);

            $result = $this->service->enable($this->user, $secret, $validOtp);

            expect($result)->toBeTrue()
                ->and($this->user->fresh()->two_factor_enabled)->toBeTrue()
                ->and($this->user->fresh()->two_factor_secret)->not->toBeNull();
        });

        it('fails to enable 2FA with invalid OTP', function () {
            $secret = $this->service->generateSecretKey();
            $invalidOtp = '000000';

            $result = $this->service->enable($this->user, $secret, $invalidOtp);

            expect($result)->toBeFalse()
                ->and($this->user->fresh()->two_factor_enabled)->toBeFalse();
        });
    });

    describe('disable()', function () {
        it('disables 2FA and clears secret', function () {
            // First enable 2FA
            $secret = $this->service->generateSecretKey();
            $google2fa = new \PragmaRX\Google2FA\Google2FA;
            $validOtp = $google2fa->getCurrentOtp($secret);
            $this->service->enable($this->user, $secret, $validOtp);

            // Now disable it
            $this->service->disable($this->user);

            expect($this->user->fresh()->two_factor_enabled)->toBeFalse()
                ->and($this->user->fresh()->two_factor_secret)->toBeNull()
                ->and($this->user->fresh()->two_factor_recovery_codes)->toBeNull();
        });
    });

    describe('isEnabled()', function () {
        it('returns true when 2FA is enabled', function () {
            $this->user->update([
                'two_factor_enabled' => true,
                'two_factor_secret' => 'testsecret',
            ]);

            expect($this->service->isEnabled($this->user->fresh()))->toBeTrue();
        });

        it('returns false when 2FA is not enabled', function () {
            expect($this->service->isEnabled($this->user))->toBeFalse();
        });
    });

    describe('validateOtp()', function () {
        it('validates correct OTP', function () {
            $secret = $this->service->generateSecretKey();
            $google2fa = new \PragmaRX\Google2FA\Google2FA;
            $validOtp = $google2fa->getCurrentOtp($secret);

            // Enable 2FA first
            $this->service->enable($this->user, $secret, $validOtp);

            // Generate new OTP and validate
            $newOtp = $google2fa->getCurrentOtp($secret);
            $result = $this->service->validateOtp($this->user->fresh(), $newOtp);

            expect($result)->toBeTrue();
        });

        it('rejects incorrect OTP', function () {
            $secret = $this->service->generateSecretKey();
            $google2fa = new \PragmaRX\Google2FA\Google2FA;
            $validOtp = $google2fa->getCurrentOtp($secret);
            $this->service->enable($this->user, $secret, $validOtp);

            $result = $this->service->validateOtp($this->user->fresh(), '000000');

            expect($result)->toBeFalse();
        });
    });

    describe('verifyRecoveryCode()', function () {
        it('verifies and removes valid recovery code', function () {
            $secret = $this->service->generateSecretKey();
            $google2fa = new \PragmaRX\Google2FA\Google2FA;
            $validOtp = $google2fa->getCurrentOtp($secret);
            $this->service->enable($this->user, $secret, $validOtp);

            $user = $this->user->fresh();
            $codes = $user->two_factor_recovery_codes;
            $codeToUse = $codes[0];

            $result = $this->service->verifyRecoveryCode($user, $codeToUse);

            expect($result)->toBeTrue()
                ->and(count($user->fresh()->two_factor_recovery_codes))->toBe(7);
        });

        it('rejects invalid recovery code', function () {
            $secret = $this->service->generateSecretKey();
            $google2fa = new \PragmaRX\Google2FA\Google2FA;
            $validOtp = $google2fa->getCurrentOtp($secret);
            $this->service->enable($this->user, $secret, $validOtp);

            $result = $this->service->verifyRecoveryCode($this->user->fresh(), 'INVALID-CODE-HERE');

            expect($result)->toBeFalse();
        });
    });

    describe('regenerateRecoveryCodes()', function () {
        it('generates new recovery codes', function () {
            $secret = $this->service->generateSecretKey();
            $google2fa = new \PragmaRX\Google2FA\Google2FA;
            $validOtp = $google2fa->getCurrentOtp($secret);
            $this->service->enable($this->user, $secret, $validOtp);

            $user = $this->user->fresh();
            $oldCodes = $user->two_factor_recovery_codes;

            $newCodes = $this->service->regenerateRecoveryCodes($user);

            expect($newCodes)->toBeArray()
                ->and(count($newCodes))->toBe(8)
                ->and($newCodes)->not->toBe($oldCodes);
        });
    });
});

describe('2FA Challenge Flow', function () {
    beforeEach(function () {
        $this->user = User::factory()->create([
            'two_factor_enabled' => false,
        ]);
    });

    it('shows 2FA challenge page for users with 2FA enabled', function () {
        $this->user->update([
            'two_factor_enabled' => true,
            'two_factor_secret' => 'testsecret123',
        ]);

        $response = $this->actingAs($this->user)->get('/2fa/challenge');

        $response->assertStatus(200);
    });

    it('redirects to admin when 2FA is not enabled', function () {
        $response = $this->actingAs($this->user)->get('/2fa/challenge');

        $response->assertRedirect('/master');
    });
});
