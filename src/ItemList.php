<?php

/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

use ArrayIterator;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use Traversable;

use function array_key_last;
use function array_pop;
use function array_reverse;
use function array_slice;
use function count;
use function current;
use function end;
use function key;
use function prev;
use function usort;
use function strlen;

/**
 * List of items to be packed, ordered by volume.
 */
class ItemList implements Countable, IteratorAggregate
{
    /**
     * @var Item[]
     */
    private array $list = [];

    private bool $isSorted = false;

    private ?bool $hasConstrainedItems = null;

    private ?bool $hasNoRotationItems = null;

    /**
     * @var array<string, int>
     */
    private array $linkedGroupCounts = [];

    public function __construct(private readonly ItemSorter $sorter = new DefaultItemSorter())
    {
    }

    /**
     * Do a bulk create.
     *
     * @param Item[] $items
     */
    public static function fromArray(array $items, bool $preSorted = false): self
    {
        $list = new self();
        $list->list = array_reverse($items); // internal sort is largest at the end
        $list->isSorted = $preSorted;

        foreach ($items as $item) {
            if ($item instanceof LinkedItem) {
                $group = $item->getLinkedItemGroup();
                $list->linkedGroupCounts[$group] = ($list->linkedGroupCounts[$group] ?? 0) + 1;
            }
        }

        return $list;
    }

    public function insert(Item $item, int $qty = 1): void
    {
        if ($item instanceof LinkedItem && $item->getLinkedItemGroup() === '') {
            throw new InvalidArgumentException("Item '{$item->getDescription()}' has an empty linked item group, which is not allowed");
        }

        for ($i = 0; $i < $qty; ++$i) {
            $this->list[] = $item;
        }
        $this->isSorted = false;

        if (isset($this->hasConstrainedItems)) { // normally lazy evaluated, override if that's already been done
            $this->hasConstrainedItems = $this->hasConstrainedItems || $item instanceof ConstrainedPlacementItem;
        }

        if (isset($this->hasNoRotationItems)) { // normally lazy evaluated, override if that's already been done
            $this->hasNoRotationItems = $this->hasNoRotationItems || $item->getAllowedRotation() === Rotation::Never;
        }

        if ($item instanceof LinkedItem) {
            $group = $item->getLinkedItemGroup();
            $this->linkedGroupCounts[$group] = ($this->linkedGroupCounts[$group] ?? 0) + $qty;
        }
    }

    /**
     * Remove item from list.
     */
    public function remove(Item $item): void
    {
        if (!$this->isSorted) {
            usort($this->list, $this->sorter->compare(...));
            $this->list = array_reverse($this->list); // internal sort is largest at the end
            $this->isSorted = true;
        }

        end($this->list);
        do {
            if (current($this->list) === $item) {
                unset($this->list[key($this->list)]);
                if ($item instanceof LinkedItem) {
                    $group = $item->getLinkedItemGroup();
                    if (--$this->linkedGroupCounts[$group] === 0) {
                        unset($this->linkedGroupCounts[$group]);
                    }
                }
                $this->invalidateCachedPredicatesAfterRemoval();

                return;
            }
        } while (prev($this->list) !== false);
    }

    public function removePackedItems(PackedItemList $packedItemList): void
    {
        foreach ($packedItemList as $packedItem) {
            end($this->list);
            do {
                if (current($this->list) === $packedItem->item) {
                    unset($this->list[key($this->list)]);
                    if ($packedItem->item instanceof LinkedItem) {
                        $group = $packedItem->item->getLinkedItemGroup();
                        if (--$this->linkedGroupCounts[$group] === 0) {
                            unset($this->linkedGroupCounts[$group]);
                        }
                    }

                    break;
                }
            } while (prev($this->list) !== false);
        }
        $this->invalidateCachedPredicatesAfterRemoval();
    }

    /**
     * The cached hasConstrainedItems/hasNoRotationItems predicates are maintained on insert, but a removal may take
     * out the last constrained/unrotatable item, so a cached `true` can no longer be trusted afterwards and must be
     * recomputed on next access. A cached `false` stays correct - removals cannot add items - and is kept to avoid
     * needless rescans.
     */
    private function invalidateCachedPredicatesAfterRemoval(): void
    {
        if ($this->hasConstrainedItems === true) {
            $this->hasConstrainedItems = null;
        }
        if ($this->hasNoRotationItems === true) {
            $this->hasNoRotationItems = null;
        }
    }

    /**
     * @internal
     */
    public function extract(): Item
    {
        if (!$this->isSorted) {
            usort($this->list, $this->sorter->compare(...));
            $this->list = array_reverse($this->list); // internal sort is largest at the end
            $this->isSorted = true;
        }

        $item = array_pop($this->list);
        $this->invalidateCachedPredicatesAfterRemoval();

        return $item;
    }

    /**
     * @internal
     */
    public function top(): Item
    {
        if (!$this->isSorted) {
            usort($this->list, $this->sorter->compare(...));
            $this->list = array_reverse($this->list); // internal sort is largest at the end
            $this->isSorted = true;
        }

        return $this->list[array_key_last($this->list)];
    }

    /**
     * @internal
     */
    public function topN(int $n): self
    {
        if (!$this->isSorted) {
            usort($this->list, $this->sorter->compare(...));
            $this->list = array_reverse($this->list); // internal sort is largest at the end
            $this->isSorted = true;
        }

        $topNList = new self();
        $topNList->list = array_slice($this->list, -$n, $n);
        $topNList->isSorted = true;

        foreach ($topNList->list as $item) {
            if ($item instanceof LinkedItem) {
                $group = $item->getLinkedItemGroup();
                $topNList->linkedGroupCounts[$group] = ($topNList->linkedGroupCounts[$group] ?? 0) + 1;
            }
        }

        return $topNList;
    }

    /**
     * @return Traversable<Item>
     */
    public function getIterator(): Traversable
    {
        if (!$this->isSorted) {
            usort($this->list, $this->sorter->compare(...));
            $this->list = array_reverse($this->list); // internal sort is largest at the end
            $this->isSorted = true;
        }

        return new ArrayIterator(array_reverse($this->list));
    }

    /**
     * Number of items in list.
     */
    public function count(): int
    {
        return count($this->list);
    }

    /**
     * Does this list contain items with constrained placement criteria.
     */
    public function hasConstrainedItems(): bool
    {
        if (!isset($this->hasConstrainedItems)) {
            $this->hasConstrainedItems = false;
            foreach ($this->list as $item) {
                if ($item instanceof ConstrainedPlacementItem) {
                    $this->hasConstrainedItems = true;
                    break;
                }
            }
        }

        return $this->hasConstrainedItems;
    }

    /**
     * Does this list contain items which cannot be rotated.
     */
    public function hasNoRotationItems(): bool
    {
        if (!isset($this->hasNoRotationItems)) {
            $this->hasNoRotationItems = false;
            foreach ($this->list as $item) {
                if ($item->getAllowedRotation() === Rotation::Never) {
                    $this->hasNoRotationItems = true;
                    break;
                }
            }
        }

        return $this->hasNoRotationItems;
    }

    /**
     * Does this list contain items that must be packed in the same box as other items.
     */
    public function hasLinkedItems(): bool
    {
        return count($this->linkedGroupCounts) > 0;
    }

    /**
     * Get a map of linked group identifier => count of items in this list belonging to that group.
     *
     * @internal
     *
     * @return array<string, int>
     */
    public function getLinkedGroupCounts(): array
    {
        return $this->linkedGroupCounts;
    }

    /**
     * Generate a value-based signature for an item. Two items sharing a signature are interchangeable for packing
     * purposes - they have identical dimensions, weight and rotation constraints.
     *
     * The description is length-prefixed (strlen($description) . ':' . $description) before being joined with the
     * remaining fields using '|'. Width/length/depth/weight are ints and rotation is an int-backed enum, so none of
     * them can ever contain '|' - only the description is free-form text, and the length prefix pins down exactly
     * where it ends regardless of what characters it contains, making the signature collision-free for any
     * description.
     *
     * @internal
     */
    public static function signatureOf(Item $item): string
    {
        $description = $item->getDescription();

        return strlen($description) . ':' . $description . '|' . $item->getWidth() . '|' . $item->getLength() . '|' . $item->getDepth() . '|' . $item->getWeight() . '|' . $item->getAllowedRotation()->value;
    }

    /**
     * Get a map of item signature => [a representative item, count of items in this list with that signature].
     * The representative item is used to recover the dimensions/weight for a signature without having to parse the
     * signature string.
     *
     * @internal
     *
     * @return array<string, array{item: Item, count: int}>
     */
    public function getSignatureData(): array
    {
        $data = [];
        foreach ($this->list as $item) {
            $signature = self::signatureOf($item);
            if (isset($data[$signature])) {
                ++$data[$signature]['count'];
            } else {
                $data[$signature] = ['item' => $item, 'count' => 1];
            }
        }

        return $data;
    }

    /**
     * Return a copy of this list containing at most $caps[signature] items of each signature. Used to bound the amount
     * of work handed to the volume packer - a box can only ever hold so many copies of a given item type, so there is
     * no point evaluating more than that regardless of the total quantity remaining to be packed.
     *
     * @internal
     *
     * @param array<string, int> $caps
     */
    public function cappedBySignature(array $caps): self
    {
        if (!$this->isSorted) {
            usort($this->list, $this->sorter->compare(...));
            $this->list = array_reverse($this->list); // internal sort is largest at the end
            $this->isSorted = true;
        }

        $capped = new self($this->sorter);
        $used = [];
        foreach ($this->list as $item) {
            $signature = self::signatureOf($item);
            if (($used[$signature] ?? 0) < ($caps[$signature] ?? 0)) {
                $used[$signature] = ($used[$signature] ?? 0) + 1;
                $capped->list[] = $item;
                if ($item instanceof LinkedItem) {
                    $group = $item->getLinkedItemGroup();
                    $capped->linkedGroupCounts[$group] = ($capped->linkedGroupCounts[$group] ?? 0) + 1;
                }
            }
        }
        $capped->isSorted = true;

        return $capped;
    }

    /**
     * Remove a fixed number of items of each given signature from this list. Used when an already-solved box is
     * replicated multiple times - the items destined for those (identical) boxes are removed in bulk rather than
     * re-running the packer once per box.
     *
     * @internal
     *
     * @param array<string, int> $toRemove signature => number of items to remove
     */
    public function removeBySignatureMultiset(array $toRemove): void
    {
        if (count($toRemove) === 0) {
            return;
        }

        if (!$this->isSorted) {
            usort($this->list, $this->sorter->compare(...));
            $this->list = array_reverse($this->list); // internal sort is largest at the end
            $this->isSorted = true;
        }

        $remaining = $toRemove;
        foreach ($this->list as $key => $item) {
            $signature = self::signatureOf($item);
            if (($remaining[$signature] ?? 0) > 0) {
                --$remaining[$signature];
                unset($this->list[$key]);
                if ($item instanceof LinkedItem) {
                    $group = $item->getLinkedItemGroup();
                    if (--$this->linkedGroupCounts[$group] === 0) {
                        unset($this->linkedGroupCounts[$group]);
                    }
                }
            }
        }
        $this->invalidateCachedPredicatesAfterRemoval();
    }
}
