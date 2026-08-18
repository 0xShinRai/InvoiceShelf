<?php

namespace App\Domains\Sales\Application\EInvoice;

use App\Domains\Sales\Contracts\InvoicePdfAttachmentResolver;
use App\Domains\Sales\Models\Invoice;
use App\Platform\Pdf\Rendering\FacturXAttachment;
use Throwable;

/**
 * Decides whether an invoice's PDF becomes a Hybrid PDF, and with which XML.
 *
 * This is the one place the Fallback is applied. It sits between the E-Invoice
 * builder — the single seam that answers "valid XML or missing requirements" —
 * and the PDF pipeline, so every delivery path (download, email attachment,
 * customer portal) inherits the same decision by going through the invoice's
 * shared generation path rather than repeating any of it.
 *
 * Three things have to hold before XML is embedded: the instance runs the
 * Gotenberg driver, the company has E-Invoicing switched on, and the builder
 * returned XML that passed XSD validation. Anything else yields null, which is
 * the ordinary PDF.
 *
 * The invoice pipeline reaches this class only through the
 * InvoicePdfAttachmentResolver contract it implements — everything under this
 * EInvoice namespace stays behind that seam, so moving it into a module means
 * moving the binding, not touching the pipeline.
 *
 * @see docs/architecture/0002-einvoice-gotenberg-container-horstoeko-xml.md
 */
class EInvoiceAttachmentResolver implements InvoicePdfAttachmentResolver
{
    public function __construct(
        private readonly EInvoiceSettings $settings,
        private readonly EInvoiceBuilder $builder,
    ) {}

    /**
     * The Factur-X attachment for an invoice, or null when the ordinary PDF
     * applies.
     */
    public function resolve(Invoice $invoice): ?FacturXAttachment
    {
        // E-Invoicing must never block billing (and never send an invalid
        // e-invoice), so anything unexpected on the way to the XML is reported
        // and turned into the Fallback rather than into a failed PDF. The
        // builder already does this for its own mapping; this covers the
        // settings lookup and everything around it.
        try {
            if (! $this->settings->isEnabledFor($invoice->company_id)) {
                return null;
            }

            $result = $this->builder->build($invoice);

            if (! $result->isValid()) {
                return null;
            }

            return FacturXAttachment::en16931((string) $result->xml());
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
