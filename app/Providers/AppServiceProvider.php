<?php

namespace App\Providers;

use App\Models\ProfessionalProfile;
use App\Models\UserProfile;
use App\Policies\ProfilePolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(UserProfile::class, ProfilePolicy::class);
        Gate::policy(ProfessionalProfile::class, ProfilePolicy::class);

        RateLimiter::for('auth.login', fn (Request $request) => Limit::perMinute(30)->by(hash('sha256', $request->ip())));
        RateLimiter::for('auth.register', fn (Request $request) => Limit::perMinute(10)->by(hash('sha256', $request->ip())));
    }
}
