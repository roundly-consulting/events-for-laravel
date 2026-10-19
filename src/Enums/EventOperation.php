<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Enums;

/**
 * Every state-changing operation the manager performs — what `Events::fake()` records.
 */
enum EventOperation: string
{
    // Events
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
    case Restore = 'restore';
    case Publish = 'publish';
    case Unpublish = 'unpublish';
    case Postpone = 'postpone';
    case Reschedule = 'reschedule';
    case Cancel = 'cancel';
    case Complete = 'complete';
    case Transition = 'transition';
    case MoveOnline = 'move_online';
    case TransferOrganizer = 'transfer_organizer';
    case SetPoster = 'set_poster';

    // Category & labels
    case Categorize = 'categorize';
    case AttachLabels = 'attach_labels';
    case DetachLabels = 'detach_labels';
    case SyncLabels = 'sync_labels';

    // Venues
    case CreateVenue = 'create_venue';
    case UpdateVenue = 'update_venue';
    case DeleteVenue = 'delete_venue';
    case RestoreVenue = 'restore_venue';
    case GeocodeVenue = 'geocode_venue';

    // Ticket types
    case CreateTier = 'create_tier';
    case UpdateTier = 'update_tier';
    case DeleteTier = 'delete_tier';
    case RestoreTier = 'restore_tier';
    case ReorderTiers = 'reorder_tiers';

    // RSVPs
    case Rsvp = 'rsvp';
    case AddAttendee = 'add_attendee';
    case RemoveAttendee = 'remove_attendee';
    case ChangeTier = 'change_tier';
    case ChangeGuests = 'change_guests';
    case ConfirmRsvp = 'confirm_rsvp';
    case ConfirmPayment = 'confirm_payment';
    case ReleaseHold = 'release_hold';
    case RequestPayment = 'request_payment';
    case PromoteWaitlist = 'promote_waitlist';
    case RotateCode = 'rotate_code';

    // Check-in
    case CheckIn = 'check_in';
    case ManualCheckIn = 'manual_check_in';
    case UndoCheckIn = 'undo_check_in';

    // People
    case AddCoHost = 'add_co_host';
    case RemoveCoHost = 'remove_co_host';
    case SendInvitation = 'send_invitation';
    case RevokeInvitation = 'revoke_invitation';
    case RespondToInvitation = 'respond_to_invitation';

    // Maintenance
    case Sweep = 'sweep';
}
