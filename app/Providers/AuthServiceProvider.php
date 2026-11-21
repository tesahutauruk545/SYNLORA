<?php

namespace App\Providers;

use App\Models\Room;
use App\Models\StudySession;
use App\Policies\RoomPolicy;
use App\Policies\StudySessionPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Room::class => RoomPolicy::class,
        StudySession::class => StudySessionPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        //
    }
}
