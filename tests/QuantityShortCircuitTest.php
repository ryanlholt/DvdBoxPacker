<?php
/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

use DVDoug\BoxPacker\Test\ConstrainedPlacementByCountTestItem;
use DVDoug\BoxPacker\Test\ConstrainedTestItem;
use DVDoug\BoxPacker\Test\LimitedSupplyTestBox;
use DVDoug\BoxPacker\Test\TestBox;
use DVDoug\BoxPacker\Test\TestItem;
use PHPUnit\Framework\TestCase;

use function implode;
use function microtime;
use function sort;

/**
 * The quantity short-circuit optimisation must never change the result of packing - it only makes packing large
 * quantities faster. These tests pin that guarantee by packing the same inputs with the optimisation both on and off
 * and asserting the results are byte-for-byte identical (same boxes, same item placements).
 */
class QuantityShortCircuitTest extends TestCase
{
    public function testEquivalentSingleSkuEvenlyDivisible(): void
    {
        $box = new TestBox('Box', 100, 100, 100, 0, 100, 100, 100, 1000000);
        $item = new TestItem('Widget', 50, 50, 100, 100, Rotation::BestFit); // 4 per box

        $this->assertEquivalent([$box], [[$item, 120]]);
    }

    public function testEquivalentSingleSkuWithPartialFinalBox(): void
    {
        $box = new TestBox('Box', 100, 100, 100, 0, 100, 100, 100, 1000000);
        $item = new TestItem('Widget', 50, 50, 100, 100, Rotation::BestFit); // 4 per box

        $this->assertEquivalent([$box], [[$item, 123]]); // 30 full boxes + 1 box of 3
    }

    public function testEquivalentTwoSkus(): void
    {
        $box = new TestBox('Box', 100, 100, 100, 0, 100, 100, 100, 1000000);
        $big = new TestItem('Big', 100, 100, 50, 200, Rotation::BestFit);
        $small = new TestItem('Small', 50, 50, 50, 50, Rotation::BestFit);

        $this->assertEquivalent([$box], [[$big, 60], [$small, 80]]);
    }

    public function testEquivalentThreeSkusMultipleBoxSizes(): void
    {
        $boxes = [
            new TestBox('Small box', 100, 100, 100, 0, 100, 100, 100, 1000000),
            new TestBox('Big box', 200, 200, 100, 0, 200, 200, 100, 1000000),
        ];
        $items = [
            [new TestItem('A', 50, 50, 100, 100, Rotation::BestFit), 40],
            [new TestItem('B', 100, 50, 50, 150, Rotation::BestFit), 25],
            [new TestItem('C', 40, 40, 40, 30, Rotation::KeepFlat), 33],
        ];

        $this->assertEquivalent($boxes, $items);
    }

    public function testEquivalentWithKeepFlatItems(): void
    {
        // keepFlat is part of the signature, so these items are still safe to short-circuit.
        $box = new TestBox('Box', 100, 100, 100, 0, 100, 100, 100, 1000000);
        $item = new TestItem('Fragile', 50, 50, 50, 100, Rotation::KeepFlat);

        $this->assertEquivalent([$box], [[$item, 70]]);
    }

    public function testEquivalentWhenWeightIsTheBindingConstraint(): void
    {
        // Box is huge by volume but can only carry 5 items by weight - exercises the weight cap.
        $box = new TestBox('Box', 1000, 1000, 1000, 0, 1000, 1000, 1000, 500);
        $item = new TestItem('Heavy', 50, 50, 50, 100, Rotation::BestFit); // 5 per box by weight

        $this->assertEquivalent([$box], [[$item, 53]]);
    }

    public function testEquivalentWithLimitedSupplyBoxFullyPacked(): void
    {
        // The limited-supply box is itself replicated; replication must respect and decrement its stock.
        $box = new LimitedSupplyTestBox('Box', 100, 100, 100, 0, 100, 100, 100, 1000000, 10);
        $item = new TestItem('Widget', 50, 50, 100, 100, Rotation::BestFit); // 4 per box

        $this->assertEquivalent([$box], [[$item, 40]]); // exactly fills the 10 available boxes
    }

    public function testEquivalentWithLimitedSupplyBoxAlongsideUnlimited(): void
    {
        // Once the small box's stock is exhausted (capping replication), packing continues into the big box.
        $boxes = [
            new LimitedSupplyTestBox('Small', 100, 100, 100, 0, 100, 100, 100, 1000000, 3),
            new TestBox('Big', 200, 200, 100, 0, 200, 200, 100, 1000000),
        ];
        $item = new TestItem('Widget', 50, 50, 100, 100, Rotation::BestFit);

        $this->assertEquivalent($boxes, [[$item, 60]]);
    }

    public function testFlagHasNoEffectWithConstrainedPlacementItems(): void
    {
        $box = new TestBox('Box', 100, 100, 100, 0, 100, 100, 100, 1000000);
        ConstrainedPlacementByCountTestItem::$limit = 3;
        $item = new ConstrainedPlacementByCountTestItem('Battery', 40, 40, 40, 50, Rotation::BestFit);

        $this->assertEquivalent([$box], [[$item, 25]]);
    }

    public function testFlagHasNoEffectWithDeprecatedConstrainedItems(): void
    {
        $box = new TestBox('Box', 100, 100, 100, 0, 100, 100, 100, 1000000);
        ConstrainedTestItem::$limit = 3;
        $item = new ConstrainedTestItem('Battery', 40, 40, 40, 50, Rotation::BestFit);

        $this->assertEquivalent([$box], [[$item, 25]]);
    }

    public function testFlagHasNoEffectWithStrictItemOrdering(): void
    {
        $box = new TestBox('Box', 100, 100, 100, 0, 100, 100, 100, 1000000);
        $items = [
            [new TestItem('A', 50, 50, 100, 100, Rotation::BestFit), 20],
            [new TestItem('B', 50, 50, 50, 50, Rotation::BestFit), 20],
        ];

        $this->assertEquivalent([$box], $items, true);
    }

    /**
     * @group efficiency
     */
    public function testLargeQuantityIsHandledQuickly(): void
    {
        $box = new TestBox('Box', 100, 100, 100, 0, 100, 100, 100, 1000000);
        $item = new TestItem('Widget', 50, 50, 100, 100, Rotation::BestFit); // 4 per box

        $packer = new Packer();
        $packer->addBox($box);
        $packer->addItem($item, 100000);
        $packer->setQuantityShortCircuit(true);

        $start = microtime(true);
        $packedBoxes = $packer->pack();
        $elapsed = microtime(true) - $start;

        self::assertCount(25000, $packedBoxes);

        $totalItems = 0;
        foreach ($packedBoxes as $packedBox) {
            $totalItems += $packedBox->getItems()->count();
        }
        self::assertSame(100000, $totalItems);

        // Without the optimisation this would be ~25000 full packing solves; it must stay well under a second.
        self::assertLessThan(2.0, $elapsed, "Short-circuited packing took {$elapsed}s, expected < 2s");
    }

    /**
     * Pack the given inputs with the short-circuit both off and on, and assert the two results are identical.
     *
     * @param Box[]                         $boxes
     * @param array<array{0: Item, 1: int}> $itemsWithQty
     */
    private function assertEquivalent(array $boxes, array $itemsWithQty, bool $beStrictAboutItemOrdering = false): void
    {
        $off = $this->pack($boxes, $itemsWithQty, false, $beStrictAboutItemOrdering);
        $on = $this->pack($boxes, $itemsWithQty, true, $beStrictAboutItemOrdering);

        self::assertSame($off, $on, 'Packed boxes differ between short-circuit off and on');
    }

    /**
     * @param Box[]                         $boxes
     * @param array<array{0: Item, 1: int}> $itemsWithQty
     *
     * @return string[]
     */
    private function pack(array $boxes, array $itemsWithQty, bool $shortCircuit, bool $beStrictAboutItemOrdering): array
    {
        $packer = new Packer();
        foreach ($boxes as $box) {
            $packer->addBox($box);
        }
        foreach ($itemsWithQty as [$item, $qty]) {
            $packer->addItem($item, $qty);
        }
        $packer->setQuantityShortCircuit($shortCircuit);
        $packer->beStrictAboutItemOrdering($beStrictAboutItemOrdering);

        $packedBoxes = $packer->pack();

        $boxSignatures = [];
        foreach ($packedBoxes as $packedBox) {
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
            $boxSignatures[] = $packedBox->getBox()->getReference() . '#' . implode('|', $itemSignatures);
        }
        sort($boxSignatures);

        return $boxSignatures;
    }
}
