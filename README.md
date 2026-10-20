<!-- roundly-hero:start -->
<p align="center">
  <a href="https://roundly-consulting.com/open-source/docs/events-for-laravel?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=events-for-laravel">
    <img src="https://raw.githubusercontent.com/roundly-consulting/events-for-laravel/main/art/hero.png" alt="Events for Laravel — Roundly open source" width="100%">
  </a>
</p>
<!-- roundly-hero:end -->

<!-- roundly-badges:start -->
<p align="center">
  <a href="https://packagist.org/packages/roundly-consulting/events-for-laravel"><img src="https://img.shields.io/packagist/v/roundly-consulting/events-for-laravel?style=flat-square&label=release" alt="Latest release"></a>
  <a href="https://github.com/roundly-consulting/events-for-laravel/actions/workflows/run-tests.yml"><img src="https://img.shields.io/github/actions/workflow/status/roundly-consulting/events-for-laravel/run-tests.yml?branch=main&style=flat-square&label=tests" alt="Tests"></a>
  <a href="https://github.com/roundly-consulting/events-for-laravel/actions/workflows/fix-php-code-style-issues.yml"><img src="https://img.shields.io/github/actions/workflow/status/roundly-consulting/events-for-laravel/fix-php-code-style-issues.yml?branch=main&style=flat-square&label=code%20style" alt="Code style"></a>
  <a href="https://donate.stripe.com/dRmeVe8FX5PF1Qd9pXcEw00"><img src="https://img.shields.io/badge/donate-support%20our%20open%20source-F24E29?style=flat-square&logo=stripe&logoColor=white" alt="Donate"></a>
  <a href="https://www.patreon.com/cw/roundly"><img src="https://img.shields.io/badge/patreon-become%20a%20patron-F96854?style=flat-square&logo=patreon&logoColor=white" alt="Patreon"></a>
  <a href="https://roundly-consulting.com/support-us?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=events-for-laravel#crypto"><img src="https://img.shields.io/badge/crypto-BTC%20%C2%B7%20ETH%20%C2%B7%20BNB%20%C2%B7%20SOL-F7931A?style=flat-square&logo=bitcoin&logoColor=white" alt="Crypto"></a>
</p>
<!-- roundly-badges:end -->

# Events for Laravel

Run real-world events in any Laravel app: reusable venues with near-me search, ticket types priced in Money,
RSVPs with a race-free waitlist, QR check-in at the door, co-hosts and invite-only events. It is built for
meetup, community, venue and ticketing apps that want the event core done right, with times stored in UTC and
read in each event's own timezone.

## Installation

Requires PHP 8.4, Laravel 12 or 13, MySQL 8 or PostgreSQL, and the `bcmath`, `fileinfo`, `phar` and `zlib`
extensions (plus `gd` or `imagick` for poster variants).

```bash
composer require roundly-consulting/events-for-laravel
php artisan events:install
php artisan migrate
```

Set `EVENTS_KEY_TYPE` to your users' key type (`bigint`, `uuid` or `ulid`) before migrating, and keep
`EVENTS_PRIMARY_KEY_TYPE` equal to `lifecycle.key_type` and `tags.key_type`. Schedule `events:sweep` and
`lifecycle:sweep` every minute (held seats expire, reminders go out, finished events complete), then run
`php artisan events:doctor`.

## Usage

Create a venue and a category, publish an event with two ticket types, then RSVP, check in at the door and
find events near you:

```php
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Money\Money;

// A reusable venue: its timezone is how every wall-clock time below is read
$venue = Events::venues()->make('Blue Note')
    ->address(street: 'Hlavná 1', city: 'Bratislava', postalCode: '811 01', country: 'SK')
    ->coordinates(48.1439, 17.1097)
    ->timezone('Europe/Bratislava')
    ->create();

Events::categories()->make(['en' => 'Jazz', 'sk' => 'Jazz'])->create();   // categories are curated

$event = Events::make(['en' => 'Jazz Night', 'sk' => 'Jazzový večer'])
    ->description(['en' => 'A live quartet, then an open jam session.'])
    ->organizedBy($user)
    ->at($venue)
    ->startsAt('2026-11-14 20:00')                 // 20:00 in Bratislava, stored as UTC
    ->duration('3 hours')                          // or 'PT3H', a CarbonInterval, or ->endsAt(...)
    ->capacity(120)
    ->category('jazz')
    ->labels(['Live', 'Late night'])
    ->poster($request->file('poster'))
    ->tier('Free entry', key: 'free')
    ->tier('Supporter', price: Money::ofMajor('15', 'EUR'), capacity: 30)   // key "supporter"
    ->publish();

// RSVP: a seat while there is one, the waitlist once it is full
$rsvp = Events::for($event)->rsvp($attendee)->going(tier: 'free');
$rsvp->isGoing();                                   // or isWaitlisted(), isHeld()

// At the door: scan the attendee's QR code, as the signed-in staff member
$result = Events::for($event)->by($doorStaff)->checkIn($scannedCode);   // organizers and co-hosts only
$result->firstCheckIn;                              // false if the code was already used

// Discovery: upcoming published events (cancelled ones flagged by phase) within 25 km, nearest first
Events::browse()->upcoming()->near($here, km: 25)->orderByDistance($here)->paginate();
```

<!-- roundly-docs:start -->
## Documentation

The full documentation — configuration, every feature and its API, and testing — lives on our
website: **[roundly-consulting.com/open-source/docs/events-for-laravel](https://roundly-consulting.com/open-source/docs/events-for-laravel?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=events-for-laravel)**

Release notes are in [CHANGELOG.md](CHANGELOG.md). To contribute, see the
[contributing guide](https://github.com/roundly-consulting/.github/blob/main/CONTRIBUTING.md).
<!-- roundly-docs:end -->

<!-- roundly-support:start -->
## Support our work

This package is free and open source, built and maintained by
[Roundly Consulting](https://roundly-consulting.com/open-source?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=events-for-laravel).
If it saves you time, please consider supporting our open-source work — a one-time donation, a
monthly pledge on Patreon or a crypto donation helps fund maintenance, new features and new
packages.

<a href="https://donate.stripe.com/dRmeVe8FX5PF1Qd9pXcEw00"><img src="https://img.shields.io/badge/Donate-Support%20Roundly%20open%20source-F24E29?style=for-the-badge&logo=stripe&logoColor=white" alt="Donate to Roundly open source"></a>
<a href="https://www.patreon.com/cw/roundly"><img src="https://img.shields.io/badge/Patreon-Become%20a%20patron-F96854?style=for-the-badge&logo=patreon&logoColor=white" alt="Become a patron on Patreon"></a>
<a href="https://roundly-consulting.com/support-us?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=events-for-laravel#crypto"><img src="https://img.shields.io/badge/Crypto-BTC%20%C2%B7%20ETH%20%C2%B7%20BNB%20%C2%B7%20SOL-F7931A?style=for-the-badge&logo=bitcoin&logoColor=white" alt="Donate crypto: BTC, ETH, BNB or SOL"></a>
<!-- roundly-support:end -->

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
