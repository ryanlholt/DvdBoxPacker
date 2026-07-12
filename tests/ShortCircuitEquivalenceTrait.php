<?php
/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

use function implode;
use function sort;

/**
 * Shared canonicalisation used by the quantity short-circuit equivalence tests. Both the hand-picked fixtures in
 * {@see QuantityShortCircuitTest} and the randomised harness in {@see QuantityShortCircuitFuzzTest} compare packing
 * runs by reducing them to these order-independent string representations, so the exact same definition of "identical
 * result" is applied everywhere.
 */
trait ShortCircuitEquivalenceTrait
{
    /**
     * A canonical, order-independent string for a single packed box: its reference plus every item's placement
     * (description and x/y/z position plus the width/length/depth it was packed at).
     */
    protected function boxSignature(PackedBox $packedBox): string
    {
        $itemSignatures = [];
        foreach ($packedBox->getItems() as $packedItem) {
            $itemSignatures[] = implode(':', [
                $packedItem->getItem()->getDescription(),
                $packedItem->getX(),
                $packedItem->getY(),
                $packedItem->getZ(),
                $packedItem->getWidth(),
                $packedItem->getLength(),
                $packedItem->getDepth(),
            ]);
        }
        sort($itemSignatures);

        return $packedBox->getBox()->getReference() . '#' . implode('|', $itemSignatures);
    }

    /**
     * Canonical, order-independent representation of a completed pack: the sorted per-box signatures plus the sorted
     * descriptions of the items left unpacked. Two packs of the same inputs are byte-for-byte equivalent iff these
     * representations are identical.
     *
     * @param iterable<PackedBox> $packedBoxes
     * @param iterable<Item>      $unpackedItems
     *
     * @return array{boxes: string[], unpacked: string[]}
     */
    protected function canonicalPackingResult(iterable $packedBoxes, iterable $unpackedItems): array
    {
        $boxSignatures = [];
        foreach ($packedBoxes as $packedBox) {
            $boxSignatures[] = $this->boxSignature($packedBox);
        }
        sort($boxSignatures);

        $unpacked = [];
        foreach ($unpackedItems as $item) {
            $unpacked[] = $item->getDescription();
        }
        sort($unpacked);

        return ['boxes' => $boxSignatures, 'unpacked' => $unpacked];
    }
}
