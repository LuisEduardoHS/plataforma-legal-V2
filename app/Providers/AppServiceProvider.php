<?php

namespace App\Providers;

use App\Models\ProfessionalProfile;
use App\Models\UserProfile;
use App\Policies\ProfilePolicy;
use Illuminate\Support\Facades\Gate;
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
    }
}
