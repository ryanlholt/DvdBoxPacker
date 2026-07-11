<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

use DVDoug\BoxPacker\Test\ConstrainedPlacementByCountTestItem;
use DVDoug\BoxPacker\Test\LimitedSupplyTestBox;
use DVDoug\BoxPacker\Test\LinkedTestItem;
use DVDoug\BoxPacker\Test\TestBox;
use DVDoug\BoxPacker\Test\TestItem;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

use function implode;
use function sort;
use function str_starts_with;

/**
 * The quantity short-circuit optimisation must never change the result of packing - it only makes packing large
 * quantities faster. These tests pin that guarantee by packing the same inputs with the optimisation both on and off
 * and asserting the results are byte-for-byte identical (same boxes, same item placements, same leftovers).
 */
class QuantityShortCircuitTest extends TestCase
{
    protected function setUp(): void
    {
        // ConstrainedPlacementByCountTestItem::$limit is a static, so reset it to its class default before every
        // test to stop a value set by one test (or by another test class entirely) leaking into this one.
        ConstrainedPlacementByCountTestItem::$limit = 3;
    }

    protected function tearDown(): void
    {
        // ...and again afterwards, so this class doesn't leak a non-default value into whichever test runs next.
        ConstrainedPlacementByCountTestItem::$limit = 3;
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
        // Rotation's backing value is `int`, so none of the five non-description fields can ever contain '|' - which
        // means the old scheme was (non-obviously) already injective, since the last five '|'-delimited tokens can
        // always be peeled off the right of the string to recover them exactly, regardless of how many pipes the
        // description embeds. (Verified computationally: no collision exists for this pair across a wide search of
        // compensating length/depth/weight/rotation values.)
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

    public function testEquivalentWithNoRotationItems(): void
    {
        // Rotation::Never items are still safe to short-circuit (rotation is part of the signature).
        $box = new TestBox('Box', 100, 100, 100, 0, 100, 100, 100, 1000000);
        $item = new TestItem('Fixed', 50, 50, 50, 100, Rotation::Never);

        $this->assertEquivalent([$box], [[$item, 70]]);
    }

    public function testEquivalentWhenWeightIsTheBindingConstraint(): void
    {
        // Box is huge by volume but can only carry 5 items by weight - exercises the weight cap.
        $box = new TestBox('Box', 1000, 1000, 1000, 0, 1000, 1000, 1000, 500);
        $item = new TestItem('Heavy', 50, 50, 50, 100, Rotation::BestFit); // 5 per box by weight

        $this->assertEquivalent([$box], [[$item, 53]]);
    }

    public function testEquivalentWithLimitedSupplyBoxesFullyPacked(): void
    {
        $box = new LimitedSupplyTestBox('Box', 100, 100, 100, 0, 100, 100, 100, 1000000, 10);
        $item = new TestItem('Widget', 50, 50, 100, 100, Rotation::BestFit); // 4 per box

        $this->assertEquivalent([$box], [[$item, 40]]); // exactly fills the 10 available boxes
    }

    public function testEquivalentWithLimitedSupplyBoxesRunningOut(): void
    {
        $box = new LimitedSupplyTestBox('Box', 100, 100, 100, 0, 100, 100, 100, 1000000, 5);
        $item = new TestItem('Widget', 50, 50, 100, 100, Rotation::BestFit); // 4 per box

        // Only 5 boxes for 40 items - 20 pack, 20 are left over. Replication must respect the box stock.
        $this->assertEquivalent([$box], [[$item, 40]], throwOnUnpackableItem: false);
    }

    public function testFlagHasNoEffectWithConstrainedItems(): void
    {
        $box = new TestBox('Box', 100, 100, 100, 0, 100, 100, 100, 1000000);
        ConstrainedPlacementByCountTestItem::$limit = 3;
        $item = new ConstrainedPlacementByCountTestItem('Battery', 40, 40, 40, 50, Rotation::BestFit);

        $this->assertEquivalent([$box], [[$item, 25]]);
    }

    public function testFlagHasNoEffectWithLinkedItems(): void
    {
        $box = new TestBox('Box', 100, 100, 100, 0, 100, 100, 100, 1000000);
        $items = [
            // A linked group of 8 fills one box exactly (8 x 50mm cubes); the loose items fill others.
            [new LinkedTestItem('Linked A', 50, 50, 50, 50, Rotation::BestFit, 'group1'), 4],
            [new LinkedTestItem('Linked B', 50, 50, 50, 50, Rotation::BestFit, 'group1'), 4],
            [new TestItem('Loose', 50, 50, 50, 50, Rotation::BestFit), 20],
        ];

        $this->assertEquivalent([$box], $items);
    }

    public function testFlagHasNoEffectWithStrictItemOrdering(): void
    {
        $box = new TestBox('Box', 100, 100, 100, 0, 100, 100, 100, 1000000);
        $items = [
            [new TestItem('A', 50, 50, 100, 100, Rotation::BestFit), 20],
            [new TestItem('B', 50, 50, 50, 50, Rotation::BestFit), 20],
        ];

        $this->assertEquivalent([$box], $items, beStrictAboutItemOrdering: true);
    }

    #[Group('efficiency')]
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

            public function log($level, string|Stringable $message, array $context = []): void
            {
                if (str_starts_with((string) $message, '[EVALUATING BOX]')) {
                    ++$this->count;
                }
            }
        };
        $packer->setLogger($boxEvaluations);

        $packedBoxes = $packer->pack();

        // Smoke test: correctness of the result is still pinned, even though timing is no longer asserted.
        self::assertCount(25000, $packedBoxes);
        self::assertCount(0, $packer->getUnpackedItems());

        $totalItems = 0;
        foreach ($packedBoxes as $packedBox) {
            $totalItems += $packedBox->items->count();
        }
        self::assertSame(100000, $totalItems);

        // Derivation of the bound: with a single box type and a single, evenly-divisible SKU, the short-circuit
        // path in Packer::doBasicPacking() evaluates a box exactly twice, however large the quantity is. First it
        // solves one "template" box the normal way (1 evaluation), then Packer::replicateIdenticalBoxes() clones
        // it for as many further boxfuls as stock allows - but that method deliberately holds back one final
        // boxful's worth of items rather than replicating everything, "so that the final (possibly partial) box is
        // always solved by the normal packing loop rather than assumed to be full". That reserved boxful is what
        // costs the second evaluation. Running this exact scenario with the counting logger confirms the count is
        // 2, independent of quantity (verified directly for 100000 items -> 25000 boxes). The assertion below
        // allows up to 5 - modest headroom for incidental implementation variance - while remaining more than
        // three orders of magnitude below the ~25000 per-box evaluations that would be required without the
        // short-circuit (one full solve per box, proven separately by disabling the flag - see story 4.3).
        self::assertLessThanOrEqual(5, $boxEvaluations->count, "Expected a small, quantity-independent number of VolumePacker evaluations, got {$boxEvaluations->count}");
    }

    /**
     * Pack the given inputs with the short-circuit both off and on, and assert the two results are identical.
     *
     * @param Box[]                         $boxes
     * @param array<array{0: Item, 1: int}> $itemsWithQty
     */
    private function assertEquivalent(array $boxes, array $itemsWithQty, bool $beStrictAboutItemOrdering = false, bool $throwOnUnpackableItem = true): void
    {
        $off = $this->pack($boxes, $itemsWithQty, false, $beStrictAboutItemOrdering, $throwOnUnpackableItem);
        $on = $this->pack($boxes, $itemsWithQty, true, $beStrictAboutItemOrdering, $throwOnUnpackableItem);

        self::assertSame($off['boxes'], $on['boxes'], 'Packed boxes differ between short-circuit off and on');
        self::assertSame($off['unpacked'], $on['unpacked'], 'Unpacked items differ between short-circuit off and on');
    }

    /**
     * @param Box[]                         $boxes
     * @param array<array{0: Item, 1: int}> $itemsWithQty
     *
     * @return array{boxes: string[], unpacked: string[]}
     */
    private function pack(array $boxes, array $itemsWithQty, bool $shortCircuit, bool $beStrictAboutItemOrdering, bool $throwOnUnpackableItem): array
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
        $packer->throwOnUnpackableItem($throwOnUnpackableItem);

        $packedBoxes = $packer->pack();

        $boxSignatures = [];
        foreach ($packedBoxes as $packedBox) {
            $boxSignatures[] = $this->boxSignature($packedBox);
        }
        sort($boxSignatures);

        $unpacked = [];
        foreach ($packer->getUnpackedItems() as $item) {
            $unpacked[] = $item->getDescription();
        }
        sort($unpacked);

        return ['boxes' => $boxSignatures, 'unpacked' => $unpacked];
    }

    public function testPackAllPermutationsEquivalent(): void
    {
        // Two box sizes (4 vs 2 per box) so there are genuinely multiple permutations, and enough items that the
        // per-type cap engages for both boxes. Capping must not change the set of permutations returned.
        $boxes = [
            new TestBox('Big', 100, 100, 100, 0, 100, 100, 100, 1000000),
            new TestBox('Small', 100, 50, 100, 0, 100, 50, 100, 1000000),
        ];
        $item = new TestItem('Widget', 50, 50, 100, 100, Rotation::BestFit);

        $off = $this->packAllPermutationsCanonical($boxes, [[$item, 6]], false);
        $on = $this->packAllPermutationsCanonical($boxes, [[$item, 6]], true);

        self::assertNotEmpty($off, 'Expected at least one permutation to exist');
        self::assertSame($off, $on, 'Permutations differ between short-circuit off and on');
    }

    /**
     * Run packAllPermutations() and return a canonical, order-independent representation of the full set of
     * permutations so two runs can be compared directly.
     *
     * @param Box[]                         $boxes
     * @param array<array{0: Item, 1: int}> $itemsWithQty
     *
     * @return string[]
     */
    private function packAllPermutationsCanonical(array $boxes, array $itemsWithQty, bool $shortCircuit): array
    {
        $packer = new Packer();
        foreach ($boxes as $box) {
            $packer->addBox($box);
        }
        foreach ($itemsWithQty as [$item, $qty]) {
            $packer->addItem($item, $qty);
        }
        $packer->setQuantityShortCircuit($shortCircuit);

        $canonicalPermutations = [];
        foreach ($packer->packAllPermutations() as $permutation) {
            $boxSignatures = [];
            foreach ($permutation as $packedBox) {
                $boxSignatures[] = $this->boxSignature($packedBox);
            }
            sort($boxSignatures);
            $canonicalPermutations[] = implode(';;', $boxSignatures);
        }
        sort($canonicalPermutations);

        return $canonicalPermutations;
    }

    /**
     * A canonical, order-independent string for a single packed box: its reference plus every item's placement.
     */
    private function boxSignature(PackedBox $packedBox): string
    {
        $itemSignatures = [];
        foreach ($packedBox->items as $packedItem) {
            $itemSignatures[] = implode(':', [
                $packedItem->item->getDescription(),
                $packedItem->x,
                $packedItem->y,
                $packedItem->z,
                $packedItem->width,
                $packedItem->length,
                $packedItem->depth,
            ]);
        }
        sort($itemSignatures);

        return $packedBox->box->getReference() . '#' . implode('|', $itemSignatures);
    }
}
