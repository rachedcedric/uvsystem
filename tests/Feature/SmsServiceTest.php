<?php

use App\Services\SmsService;
use Illuminate\Support\Facades\Log;

describe('SmsService', function () {

    describe('isEnabled()', function () {

        it('returns false when SMS is disabled', function () {
            config(['services.sms.enabled' => false]);
            config(['services.twilio.account_sid' => null]);

            $smsService = new SmsService;

            expect($smsService->isEnabled())->toBeFalse();
        });

        it('returns false when twilio credentials are missing', function () {
            config(['services.sms.enabled' => true]);
            config(['services.twilio.account_sid' => null]);
            config(['services.twilio.auth_token' => null]);

            $smsService = new SmsService;

            expect($smsService->isEnabled())->toBeFalse();
        });

    });

    describe('send()', function () {

        it('returns false when SMS is disabled and logs info', function () {
            config(['services.sms.enabled' => false]);
            config(['services.twilio.account_sid' => null]);

            Log::shouldReceive('info')
                ->once()
                ->with('SMS not sent (disabled)', \Mockery::any());

            $smsService = new SmsService;
            $result = $smsService->send('+2250708091011', 'Test message');

            expect($result)->toBeFalse();
        });

    });

    describe('sendToAgent()', function () {

        it('returns false when agent has no phone', function () {
            config(['services.sms.enabled' => false]);
            config(['services.twilio.account_sid' => null]);

            $agent = new \App\Models\Agent;
            $agent->id = 1;
            $agent->name = 'Test Agent';
            $agent->phone = null;

            Log::shouldReceive('warning')
                ->once()
                ->with('Cannot send SMS to agent without phone', ['agent_id' => 1]);

            $smsService = new SmsService;
            $result = $smsService->sendToAgent($agent, 'Test notification');

            expect($result)->toBeFalse();
        });

        it('returns false when SMS is disabled', function () {
            config(['services.sms.enabled' => false]);
            config(['services.twilio.account_sid' => null]);

            $agent = new \App\Models\Agent;
            $agent->id = 2;
            $agent->name = 'Test Agent';
            $agent->phone = '0708091011';

            Log::shouldReceive('info')
                ->once()
                ->with('SMS not sent (disabled)', \Mockery::any());

            $smsService = new SmsService;
            $result = $smsService->sendToAgent($agent, 'Test notification');

            expect($result)->toBeFalse();
        });

    });

    describe('sendBulk()', function () {

        it('returns 0 success and N failed when SMS is disabled', function () {
            config(['services.sms.enabled' => false]);
            config(['services.twilio.account_sid' => null]);

            $agent1 = new \App\Models\Agent;
            $agent1->id = 1;
            $agent1->phone = '0708091011';

            $agent2 = new \App\Models\Agent;
            $agent2->id = 2;
            $agent2->phone = '0708091012';

            Log::shouldReceive('info')
                ->twice()
                ->with('SMS not sent (disabled)', \Mockery::any());

            $smsService = new SmsService;
            $results = $smsService->sendBulk([$agent1, $agent2], 'Bulk test message');

            expect($results)->toBeArray()
                ->and($results['success'])->toBe(0)
                ->and($results['failed'])->toBe(2);
        });

    });

    describe('sendDebtAlert()', function () {

        it('sends debt alert with formatted message', function () {
            config(['services.sms.enabled' => false]);
            config(['services.twilio.account_sid' => null]);

            $agent = new \App\Models\Agent;
            $agent->id = 3;
            $agent->phone = '0708091011';

            Log::shouldReceive('info')
                ->once()
                ->with('SMS not sent (disabled)', \Mockery::any());

            $smsService = new SmsService;
            $result = $smsService->sendDebtAlert($agent, 500000, 600000);

            expect($result)->toBeFalse(); // Disabled, but method executed
        });

    });

    describe('sendPaymentReminder()', function () {

        it('sends payment reminder without error', function () {
            config(['services.sms.enabled' => false]);
            config(['services.twilio.account_sid' => null]);

            $agent = new \App\Models\Agent;
            $agent->id = 4;
            $agent->name = 'Test Agent';
            $agent->phone = '0708091011';

            Log::shouldReceive('info')
                ->once()
                ->with('SMS not sent (disabled)', \Mockery::any());

            $smsService = new SmsService;
            $result = $smsService->sendPaymentReminder($agent, 250000);

            expect($result)->toBeFalse();
        });

    });

    describe('sendTransactionConfirmation()', function () {

        it('sends transaction confirmation', function () {
            config(['services.sms.enabled' => false]);
            config(['services.twilio.account_sid' => null]);

            $agent = new \App\Models\Agent;
            $agent->id = 5;
            $agent->phone = '0708091011';

            Log::shouldReceive('info')
                ->once()
                ->with('SMS not sent (disabled)', \Mockery::any());

            $smsService = new SmsService;
            $result = $smsService->sendTransactionConfirmation($agent, 100000, 50, 350000);

            expect($result)->toBeFalse();
        });

    });

    describe('sendRemboursementConfirmation()', function () {

        it('sends remboursement confirmation', function () {
            config(['services.sms.enabled' => false]);
            config(['services.twilio.account_sid' => null]);

            $agent = new \App\Models\Agent;
            $agent->id = 6;
            $agent->phone = '0708091011';

            Log::shouldReceive('info')
                ->once()
                ->with('SMS not sent (disabled)', \Mockery::any());

            $smsService = new SmsService;
            $result = $smsService->sendRemboursementConfirmation($agent, 50000, 300000);

            expect($result)->toBeFalse();
        });

    });

});
