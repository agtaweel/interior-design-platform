<?php

namespace App\Services\Invoices;

use RuntimeException;

/**
 * Thrown by InvoiceUploadService when a new upload matches an existing InvoiceDocument on
 * either the file's own content (file_fingerprint) or the vendor/invoice/amount/date
 * combination (BRD v3 §7 "duplicate detection"). Carries the existing row's id so the
 * controller can surface it in the 409 response, letting staff decide whether to view/supersede
 * the existing document rather than silently rejecting with no way to act on it.
 */
class DuplicateInvoiceException extends RuntimeException
{
    public function __construct(public readonly int $existingInvoiceId)
    {
        parent::__construct('An invoice matching this file or vendor/invoice-number/amount/date already exists.');
    }
}
