<?php

namespace App\Services\Marketplace;

use App\Models\Organization;
use App\Models\OrganizationProfile;
use RuntimeException;

/**
 * BRD v4 "Client Marketplace" — manages an organization's public listing profile. Deliberately
 * refuses to flip `is_marketplace_listed` to true for a profile with no description: a bare
 * name+logo listing gives a browsing client nothing to compare, which was the whole reason the
 * marketplace listing decision was "opt-in with a real profile," not "auto-list everyone."
 */
class OrganizationProfileService
{
    public function updateProfile(Organization $organization, array $data): OrganizationProfile
    {
        $profile = $organization->profile()->first() ?? new OrganizationProfile(['organization_id' => $organization->id]);
        $profile->fill($data);
        $profile->save();

        return $profile->refresh();
    }

    public function setListed(Organization $organization, bool $listed): OrganizationProfile
    {
        // Queries fresh rather than using $organization->profile (the cached relation accessor):
        // the controller calls updateProfile() then setListed() on the same $organization
        // instance within one request, and updateProfile() may have just created the row that
        // a prior cached-null access here would otherwise miss.
        $profile = $organization->profile()->first();

        if ($listed && (! $profile || ! trim((string) $profile->description))) {
            throw new RuntimeException('Add a description before listing this organization in the marketplace.');
        }

        $profile ??= new OrganizationProfile(['organization_id' => $organization->id]);
        $profile->is_marketplace_listed = $listed;
        $profile->save();

        return $profile->refresh();
    }
}
