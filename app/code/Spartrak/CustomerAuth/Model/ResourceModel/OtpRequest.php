<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAuth\Model\ResourceModel;

use Magento\Framework\DB\Select;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Spartrak\CustomerAuth\Model\Otp\Status;

/**
 * Resource model for the OTP ledger.
 *
 * The counting and bulk-mutation helpers live here rather than in the service
 * because they have to run as single SQL statements. Loading a collection into
 * PHP to count it, or revoking rows one save() at a time, is both slower and
 * racy — the count would already be stale by the time the limiter acted on it.
 */
class OtpRequest extends AbstractDb
{
    public const TABLE_NAME = 'spartrak_customer_otp';

    /**
     * @inheritDoc
     */
    protected function _construct(): void
    {
        $this->_init(self::TABLE_NAME, 'request_id');
    }

    /**
     * How many codes were sent to this number since $since (UTC Y-m-d H:i:s).
     */
    public function countSendsForPhoneSince(string $phone, string $since): int
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable(), ['count' => 'COUNT(*)'])
            ->where('phone = ?', $phone)
            ->where('created_at >= ?', $since);

        return (int) $connection->fetchOne($select);
    }

    /**
     * How many codes were sent from this IP since $since, across all numbers.
     */
    public function countSendsForIpSince(string $ipAddress, string $since): int
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable(), ['count' => 'COUNT(*)'])
            ->where('ip_address = ?', $ipAddress)
            ->where('created_at >= ?', $since);

        return (int) $connection->fetchOne($select);
    }

    /**
     * When the newest code for this number was actually sent, or null if never.
     *
     * Drives the resend cooldown. Reads MAX(created_at) rather than ordering a
     * collection so it stays a single indexed aggregate.
     *
     * UNDELIVERED rows are excluded: no code reached the handset, so there is
     * nothing for the shopper to wait for. Note that REVOKED rows are NOT
     * excluded — a code revoked for exhausted attempts was still delivered, and
     * skipping it would let an attacker burn through the attempt cap and
     * immediately request a fresh code with no wait at all.
     */
    public function getLastSendTime(string $phone, string $purpose): ?string
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable(), ['last_send' => 'MAX(created_at)'])
            ->where('phone = ?', $phone)
            ->where('purpose = ?', $purpose)
            ->where('status <> ?', Status::UNDELIVERED);

        $lastSend = $connection->fetchOne($select);

        return ($lastSend === false || $lastSend === null || $lastSend === '') ? null : (string) $lastSend;
    }

    /**
     * Revoke every live row for a number+purpose in one statement.
     *
     * Called immediately before a new code is issued, which is what makes "only
     * the newest code works" actually true. Without it, requesting three codes
     * would leave three valid codes outstanding and triple the guessing surface.
     */
    public function revokeOpenRequests(string $phone, string $purpose): int
    {
        $connection = $this->getConnection();

        return (int) $connection->update(
            $this->getMainTable(),
            ['status' => Status::REVOKED],
            [
                'phone = ?' => $phone,
                'purpose = ?' => $purpose,
                'status IN (?)' => [Status::PENDING, Status::VERIFIED],
            ]
        );
    }

    /**
     * Mark one row as never delivered.
     *
     * Called when the gateway rejects the message. Kept as a targeted UPDATE on
     * a known id rather than going through revokeOpenRequests(), so a concurrent
     * successful request for the same number cannot be collaterally killed by
     * this one's failure.
     */
    public function markUndelivered(int $requestId): void
    {
        $this->getConnection()->update(
            $this->getMainTable(),
            ['status' => Status::UNDELIVERED],
            ['request_id = ?' => $requestId]
        );
    }

    /**
     * Find the single live row a proof token refers to.
     *
     * @return array<string, mixed>|null
     */
    public function loadByTokenHash(string $tokenHash): ?array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable())
            ->where('token_hash = ?', $tokenHash)
            ->where('status = ?', Status::VERIFIED)
            ->limit(1);

        $row = $connection->fetchRow($select);

        return (is_array($row) && $row !== []) ? $row : null;
    }

    /**
     * Newest pending row for a number+purpose, or null.
     *
     * @return array<string, mixed>|null
     */
    public function loadNewestPending(string $phone, string $purpose): ?array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable())
            ->where('phone = ?', $phone)
            ->where('purpose = ?', $purpose)
            ->where('status = ?', Status::PENDING)
            ->order('request_id ' . Select::SQL_DESC)
            ->limit(1);

        $row = $connection->fetchRow($select);

        return (is_array($row) && $row !== []) ? $row : null;
    }

    /**
     * Delete spent rows whose code expired before $before.
     *
     * Live rows are never deleted regardless of age, so the cron can never break
     * an in-flight registration.
     */
    public function purgeExpiredBefore(string $before): int
    {
        $connection = $this->getConnection();

        return (int) $connection->delete(
            $this->getMainTable(),
            [
                'expires_at < ?' => $before,
                'status NOT IN (?)' => [Status::PENDING, Status::VERIFIED],
            ]
        );
    }

    /**
     * Record a failed attempt and revoke the row if the cap is now reached.
     *
     * Done as one atomic UPDATE so two concurrent wrong guesses cannot both read
     * "attempts = 4" and each write 5, which would hand an attacker a free extra
     * try for every parallel request they make.
     *
     * @return int The attempt count after this failure.
     */
    public function registerFailedAttempt(int $requestId, int $maxAttempts): int
    {
        $connection = $this->getConnection();
        $table = $this->getMainTable();

        $statusExpression = $connection->quoteInto(
            'CASE WHEN attempts + 1 >= ? THEN ' . $connection->quote(Status::REVOKED) . ' ELSE status END',
            $maxAttempts
        );

        $connection->update(
            $table,
            [
                'attempts' => new \Zend_Db_Expr('attempts + 1'),
                'status' => new \Zend_Db_Expr($statusExpression),
            ],
            ['request_id = ?' => $requestId]
        );

        $select = $connection->select()
            ->from($table, ['attempts'])
            ->where('request_id = ?', $requestId);

        return (int) $connection->fetchOne($select);
    }
}
