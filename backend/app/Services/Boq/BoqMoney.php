<?php

namespace App\Services\Boq;

use App\Models\BoqItem;

/**
 * Tiny bcmath wrapper shared by the BOQ services below. Every BOQ money figure is a decimal
 * string (matching the `decimal:2` casts on BoqItem/BoqTemplateItem and the bcmath-based
 * `direct_cost`/`client_total` accessors on BoqItem) — this class exists purely so subtotal/
 * grand-total accumulation never drops into float arithmetic, per PROJECT_CONTEXT.md's money
 * rules ("decimal types only, never floating point").
 */
final class BoqMoney
{
    public const SCALE = 2;

    public static function zero(): string
    {
        return '0.00';
    }

    public static function add(string $a, string $b): string
    {
        return bcadd($a, $b, self::SCALE);
    }

    /**
     * Sums an accessor (e.g. 'direct_cost' or 'client_total') across a collection of BoqItem
     * models using bcmath throughout, never a float-based array_sum().
     *
     * @param  iterable<BoqItem>  $items
     */
    public static function sumAccessor(iterable $items, string $accessor): string
    {
        $total = self::zero();

        foreach ($items as $item) {
            $total = self::add($total, (string) $item->{$accessor});
        }

        return $total;
    }
}
