<?php

namespace App\Domains\Sales\Contracts;

use App\Domains\Sales\Models\Invoice;
use App\Platform\Pdf\Rendering\FacturXAttachment;

/**
 * Supplies the e-invoice attachment an invoice's PDF carries, or null for the
 * ordinary PDF.
 *
 * This is the seam between invoice PDF generation and e-invoicing: the invoice
 * pipeline asks one question here and knows nothing about how the answer is
 * produced. The default implementation is the Sales domain's
 * EInvoiceAttachmentResolver (bound in SalesServiceProvider); an extension —
 * for instance an e-invoice module — replaces the behaviour by rebinding this
 * contract, without touching the pipeline.
 *
 * @see docs/architecture/0002-einvoice-gotenberg-container-horstoeko-xml.md
 */
interface InvoicePdfAttachmentResolver
{
    public function resolve(Invoice $invoice): ?FacturXAttachment;
}
