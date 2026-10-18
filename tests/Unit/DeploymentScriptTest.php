<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class DeploymentScriptTest extends TestCase
{
    public function test_restore_verification_does_not_consume_the_counts_manifest(): void
    {
        $script = file_get_contents(__DIR__.'/../../deploy/scripts/verify-restore.sh');

        $this->assertStringContainsString(
            'SELECT COUNT(*) FROM \`$t\`" </dev/null',
            $script,
        );
    }

    public function test_deployment_synchronizes_authorization_reference_data_before_validation(): void
    {
        $script = file_get_contents(__DIR__.'/../../deploy/scripts/deploy.sh');

        $seed = 'php artisan db:seed --class=RolesAndPermissionsSeeder --force';
        $check = 'php artisan tenancy:deploy-check';
        $optimize = 'php artisan optimize';

        $this->assertStringContainsString($seed, $script);
        $this->assertStringContainsString($check, $script);
        $this->assertLessThan(strpos($script, $check), strpos($script, $seed));
        $this->assertLessThan(strpos($script, $optimize), strpos($script, $check));
    }
}
