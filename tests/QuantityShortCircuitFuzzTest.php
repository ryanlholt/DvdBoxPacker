<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

use DVDoug\BoxPacker\Test\LimitedSupplyTestBox;
use DVDoug\BoxPacker\Test\TestBox;
use DVDoug\BoxPacker\Test\TestItem;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

use function ceil;
use function count;
use function fwrite;
use function getenv;
use function implode;
use function intdiv;
use function json_encode;
use function max;
use function min;
use function mt_rand;
use function round;
use function sprintf;
use function str_repeat;
use function getrandmax;
use function srand;

use const JSON_PRETTY_PRINT;
use const PHP_EOL;
use const STDERR;

/**
 * Randomised differential harness for the quantity short-circuit (story 4.2).
 *
 * The short-circuit's "identical results" guarantee rests on the assumption that handing {@see VolumePacker} only
 * `capacity` copies of an item signature (rather than every remaining copy) never changes how a box packs. That
 * assumption is *not* obviously true: orientation choice consults a forward-looking window over the next up-to-8
 * items ({@see OrientatedItemSorter::calculateAdditionalItemsPackedWithThisOrientation}, which calls
 * `ItemList::topN(8)`). If a "big" SKU's per-box capacity is below 8 while many further copies remain in the pool,
 * the uncapped run's lookahead window at some placement step is full of that SKU, whereas the capped run's window
 * contains the trailing copies plus whatever smaller SKUs follow. A different window can change the lookahead score,
 * hence the chosen orientation, hence the placement, hence potentially which box wins the iteration.
 *
 * This harness generates seeded random scenarios that provably reach that region (it measures the hit rate and
 * asserts a floor on it), packs each scenario with the short-circuit off and on, and compares the canonical results.
 * On divergence it surfaces a fully reconstructable, machine-readable scenario dump.
 *
 * HISTORY (see docs/epics/stories/4.2 report): running this harness established two things, both since fixed.
 *   1. The hypothesised lookahead-*capping* divergence was real and is closed by the `capacity + LOOKAHEAD_DEPTH`
 *      headroom applied in {@see Packer::itemsForBoxEvaluation()} (pinned by
 *      QuantityShortCircuitTest::testEquivalentWhenPerBoxCapacityIsBelowLookaheadDepth()).
 *   2. A *second*, deeper divergence existed in {@see Packer::replicateIdenticalBoxes()}: it cloned the first solved
 *      box for every further boxful, but independently solving a later boxful from a depleted pool can pick a
 *      different orientation once its lookahead window shrinks. Replication is now guarded to only clone while the
 *      replaced iteration's pool provably stays above maxCapacity + LOOKAHEAD_DEPTH per constituent signature (pinned
 *      by QuantityShortCircuitTest::testEquivalentWhenReplicationWouldOutrunDepletedPool()).
 *   3. Box selection can depend on getBoxList()'s pool-dependent evaluation order. Replication is disabled for custom
 *      sorters, and the built-in sorter is protected by a volume-partition floor because rounded utilisation can tie
 *      unequal-volume boxes (pinned by QuantityShortCircuitTest::testCustomPackedBoxSorterDisablesReplication() and
 *      QuantityShortCircuitTest::testDefaultSorterRoundingTieDoesNotCrossBoxPreferenceBoundary()).
 *
 * With these fixes in place this harness is a hard regression guard: any observed divergence fails the build and
 * prints a fully reconstructable repro.
 *
 * @phpstan-type BoxSpec array{reference: string, w: int, l: int, d: int, emptyWeight: int, maxWeight: int, limit: int|null}
 * @phpstan-type ItemSpec array{description: string, w: int, l: int, d: int, weight: int, rotation: int, qty: int}
 * @phpstan-type ScenarioArray array{boxes: list<BoxSpec>, items: list<ItemSpec>}
 */
class QuantityShortCircuitFuzzTest extends TestCase
{
    use ShortCircuitEquivalenceTrait;

    /**
     * The lookahead depth consulted by {@see OrientatedItemSorter::calculateAdditionalItemsPackedWithThisOrientation}
     * via `ItemList::topN(8)`. Kept here so the "stress region" measurement below is expressed in terms of the same
     * number the feature under test actually uses.
     */
    private const LOOKAHEAD_DEPTH = 8;

    /**
     * Fixed default seed for CI determinism. Override with the SHORT_CIRCUIT_FUZZ_SEED env var for local deep runs.
     */
    private const DEFAULT_SEED = 20260711;

    /**
     * Default iteration count - deliberately modest so the (super-linear) short-circuit-off packing keeps the default
     * run to ~1-2 minutes. Override with the SHORT_CIRCUIT_FUZZ_ITERATIONS env var for local deep runs.
     */
    private const DEFAULT_ITERATIONS = 250;

    /**
     * The harness only earns its keep if it actually reaches the stress region. If fewer than this fraction of
     * scenarios cap some signature below the lookahead depth with surplus copies remaining, the generator has
     * silently drifted away from the corner it is meant to probe and the test fails rather than giving false
     * confidence.
     */
    private const MIN_STRESS_HIT_RATE = 0.5;

    #[Group('efficiency')]
    public function testRandomisedDifferentialEquivalence(): void
    {
        $seedEnv = getenv('SHORT_CIRCUIT_FUZZ_SEED');
        $iterationsEnv = getenv('SHORT_CIRCUIT_FUZZ_ITERATIONS');
        $seed = $seedEnv !== false ? (int) $seedEnv : self::DEFAULT_SEED;
        $iterations = $iterationsEnv !== false ? (int) $iterationsEnv : self::DEFAULT_ITERATIONS;

        srand($seed);

        $cappingEngaged = 0;          // (a) at least one signature capped below its available count for some box
        $cappedBelowLookahead = 0;    // (b) some signature capped below the lookahead depth with surplus remaining
        $cappedWithFullWindowSurplus = 0; // (b') stronger: capped below depth with >= depth surplus copies remaining
        $maxItems = 0;

        $divergenceCount = 0;
        $firstDivergences = [];

        for ($iteration = 0; $iteration < $iterations; ++$iteration) {
            $scenario = $this->generateScenario();

            $stress = $this->measureStressRegion($scenario);
            $cappingEngaged += $stress['cappingEngaged'] ? 1 : 0;
            $cappedBelowLookahead += $stress['cappedBelowLookahead'] ? 1 : 0;
            $cappedWithFullWindowSurplus += $stress['cappedWithFullWindowSurplus'] ? 1 : 0;
            $maxItems = max($maxItems, $stress['totalItems']);

            $off = $this->packScenario($scenario, false);
            $on = $this->packScenario($scenario, true);

            if ($off !== $on) {
                ++$divergenceCount;
                if (count($firstDivergences) < 3) {
                    $firstDivergences[] = $this->describeDivergence($seed, $iteration, $scenario, $off, $on);
                }
            }
        }

        fwrite(STDERR, sprintf(
            PHP_EOL . '[QuantityShortCircuitFuzz] seed=%d iterations=%d maxItemsInAScenario=%d' . PHP_EOL
                . '  (a) capping engaged:                          %d/%d (%.1f%%)' . PHP_EOL
                . '  (b) signature capped below lookahead+surplus:  %d/%d (%.1f%%)' . PHP_EOL
                . "  (b') capped below lookahead, >=%d surplus:      %d/%d (%.1f%%)" . PHP_EOL
                . '  divergences (short-circuit off vs on):         %d/%d' . PHP_EOL,
            $seed,
            $iterations,
            $maxItems,
            $cappingEngaged,
            $iterations,
            100 * $cappingEngaged / $iterations,
            $cappedBelowLookahead,
            $iterations,
            100 * $cappedBelowLookahead / $iterations,
            self::LOOKAHEAD_DEPTH,
            $cappedWithFullWindowSurplus,
            $iterations,
            100 * $cappedWithFullWindowSurplus / $iterations,
            $divergenceCount,
            $iterations,
        ));

        // If we ran enough iterations to be statistically meaningful, guard the stress-region hit rate so the harness
        // cannot silently degrade into testing benign scenarios that never exercise the lookahead corner. Checked
        // before the divergence assertion so a degraded generator is reported even on an otherwise-green run.
        if ($iterations >= 20) {
            self::assertGreaterThanOrEqual(
                (int) ceil(self::MIN_STRESS_HIT_RATE * $iterations),
                $cappedBelowLookahead,
                sprintf(
                    'Stress region (b) reached in only %d/%d scenarios (< %.0f%%); the generator is no longer '
                        . 'exercising the lookahead-window corner it exists to probe.',
                    $cappedBelowLookahead,
                    $iterations,
                    100 * self::MIN_STRESS_HIT_RATE
                )
            );
        }

        if ($divergenceCount > 0) {
            self::fail(sprintf(
                '%d/%d scenarios diverged between short-circuit off and on. First repro(s) below.' . PHP_EOL . PHP_EOL . '%s',
                $divergenceCount,
                $iterations,
                implode(PHP_EOL . str_repeat('-', 80) . PHP_EOL, $firstDivergences)
            ));
        }

        // Reaching here means every scenario packed identically with the short-circuit off and on.
        self::assertSame(0, $divergenceCount);
    }

    /**
     * Generator-health guard that runs in the fast leg (no packing): confirms the generator still reaches the
     * lookahead stress region for the default seed within a few scenarios, so drift is caught on every CI run rather
     * than only in the efficiency leg. The concrete minimal scenarios the hunt turned up are pinned as hard-coded
     * regression tests in QuantityShortCircuitTest (testEquivalentWhenPerBoxCapacityIsBelowLookaheadDepth and
     * testEquivalentWhenReplicationWouldOutrunDepletedPool).
     */
    public function testGeneratorReachesStressRegionForFixedSeed(): void
    {
        srand(self::DEFAULT_SEED);

        $reached = false;
        for ($i = 0; $i < 40; ++$i) {
            $scenario = $this->generateScenario();
            if ($this->measureStressRegion($scenario)['cappedBelowLookahead']) {
                $reached = true;
                break;
            }
        }

        self::assertTrue($reached, 'The default seed should reach the lookahead stress region within a few scenarios.');
    }

    /**
     * Pack a scenario with the short-circuit forced off or on, using freshly constructed box/item instances so no
     * state can bleed between the two runs, and return the canonical (order-independent) result for comparison.
     *
     * @param ScenarioArray $scenario
     *
     * @return array{boxes: string[], unpacked: string[]}
     */
    private function packScenario(array $scenario, bool $shortCircuit): array
    {
        $packer = new Packer();
        foreach ($scenario['boxes'] as $boxSpec) {
            $packer->addBox($this->buildBox($boxSpec));
        }
        foreach ($scenario['items'] as $itemSpec) {
            $packer->addItem($this->buildItem($itemSpec), $itemSpec['qty']);
        }
        $packer->setQuantityShortCircuit($shortCircuit);
        // Limited-supply boxes can legitimately run out, leaving leftovers; comparing leftovers is part of the point,
        // so never throw.
        $packer->throwOnUnpackableItem(false);

        $packedBoxes = $packer->pack();

        return $this->canonicalPackingResult($packedBoxes, $packer->getUnpackedItems());
    }

    /**
     * @param BoxSpec $spec
     */
    private function buildBox(array $spec): Box
    {
        if ($spec['limit'] !== null) {
            return new LimitedSupplyTestBox(
                $spec['reference'],
                $spec['w'],
                $spec['l'],
                $spec['d'],
                $spec['emptyWeight'],
                $spec['w'],
                $spec['l'],
                $spec['d'],
                $spec['maxWeight'],
                $spec['limit']
            );
        }

        return new TestBox(
            $spec['reference'],
            $spec['w'],
            $spec['l'],
            $spec['d'],
            $spec['emptyWeight'],
            $spec['w'],
            $spec['l'],
            $spec['d'],
            $spec['maxWeight']
        );
    }

    /**
     * @param ItemSpec $spec
     */
    private function buildItem(array $spec): TestItem
    {
        return new TestItem(
            $spec['description'],
            $spec['w'],
            $spec['l'],
            $spec['d'],
            $spec['weight'],
            $this->rotation($spec['rotation'])
        );
    }

    private function rotation(int $value): Rotation
    {
        return match ($value) {
            Rotation::Never->value => Rotation::Never,
            Rotation::KeepFlat->value => Rotation::KeepFlat,
            default => Rotation::BestFit,
        };
    }

    /**
     * Compute, for each box, the per-signature capacity exactly as {@see Packer::itemsForBoxEvaluation} does, and
     * report whether this scenario reaches the lookahead stress region.
     *
     * @param ScenarioArray $scenario
     *
     * @return array{cappingEngaged: bool, cappedBelowLookahead: bool, cappedWithFullWindowSurplus: bool, totalItems: int}
     */
    private function measureStressRegion(array $scenario): array
    {
        $cappingEngaged = false;
        $cappedBelowLookahead = false;
        $cappedWithFullWindowSurplus = false;
        $totalItems = 0;
        foreach ($scenario['items'] as $itemSpec) {
            $totalItems += $itemSpec['qty'];
        }

        foreach ($scenario['boxes'] as $boxSpec) {
            $innerVolume = $boxSpec['w'] * $boxSpec['l'] * $boxSpec['d'];
            $netWeight = $boxSpec['maxWeight'] - $boxSpec['emptyWeight'];

            foreach ($scenario['items'] as $itemSpec) {
                $unitVolume = max($itemSpec['w'] * $itemSpec['l'] * $itemSpec['d'], 1);
                $capacity = intdiv($innerVolume, $unitVolume);
                if ($itemSpec['weight'] > 0) {
                    $capacity = min($capacity, intdiv($netWeight, $itemSpec['weight']));
                }
                if ($capacity < 0) {
                    $capacity = 0;
                }

                $count = $itemSpec['qty'];
                if ($capacity < $count) {
                    $cappingEngaged = true;
                }
                if ($capacity < self::LOOKAHEAD_DEPTH && $count > $capacity) {
                    $cappedBelowLookahead = true;
                }
                if ($capacity < self::LOOKAHEAD_DEPTH && $count >= $capacity + self::LOOKAHEAD_DEPTH) {
                    $cappedWithFullWindowSurplus = true;
                }
            }
        }

        return [
            'cappingEngaged' => $cappingEngaged,
            'cappedBelowLookahead' => $cappedBelowLookahead,
            'cappedWithFullWindowSurplus' => $cappedWithFullWindowSurplus,
            'totalItems' => $totalItems,
        ];
    }

    /**
     * Produce one random scenario. This is the ONLY consumer of mt_rand() in the harness, so the whole run is fully
     * determined by the seed.
     *
     * The generator is biased hard toward the stress region: with high probability the first SKU is a "big" one whose
     * per-box capacity against the smallest box is in [2, 7] (below the lookahead depth) while its quantity is well
     * above capacity + lookahead depth, so the uncapped lookahead window at the first placement step is full of that
     * SKU while the capped window is not.
     *
     * Every generated box can hold at least one copy of every generated item type (by both volume and weight). That is
     * a deliberate invariant: during the original hunt it isolated the harness from a *separate* short-circuit defect
     * (a zero cap for every remaining signature handed VolumePacker an empty ItemList, crashing in `top()`) so that a
     * divergence found here could be attributed to the lookahead mechanism under study. That crash path is gone -
     * caps now include LOOKAHEAD_DEPTH headroom so a capped list is never empty - but the invariant is kept so every
     * generated box remains a genuine candidate and scenarios stay meaningful.
     *
     * @return ScenarioArray
     */
    private function generateScenario(): array
    {
        $boxCount = $this->randInt(1, 4);
        $boxes = [];
        $smallestVolume = null;
        $minNetWeight = null;
        for ($b = 0; $b < $boxCount; ++$b) {
            $w = $this->randInt(60, 170);
            $l = $this->randInt(60, 170);
            $d = $this->randInt(60, 170);
            $emptyWeight = $this->randInt(0, 40);
            // Net carrying capacity - kept well above the heaviest item generated below, but small enough that weight
            // is sometimes the binding cap.
            $netWeight = $this->randInt(400, 6000);
            $limit = $this->chance(0.35) ? $this->randInt(2, 40) : null;
            $boxes[] = [
                'reference' => 'Box' . $b,
                'w' => $w,
                'l' => $l,
                'd' => $d,
                'emptyWeight' => $emptyWeight,
                'maxWeight' => $emptyWeight + $netWeight,
                'limit' => $limit,
            ];
            $volume = $w * $l * $d;
            if ($smallestVolume === null || $volume < $smallestVolume) {
                $smallestVolume = $volume;
                $smallestBox = $boxes[count($boxes) - 1];
            }
            $minNetWeight = $minNetWeight === null ? $netWeight : min($minNetWeight, $netWeight);
        }
        /** @var BoxSpec $smallestBox */
        /** @var int $smallestVolume */
        /** @var int $minNetWeight */
        $items = [];

        // The "big" SKU: sized so that its capacity against the smallest box lands below the lookahead depth. Because
        // the smallest box has the least volume of any box, capping this SKU below the lookahead depth against it
        // guarantees at least one box reaches the stress region while every box still holds >= 1 copy.
        $bigIncluded = $this->chance(0.9);
        if ($bigIncluded) {
            [$bw, $bl, $bd, $capacity] = $this->sizeBigItem($smallestBox);
            // Optionally make weight rather than volume the binding constraint on this SKU.
            $weight = $this->randInt(1, max(1, intdiv($minNetWeight, max($capacity, 1))));
            if ($this->chance(0.3)) {
                // Force a weight cap in [2, capacity] so weight becomes the (still < lookahead) binding constraint.
                $targetWeightCap = $this->randInt(2, max(2, $capacity));
                $weight = max(1, intdiv($minNetWeight, $targetWeightCap));
            }
            $effectiveCapacity = min($capacity, $weight > 0 ? intdiv($minNetWeight, $weight) : $capacity);
            $effectiveCapacity = max($effectiveCapacity, 1);
            $items[] = [
                'description' => 'Big',
                'w' => $bw,
                'l' => $bl,
                'd' => $bd,
                'weight' => $weight,
                'rotation' => $this->randomRotation(),
                'qty' => $effectiveCapacity + self::LOOKAHEAD_DEPTH + $this->randInt(1, 60),
            ];
        }

        // Additional smaller SKUs. Sized strictly smaller (by volume) than the big SKU so they trail it in the
        // volume-descending sort and only enter the lookahead window once the big copies are exhausted - exactly the
        // trailing-copy composition the capped run differs on.
        $bigVolume = $bigIncluded ? $items[0]['w'] * $items[0]['l'] * $items[0]['d'] : $smallestVolume;
        $smallCount = $this->randInt($bigIncluded ? 0 : 1, 5);
        for ($s = 0; $s < $smallCount; ++$s) {
            $spec = $this->sizeSmallItem($smallestBox, $bigVolume, $minNetWeight, $s);
            if ($spec === null) {
                continue;
            }
            $items[] = $spec;
        }

        // Guarantee a minimum total so the off-run is a meaningful pack rather than a trivial one.
        $total = 0;
        foreach ($items as $item) {
            $total += $item['qty'];
        }
        if ($total < 50 && $items !== []) {
            $items[0]['qty'] += 50 - $total;
        }

        return ['boxes' => $boxes, 'items' => $items];
    }

    /**
     * Size the big SKU as random fractions of the smallest box so it fits that box dimensionally (giving it real
     * orientation choices) while its volumetric capacity against that box lands in [2, 7] - below the lookahead depth.
     *
     * @param BoxSpec $smallestBox
     *
     * @return array{0: int, 1: int, 2: int, 3: int} width, length, depth, volumetric capacity against the smallest box
     */
    private function sizeBigItem(array $smallestBox): array
    {
        $boxVolume = $smallestBox['w'] * $smallestBox['l'] * $smallestBox['d'];
        $best = null;
        for ($try = 0; $try < 60; ++$try) {
            $w = max(1, (int) round($smallestBox['w'] * $this->randFloat(0.45, 0.95)));
            $l = max(1, (int) round($smallestBox['l'] * $this->randFloat(0.45, 0.95)));
            $d = max(1, (int) round($smallestBox['d'] * $this->randFloat(0.45, 0.95)));
            $capacity = intdiv($boxVolume, max($w * $l * $d, 1));
            if ($capacity >= 2 && $capacity <= 7) {
                return [$w, $l, $d, $capacity];
            }
            // Remember the closest-to-range attempt as a fallback.
            if ($best === null || ($capacity >= 1 && $capacity < $best[3])) {
                $best = [$w, $l, $d, max($capacity, 1)];
            }
        }

        // Fallback: half the box in each axis gives capacity ~8; nudge one axis up to drop below the lookahead depth.
        $w = max(1, intdiv($smallestBox['w'] * 3, 4));
        $l = max(1, intdiv($smallestBox['l'] * 3, 4));
        $d = max(1, intdiv($smallestBox['d'] * 2, 3));
        $capacity = max(2, min(7, intdiv($boxVolume, max($w * $l * $d, 1))));

        return $best ?? [$w, $l, $d, $capacity];
    }

    /**
     * Size a smaller SKU that fits the smallest box, has smaller volume than the big SKU, and weighs within every
     * box's carrying capacity (so every box holds >= 1 by both volume and weight).
     *
     * @param BoxSpec $smallestBox
     *
     * @return ItemSpec|null
     */
    private function sizeSmallItem(array $smallestBox, int $bigVolume, int $minNetWeight, int $index): ?array
    {
        for ($try = 0; $try < 40; ++$try) {
            $w = max(1, (int) round($smallestBox['w'] * $this->randFloat(0.15, 0.55)));
            $l = max(1, (int) round($smallestBox['l'] * $this->randFloat(0.15, 0.55)));
            $d = max(1, (int) round($smallestBox['d'] * $this->randFloat(0.15, 0.55)));
            if ($w * $l * $d >= $bigVolume) {
                continue; // must trail the big SKU in the volume sort
            }
            $weight = $this->chance(0.2) ? 0 : $this->randInt(1, max(1, $minNetWeight));

            return [
                'description' => 'Small' . $index,
                'w' => $w,
                'l' => $l,
                'd' => $d,
                'weight' => $weight,
                'rotation' => $this->randomRotation(),
                'qty' => $this->randInt(1, 60),
            ];
        }

        return null;
    }

    private function randomRotation(): int
    {
        return match ($this->randInt(0, 2)) {
            0 => Rotation::Never->value,
            1 => Rotation::KeepFlat->value,
            default => Rotation::BestFit->value,
        };
    }

    /**
     * Build a human- and machine-readable failure message that fully reconstructs the failing case as a permanent
     * regression fixture.
     *
     * @param ScenarioArray                              $scenario
     * @param array{boxes: string[], unpacked: string[]} $off
     * @param array{boxes: string[], unpacked: string[]} $on
     */
    private function describeDivergence(int $seed, int $iteration, array $scenario, array $off, array $on): string
    {
        return sprintf(
            'Short-circuit divergence found.' . PHP_EOL
                . 'seed=%d iteration=%d' . PHP_EOL
                . 'Set SHORT_CIRCUIT_FUZZ_SEED=%d and SHORT_CIRCUIT_FUZZ_ITERATIONS=%d to reproduce.' . PHP_EOL
                . 'Scenario (reconstructable):' . PHP_EOL . '%s' . PHP_EOL
                . 'Short-circuit OFF result:' . PHP_EOL . '%s' . PHP_EOL
                . 'Short-circuit ON result:' . PHP_EOL . '%s' . PHP_EOL,
            $seed,
            $iteration,
            $seed,
            $iteration + 1,
            json_encode($scenario, JSON_PRETTY_PRINT),
            json_encode($off, JSON_PRETTY_PRINT),
            json_encode($on, JSON_PRETTY_PRINT)
        );
    }

    /**
     * Seedable bounded integer in [$min, $max].
     *
     * Deliberately derived from the bare, seedable Mersenne Twister (srand()/mt_rand()) rather than the two-argument
     * mt_rand($min, $max): the project's risky rule set rewrites that two-argument form to the unseedable random_int(),
     * which would silently destroy the reproducibility this whole harness depends on. The bare mt_rand() used here is
     * left untouched by that rule, so seeding via srand() stays effective.
     */
    private function randInt(int $min, int $max): int
    {
        if ($max <= $min) {
            return $min;
        }

        return $min + (int) (mt_rand() / (getrandmax() + 1) * ($max - $min + 1));
    }

    private function randFloat(float $min, float $max): float
    {
        return $min + (mt_rand() / getrandmax()) * ($max - $min);
    }

    private function chance(float $probability): bool
    {
        return mt_rand() / getrandmax() < $probability;
    }
}
