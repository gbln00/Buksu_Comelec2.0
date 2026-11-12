<?php

namespace App\Providers;

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureStudent;
use App\Models\ChangeRequest;
use App\Models\Election;
use App\Policies\ChangeRequestPolicy;
use App\Policies\ElectionPolicy;
use App\Services\Auth\GoogleIdentityProvider;
use App\Services\Auth\SocialiteGoogleIdentityProvider;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(GoogleIdentityProvider::class, SocialiteGoogleIdentityProvider::class);

        // Laravel 12 merges the FRAMEWORK's default auth guards/providers/password
        // brokers underneath config/auth.php, which silently re-adds the stock
        // `web` guard, the `users` provider (bound to the deleted stock user model) and the
        // password-reset broker. Omitting them from config/auth.php is not enough,
        // so remove them explicitly: student + admin are the only identity domains.
        config([
            'auth.guards' => Arr::only((array) config('auth.guards'), ['student', 'admin']),
            'auth.providers' => Arr::only((array) config('auth.providers'), ['students', 'admins']),
            'auth.passwords' => [],
        ]);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(ChangeRequest::class, ChangeRequestPolicy::class);
        Gate::policy(Election::class, ElectionPolicy::class);

        // Livewire 4: middleware applied to Livewire update requests is limited to
        // this allow-list (Mechanisms\PersistentMiddleware). Route middleware such as
        // EnsureAdmin is NOT re-run on /livewire/update unless registered here.
        // (`Authenticate` — i.e. auth:admin — is already in Livewire's default list.)
        Livewire::addPersistentMiddleware([
            EnsureAdmin::class,
            EnsureStudent::class,
        ]);
    }
}
