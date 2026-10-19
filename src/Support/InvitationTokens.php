<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Support;

use RoundlyConsulting\Crypto\CryptoManager;
use RoundlyConsulting\Events\Enums\InvitationStatus;
use RoundlyConsulting\Events\Exceptions\InvalidInvitationException;
use RoundlyConsulting\Events\Models\EventInvitation;
use SensitiveParameter;

/**
 * Invitation tokens: `INV1-` + base32 of 20 random bytes, a bearer secret stored as its SHA-256
 * only.
 *
 * @internal
 */
final readonly class InvitationTokens
{
    public const string PREFIX = 'INV1-';

    public function __construct(
        private CryptoManager $crypto,
    ) {}

    public function issue(): string
    {
        return self::PREFIX.$this->crypto->base32Encode($this->crypto->randomBytes(20));
    }

    /**
     * The hash of a well-formed token, or null.
     */
    public function hash(#[SensitiveParameter] string $token): ?string
    {
        $token = strtoupper(trim($token));

        return preg_match('/^INV1-[A-Z2-7]{32}$/', $token) === 1 ? $this->crypto->digest()->hex($token) : null;
    }

    /**
     * The live invitation behind a token — or the uniform refusal for an unknown, malformed,
     * revoked, declined, used or expired token, or one of a deleted event.
     */
    public function invitation(#[SensitiveParameter] string $token): EventInvitation
    {
        $hash = $this->hash($token);
        $invitation = $hash === null ? null : EventInvitation::query()->where('token_hash', $hash)->first();
        $event = $invitation?->event;

        if ($invitation === null || $event === null || $event->trashed()
            || ! in_array($invitation->status, [InvitationStatus::Pending, InvitationStatus::Accepted], true)
            || ($invitation->expires_at !== null && Clock::now()->greaterThanOrEqualTo($invitation->expires_at))) {
            throw InvalidInvitationException::invalid();
        }

        return $invitation;
    }
}
