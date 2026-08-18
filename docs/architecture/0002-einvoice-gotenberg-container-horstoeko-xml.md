# E-invoice container is built by Gotenberg, the XML by horstoeko/zugferd, integrated in core

Status: accepted

To issue ZUGFeRD e-invoices (hybrid PDF/A-3 with embedded EN 16931 CII XML), we split the work between two tools: **horstoeko/zugferd builds and XSD-validates the CII XML**, and **Gotenberg (≥ 8.34) builds the container** — its Chromium route accepts a `facturxXml` field alongside `pdfa=PDF/A-3b` and produces the complete hybrid PDF (embedded `factur-x.xml`, `AFRelationship`, Factur-X XMP) in a single request. The feature lives in **core** (`app/Platform/Pdf/*` and a Sales-domain service), not in a module, and **requires the Gotenberg PDF driver** — there is no DomPDF path.

## Considered options

- **horstoeko's `ZugferdDocumentPdfMerger` for embedding** — rejected: it stamps XMP/OutputIntent/AF onto an existing PDF but does not *convert* to PDF/A, and its free FPDI cannot parse PDFs with compressed xref streams. Output would not be reliably PDF/A-3 conformant.
- **A DomPDF path** — rejected: DomPDF has no real PDF/A-3 support (only an experimental metadata mode since v3.1). Supporting it would produce non-conformant e-invoices; an honest "requires Gotenberg" beats a broken second path.
- **Building it as a module** — rejected for now: the module system exists, but the PDF pipeline has no extension hooks, so a module would need core patches anyway. Core integration is the smaller diff and the upstream-friendly shape (PRs target `3.x`).

  *Update (2026-08): upstream declined core integration and is building its own e-invoice module, so the code is now structured for extraction. The invoice pipeline depends on the `InvoicePdfAttachmentResolver` contract (bound in `SalesServiceProvider`), and everything e-invoice-specific in the Sales domain lives under `Application/EInvoice/`. Moving the feature into a module means moving that directory plus the settings/readiness endpoints and rebinding the contract; the remaining core needs are the contract itself, the `FacturXAttachment` capability in the PDF platform, the `unit_code`/`tax_category_code` columns, and `horstoeko/zugferd` as a host dependency (module policy is host-provided-only).*

## Consequences

- E-invoicing is only available when the instance-global PDF driver is Gotenberg (≥ 8.34; `gotenberg/gotenberg-php` ≥ 2.23 in the lock file).
- The deferred XRechnung export reuses the horstoeko XML layer unchanged — only the container/delivery differs (pure XML file instead of hybrid PDF).
