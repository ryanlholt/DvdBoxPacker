<?php
/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use SplObjectStorage;

use function count;
use function get_class;
use function intdiv;
use function max;
use function min;
use function usort;

use const PHP_INT_MAX;

/**
 * Actual packer.
 */
class Packer implements LoggerAwareInterface
{
    private LoggerInterface $logger;

    protected int $maxBoxesToBalanceWeight = 12;

    protected ItemList $items;

    protected BoxList $boxes;

    /**
     * @var SplObjectStorage<Box, int>
     */
    protected SplObjectStorage $boxQuantitiesAvailable;

    protected PackedBoxSorter $packedBoxSorter;

    protected bool $quantityShortCircuit = false;

    /**
     * Headroom, in copies per signature, added on top of a box's physical capacity when bounding the item list handed
     * to a single box evaluation (see itemsForBoxEvaluation()). Orientation choice consults a forward-looking window
     * over the next few items, so supplying only `capacity` copies could change how a box packs versus the uncapped
     * run; supplying `capacity` + this headroom guarantees that window is always identical to the uncapped run.
     *
     * MUST be kept equal to (or greater than) the topN() lookahead depth used in
     * OrientatedItemSorter::calculateAdditionalItemsPackedWithThisOrientation().
     */
    private const LOOKAHEAD_DEPTH = 8;

    private bool $beStrictAboutItemOrdering = false;

    public function __construct()
    {
        $this->items = new ItemList();
        $this->boxes = new BoxList();
        $this->boxQuantitiesAvailable = new SplObjectStorage();
        $this->packedBoxSorter = new DefaultPackedBoxSorter();

        $this->logger = new NullLogger();
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Add item to be packed.
     */
    public function addItem(Item $item, int $qty = 1): void
    {
        $this->items->insert($item, $qty);
        $this->logger->log(LogLevel::INFO, "added {$qty} x {$item->getDescription()}", ['item' => $item]);
    }

    /**
     * Set a list of items all at once.
     * @param iterable<Item> $items
     */
    public function setItems(iterable $items): void
    {
        if ($items instanceof ItemList) {
            $this->items = clone $items;
        } else {
            $this->items = new ItemList();
            foreach ($items as $item) {
                $this->items->insert($item);
            }
        }
    }

    /**
     * Add box size.
     */
    public function addBox(Box $box): void
    {
        $this->boxes->insert($box);
        $this->setBoxQuantity($box, $box instanceof LimitedSupplyBox ? $box->getQuantityAvailable() : PHP_INT_MAX);
        $this->logger->log(LogLevel::INFO, "added box {$box->getReference()}", ['box' => $box]);
    }

    /**
     * Add a pre-prepared set of boxes all at once.
     */
    public function setBoxes(BoxList $boxList): void
    {
        $this->boxes = $boxList;
        foreach ($this->boxes as $box) {
            $this->setBoxQuantity($box, $box instanceof LimitedSupplyBox ? $box->getQuantityAvailable() : PHP_INT_MAX);
        }
    }

    /**
     * Set the quantity of this box type available.
     */
    public function setBoxQuantity(Box $box, int $qty): void
    {
        $this->boxQuantitiesAvailable[$box] = $qty;
    }

    /**
     * Number of boxes at which balancing weight is deemed not worth the extra computation time.
     */
    public function getMaxBoxesToBalanceWeight(): int
    {
        return $this->maxBoxesToBalanceWeight;
    }

    /**
     * Number of boxes at which balancing weight is deemed not worth the extra computation time.
     */
    public function setMaxBoxesToBalanceWeight(int $maxBoxesToBalanceWeight): void
    {
        $this->maxBoxesToBalanceWeight = $maxBoxesToBalanceWeight;
    }

    public function setPackedBoxSorter(PackedBoxSorter $packedBoxSorter): void
    {
        $this->packedBoxSorter = $packedBoxSorter;
    }

    public function beStrictAboutItemOrdering(bool $beStrict): void
    {
        $this->beStrictAboutItemOrdering = $beStrict;
    }

    /**
     * Enable/disable the large-quantity short-circuit optimisation.
     *
     * When enabled, packing large numbers of identical (or few distinct) items becomes dramatically faster: each box
     * evaluation is bounded to the number of items that could physically fit, and once a box has been solved its exact
     * makeup is replicated for as many further boxfuls as the remaining stock allows rather than being re-solved. The
     * resulting set of boxes is identical to packing with it disabled.
     *
     * Disabled by default. Has no effect when items require constrained placement or when strict item ordering has
     * been requested - in those cases packing proceeds exactly as if it were disabled. When a custom PackedBoxSorter
     * is configured, per-box item capping still applies but identical-box replication is disabled.
     */
    public function setQuantityShortCircuit(bool $quantityShortCircuit): void
    {
        $this->quantityShortCircuit = $quantityShortCircuit;
    }

    /**
     * Pack items into boxes using built-in heuristics for the best solution.
     */
    public function pack(): PackedBoxList
    {
        $this->logger->log(LogLevel::INFO, '[PACKING STARTED]');

        $packedBoxes = $this->doBasicPacking();

        // If we have multiple boxes, try and optimise/even-out weight distribution
        if (!$this->beStrictAboutItemOrdering && $packedBoxes->count() > 1 && $packedBoxes->count() <= $this->maxBoxesToBalanceWeight) {
            $redistributor = new WeightRedistributor($this->boxes, $this->packedBoxSorter, $this->boxQuantitiesAvailable);
            $redistributor->setLogger($this->logger);
            $packedBoxes = $redistributor->redistributeWeight($packedBoxes);
        }

        $this->logger->log(LogLevel::INFO, "[PACKING COMPLETED], {$packedBoxes->count()} boxes");

        return $packedBoxes;
    }

    /**
     * @internal
     */
    public function doBasicPacking(bool $enforceSingleBox = false): PackedBoxList
    {
        $packedBoxes = new PackedBoxList($this->packedBoxSorter);

        // The short-circuit optimisation cannot be applied when placement depends on what else is in the box
        // (constrained items) or when the caller has asked for the item ordering to be preserved exactly. These
        // properties do not change during packing, so it is determined once up front.
        $shortCircuit = $this->quantityShortCircuit
            && !$this->beStrictAboutItemOrdering
            && !$this->itemsHaveConstraints();

        // Keep going until everything packed
        while ($this->items->count()) {
            $packedBoxesIteration = [];
            $signatureData = $shortCircuit ? $this->items->getSignatureData() : [];

            // Loop through boxes starting with smallest, see what happens
            foreach ($this->getBoxList($enforceSingleBox) as $box) {
                $itemsForBox = $shortCircuit ? $this->itemsForBoxEvaluation($box, $signatureData) : $this->items;
                $volumePacker = new VolumePacker($box, $itemsForBox);
                $volumePacker->setLogger($this->logger);
                $volumePacker->beStrictAboutItemOrdering($this->beStrictAboutItemOrdering);
                $packedBox = $volumePacker->pack();
                if ($packedBox->getItems()->count()) {
                    $packedBoxesIteration[] = $packedBox;

                    // Have we found a single box that contains everything?
                    if ($packedBox->getItems()->count() === $this->items->count()) {
                        $this->logger->log(LogLevel::DEBUG, "Single box found for remaining {$this->items->count()} items");
                        break;
                    }
                }
            }

            if (count($packedBoxesIteration) > 0) {
                // Find best box of iteration, and remove packed items from unpacked list
                usort($packedBoxesIteration, [$this->packedBoxSorter, 'compare']);
                $bestBox = $packedBoxesIteration[0];

                $this->items->removePackedItems($bestBox->getItems());

                $packedBoxes->insert($bestBox);
                $this->boxQuantitiesAvailable[$bestBox->getBox()] = $this->boxQuantitiesAvailable[$bestBox->getBox()] - 1;

                // Having solved one box, replicate its exact makeup for as many further identical boxfuls as the
                // remaining stock of both items and boxes allows, rather than re-solving each one from scratch.
                if ($shortCircuit) {
                    foreach ($this->replicateIdenticalBoxes($bestBox) as $replica) {
                        $packedBoxes->insert($replica);
                    }
                }
            } elseif (!$enforceSingleBox) {
                throw new NoBoxesAvailableException("No boxes could be found for item '{$this->items->top()->getDescription()}'", $this->items->top());
            } else {
                $this->logger->log(LogLevel::INFO, "{$this->items->count()} unpackable items found");
                break;
            }
        }

        return $packedBoxes;
    }

    /**
     * Get a "smart" ordering of the boxes to try packing items into. The initial BoxList is already sorted in order
     * so that the smallest boxes are evaluated first, but this means that time is spent on boxes that cannot possibly
     * hold the entire set of items due to volume limitations. These should be evaluated first.
     *
     * @return iterable<Box>
     */
    protected function getBoxList(bool $enforceSingleBox = false): iterable
    {
        $this->logger->log(LogLevel::INFO, 'Determining box search pattern', ['enforceSingleBox' => $enforceSingleBox]);
        $itemVolume = 0;
        foreach ($this->items as $item) {
            $itemVolume += $item->getWidth() * $item->getLength() * $item->getDepth();
        }
        $this->logger->log(LogLevel::DEBUG, 'Item volume', ['itemVolume' => $itemVolume]);

        $preferredBoxes = [];
        $otherBoxes = [];
        foreach ($this->boxes as $box) {
            if ($this->boxQuantitiesAvailable[$box] > 0) {
                if ($box->getInnerWidth() * $box->getInnerLength() * $box->getInnerDepth() >= $itemVolume) {
                    $preferredBoxes[] = $box;
                } elseif (!$enforceSingleBox) {
                    $otherBoxes[] = $box;
                }
            }
        }

        $this->logger->log(LogLevel::INFO, 'Box search pattern complete', ['preferredBoxCount' => count($preferredBoxes), 'otherBoxCount' => count($otherBoxes)]);

        return [...$preferredBoxes, ...$otherBoxes];
    }

    /**
     * Whether any item has placement constraints that make it unsafe to short-circuit. Covers both the current
     * ConstrainedPlacementItem interface and the deprecated ConstrainedItem interface.
     */
    private function itemsHaveConstraints(): bool
    {
        if ($this->items->hasConstrainedItems()) {
            return true;
        }

        foreach ($this->items as $item) {
            if ($item instanceof ConstrainedItem) {
                return true;
            }
        }

        return false;
    }

    /**
     * Bound the set of items handed to the volume packer for a single box evaluation.
     *
     * A box can only ever physically hold a limited number of copies of each distinct item type (limited by volume,
     * and by weight). Supplying only that many copies is not quite safe, however: orientation choice consults a
     * forward-looking window over the next up-to-LOOKAHEAD_DEPTH items (ItemList::topN(), driven by
     * OrientatedItemSorter::calculateAdditionalItemsPackedWithThisOrientation()). If a signature's per-box capacity is
     * below that depth while more copies remain in the pool, handing the packer only `capacity` copies would leave the
     * lookahead window short of the copies the uncapped run sees, admitting different (smaller) items into it and
     * potentially changing an orientation - and therefore the packed result.
     *
     * Supplying `capacity + LOOKAHEAD_DEPTH` copies closes that gap: at most `capacity` copies of a signature can ever
     * be placed in the box, so at every placement decision at least LOOKAHEAD_DEPTH copies of each still-surplus
     * signature remain available to fill the window exactly as the uncapped run would, while the cost of evaluating a
     * box stays independent of the total quantity remaining to be packed. If no signature needs capping for this box
     * the full list is returned unchanged so behaviour is preserved exactly.
     *
     * @param array<string, array{item: Item, count: int}> $signatureData signature data derived from $this->items
     */
    private function itemsForBoxEvaluation(Box $box, array $signatureData): ItemList
    {
        $caps = [];
        $needsCap = false;
        foreach ($signatureData as $signature => $data) {
            $cap = $this->perBoxCapacity($box, $data['item']) + self::LOOKAHEAD_DEPTH;
            $caps[$signature] = $cap;
            if ($cap < $data['count']) {
                $needsCap = true;
            }
        }

        if (!$needsCap) {
            return $this->items;
        }

        return $this->items->cappedBySignature($caps);
    }

    /**
     * Upper bound on how many copies of an item a box could ever hold, by volume and by weight. The real geometric
     * limit may be lower, never higher.
     */
    private function perBoxCapacity(Box $box, Item $item): int
    {
        $innerVolume = $box->getInnerWidth() * $box->getInnerLength() * $box->getInnerDepth();
        $unitVolume = max($item->getWidth() * $item->getLength() * $item->getDepth(), 1);
        $capacity = intdiv($innerVolume, $unitVolume);
        if ($item->getWeight() > 0) {
            $capacity = min($capacity, intdiv($box->getMaxWeight() - $box->getEmptyWeight(), $item->getWeight()));
        }

        return max($capacity, 0);
    }

    /**
     * Given a box that has just been packed and removed from the pool, produce as many identical copies of it as can
     * be proven to match what the normal packing loop would have produced.
     *
     * A replica standing in for a later iteration is only valid if that iteration's evaluations would have been
     * byte-for-byte the template's. Each box evaluation sees min(count, capacity + LOOKAHEAD_DEPTH) copies of each
     * signature (itemsForBoxEvaluation), so an iteration whose pool still holds at least
     * maxCapacityAcrossInStockBoxes + LOOKAHEAD_DEPTH copies of every constituent signature sees exactly the same
     * capped list for every box type as the template's iteration did - the same winner with the same contents
     * necessarily follows. Only the constituent signatures deplete between iterations, so replication may continue
     * precisely while the pool the replaced iteration would have seen stays above that threshold; the remaining tail
     * boxes (where windows genuinely shrink and a different orientation, or even a different box, may win) are always
     * solved by the normal loop from the true pool, exactly as they would be with the optimisation disabled.
     *
     * Replication is restricted to the exact built-in DefaultPackedBoxSorter. A custom sorter (including a subclass)
     * may tie candidates that the default sorter distinguishes, making the winner depend on getBoxList()'s
     * pool-dependent evaluation order. Per-box item capping remains safe and enabled with custom sorters.
     *
     * Even the default sorter can tie unequal-volume boxes because volume utilisation is rounded. Replication must
     * therefore also stop before the shrinking item pool would move another box into getBoxList()'s preferred
     * partition and change the evaluation order used to resolve that tie.
     *
     * @return PackedBox[]
     */
    private function replicateIdenticalBoxes(PackedBox $template): array
    {
        if (get_class($this->packedBoxSorter) !== DefaultPackedBoxSorter::class) {
            return [];
        }

        $box = $template->getBox();
        $perBox = $template->getItems()->count();
        if ($perBox === 0 || $this->boxQuantitiesAvailable[$box] <= 0) {
            return [];
        }

        $boxCounts = [];
        foreach ($template->getItems() as $packedItem) {
            $signature = ItemList::signatureOf($packedItem->getItem());
            $boxCounts[$signature] = ($boxCounts[$signature] ?? 0) + 1;
        }

        $poolData = $this->items->getSignatureData();

        $replications = $this->boxQuantitiesAvailable[$box];

        $poolVolume = 0;
        foreach ($poolData as $data) {
            $item = $data['item'];
            $poolVolume += $data['count'] * $item->getWidth() * $item->getLength() * $item->getDepth();
        }
        $templateVolume = $template->getItems()->getVolume();
        $templateIterationVolume = $poolVolume + $templateVolume;

        $largestNonPreferredBoxVolume = null;
        foreach ($this->boxes as $candidateBox) {
            if ($this->boxQuantitiesAvailable[$candidateBox] > 0) {
                $boxVolume = $candidateBox->getInnerWidth() * $candidateBox->getInnerLength() * $candidateBox->getInnerDepth();
                if ($boxVolume < $templateIterationVolume) {
                    $largestNonPreferredBoxVolume = max($largestNonPreferredBoxVolume ?? 0, $boxVolume);
                }
            }
        }

        if ($largestNonPreferredBoxVolume !== null) {
            if ($poolVolume <= $largestNonPreferredBoxVolume) {
                return [];
            }
            if ($templateVolume > 0) {
                $replications = min(
                    $replications,
                    intdiv($poolVolume - $largestNonPreferredBoxVolume - 1, $templateVolume) + 1
                );
            }
        }

        foreach ($boxCounts as $signature => $need) {
            $data = $poolData[$signature] ?? null;
            if ($data === null) {
                return [];
            }

            $maxCapacity = 0;
            foreach ($this->boxes as $candidateBox) {
                if ($this->boxQuantitiesAvailable[$candidateBox] > 0) {
                    $maxCapacity = max($maxCapacity, $this->perBoxCapacity($candidateBox, $data['item']));
                }
            }

            // The k-th replica replaces an iteration whose pool holds $have - (k-1) * $need copies; that pool must
            // stay at or above the threshold for the replica to be provably identical to a real solve.
            $mustRemain = $maxCapacity + self::LOOKAHEAD_DEPTH;
            if ($data['count'] < $mustRemain) {
                return [];
            }
            $possible = intdiv($data['count'] - $mustRemain, $need) + 1;
            if ($possible < $replications) {
                $replications = $possible;
            }
        }
        if ($replications <= 0) {
            return [];
        }

        $clones = [];
        for ($i = 0; $i < $replications; ++$i) {
            $clones[] = new PackedBox($box, $template->getItems());
        }
        $this->boxQuantitiesAvailable[$box] -= $replications;

        $toRemove = [];
        foreach ($boxCounts as $signature => $need) {
            $toRemove[$signature] = $need * $replications;
        }
        $this->items->removeBySignatureMultiset($toRemove);

        return $clones;
    }
}
