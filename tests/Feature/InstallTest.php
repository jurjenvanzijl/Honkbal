<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.setup_token' => 'geheim-token-123']);
    }

    public function test_zonder_of_met_verkeerd_token_niet_bereikbaar(): void
    {
        $this->get('/installatie')->assertNotFound();
        $this->get('/installatie?token=fout')->assertNotFound();

        config(['app.setup_token' => null]);
        $this->get('/installatie?token=')->assertNotFound();
    }

    public function test_eerste_account_aanmaken(): void
    {
        $this->get('/installatie?token=geheim-token-123')->assertOk()->assertSee('Eerste account aanmaken');

        $this->post('/installatie', [
            'token' => 'geheim-token-123',
            'name' => 'Beheerder',
            'email' => 'beheer@example.com',
            'password' => 'een-lang-wachtwoord',
            'password_confirmation' => 'een-lang-wachtwoord',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticated();
        $this->assertSame(1, User::count());
    }

    public function test_geen_tweede_account_via_installatie(): void
    {
        User::factory()->create();

        $this->get('/installatie?token=geheim-token-123')->assertOk()->assertDontSee('Eerste account aanmaken');
        $this->post('/installatie', [
            'token' => 'geheim-token-123',
            'name' => 'Indringer',
            'email' => 'x@example.com',
            'password' => 'een-lang-wachtwoord',
            'password_confirmation' => 'een-lang-wachtwoord',
        ])->assertForbidden();
        $this->assertSame(1, User::count());
    }
}
