<?php

namespace App\Services\Campaign\Dto;

use App\Models\UserCompareGroup;

/**
 * Outcome of a create() or resync() pass. Lets the caller (bot command,
 * scheduled job) report what changed and, crucially, surface the `orphaned`
 * list so the user can confirm deletions ("report and wait" policy).
 */
final class ResyncResult
{
    /**
     * @param  list<UserCompareGroup>  $created      brand-new child groups
     * @param  list<UserCompareGroup>  $updated      existing children whose membership changed
     * @param  list<UserCompareGroup>  $reactivated  previously-orphaned children that reappeared
     * @param  list<UserCompareGroup>  $orphaned     children whose split/MVT vanished (awaiting decision)
     */
    public function __construct(
        public readonly array $created = [],
        public readonly array $updated = [],
        public readonly array $reactivated = [],
        public readonly array $orphaned = [],
    ) {}

    public function changed(): bool
    {
        return $this->created !== []
            || $this->updated !== []
            || $this->reactivated !== []
            || $this->orphaned !== [];
    }
}
