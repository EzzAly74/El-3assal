<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\InstaPay\Api\Data;

/**
 * A customer's claim that they transferred the money, and the receipt for it.
 *
 * A service contract rather than a bare model, because two things outside this
 * module already need to read one - the admin order view and, in time, any
 * reconciliation job or export a merchant asks for. Handing those a concrete
 * model would tie them to its table.
 */
interface TransferInterface
{
    public const TRANSFER_ID    = 'transfer_id';
    public const ORDER_ID       = 'order_id';
    public const QUOTE_ID       = 'quote_id';
    public const CUSTOMER_PHONE = 'customer_phone';
    public const PROOF_PATH     = 'proof_path';
    public const ORIGINAL_NAME  = 'original_name';
    public const FILE_SIZE      = 'file_size';
    public const STATUS         = 'status';
    public const REVIEWED_BY    = 'reviewed_by';
    public const REVIEWED_AT    = 'reviewed_at';
    public const CREATED_AT     = 'created_at';

    /** Uploaded, nobody has looked at it yet. */
    public const STATUS_PENDING = 'pending';
    /** A member of staff confirmed the money arrived. */
    public const STATUS_APPROVED = 'approved';
    /** A member of staff could not match it to a payment. */
    public const STATUS_REJECTED = 'rejected';

    public function getTransferId(): ?int;

    public function getOrderId(): int;

    public function setOrderId(int $orderId): self;

    public function getQuoteId(): ?int;

    public function setQuoteId(?int $quoteId): self;

    public function getCustomerPhone(): string;

    public function setCustomerPhone(string $phone): self;

    public function getProofPath(): string;

    public function setProofPath(string $path): self;

    public function getOriginalName(): ?string;

    public function setOriginalName(?string $name): self;

    public function getFileSize(): ?int;

    public function setFileSize(?int $bytes): self;

    public function getStatus(): string;

    public function setStatus(string $status): self;

    public function getReviewedBy(): ?string;

    public function setReviewedBy(?string $username): self;

    public function getReviewedAt(): ?string;

    public function setReviewedAt(?string $at): self;

    public function getCreatedAt(): ?string;
}
