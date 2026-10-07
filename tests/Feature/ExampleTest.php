<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response
            ->assertStatus(200)
            ->assertSee('Vos flux UV.')
            ->assertSee('MODELEC CI')
            ->assertSee('href="/master/login"', false)
            ->assertSee('href="/m/login"', false)
            ->assertSee('class="brand" href="/"', false);
    }

    public function test_the_client_panel_uses_master_urls_and_redirects_legacy_admin_urls(): void
    {
        $this->assertSame(url('/master/login'), route('filament.admin.auth.login'));

        $this->get('/master/login')->assertOk();
        $this->get('/admin/login')->assertRedirect('/master/login')->assertStatus(301);
        $this->get('/admin/remboursements/create?agent_id=12')
            ->assertRedirect('/master/remboursements/create?agent_id=12')
            ->assertStatus(301);
    }
}
