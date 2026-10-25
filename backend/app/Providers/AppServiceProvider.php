<?php

namespace App\Providers;

use App\Access\Access;
use App\Access\PermissionCatalog;
use App\Api\SiteToken;
use App\Auth\AdminAudit;
use App\Chat\ChatResponder;
use App\Chat\KnowledgeBaseResponder;
use App\Console\WipeDatabaseCommand;
use App\Events\PostChanged;
use App\Filament\Forms\LtrFields;
use App\Filament\Pages\Auth\AdminLoginCode;
use App\Listeners\NotifyIndexNowOfPostChange;
use App\Listeners\NotifySiteOfPostChange;
use App\Livewire\AdminBell;
use App\Models\User;
use App\Panel\SystemFailures;
use App\Search\SearchSync;
use App\Support\ProductionChecks;
use App\Support\TrackingHash;
use Illuminate\Auth\Events\CurrentDeviceLogout;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Database\Console\WipeCommand;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // migrate:fresh calls db:wipe; ours also drops functions, domains and extensions.
        $this->app->extend(WipeCommand::class, fn ($command, $app) => $app->make(WipeDatabaseCommand::class));

        // The chatbot's answers (18.13): the free knowledge base in V1; a model can take its place later, same API.
        $this->app->bind(ChatResponder::class, KnowledgeBaseResponder::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // db:wipe, migrate:fresh/refresh/reset/rollback never run in production, not even with --force.
        DB::prohibitDestructiveCommands($this->app->isProduction());

        // The sign-in code page is a bare route, not a panel page, so Filament does not register it. Without
        // a name Livewire cannot find it again when the page posts back to /livewire/update (419).
        Livewire::component('admin-login-code', AdminLoginCode::class);
        Livewire::component('admin-bell', AdminBell::class);

        // The dialog's keep-alive and status requests: 10 a minute for each, per admin.
        RateLimiter::for('admin-session', fn (Request $request) => Limit::perMinute(10)
            ->by($request->route()?->getName().'|'.($request->user()?->getAuthIdentifier() ?? TrackingHash::visitor($request->ip()))));

        // The preview page and the upload endpoint of Livewire: 60 a minute per visitor (an unnamed throttle keys on a bare hash of the address).
        RateLimiter::for('per-visitor', fn (Request $request) => Limit::perMinute(60)->by(TrackingHash::visitor($request->ip())));
        config(['livewire.temporary_file_upload.middleware' => 'throttle:per-visitor']);

        // The largest file the media library accepts (an image); the library itself checks each type's own limit.
        config(['livewire.temporary_file_upload.rules' => ['required', 'file', 'max:'.(int) (max(config('media.limits.image_upload'), config('media.limits.video')) / 1024)]]);

        LtrFields::register();

        Event::listen(PostChanged::class, NotifySiteOfPostChange::class);
        Event::listen(PostChanged::class, NotifyIndexNowOfPostChange::class);
        SearchSync::register();

        // The public API: 120 a minute per client address; the website, holding the secret token, gets far more.
        RateLimiter::for('api-public', function (Request $request) {
            return SiteToken::valid($request) ? Limit::perMinute((int) config('raqam.api.site_limit'))->by('site') : Limit::perMinute((int) config('raqam.api.limit'))->by(TrackingHash::visitor($request->ip()));
        });

        // Search (10.13): 60 a minute per visitor. Instant search reaches us through the site's own route, so the
        // visitor is the address the site names, believed only with the site's token; the key is an HMAC of it
        // like every other counter. The site without a visitor address gets its own large budget.
        RateLimiter::for('api-search', function (Request $request) {
            $perVisitor = Limit::perMinute((int) config('raqam.search.per_minute'));
            if (($visitor = SiteToken::visitor($request)) !== null) {
                return $perVisitor->by(TrackingHash::visitor($visitor));
            }

            return SiteToken::valid($request) ? Limit::perMinute((int) config('raqam.api.site_limit'))->by('site') : $perVisitor->by(TrackingHash::visitor($request->ip()));
        });

        // Ads (13.6): the view reports of one visitor (the address the site names with its token, or the request's own).
        // Click links have no limit here (the visitor must always reach the advertiser); their counter limits what counts.
        RateLimiter::for('ads-impressions', fn (Request $request) => Limit::perMinute((int) config('raqam.ads.impression_requests_per_minute'))
            ->by('ads-views|'.TrackingHash::visitor(SiteToken::visitorIp($request))));
        RateLimiter::for('newsletter', fn (Request $request) => Limit::perMinute((int) config('raqam.newsletter.requests_per_minute'))->by('newsletter|'.TrackingHash::visitor(SiteToken::visitorIp($request))));
        RateLimiter::for('brevo-webhook', fn (Request $request) => Limit::perMinute(120)->by('brevo-webhook|'.TrackingHash::visitor($request->ip())));
        RateLimiter::for('chat', fn (Request $request) => Limit::perMinute(30)->by('chat|'.TrackingHash::visitor(SiteToken::visitorIp($request))));
        RateLimiter::for('contact-form', fn (Request $request) => Limit::perMinute((int) config('raqam.contact.requests_per_minute'))->by('contact-form|'.TrackingHash::visitor(SiteToken::visitorIp($request))));
        RateLimiter::for('stats', fn (Request $request) => Limit::perMinute((int) config('raqam.stats.requests_per_minute'))->by('stats|'.TrackingHash::visitor(SiteToken::visitorIp($request))));
        RateLimiter::for('accounts', fn (Request $request) => Limit::perMinute((int) config('raqam.accounts.requests_per_minute'))->by('accounts|'.TrackingHash::visitor(SiteToken::visitorIp($request))));
        RateLimiter::for('booking', fn (Request $request) => Limit::perMinute((int) config('raqam.booking.requests_per_minute'))->by('booking|'.TrackingHash::visitor(SiteToken::visitorIp($request))));
        RateLimiter::for('booking-track', fn (Request $request) => Limit::perMinute((int) config('raqam.booking.track_per_minute'))->by('booking-track|'.TrackingHash::visitor(SiteToken::visitorIp($request))));
        RateLimiter::for('payments', fn (Request $request) => Limit::perMinute((int) config('raqam.payments.requests_per_minute'))->by('payments|'.TrackingHash::visitor(SiteToken::visitorIp($request))));
        RateLimiter::for('payment-webhooks', fn (Request $request) => Limit::perMinute((int) config('raqam.payments.webhook_per_minute'))->by('payment-webhooks|'.TrackingHash::visitor($request->ip())));
        RateLimiter::for('service-contact', fn (Request $request) => Limit::perMinute(30)->by('service-contact|'.TrackingHash::visitor(SiteToken::visitorIp($request))));

        // The admin team's permissions (2.7) answer every ability written as a permission code (`posts.manage`); any
        // other ability is left to the rest of the gate.
        Gate::before(fn ($user, string $ability) => PermissionCatalog::exists($ability) ? app(Access::class)->can($user instanceof User ? $user : null, $ability) : null);

        // A job that gave up or a scheduled task that failed reaches the admins (20.4).
        Queue::failing(fn (JobFailed $event) => SystemFailures::job($event));
        Event::listen(ScheduledTaskFailed::class, fn (ScheduledTaskFailed $event) => SystemFailures::taskFailed($event));
        Event::listen(ScheduledTaskFinished::class, fn (ScheduledTaskFinished $event) => SystemFailures::taskFinished($event));

        // Every sign-out of an admin is logged with its reason. CurrentDeviceLogout is what
        // AuthenticateSession raises when the password changed since the session began.
        Event::listen(Logout::class, fn (Logout $event) => $event->user && AdminAudit::logout($event->user, AdminAudit::expectedReason()));
        Event::listen(CurrentDeviceLogout::class, fn (CurrentDeviceLogout $event) => $event->user && AdminAudit::logout($event->user, AdminAudit::PASSWORD_CHANGED));

        if ($this->app->isProduction() && ProductionChecks::appliesTo($this->app->runningInConsole(), $_SERVER['argv'][1] ?? null)) {
            ProductionChecks::enforce();
        }
    }
}
