<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Model;

use Magento\Framework\Phrase;
use Magento\Sales\Api\Data\OrderInterface;
use Spartrak\PickupLocation\Api\Data\ConsignmentInterface;

/**
 * What a `موقف` order still needs before it can be dispatched, BY NAME.
 *
 * ===========================================================================
 * WHY A NAMED LIST AND NOT A BOOLEAN
 * ===========================================================================
 * The rule itself already existed as a boolean — `Consignment::isComplete()` —
 * and the gate that enforces it threw one sentence:
 *
 *     "Add the driver and vehicle details before marking this order out for
 *      delivery."
 *
 * That is the wrong shape for an operational screen. A dispatcher working a
 * queue discovers the refusal AFTER pressing save, as an exception, and is not
 * told which of five fields is empty. On an order where four are filled, the
 * message is actively misleading.
 *
 * So the requirement is expressed once, here, as a list of missing field
 * LABELS, and both consumers read the same list:
 *
 *   Block\Adminhtml\Order\View\Consignment  draws it as a checklist BEFORE the
 *                                           dispatcher acts
 *   Observer\RequireConsignmentBeforeDispatch  names the same fields in the
 *                                           refusal, if they get that far
 *
 * The panel and the gate can therefore never disagree about what is required —
 * which is the failure this class exists to prevent, not a hypothetical one:
 * the two used to be a loop over five getters in one file and a hardcoded
 * sentence in another.
 *
 * `isSatisfied()` delegates to `Consignment::isComplete()` rather than
 * recomputing. That keeps ONE boolean authority, and it fails in the safe
 * direction: if a sixth required field is ever added to `isComplete()` and
 * nobody adds its label here, the gate still gates correctly and the checklist
 * merely does not name the sixth. The reverse — a label with no enforcement —
 * cannot happen.
 *
 * ===========================================================================
 * THE VEHICLE PHOTO IS REQUIRED, AND THAT IS A BUSINESS DECISION
 * ===========================================================================
 * BUSINESS.md section 12, §4 asks for "a photo of the ACTUAL vehicle carrying
 * the shipment, so the customer can identify it in a yard full of
 * near-identical microbuses". The photo is not decoration on this channel: the
 * customer is meeting a stranger at a public station and the plate number alone
 * is a poor way to find one white microbus among thirty. It stays required.
 *
 * ===========================================================================
 * A MISSING DESTINATION IS A WARNING, NOT A BLOCK
 * ===========================================================================
 * `الي الموقف` comes from the customer's checkout choice, not from this form,
 * and it is deliberately NOT in the missing-fields list. When it is absent (see
 * Model\OrderDestination::isKnown) the panel says so prominently and dispatch
 * still proceeds.
 *
 * The reasoning is that the parcel physically has to travel, and the driver's
 * phone number is the customer's real lifeline — withholding the whole dispatch
 * over a bookkeeping column that this module failed to write would punish the
 * customer for our own data fault. The warning is aimed at the one person who
 * can resolve it by picking the phone up.
 *
 * Since §9 question 5 was settled, that person can also RECORD what they are
 * told: the panel's destination field writes an override. It is still not a
 * required field, because the dispatcher may not have reached the customer yet
 * and the vehicle may already be leaving — a rule that stops a dispatch until
 * somebody answers their phone would be a worse rule than the one it replaced.
 */
class ConsignmentRequirements
{
    /**
     * getter => the label the admin form uses for that field.
     *
     * The labels are the form's own, verbatim, so the checklist and the
     * refusal name fields the dispatcher can actually find on screen.
     *
     * @var array<string, string>
     */
    private const FIELDS = [
        'getDriverName' => 'Driver name',
        'getDriverPhone' => 'Driver phone',
        'getPlateNumber' => 'Vehicle plate number',
        'getOriginStation' => 'From station',
        'getVehiclePhoto' => 'Vehicle photo',
    ];

    /**
     * Does the gate apply to this order at all?
     *
     * Depot only. A branch collection has no driver and no vehicle — the
     * customer comes to us — and a home delivery has a courier this feature
     * says nothing about. `سوبر جيت` is out of scope (§7 question 1) and has no
     * carrier of its own, so nothing here pretends to cover it.
     */
    public function appliesTo(FulfilmentChannel $channel, ?OrderInterface $order): bool
    {
        return $channel->isDepot($order);
    }

    /**
     * @return array<int, Phrase> the labels of the fields still to be filled,
     *         in the order they appear on the form
     */
    public function getMissing(?ConsignmentInterface $consignment): array
    {
        $missing = [];

        foreach (self::FIELDS as $getter => $label) {
            $value = $consignment === null ? '' : (string) $consignment->{$getter}();

            if (trim($value) === '') {
                $missing[] = __($label);
            }
        }

        return $missing;
    }

    public function isSatisfied(?ConsignmentInterface $consignment): bool
    {
        return $consignment !== null && $consignment->isComplete();
    }

    /**
     * The missing labels as one comma-joined string, for a message.
     */
    public function getMissingAsText(?ConsignmentInterface $consignment): string
    {
        return implode(
            (string) __(', '),
            array_map(static fn (Phrase $label): string => (string) $label, $this->getMissing($consignment))
        );
    }
}
