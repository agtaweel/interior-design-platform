<?php

namespace App\Services\Marketplace;

use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Collection;

/**
 * BRD v4 "Client Marketplace" Phase C — the org-side hand-off from an inquiry conversation to a
 * real `Client` contact record staff can attach a project to. `clients.email` carries no
 * uniqueness constraint (clients were never required to have unique emails before the
 * marketplace existed), so a ClientUser's email can match zero, one, or many existing Client
 * rows within one organization:
 *
 *  - Zero matches: create a brand-new Client row, linked immediately.
 *  - Exactly one match: auto-link it (set client_user_id) rather than creating a duplicate
 *    contact for someone staff already has a record for.
 *  - More than one match: too ambiguous to guess — returned as candidates for staff to resolve,
 *    rather than silently picking one or creating a third duplicate.
 */
class ClientUserLinkingService
{
    /**
     * @return array{client: ?Client, candidates: ?Collection<int, Client>}
     */
    public function findOrCreateClient(Organization $organization, ClientUser $clientUser): array
    {
        $matches = Client::query()
            ->where('organization_id', $organization->id)
            ->where('email', $clientUser->email)
            ->get();

        if ($matches->count() > 1) {
            return ['client' => null, 'candidates' => $matches];
        }

        if ($matches->count() === 1) {
            $client = $matches->first();

            if (! $client->client_user_id) {
                $client->forceFill(['client_user_id' => $clientUser->id])->save();
            }

            return ['client' => $client, 'candidates' => null];
        }

        $client = Client::create([
            'organization_id' => $organization->id,
            'client_user_id' => $clientUser->id,
            'name' => $clientUser->name,
            'email' => $clientUser->email,
            'phone' => $clientUser->phone,
        ]);

        return ['client' => $client, 'candidates' => null];
    }
}
