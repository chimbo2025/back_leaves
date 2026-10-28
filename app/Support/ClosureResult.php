<?php

namespace App\Support;

/**
 * Result of a return-to-work closure eligibility check (Phase 3).
 *
 * Closing is a separate lifecycle step from reviewing: a decision records the
 * outcome, while closure confirms every downstream action the decision implies
 * (a posted balance transaction, the review audit event, any required document)
 * is actually in place before the case is sealed.
 */
class ClosureResult
{
    private function __construct(
        public bool $ok,
        public ?string $reason = null,
    ) {}

    public static function valid(): self
    {
        return new self(true);
    }

    public static function invalid(string $reason): self
    {
        return new self(false, $reason);
    }
}
