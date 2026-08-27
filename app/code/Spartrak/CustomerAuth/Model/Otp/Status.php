<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAuth\Model\Otp;

/**
 * Lifecycle of a single OTP row.
 *
 * PENDING -> VERIFIED -> CONSUMED is the happy path. REVOKED is terminal and
 * covers three different dead ends (attempts exhausted, superseded by a newer
 * code, gateway failed to send) because the distinction only matters for
 * forensics, and the ledger keeps created_at/attempts for that.
 */
final class Status
{
    /** Issued, not yet proven. */
    public const PENDING = 'pending';

    /** Code accepted; a proof token was issued and is not yet spent. */
    public const VERIFIED = 'verified';

    /** Proof token spent. Terminal — a token is single-use by design. */
    public const CONSUMED = 'consumed';

    /** Dead. Terminal. */
    public const REVOKED = 'revoked';

    /**
     * Issued but the gateway refused it, so no code ever reached the handset.
     * Terminal.
     *
     * Distinct from REVOKED for one specific reason: an undelivered row must NOT
     * start the resend cooldown. Holding a shopper for 60 seconds because of a
     * provider outage on our side is the most infuriating failure this flow can
     * produce, and it looks identical to the code being lost in transit.
     *
     * It still counts toward the per-phone and per-IP send QUOTAS, though —
     * otherwise a permanently failing gateway could be hammered without limit,
     * which turns our own outage into an amplification vector.
     */
    public const UNDELIVERED = 'undelivered';
}
