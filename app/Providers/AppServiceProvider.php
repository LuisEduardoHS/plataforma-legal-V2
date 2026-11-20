<?php

namespace App\Providers;

use App\Models\LexaConversation;
use App\Models\ProfessionalProfile;
use App\Models\UserProfile;
use App\Policies\LexaConversationPolicy;
use App\Policies\ProfilePolicy;
use App\Services\Lexa\Contracts\AiProvider;
use App\Services\Lexa\Providers\GeminiAiProvider;
use App\Services\Lexa\Providers\NullAiProvider;
use App\Support\CommunityCardIcons;
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
        $this->app->scoped(CommunityCardIcons::class);
        $this->app->bind(AiProvider::class, function ($app) {
            if (config('lexa.enabled_generation', false) && config('lexa.provider') === 'gemini') {
                return $app->make(GeminiAiProvider::class);
            }

            return $app->make(NullAiProvider::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(UserProfile::class, ProfilePolicy::class);
        Gate::policy(ProfessionalProfile::class, ProfilePolicy::class);
        Gate::policy(LexaConversation::class, LexaConversationPolicy::class);

        RateLimiter::for('operations.read', fn (Request $request) => Limit::perMinute(90)->by((string) $request->user()?->id));
        RateLimiter::for('operations.write', fn (Request $request) => Limit::perMinute(20)->by((string) $request->user()?->id));

        RateLimiter::for('auth.recovery.read', fn (Request $request) => Limit::perMinute(60)->by(hash('sha256', $request->ip())));
        RateLimiter::for('auth.recovery', fn (Request $request) => [
            Limit::perMinute(15)->by('ip:'.hash('sha256', $request->ip())),
            Limit::perMinute(5)->by('identity:'.hash('sha256', (is_string($request->input('email')) ? mb_strtolower(trim($request->input('email'))) : '').'|'.$request->ip())),
        ]);

        RateLimiter::for('verification.read', fn (Request $request) => Limit::perMinute(90)->by((string) $request->user()?->id));
        RateLimiter::for('verification.write', fn (Request $request) => Limit::perMinute(20)->by((string) $request->user()?->id));
        RateLimiter::for('verification.upload', fn (Request $request) => Limit::perMinute(5)->by((string) $request->user()?->id));
        RateLimiter::for('verification.files', fn (Request $request) => Limit::perMinute(20)->by((string) $request->user()?->id));

        RateLimiter::for('auth.login', fn (Request $request) => Limit::perMinute(30)->by(hash('sha256', $request->ip())));
        RateLimiter::for('auth.register', fn (Request $request) => Limit::perMinute(10)->by(hash('sha256', $request->ip())));
        RateLimiter::for('profiles.read', fn (Request $request) => Limit::perMinute(90)->by(hash('sha256', $request->ip())));
        RateLimiter::for('profiles.write', fn (Request $request) => Limit::perMinute(20)->by((string) $request->user()?->id));
        RateLimiter::for('profiles.photo', fn (Request $request) => Limit::perMinute(5)->by((string) $request->user()?->id));
        RateLimiter::for('community.read', fn (Request $request) => Limit::perMinute(120)->by(hash('sha256', $request->ip())));
        RateLimiter::for('community.write', fn (Request $request) => Limit::perMinute(60)->by((string) $request->user()?->id));
        RateLimiter::for('community.upload', fn (Request $request) => Limit::perMinute(10)->by((string) $request->user()?->id));
        RateLimiter::for('content.read', fn (Request $request) => Limit::perMinute(120)->by(hash('sha256', $request->ip())));
        RateLimiter::for('content.write', fn (Request $request) => Limit::perMinute(60)->by((string) $request->user()?->id));
        RateLimiter::for('content.upload', fn (Request $request) => Limit::perMinute(10)->by((string) $request->user()?->id));
        RateLimiter::for('messaging.read', fn (Request $request) => Limit::perMinute(90)->by((string) $request->user()?->id));
        RateLimiter::for('messaging.write', fn (Request $request) => Limit::perMinute(60)->by((string) $request->user()?->id));
        RateLimiter::for('messaging.start', fn (Request $request) => Limit::perMinute(15)->by((string) $request->user()?->id));
        RateLimiter::for('messaging.upload', fn (Request $request) => Limit::perMinute(10)->by((string) $request->user()?->id));
        RateLimiter::for('messaging.files', fn (Request $request) => Limit::perMinute(30)->by((string) $request->user()?->id));
        RateLimiter::for('lexa.read', fn (Request $request) => Limit::perMinute(90)->by((string) $request->user()?->id));
        RateLimiter::for('lexa.write', fn (Request $request) => Limit::perMinute(30)->by((string) $request->user()?->id));
        RateLimiter::for('lexa.generate', fn (Request $request) => Limit::perMinute(10)->by((string) $request->user()?->id));
    }
}
