# InvoiceShelf E-Invoicing Fork

InvoiceShelf with support for issuing German/EU electronic invoices. This glossary pins the e-invoicing vocabulary used in issues, specs, and code.

## Language

**E-Invoice (E-Rechnung)**:
An invoice in a structured electronic format conforming to EN 16931, as defined by German law (§ 14 UStG). A plain PDF is *not* an e-invoice.
_Avoid_: digital invoice, electronic PDF

**ZUGFeRD**:
The hybrid e-invoice format: a human-readable PDF/A-3 with an embedded machine-readable XML (UN/CEFACT CII). Technically identical to Factur-X.
_Avoid_: Zugpferd, ZUGFerd

**Hybrid PDF**:
An invoice PDF that carries the embedded ZUGFeRD XML. To recipients without e-invoicing software it behaves like any ordinary invoice PDF.
_Avoid_: e-invoice PDF, smart PDF

**Profile EN 16931 (COMFORT)**:
The ZUGFeRD profile this project targets. The smallest profile that is fully EN 16931-compliant and thus satisfies the German B2B mandate.
_Avoid_: COMFORT (alone, ambiguous with older ZUGFeRD 1.x)

**XRechnung**:
Germany's pure-XML e-invoice format (CII or UBL, no PDF), required when invoicing German public-sector customers (B2G).
_Avoid_: X-Rechnung

**E-Invoice Ready**:
The state of a company whose master data is complete enough to produce valid e-invoices (bank details, VAT data, address). Checked when the feature is enabled and surfaced in settings.
_Avoid_: configured, complete

**Fallback**:
What happens when an invoice cannot produce valid XML: the ordinary PDF is generated without embedded XML and a warning is shown. An invalid XML is never embedded.
_Avoid_: degraded mode

**Missing Requirements**:
What the E-Invoice builder answers with instead of XML: the named inputs an invoice still lacks before it can become an E-Invoice (seller IBAN, buyer country, exemption reason, …). The same list drives the E-Invoice Ready indicator and the Fallback decision.
_Avoid_: errors, validation messages

**Tax Category Code**:
The EN 16931 code classifying a tax rate (S = standard, E = exempt, AE = reverse charge, …). Stored per tax type; exempt categories carry an exemption reason text (e.g. § 19 UStG).
_Avoid_: tax code, VAT type

**Unit Code**:
The UN/ECE Rec 20 code identifying a line item's unit of measure (C62 = piece, HUR = hour, KGM = kilogram). Stored per unit, default C62.
_Avoid_: unit type
