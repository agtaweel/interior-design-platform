<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when code attempts to save a tenant-scoped model with an organization_id that does
 * not match the currently resolved tenant context — e.g. a controller accidentally (or a
 * malicious client deliberately) passing another organization's id in a request body. See
 * App\Models\Concerns\BelongsToOrganization for where this is raised, and
 * bootstrap/app.php for how it is rendered as a 403 in the API error envelope.
 */
class TenantMismatchException extends RuntimeException
{
    //
}
