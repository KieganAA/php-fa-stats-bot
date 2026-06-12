<?php

namespace App\Services\Campaign\Exceptions;

use RuntimeException;

/**
 * Thrown when a user tries to subscribe to a campaign that has nothing to
 * track — no traffic splits (2+ landings on a step) and no MVT landings.
 * Subscribing would create a parent row that never pushes anything, so we
 * refuse and tell the user instead.
 */
final class EmptyCampaignException extends RuntimeException {}
