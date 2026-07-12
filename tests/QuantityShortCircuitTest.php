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
use Psr\Log\AbstractLogger;

use function strpos;

/**
 * The quantity short-circuit optimisation must never change the result of packing - it only makes packing large
 * quantities faster. These tests pin that guarantee by packing the same inputs with the optimisation both on and off
 * and asserting the results are byte-for-byte identical (same boxes, same item placements).
 */
class QuantityShortCircuitTest extends TestCase
{
    use ShortCircuitEquivalenceTrait;

    protected function setUp(): void
    {
        // ConstrainedPlacementByCountTestItem::$limit and ConstrainedTestItem::$limit are statics, so reset them to
        // their class default before every test to stop a value set by one test (or by another test class
        // entirely) leaking into this one.
        ConstrainedPlacementByCountTestItem::$limit = 3;
        ConstrainedTestItem::$limit = 3;
    }

    protected function tearDown(): void
    {
        // ...and again afterwards, so this class doesn't leak a non-default value into whichever test runs next.
        ConstrainedPlacementByCountTestItem::$limit = 3;
        ConstrainedTestItem::$limit = 3;
    }

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

    public function testEquivalentWithDelimiterInDescription(): void
    {
        // Regression test for the item-signature delimiter-safety fix (docs/epics/stories/4.1-delimiter-safe-item-signatures.md).
        //
        // This pair mimics the classic "field-shifting" collision shape: item A's description embeds a '|'
        // immediately followed by digits that match item B's real width ("A|10" width=5 vs "A" width=10). Note that
        // for *this* codebase specifically, this exact pair can never produce a byte-identical signature under the
        // old (pre-fix) scheme for any choice of the other fields: width/length/depth/weight are strictly `int` and
        // keepFlat is `bool`, so none of the five non-description fields can ever contain '|' - which means the old
        // scheme was (non-obviously) already injective, since the last five '|'-delimited tokens can always be
        // peeled off the right of the string to recover them exactly, regardless of how many pipes the description
        // embeds. (Verified computationally: no collision exists for this pair across a wide search of compensating
        // length/depth/weight/keepFlat values.)
        //
        // The new length-prefixed scheme (ItemList::signatureOf()) makes the collision-free property hold by direct
        // construction instead of relying on that non-obvious argument, so it stays safe even if a future Item
        // implementation ever relaxed those type guarantees. This test packs large-enough quantities of each item to
        // engage both the per-signature cap and box replication, and asserts the two SKUs are never merged.
        $box = new TestBox('Box', 100, 100, 100, 0, 100, 100, 100, 1000000);
        $itemA = new TestItem('A|10', 5, 100, 100, 100, Rotation::BestFit); // 20 per box
        $itemB = new TestItem('A', 10, 100, 100, 100, Rotation::BestFit); // 10 per box

        $this->assertEquivalent([$box], [[$itemA, 45], [$itemB, 25]]);
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

    public function testEquivalentWhenPerBoxCapacityIsBelowLookaheadDepth(): void
    {
        // Regression test pinning the *capping* half of the lookahead divergence, fixed by the
        // capacity+LOOKAHEAD_DEPTH headroom in Packer::itemsForBoxEvaluation().
        //
        // The equivalent fixture ported directly from the 4.x branch (a different box/item pairing) does not
        // reproduce a divergence on this branch's packing engine when run pre-fix, so this fixture was instead
        // minimised from a 3.x-native divergence found by running QuantityShortCircuitFuzzTest against the
        // pre-fix source (seed 20260711, iteration 137 of the default run): reduced from a 2-box/60-qty scenario
        // down to a single box and a quantity of 7.
        //
        // This box holds only 4 'Big' items by weight (netWeight 5750 / weight 1300 = 4) - below the 8-item lookahead
        // depth used by OrientatedItemSorter - while 7 copies are available. With the old cap-at-capacity behaviour
        // (no headroom at all) the short-circuit handed the volume packer only 4 copies, so its forward-looking
        // topN(8) window saw 4 items instead of the uncapped run's 7; that changed the chosen orientation and
        // diverged the placements. The headroom fix hands capacity + 8 copies (comfortably above the available 7),
        // restoring an identical window. Diverges without the fix and is identical with it (verified directly
        // against this branch, not just ported from 4.x).
        $box = new TestBox('Box', 154, 85, 149, 22, 154, 85, 149, 5772);
        $big = new TestItem('Big', 56, 40, 70, 1300, Rotation::BestFit); // 4 per box by weight

        $this->assertEquivalent([$box], [[$big, 7]]);
    }

    public function testEquivalentWhenReplicationWouldOutrunDepletedPool(): void
    {
        // Regression test for the *replication* half of the divergence found by the fuzz harness, distinct from the
        // capping issue above: here nothing is capped (capacity 3 + LOOKAHEAD_DEPTH 8 = 11 = the available quantity,
        // so Packer::itemsForBoxEvaluation() is a no-op and each box evaluation is byte-for-byte the uncapped one).
        // Replication used to clone the first solved box for every further full boxful, but independently solving a
        // later boxful from a depleted pool (here the third boxful, packed from 5 remaining copies rather than 11)
        // picks a different orientation once its lookahead window shrinks. replicateIdenticalBoxes() now only clones
        // while the replaced iteration's pool provably stays above maxCapacity + LOOKAHEAD_DEPTH per constituent
        // signature - in this scenario that means no clones at all, and every box is solved by the normal loop.
        //
        // Box holds 3 'Widget' by weight (netWeight 4280 / weight 1151 = 3); 11 copies -> boxes of 3,3,3,2. Without
        // the replication guard, the third full box diverges from the cloned template.
        $box = new TestBox('Box', 68, 74, 40, 0, 68, 74, 40, 4280);
        $widget = new TestItem('Widget', 33, 30, 22, 1151, Rotation::BestFit); // 3 per box by weight

        $this->assertEquivalent([$box], [[$widget, 11]]);
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

        // Machine-independent stand-in for a wall-clock assertion: a spy logger that counts how many times
        // VolumePacker actually evaluates a box (VolumePacker::pack() logs a "[EVALUATING BOX]" debug line exactly
        // once per call - see src/VolumePacker.php). The short-circuit's entire purpose is to make that count
        // independent of the requested quantity, which a timing assertion can only ever approximate.
        $boxEvaluations = new class extends AbstractLogger {
            public int $count = 0;

            public function log($level, $message, array $context = []): void
            {
                if (strpos((string) $message, '[EVALUATING BOX]') === 0) {
                    ++$this->count;
                }
            }
        };
        $packer->setLogger($boxEvaluations);

        $packedBoxes = $packer->pack();

        // Smoke test: correctness of the result is still pinned, even though timing is no longer asserted.
        self::assertCount(25000, $packedBoxes);

        $totalItems = 0;
        foreach ($packedBoxes as $packedBox) {
            $totalItems += $packedBox->getItems()->count();
        }
        self::assertSame(100000, $totalItems);

        // Derivation of the bound: with a single box type and a single, evenly-divisible SKU, the short-circuit
        // path in Packer::doBasicPacking() evaluates a box exactly twice, however large the quantity is. First it
        // solves one "template" box the normal way (1 evaluation), then Packer::replicateIdenticalBoxes() clones it
        // for as many further boxfuls as stock allows - but that method deliberately holds back one final boxful's
        // worth of items rather than replicating everything, so that the final (possibly partial) box is always
        // solved by the normal packing loop rather than assumed to be full. That reserved boxful is what costs the
        // second evaluation. Running this exact scenario with the counting logger confirms the count is 2,
        // independent of quantity (verified directly for 100000 items -> 25000 boxes). The assertion below allows
        // up to 5 - modest headroom for incidental implementation variance - while remaining more than three
        // orders of magnitude below the ~25000 per-box evaluations that would be required without the short-circuit
        // (one full solve per box, proven separately by disabling the flag - see story 4.3).
        self::assertLessThanOrEqual(5, $boxEvaluations->count, "Expected a small, quantity-independent number of VolumePacker evaluations, got {$boxEvaluations->count}");
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

        self::assertSame($off['boxes'], $on['boxes'], 'Packed boxes differ between short-circuit off and on');
        self::assertSame($off['unpacked'], $on['unpacked'], 'Unpacked items differ between short-circuit off and on');
    }

    /**
     * @param Box[]                         $boxes
     * @param array<array{0: Item, 1: int}> $itemsWithQty
     *
     * @return array{boxes: string[], unpacked: string[]}
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

        return $this->canonicalPackingResult($packedBoxes, new ItemList());
    }
}
