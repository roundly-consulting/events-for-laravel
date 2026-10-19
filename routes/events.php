<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use RoundlyConsulting\Events\Http\Controllers\AttendeeController;
use RoundlyConsulting\Events\Http\Controllers\CheckInController;
use RoundlyConsulting\Events\Http\Controllers\EventController;
use RoundlyConsulting\Events\Http\Controllers\EventStatusController;
use RoundlyConsulting\Events\Http\Controllers\HostController;
use RoundlyConsulting\Events\Http\Controllers\IcsController;
use RoundlyConsulting\Events\Http\Controllers\InvitationController;
use RoundlyConsulting\Events\Http\Controllers\InvitationResponseController;
use RoundlyConsulting\Events\Http\Controllers\MyRsvpController;
use RoundlyConsulting\Events\Http\Controllers\PosterController;
use RoundlyConsulting\Events\Http\Controllers\TierController;
use RoundlyConsulting\Events\Http\Controllers\VenueController;
use RoundlyConsulting\Events\Http\Controllers\WaitlistController;
use RoundlyConsulting\Events\Support\EventsConfig;

/*
|--------------------------------------------------------------------------
| events-for-laravel — the JSON API
|--------------------------------------------------------------------------
|
| Loaded only with `events.routes.enabled` on. Reads allow guests (the visibility rule
| applies: a hidden event is a 404); writes add `events.routes.auth_middleware`. `{event}` is
| the uuid, so `/events/feed.ics` never reaches the show route.
|
*/

Route::prefix(EventsConfig::routesPrefix())
    ->middleware(EventsConfig::routesMiddleware())
    ->name('events.')
    ->group(static function (): void {
        // Public reads.
        Route::get('events', [EventController::class, 'index'])->name('index');
        Route::get('events/feed.ics', [IcsController::class, 'feed'])->middleware('throttle:events-feeds')->name('feed');

        Route::prefix('events/{event}')->whereUuid('event')->group(static function (): void {
            Route::get('/', [EventController::class, 'show'])->name('show');
            Route::get('calendar.ics', [IcsController::class, 'show'])->middleware('throttle:events-feeds')->name('calendar');
            Route::get('tiers', [TierController::class, 'index'])->name('tiers.index');
            Route::get('attendees', [AttendeeController::class, 'index'])->name('attendees.index');
            Route::get('hosts', [HostController::class, 'index'])->name('hosts.index');
        });

        Route::get('event-venues', [VenueController::class, 'index'])->name('venues.index');
        Route::get('event-venues/{venue}', [VenueController::class, 'show'])->whereNumber('venue')->name('venues.show');

        // An invitation link: the token is the credential (and a signed-in user must be the invitee).
        Route::middleware('throttle:events-rsvp')->group(static function (): void {
            Route::post('event-invitations/accept', [InvitationResponseController::class, 'accept'])->name('invitations.accept');
            Route::post('event-invitations/decline', [InvitationResponseController::class, 'decline'])->name('invitations.decline');
        });

        // Writes.
        Route::middleware(EventsConfig::authMiddleware())->group(static function (): void {
            Route::post('events', [EventController::class, 'store'])->name('store');

            Route::prefix('events/{event}')->whereUuid('event')->group(static function (): void {
                Route::patch('/', [EventController::class, 'update'])->name('update');
                Route::delete('/', [EventController::class, 'destroy'])->name('destroy');
                Route::post('restore', [EventController::class, 'restore'])->name('restore');

                Route::post('publish', [EventStatusController::class, 'publish'])->name('publish');
                Route::post('unpublish', [EventStatusController::class, 'unpublish'])->name('unpublish');
                Route::post('postpone', [EventStatusController::class, 'postpone'])->name('postpone');
                Route::post('reschedule', [EventStatusController::class, 'reschedule'])->name('reschedule');
                Route::post('cancel', [EventStatusController::class, 'cancel'])->name('cancel');
                Route::post('complete', [EventStatusController::class, 'complete'])->name('complete');
                Route::post('move-online', [EventStatusController::class, 'moveOnline'])->name('move-online');

                Route::put('poster', [PosterController::class, 'update'])->name('poster.update');
                Route::delete('poster', [PosterController::class, 'destroy'])->name('poster.destroy');

                Route::post('tiers', [TierController::class, 'store'])->name('tiers.store');
                Route::post('tiers/reorder', [TierController::class, 'reorder'])->name('tiers.reorder');
                Route::patch('tiers/{tier}', [TierController::class, 'update'])->name('tiers.update');
                Route::delete('tiers/{tier}', [TierController::class, 'destroy'])->name('tiers.destroy');

                Route::get('rsvp', [MyRsvpController::class, 'show'])->name('rsvp.show');
                Route::get('rsvp/qr.svg', [MyRsvpController::class, 'qr'])->name('rsvp.qr');
                Route::middleware('throttle:events-rsvp')->group(static function (): void {
                    Route::put('rsvp', [MyRsvpController::class, 'update'])->name('rsvp.update');
                    Route::delete('rsvp', [MyRsvpController::class, 'destroy'])->name('rsvp.destroy');
                });

                Route::post('attendees', [AttendeeController::class, 'store'])->name('attendees.store');
                Route::patch('attendees/{rsvp}', [AttendeeController::class, 'update'])->name('attendees.update');
                Route::delete('attendees/{rsvp}', [AttendeeController::class, 'destroy'])->name('attendees.destroy');
                Route::post('attendees/{rsvp}/check-in', [AttendeeController::class, 'checkIn'])->name('attendees.check-in');
                Route::delete('attendees/{rsvp}/check-in', [AttendeeController::class, 'undoCheckIn'])->name('attendees.undo-check-in');

                Route::post('check-ins', [CheckInController::class, 'store'])->middleware('throttle:events-check-in')->name('check-ins.store');

                Route::get('waitlist', [WaitlistController::class, 'index'])->name('waitlist.index');
                Route::post('waitlist/promote', [WaitlistController::class, 'promote'])->name('waitlist.promote');

                Route::post('hosts', [HostController::class, 'store'])->name('hosts.store');
                Route::delete('hosts/{host}', [HostController::class, 'destroy'])->name('hosts.destroy');

                Route::get('invitations', [InvitationController::class, 'index'])->name('invitations.index');
                Route::post('invitations', [InvitationController::class, 'store'])->name('invitations.store');
                Route::delete('invitations/{invitee}', [InvitationController::class, 'destroy'])->name('invitations.destroy');
            });

            Route::post('event-venues', [VenueController::class, 'store'])->name('venues.store');
            Route::patch('event-venues/{venue}', [VenueController::class, 'update'])->whereNumber('venue')->name('venues.update');
            Route::delete('event-venues/{venue}', [VenueController::class, 'destroy'])->whereNumber('venue')->name('venues.destroy');
            Route::post('event-venues/{venue}/geocode', [VenueController::class, 'geocode'])->whereNumber('venue')->name('venues.geocode');
        });
    });
