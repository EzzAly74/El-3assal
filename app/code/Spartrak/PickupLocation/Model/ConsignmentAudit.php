<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Model;

use Magento\Framework\Phrase;
use Spartrak\PickupLocation\Api\Data\ConsignmentInterface;

/**
 * "What changed on this consignment, in words?" — for the order's own history.
 *
 * ===========================================================================
 * THE RULE THIS IMPLEMENTS
 * ===========================================================================
 * BUSINESS.md section 12, §6, on a driver or vehicle swapped after dispatch:
 *
 *   > The consignment is updated and the change is written to the order's
 *   > history. The customer's card changes under them with no notification;
 *   > the history is the only trace of why.
 *
 * That last sentence is the entire justification. Notification is on-site only
 * (§7), so a shopper who wrote down a phone number on Tuesday and finds a
 * different one on Wednesday gets no explanation from the system at all. When
 * they ring to ask, the person answering needs to be able to see that it
 * changed, when, and — for a destination — why.
 *
 * Section 9 question 5 asks for the same thing on the destination override,
 * and adds a REASON to it, so both are written by this one class.
 *
 * ===========================================================================
 * WHY A DIFF AND NOT "SAVED"
 * ===========================================================================
 * A history full of "driver and vehicle details saved" is a history nobody
 * reads twice: it cannot distinguish a dispatcher correcting a typo in a plate
 * number from a dispatcher putting the shipment in a different vehicle with a
 * different driver, and those are very different facts about where a
 * customer's goods are.
 *
 * ===========================================================================
 * WHAT IT DELIBERATELY DOES NOT RECORD
 * ===========================================================================
 * The photograph's PATH. It is a hashed filename that means nothing to a human
 * and would push a line of noise into every entry; "vehicle photo replaced" is
 * the fact, and the photograph itself is on the panel to look at.
 *
 * Nothing here is customer-visible. These entries are written with
 * `$isVisibleOnFront = false`, because they are an operational record for the
 * people answering the phone, not an announcement — and §7's decision that
 * there is no notification is a business decision this class must not quietly
 * reverse by publishing changes to the order page.
 */
class ConsignmentAudit
{
    /**
     * The fields worth naming, and the label each is named by.
     *
     * The labels are the admin form's own, verbatim, so an entry names a field
     * the reader can find on screen — the same discipline
     * Model\ConsignmentRequirements applies to the missing-field checklist.
     *
     * @var array<string, string>
     */
    private const TRACKED = [
        ConsignmentInterface::DRIVER_NAME => 'Driver name',
        ConsignmentInterface::DRIVER_PHONE => 'Driver phone',
        ConsignmentInterface::PLATE_NUMBER => 'Vehicle plate number',
        ConsignmentInterface::ORIGIN_STATION => 'From station',
    ];

    /**
     * The state to compare against, taken BEFORE the form is applied.
     *
     * A flat array rather than the model, because the model is about to be
     * mutated in place — the admin form loads the existing row, sets the posted
     * values onto it and saves it, so a reference to it would show the new
     * values by the time the diff ran.
     *
     * @return array<string, string>
     */
    public function capture(?ConsignmentInterface $consignment): array
    {
        if ($consignment === null) {
            return [];
        }

        return [
            ConsignmentInterface::DRIVER_NAME => $consignment->getDriverName(),
            ConsignmentInterface::DRIVER_PHONE => $consignment->getDriverPhone(),
            ConsignmentInterface::PLATE_NUMBER => $consignment->getPlateNumber(),
            ConsignmentInterface::ORIGIN_STATION => $consignment->getOriginStation(),
            ConsignmentInterface::VEHICLE_PHOTO => $consignment->getVehiclePhoto(),
            ConsignmentInterface::DESTINATION_NAME => (string) $consignment->getDestinationName(),
        ];
    }

    /**
     * The history entry for this save, or null when nothing actually changed.
     *
     * Null matters: an admin who opens the panel and presses save without
     * touching anything should not add a row to the order's history.
     *
     * @param array<string, string> $before as returned by capture()
     * @param string|null $customerChoice the station the customer chose at
     *        checkout, so a destination entry can say whether this is a
     *        REDIRECT or a REPAIR
     */
    public function describe(
        array $before,
        ConsignmentInterface $after,
        ?string $customerChoice
    ): ?Phrase {
        $changes = $before === []
            ? $this->firstEntry($after)
            : $this->diff($before, $after);

        $destination = $this->destinationChange($before, $after, $customerChoice);

        if ($destination !== null) {
            $changes[] = $destination;
        }

        if ($changes === []) {
            return null;
        }

        return __(
            'Station consignment: %1',
            implode((string) __('; '), array_map(static fn ($line): string => (string) $line, $changes))
        );
    }

    /**
     * The first save is a statement, not a diff — there is no previous value
     * for any of it, and listing four "changed from empty to X" lines would
     * bury the one thing the reader wants: who is driving.
     *
     * @return array<int, Phrase>
     */
    private function firstEntry(ConsignmentInterface $after): array
    {
        return [
            __(
                'recorded — driver %1 on %2, vehicle %3, departing %4',
                $after->getDriverName(),
                $after->getDriverPhone(),
                $after->getPlateNumber(),
                $after->getOriginStation()
            ),
        ];
    }

    /**
     * @param array<string, string> $before
     * @return array<int, Phrase>
     */
    private function diff(array $before, ConsignmentInterface $after): array
    {
        $now = $this->capture($after);
        $changes = [];

        foreach (self::TRACKED as $field => $label) {
            $old = trim($before[$field] ?? '');
            $new = trim($now[$field] ?? '');

            if ($old === $new) {
                continue;
            }

            $changes[] = $old === ''
                ? __('%1 set to %2', __($label), $new)
                : __('%1 changed from %2 to %3', __($label), $old, $new);
        }

        // The path, not the picture — see the class header for why the filename
        // itself is not printed.
        if (($before[ConsignmentInterface::VEHICLE_PHOTO] ?? '') !== $after->getVehiclePhoto()) {
            $changes[] = ($before[ConsignmentInterface::VEHICLE_PHOTO] ?? '') === ''
                ? __('vehicle photo added')
                : __('vehicle photo replaced');
        }

        return $changes;
    }

    /**
     * The destination line, which is the one entry that has to say WHY.
     *
     * Three distinct outcomes, because they mean three different things to
     * whoever reads the order later:
     *
     *   recorded    the checkout snapshot never landed and somebody
     *               established the station by asking the customer — our data
     *               fault, repaired
     *   redirected  the customer chose one station and the shipment is going
     *               to another — our policy decision, and the reason is
     *               mandatory at the form
     *   cleared     the override was removed, so the order falls back to the
     *               customer's own choice
     *
     * @param array<string, string> $before
     */
    private function destinationChange(
        array $before,
        ConsignmentInterface $after,
        ?string $customerChoice
    ): ?Phrase {
        $old = trim($before[ConsignmentInterface::DESTINATION_NAME] ?? '');
        $new = (string) $after->getDestinationName();

        if ($old === $new) {
            return null;
        }

        if ($new === '') {
            return __('destination override removed');
        }

        $reason = $after->getDestinationReason();
        $customerChoice = $customerChoice !== null ? trim($customerChoice) : '';

        if ($customerChoice !== '' && $customerChoice !== $new) {
            return $reason !== null
                ? __(
                    'destination REDIRECTED from %1 (the customer\'s choice) to %2 — %3',
                    $customerChoice,
                    $new,
                    $reason
                )
                : __(
                    'destination REDIRECTED from %1 (the customer\'s choice) to %2',
                    $customerChoice,
                    $new
                );
        }

        return $reason !== null
            ? __('destination recorded as %1 — %2', $new, $reason)
            : __(
                'destination recorded as %1, which the checkout did not save onto this order',
                $new
            );
    }
}
