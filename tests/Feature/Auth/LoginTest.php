<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_token_and_user_role(): void
    {
        $admin = User::factory()->admin()->create([
            'username' => 'admin',
            'password_hash' => bcrypt('password123'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'username' => 'admin',
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Login successful')
            ->assertJsonPath('user.id', $admin->id)
            ->assertJsonPath('user.username', 'admin')
            ->assertJsonPath('user.role', 'ADMIN')
            ->assertJsonStructure([
                'message',
                'user' => ['id', 'username', 'email', 'role', 'created_at', 'updated_at'],
                'token',
                'expires_in',
            ]);

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $admin->id,
            'tokenable_type' => User::class,
        ]);
    }

    public function test_login_never_returns_the_password_hash(): void
    {
        User::factory()->admin()->create([
            'username' => 'admin',
            'password_hash' => bcrypt('password123'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'username' => 'admin',
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonMissingPath('user.password_hash')
            ->assertJsonMissingPath('user.password');
    }

    public function test_login_rejects_wrong_password(): void
    {
        User::factory()->admin()->create([
            'username' => 'admin',
            'password_hash' => bcrypt('password123'),
        ]);

        $this->postJson('/api/auth/login', [
            'username' => 'admin',
            'password' => 'wrong-password',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The provided credentials are incorrect.');
    }

    public function test_unknown_username_returns_the_same_message_as_wrong_password(): void
    {
        User::factory()->admin()->create([
            'username' => 'admin',
            'password_hash' => bcrypt('password123'),
        ]);

        $wrongPassword = $this->postJson('/api/auth/login', [
            'username' => 'admin',
            'password' => 'wrong-password',
        ]);

        $unknownUser = $this->postJson('/api/auth/login', [
            'username' => 'tidak-ada',
            'password' => 'password123',
        ]);

        $unknownUser->assertStatus(422)
            ->assertJsonPath('errors.username.0', $wrongPassword->json('errors.username.0'));
    }

    public function test_login_requires_username_and_password(): void
    {
        $this->postJson('/api/auth/login', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['username', 'password']);
    }

    public function test_me_returns_the_authenticated_user(): void
    {
        $driver = User::factory()->driver()->create();

        $this->actingAs($driver, 'sanctum')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.id', $driver->id)
            ->assertJsonPath('user.username', $driver->username)
            ->assertJsonPath('user.role', 'DRIVER');
    }

    public function test_me_returns_401_json_without_a_token(): void
    {
        $this->getJson('/api/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated')
            ->assertJsonPath('error', 'Missing or invalid API token. Please login first.');
    }

    /**
     * A 401 has to arrive whatever the client asked for.
     *
     * A curl with no Accept header, or Swagger's "Try it out" button, sends none.
     * That used to end in a 500: Laravel redirects guests to route 'login', which
     * this app does not have, and it blew up building the exception before the 401
     * could be rendered. The status code has to describe the missing token, not the
     * shape of the request that noticed it.
     */
    public function test_me_returns_401_even_when_the_client_did_not_ask_for_json(): void
    {
        foreach ([['Accept' => 'text/html'], ['Accept' => '*/*'], []] as $headers) {
            $this->get('/api/auth/me', $headers)
                ->assertStatus(401)
                ->assertHeader('Content-Type', 'application/json')
                ->assertJsonPath('message', 'Unauthenticated');
        }
    }

    /**
     * The same has to hold for an invalid token, which is the case a client hits
     * when a session expires: it still sends a header, just not one that resolves.
     */
    public function test_an_invalid_token_is_a_401_and_not_a_500(): void
    {
        $this->withHeader('Authorization', 'Bearer not-a-real-token')
            ->get('/api/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated');
    }

    public function test_logout_revokes_the_token_used_for_that_request(): void
    {
        $admin = User::factory()->admin()->create([
            'username' => 'admin',
            'password_hash' => bcrypt('password123'),
        ]);

        $token = $this->postJson('/api/auth/login', [
            'username' => 'admin',
            'password' => 'password123',
        ])->json('token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logout successful');

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $admin->id,
            'tokenable_type' => User::class,
        ]);

        // The auth guard caches the resolved user for the whole test, so it
        // has to be reset before the revoked token is retried.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')
            ->assertStatus(401);
    }

    public function test_logout_all_revokes_every_token_of_the_user(): void
    {
        $admin = User::factory()->admin()->create([
            'username' => 'admin',
            'password_hash' => bcrypt('password123'),
        ]);

        $tokenA = $this->postJson('/api/auth/login', ['username' => 'admin', 'password' => 'password123'])->json('token');
        $tokenB = $this->postJson('/api/auth/login', ['username' => 'admin', 'password' => 'password123'])->json('token');

        $this->assertDatabaseCount('personal_access_tokens', 2);

        $this->withHeader('Authorization', 'Bearer '.$tokenA)
            ->postJson('/api/auth/logout-all')
            ->assertOk()
            ->assertJsonPath('message', 'Logged out from all devices');

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $admin->id,
            'tokenable_type' => User::class,
        ]);

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$tokenB)
            ->getJson('/api/auth/me')
            ->assertStatus(401);
    }
}
