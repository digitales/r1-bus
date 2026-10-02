<?php

use App\Services\BusStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;

it('sends guests from admin to the login page', function () {
    $this->get('/admin')->assertRedirect('/login');
});

it('redirects the home page to admin', function () {
    $this->get('/')->assertRedirect('/admin');
});

it('shows the login form', function () {
    $this->get('/login')->assertOk()->assertSee('Sign in');
});

it('logs in with the right password', function () {
    $user = makeAdmin();

    $this->post('/login', ['email' => 'ross@example.com', 'password' => 'correct-horse-battery'])
        ->assertRedirect('/admin');

    $this->assertAuthenticatedAs($user);
    $this->get('/admin')->assertOk();
});

it('fails the login cleanly when no admin has been created', function () {
    $this->from('/login')
        ->post('/login', ['email' => 'ross@example.com', 'password' => 'correct-horse-battery'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('signs out a browser once the admin email is replaced', function () {
    $this->actingAs(makeAdmin());
    $this->get('/admin')->assertOk();

    app(BusStore::class)->setAdmin('someone-else@example.com', 'hash');
    Auth::forgetGuards();

    $this->get('/admin')->assertRedirect('/login');
});

it('rejects a wrong password', function () {
    makeAdmin();

    $this->from('/login')
        ->post('/login', ['email' => 'ross@example.com', 'password' => 'wrong'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('throttles repeated login attempts', function () {
    foreach (range(1, 5) as $attempt) {
        $this->post('/login', ['email' => 'a@example.com', 'password' => 'wrong']);
    }

    $this->post('/login', ['email' => 'a@example.com', 'password' => 'wrong'])->assertStatus(429);
});

it('shows admin to a signed in user and logs out', function () {
    $this->actingAs(makeAdmin());

    $this->get('/admin')->assertOk();
    $this->post('/logout')->assertRedirect('/login');
    $this->assertGuest();
});

it('has no registration route', function () {
    $this->get('/register')->assertNotFound();
});

it('creates an admin user from the command line', function () {
    $this->artisan('bus:make-admin', ['email' => 'ross@example.com'])
        ->expectsQuestion('Password (min 12 characters)', 'correct-horse-battery')
        ->assertSuccessful();

    expect(Auth::attempt(['email' => 'ross@example.com', 'password' => 'correct-horse-battery']))->toBeTrue()
        ->and(app(BusStore::class)->admin()['password'])->not->toContain('correct-horse-battery');
});

it('updates the password when the admin already exists', function () {
    makeAdmin('ross@example.com', 'old-password-old');

    $this->artisan('bus:make-admin', ['email' => 'ross@example.com'])
        ->expectsQuestion('Password (min 12 characters)', 'new-password-new')
        ->assertSuccessful();

    expect(Auth::attempt(['email' => 'ross@example.com', 'password' => 'new-password-new']))->toBeTrue()
        ->and(Auth::attempt(['email' => 'ross@example.com', 'password' => 'old-password-old']))->toBeFalse();
});

it('replaces the admin when given a different email', function () {
    makeAdmin('old@example.com', 'old-password-old');

    $this->artisan('bus:make-admin', ['email' => 'new@example.com', '--password' => 'new-password-new'])
        ->assertSuccessful();

    expect(app(BusStore::class)->admin()['email'])->toBe('new@example.com')
        ->and(Auth::attempt(['email' => 'old@example.com', 'password' => 'old-password-old']))->toBeFalse()
        ->and(Auth::attempt(['email' => 'new@example.com', 'password' => 'new-password-new']))->toBeTrue();
});

it('refuses a short password or a bad email', function (string $email, string $password) {
    $this->artisan('bus:make-admin', ['email' => $email])
        ->expectsQuestion('Password (min 12 characters)', $password)
        ->assertFailed();

    expect(app(BusStore::class)->admin())->toBeNull();
})->with([
    'short password' => ['ross@example.com', 'short'],
    'bad email' => ['not-an-email', 'correct-horse-battery'],
]);

it('does not let device polling use up login attempts', function () {
    makeAdmin();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00', 'Europe/London'));

    foreach (range(1, 6) as $poll) {
        $this->getJson(deviceUrl('/arrivals'))->assertOk();
    }

    $this->post('/login', ['email' => 'ross@example.com', 'password' => 'correct-horse-battery'])
        ->assertRedirect('/admin');
});

it('creates an admin without a prompt when given a password option', function () {
    $this->artisan('bus:make-admin', ['email' => 'ross@example.com', '--password' => 'correct-horse-battery'])
        ->assertSuccessful();

    expect(Auth::attempt(['email' => 'ross@example.com', 'password' => 'correct-horse-battery']))->toBeTrue();
});
