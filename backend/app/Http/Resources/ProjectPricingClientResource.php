<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The client-facing seam for Sprint 3 pricing (PROJECT_CONTEXT.md "Client-facing exposure"):
 * wraps a Project and exposes ONLY the final client-facing price and when it was last priced —
 * no direct_cost_total, no client_subtotal, no rule list, no markup/fee/discount breakdown.
 * Mirrors the BoqItemResource-vs-"BoqItemClientResource" split called out in Sprint 2: internal
 * views and client-facing views of the same entity are always separate Resource classes so the
 * leak-prevention rule can't be accidentally bypassed with a stray `only`/`except` parameter.
 *
 * No route wires this up yet — there is no client-facing consumer until Sprint 4's client
 * portal/proposal views. This class exists now purely as the seam Sprint 4 plugs into, per this
 * sprint's explicit scope.
 *
 * grand_total null-coalesces to 0, same rationale as ProjectResource.financials.value: a client
 * should see "0" for a project that hasn't been priced yet, never a raw `null` that a frontend
 * would have to special-case.
 */
class ProjectPricingClientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'grand_total' => $this->grand_total ?? 0,
            'priced_at' => $this->priced_at,
        ];
    }
}
