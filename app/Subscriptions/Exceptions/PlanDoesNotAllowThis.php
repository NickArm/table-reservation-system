<?php

declare(strict_types=1);

namespace App\Subscriptions\Exceptions;

use App\Enums\Feature;
use RuntimeException;

/**
 * The plan says no (SPEC §4.5).
 *
 * Thrown from the action rather than checked in the controller, for the same
 * reason SpotNotAvailable is: the web form, the API and a console command all
 * reach the same action, and a limit enforced in one of the three is not a
 * limit. Being an exception means it cannot be forgotten on the way to a
 * database write.
 *
 * It carries the sentence the owner reads. The message is written where the
 * rule lives, so it can name the actual number instead of saying "limit
 * reached" and leaving them to guess.
 */
final class PlanDoesNotAllowThis extends RuntimeException
{
    public static function noRoom(string $message): self
    {
        return new self($message);
    }

    public static function featureMissing(Feature $feature): self
    {
        return new self($feature->refusal());
    }
}
