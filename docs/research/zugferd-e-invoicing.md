# ZUGFeRD-/E-Rechnungs-Unterstützung für InvoiceShelf 3.x — Recherche

> **Ablageort:** Das Repo hatte bislang keine Konvention für Recherche-Notizen; `docs/research/` wird
> hiermit als Heimat dafür eingeführt (neben `docs/agents/` und `docs/architecture/`).
>
> **Stand:** 2026-08-15, Branch `3.x` (Version 3.0.0-alpha.2). Alle Aussagen sind mit Primärquelle
> (URL bzw. Dateipfad + Zeile) belegt.

---

## TL;DR — Architektur-Empfehlung

1. **XML-Erzeugung:** CII-XML im Profil **EN 16931** (ggf. EXTENDED/XRECHNUNG) mit
   [`horstoeko/zugferd`](https://github.com/horstoeko/zugferd) bauen (MIT, sehr aktiv gepflegt,
   PHP `>=7.3` ohne Obergrenze → PHP 8.4 ok, unterstützt alle ZUGFeRD-Profile inkl. XRechnung 3.0,
   nur CII-Syntax). Die Laravel-Bridge `horstoeko/zugferd-laravel` ist optionaler Komfort, kein Muss.

2. **PDF/A-3 + Einbettung — der überraschende Befund:** **Gotenberg erledigt seit v8.34.0 (06/2026)
   den kompletten Container-Teil nativ in einem einzigen Request**: Chromium-HTML→PDF mit
   `pdfa=PDF/A-3b` (Konvertierung via LibreOffice) **plus** `facturxXml`-Feld, das das XML als
   `factur-x.xml` mit korrektem `AFRelationship` **und Factur-X-XMP-Extension-Schema** einbettet
   (XMP via QPDF, Embedding via pdfcpu). Wird Factur-X angefordert, erzwingt Gotenberg automatisch
   PDF/A-3b ([Release v8.34.0](https://github.com/gotenberg/gotenberg/releases/tag/v8.34.0)).
   Der PHP-Client bietet dafür `->facturX(new FacturX(...))` ab **v2.23.0** — die Constraint
   `gotenberg/gotenberg-php: ^2.8` in `composer.json` deckt das ab, aber **`composer.lock` pinnt
   aktuell v2.18.0** → ein `composer update gotenberg/gotenberg-php` genügt (kein Major-Bump).
   Das Docker-Setup nutzt den Floating-Tag `gotenberg/gotenberg:8`
   (`docker/development/docker-compose.sqlite.gotenberg.yml:64`) — für Reproduzierbarkeit auf
   `>=8.34` pinnen.

3. **Empfohlene Pipeline** (Gotenberg-Treiber): Einhängepunkt ist
   `App\Platform\Pdf\Rendering\GotenbergPdfDriver::buildRequest()`
   (`app/Platform/Pdf/Rendering/GotenbergPdfDriver.php:27-108`) — dort wird heute schon
   `->pdfa($pdfa)` gesetzt (Z. 75-77); analog `->facturX(...)` mit dem von horstoeko erzeugten XML
   ergänzen. Damit ist **jeder** Auslieferungsweg abgedeckt, denn Stream, Mail-Anhang und
   Media-Library-Speicherung laufen alle durch denselben `ResponseStream` (siehe Abschnitt 4).

4. **DomPDF-Fallback ist keiner:** dompdf hat seit v3.1.0 nur einen *experimentellen*
   PDF/A-3b-Modus („only takes care of adding the required metadata", kein Font-Zwang, kein
   Factur-X-XMP) — für rechtssichere ZUGFeRD-Ausgabe ungeeignet. Der alternative Weg
   (horstoeko `ZugferdDocumentPdfMerger` als Post-Processing auf beliebigem Treiber-Output)
   stempelt ebenfalls nur PDF/A-Strukturen (XMP, OutputIntent, /AF), **konvertiert aber nicht**
   — echtes PDF/A entsteht nur, wenn die Eingabe-PDF bereits konform-nah ist.
   **Konsequenz: ZUGFeRD-Ausgabe an `PDF_DRIVER=gotenberg` koppeln.**

5. **Datenmodell-Lücken** (Abschnitt 4.4): Es fehlen v. a. Verkäufer-IBAN/Zahlungsweg (BG-16/BT-84),
   Leitweg-ID/Buyer Reference (BT-10, Pflicht für XRechnung), elektronische Adressen (BT-34/BT-49),
   USt-Kategorie-Codes je Steuersatz (UNCL 5305), UN/ECE-Mengeneinheiten-Codes (Rec. 20;
   `unit_name` ist Freitext) und eine strukturierte USt-IdNr. des Kunden (nur generisches
   `tax_id`-Feld). Firmenseitig existiert `companies.vat_id` bereits.

6. **Rechtlicher Rahmen:** Seit 01.01.2025 Empfangspflicht für E-Rechnungen im inländischen B2B;
   Ausstellungspflicht phasenweise (ab 2027 für > 800 k€ Vorjahresumsatz, ab 2028 für alle).
   ZUGFeRD **ab 2.0.1 außer MINIMUM/BASIC-WL** gilt als E-Rechnung; bei Hybridformaten ist das
   **XML der führende Teil** (BMF-Schreiben 15.10.2024 und 15.10.2025). Abweichungen zwischen
   PDF-Bild und XML riskieren § 14c UStG → PDF und XML müssen aus denselben Daten erzeugt werden.

7. **Validierung in CI:** Mustang-CLI (`--action validate`, prüft XML + PDF/A-3-Container via
   eingebettetem veraPDF, Exit-Code 0/≠0), KoSIT-Validator (XRechnung/EN-16931-XML) und
   veraPDF (`ghcr.io/verapdf/cli`) — alle headless/CI-tauglich (Java bzw. Docker).

---

## 1. ZUGFeRD / Factur-X / EN 16931 / XRechnung — Landschaft und Rechtslage

### 1.1 Standard und Versionen

ZUGFeRD ist ein **Hybridformat**: eine PDF/A-3-Datei (ISO 19005-3; Konformitätsstufen a/b/u
zulässig) mit eingebettetem XML in **UN/CEFACT-CII-Syntax**. Das PDF ist der menschenlesbare, das
XML der strukturierte Teil ([ferd-net.de/standards/zugferd](https://www.ferd-net.de/standards/zugferd)).
Dateiname des eingebetteten XML: seit ZUGFeRD 2.1 / Factur-X einheitlich **`factur-x.xml`**
(ZUGFeRD 1.0: `ZUGFeRD-invoice.xml`, 2.0: `zugferd-invoice.xml`; für das XRECHNUNG-Referenzprofil
ist `xrechnung.xml` vorgesehen — Letzteres nur über Sekundärquellen bestätigt, da das Spec-PDF
hinter einem E-Mail-Gate liegt; z. B. [pdflib.com](https://www.pdflib.com/de/pdf-know-how/zugferd-und-factur-x/)).

Aktuelle Version: **ZUGFeRD 2.5.2 / Factur-X 1.09.2** (veröffentlicht 04.08.2026, gültig ab
01.09.2026); davor 2.4/1.08 (12/2025, CII D22B, rückwärtskompatibel zu D16B) und 2.3.x (2024/25)
([ferd-net.de Download](https://www.ferd-net.de/download-zugferd),
[Publikationsseite 2.5.2](https://www.ferd-net.de/publikationen-produkte/publikationen/detailseite/zugferd-252-deutsch),
[Pressemitteilung FeRD/FNFE 04.12.2025](https://fnfe-mpe.org/wp-content/uploads/2025/12/2025-12-04_ZUGFeRD_2.4_Factur-X_1.08_Meldung_DE.pdf)).
Das „Infopaket" von ferd-net.de enthält Spezifikation, XMP-Extension-Schema, XSD- und
Schematron-Artefakte je Profil sowie Beispielrechnungen.

### 1.2 ZUGFeRD ↔ Factur-X

Seit ZUGFeRD 2.1 / Factur-X 1.0 sind beide **derselbe, technisch identische Standard**, gemeinsam
gepflegt von **FeRD** (Deutschland, unter dem Dach der AWV e.V.) und **FNFE-MPE** (Frankreich),
stets paarweise versioniert. O-Ton der gemeinsamen Pressemitteilung: „Technisch sind ZUGFeRD und
Factur-X identisch." ([PM 04.12.2025](https://fnfe-mpe.org/wp-content/uploads/2025/12/2025-12-04_ZUGFeRD_2.4_Factur-X_1.08_Meldung_DE.pdf),
[fnfe-mpe.org/factur-x](https://fnfe-mpe.org/factur-x/): „Factur-X is the same standard as ZUGFeRD 2.5").

### 1.3 Profile

Quelle: [FeRD-FAQ](https://www.ferd-net.de/standards/zugferd-faq). Fünf Profile plus
Referenzprofil XRECHNUNG:

| Profil | Inhalt | Vollständige Rechnung nach UStG |
|---|---|---|
| MINIMUM | Käufer/Verkäufer, Gesamtbetrag, Gesamt-USt; keine USt-Aufschlüsselung — „Buchungshilfe" | **Nein** |
| BASIC WL | Kopf-/Fußdaten ohne Positionen — „Buchungshilfe" | **Nein** |
| BASIC | Untermenge der EN 16931-1 mit essenziellen Positionsdaten | Ja |
| EN 16931 („COMFORT") | Bildet die EN 16931-1 vollständig ab; FeRD-Empfehlung für EU-konforme E-Rechnungen | Ja |
| EXTENDED | Erweiterung über EN 16931 hinaus (mehrere Lieferorte, strukturierte Zahlungsbedingungen, seit 2.4 Unterpositionen) | Ja |
| XRECHNUNG (Referenzprofil, seit 2.1.1) | XML-Teil von der **KoSIT** spezifiziert (Standard XRechnung), nur CII-Syntax; FeRD liefert das XMP-Schema für die PDF-Einbettung | Ja |

**EN-16931-konform** im engeren Sinn sind BASIC (Teilmenge), EN 16931 und XRECHNUNG (CIUS);
EXTENDED ist eine „conformant extension". MINIMUM und BASIC WL sind laut FeRD ausdrücklich keine
vollständigen Rechnungen im umsatzsteuerlichen Sinn.

### 1.4 XRechnung vs. ZUGFeRD

**XRechnung** ist ein **reines XML-Format** (kein Hybrid): eine CIUS der EN 16931 mit optionaler
„Extension XRechnung", zulässig in **zwei Syntaxen — UBL 2.1 und UN/CEFACT CII** —, betrieben von
der KoSIT im Auftrag des IT-Planungsrats, **verpflichtend für B2G** (E-RechV des Bundes bzw.
Landesverordnungen, Umsetzung der RL 2014/55/EU)
([xeinkauf.de/xrechnung](https://xeinkauf.de/xrechnung/)). Aktuelle Version: **3.0.2**
(Spezifikation vom 20.06.2024; aktuelles normatives Bundle „3.0.2 Winter 2025/26", wirksam seit
31.01.2026; eine 4.x ist auf der offiziellen Versionsseite nicht angekündigt)
([Versionen und Bundles](https://xeinkauf.de/xrechnung/versionen-und-bundles/)).
ZUGFeRD verpackt im Referenzprofil XRECHNUNG eine KoSIT-konforme CII-XRechnung in das Hybridformat.

### 1.5 Deutsche Rechtslage (Wachstumschancengesetz)

**§ 14 UStG n.F.** ([gesetze-im-internet.de](https://www.gesetze-im-internet.de/ustg_1980/__14.html)):
Eine **E-Rechnung** wird „in einem strukturierten elektronischen Format ausgestellt, übermittelt
und empfangen" und muss entweder (Nr. 1) der **EN 16931** entsprechen oder (Nr. 2) zwischen den
Parteien vereinbart sein, sofern die richtige und vollständige Extraktion in ein
EN-16931-interoperables Format möglich ist (deckt EDI ab). Alles andere ist „sonstige Rechnung".

**Fristen** (§ 27 Abs. 38 UStG, [gesetze-im-internet.de](https://www.gesetze-im-internet.de/ustg_1980/__27.html);
BMF 15.10.2024 Rn. 62-65):

- **Seit 01.01.2025:** Empfangspflicht für alle inländischen Unternehmer (ohne Übergangsfrist).
- **Bis 31.12.2026:** Aussteller dürfen weiterhin Papier bzw. andere elektronische Formate mit
  Zustimmung des Empfängers nutzen.
- **Bis 31.12.2027:** dito für Aussteller mit **Vorjahresumsatz ≤ 800.000 €**; EDI (94/820/EG)
  mit Zustimmung für alle.
- **Ab 01.01.2028:** volle Ausstellungspflicht. Dauerhafte Ausnahmen: steuerfreie Umsätze
  § 4 Nr. 8-29 UStG, Kleinbetragsrechnungen ≤ 250 € (§ 33 UStDV), Fahrausweise (§ 34 UStDV).

**BMF-Schreiben vom 15.10.2024** (GZ III C 2 - S 7287-a/23/10001 :007, BStBl I S. 1320;
[BMF-Seite](https://www.bundesfinanzministerium.de/Content/DE/Downloads/BMF_Schreiben/Steuerarten/Umsatzsteuer/Umsatzsteuer-Anwendungserlass/2024-10-15-einfuehrung-e-rechnung.html)):
**XRechnung und ZUGFeRD ab Version 2.0.1 — „ausgenommen die Profile MINIMUM und BASIC-WL" —
erfüllen die E-Rechnungs-Anforderungen**; ebenso z. B. Factur-X und Peppol BIS Billing. Bei
Hybridformaten „bilden die im XML-Format vorliegenden Rechnungsdaten den **führenden Teil**".

**BMF-Schreiben vom 15.10.2025** (GZ III C 2 - S 7287-a/00019/007/243;
[PDF](https://www.bundesfinanzministerium.de/Content/DE/Downloads/BMF_Schreiben/Steuerarten/Umsatzsteuer/Umsatzsteuer-Anwendungserlass/2025-10-15-einfuehrung-obligatorische-e-rechnung.pdf?__blob=publicationFile&v=5))
präzisiert u. a.: Unterscheidung **Formatfehler** vs. **Geschäftsregelfehler** (Rn. 6a/6b;
Validierung empfohlen); der PDF-Bildteil ist ein „inhaltlich identisches Mehrstück" — enthält er
**abweichende** Angaben, droht ein Steuerausweis nach **§ 14c UStG**; **Vorsteuerabzug nur aus dem
strukturierten XML-Teil**; ein menschenlesbares Dokument ist für die „Lesbarkeit" nicht mehr
erforderlich. → Konsequenz für die Implementierung: PDF-Rendering und XML-Erzeugung müssen aus
demselben Datenstand gespeist werden, und ein einmal ausgestelltes Dokument darf nicht divergieren
(relevant für die `retrospective_edits`-Einstellung, siehe 4.3).

---

## 2. horstoeko/zugferd (PHP)

Quellen: [github.com/horstoeko/zugferd](https://github.com/horstoeko/zugferd) (README,
`composer.json`, `src/`), [packagist.org/packages/horstoeko/zugferd](https://packagist.org/packages/horstoeko/zugferd).

### 2.1 Fähigkeiten

- **Nur CII-Syntax** („This package provides only support for CII-Syntax - not UBL-Syntax", README;
  für UBL existiert `horstoeko/zugferd-ubl-bridge`).
- **Profile** (`src/ZugferdProfiles.php`): `PROFILE_MINIMUM`, `PROFILE_BASICWL`, `PROFILE_BASIC`,
  `PROFILE_EN16931`, `PROFILE_EXTENDED` sowie `PROFILE_XRECHNUNG` 1.2 bis **3.0**
  (`…#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0`).
- **Lesen:** `ZugferdDocumentReader` (XML), `ZugferdDocumentPdfReader` (extrahiert XML aus
  ZUGFeRD-PDF), `ZugferdDocumentJsonExporter`.
- **Validierung:** `src/ZugferdXsdValidator.php` (XSD gegen mitgelieferte Schemata);
  `src/ZugferdKositValidator.php` wrappt tatsächlich den **KoSIT-Validator**: lädt
  `validator-1.5.0-distribution.zip` + XRechnung-Szenarien von GitHub und startet das JAR —
  **Java lokal erforderlich** (Code prüft `ExecutableFinder->find('java')`); alternativ
  Remote-Modus gegen einen laufenden KoSIT-Daemon (`enableRemoteMode()`).

### 2.2 PDF-Einbettung — die kritische Frage

`ZugferdDocumentPdfBuilder` (Builder + bestehende PDF) und `ZugferdDocumentPdfMerger`
(fertiges XML als String/Datei + PDF als Pfad/Binärstring) erben von
`ZugferdDocumentPdfBuilderAbstract`. **Ja: Sie nehmen eine beliebige bestehende PDF entgegen**
und hängen das XML an. Intern: **FPDF + FPDI** (`setasign/fpdf ^1`, `setasign/fpdi ^2` in
`composer.json`; `src/ZugferdPdfWriter.php` erweitert FPDI) — kein TCPDF.

Was geschrieben wird (`ZugferdPdfWriter.php`, `ZugferdDocumentPdfBuilderAbstract.php`):
XMP mit `pdfaid:part=3` / `pdfaid:conformance` (Default B, per `setPdfAConformanceLevel()`
B/U/A) und Factur-X-Extension-Schema (`fx:ConformanceLevel`, `fx:DocumentFileName`);
`/EmbeddedFiles`-Name-Tree, `/AF`-Array, Filespec mit `/AFRelationship` (Data/Alternative/
Source/Supplement konfigurierbar); `/OutputIntent` mit eingebettetem sRGB-ICC-Profil;
PDF-Version 1.7.

**Aber — keine echte PDF/A-Konvertierung:** Es gibt keinerlei Font-Embedding,
Farbraum-Normalisierung oder Content-Umschreibung. Die Bibliothek *deklariert* PDF/A-3 auf den
importierten Seiten; tatsächliche veraPDF-Konformität setzt voraus, dass die Eingabe-PDF bereits
konform-nah ist (alle Fonts eingebettet, keine verbotenen Features). Beleg aus der Issue-Historie:
[Issue #342 / PR #344](https://github.com/horstoeko/zugferd/issues?q=PDF%2FA) („PDF/A3 invalide
when original PDF contains link", 01/2026) — selbst Hyperlinks in der Quell-PDF brachen die
Konformität und wurden einzeln nachgepatcht.

**FPDI-Free-Limitation:** Das kostenlose FPDI parst **keine komprimierten
Cross-Reference-/Object-Streams** (üblich ab PDF 1.5; Setasign: „Do you need support for PDF
documents using compressed cross-references and object streams? Check out the FPDI PDF-Parser!"
— kommerzielles Add-on, [setasign.com](https://www.setasign.com/products/fpdi/about/)).
Für beliebige Fremd-PDFs als Input eine reale Stolperfalle (`CrossReferenceException`).

### 2.3 Lizenz, Wartung, Laravel

- **MIT**; aktuell **v1.0.124** (10.07.2026), letzter Push 08/2026, 0 offene Issues/PRs,
  ~6,75 Mio. Installs ([Releases](https://github.com/horstoeko/zugferd/releases),
  [Packagist](https://packagist.org/packages/horstoeko/zugferd)). Hinweis: Der Maintainer sucht
  Mitstreiter und plant ein modernisiertes Nachfolgeprojekt mit Legacy-Support.
- **PHP:** `"php": ">=7.3"` ohne Obergrenze → **PHP 8.4 zulässig**; Symfony-Deps bis `^8`.
- **`horstoeko/zugferd-laravel`** existiert ([GitHub](https://github.com/horstoeko/zugferd-laravel)):
  Service-Provider + Facade `ZugferdLaravel` (Builder je Profil, Lesen aus Datei/String/PDF,
  Merge). Aber: letzte Version v1.0.5 (03/2025), deutlich trägere Pflege. Das Kernpaket ist
  framework-agnostisch — direkte Nutzung ist die robustere Wahl.

### 2.4 Alternativen (kurz)

- **horstoeko/orderx**: Schwesterbibliothek für **Order-X** (elektronische Bestellungen, keine
  Rechnungen) — hier nicht relevant.
- **[atgp/factur-x](https://github.com/atgp/factur-x)**: schlanker `Writer`/`Reader` (ebenfalls
  FPDI/FPDF + smalot/pdfparser), MIT, PHP >= 7.4 — **kein XML-Builder**, PDF/A nur rudimentär;
  gleiche FPDI-Grenze. Nur sinnvoll, wenn das XML anderweitig erzeugt wird.
- **[easybill/zugferd-php](https://github.com/easybill/zugferd-php)**: solides reines
  XML-Mapping (ZUGFeRD v1/v2/XRechnung), PDF-Embedding nicht der Kern.

**Fazit:** horstoeko/zugferd ist die vollständigste Option (Builder + Reader + XSD-/
KoSIT-Validierung + PDF-Merge) — für den XML-Teil klar erste Wahl. Den PDF/A-Container sollte
trotzdem Gotenberg bauen (Abschnitt 3), nicht der FPDI-Stempelweg.

---

## 3. Gotenberg: PDF/A-3 und Datei-Einbettung

Quellen: [gotenberg.dev](https://gotenberg.dev/docs/manipulate-pdfs/pdfa-pdfua),
[github.com/gotenberg/gotenberg](https://github.com/gotenberg/gotenberg),
[github.com/gotenberg/gotenberg-php](https://github.com/gotenberg/gotenberg-php).

### 3.1 PDF/A und PDF/UA

Alle drei Routen-Familien akzeptieren `pdfa` und `pdfua`:

| Route | `pdfa`-Werte |
|---|---|
| Chromium `POST /forms/chromium/convert/html` (+ url/markdown) | `PDF/A-1b`, `PDF/A-2b`, `PDF/A-3b` |
| LibreOffice `POST /forms/libreoffice/convert` | dito |
| PDF Engines `POST /forms/pdfengines/convert` (bestehende PDFs) | dito |

Nur die **b-Level**; kein 3a/3u, kein PDF/A-4. Die Konvertierung macht **LibreOffice**
(„PDF/A and PDF/UA conversion relies on LibreOffice to re-process documents",
[Doku](https://gotenberg.dev/docs/manipulate-pdfs/pdfa-pdfua); im Code: Default
`--pdfengines-convert-engines=["libreoffice-pdfengine"]`,
[pkg/modules/pdfengines/pdfengines.go](https://github.com/gotenberg/gotenberg/blob/main/pkg/modules/pdfengines/pdfengines.go);
QPDF/PDFtk/ExifTool/pdfcpu implementieren `Convert` nicht). **Chromium selbst kann kein PDF/A** —
bei den Chromium-Routen läuft die Konvertierung als Post-Processing durch LibreOffice
([Chromium-Doku](https://gotenberg.dev/docs/convert-with-chromium/convert-html-to-pdf)).

### 3.2 Datei-Einbettung und natives Factur-X — ja, versioniert

- **v8.25.0 (16.11.2025)** — „Embed Files": *„This feature enables the creation of PDFs compatible
  with standards like ZUGFeRD / Factur-X … Available on the Chromium, LibreOffice, and PDF Engines
  modules."* ([Release](https://github.com/gotenberg/gotenberg/releases/tag/v8.25.0)). Embedding-Engine:
  pdfcpu.
- **v8.28.0 (03/2026)**: PDF/A-1b/2b + `embeds` wird als inkompatibel mit `400` abgelehnt
  („use PDF/A-3b if you need both", [Release](https://github.com/gotenberg/gotenberg/releases/tag/v8.28.0)).
- **v8.31.0 (04/2026)**: `embedsMetadata` (JSON je Dateiname: `mimeType` → `/Subtype`,
  `relationship` → `/AFRelationship`) ([Release](https://github.com/gotenberg/gotenberg/releases/tag/v8.31.0),
  [Attachments-Doku](https://gotenberg.dev/docs/manipulate-pdfs/attachments)).
- **v8.34.0 (12.06.2026)**: **dedizierte Factur-X/ZUGFeRD-Unterstützung inkl. XMP-Injection**
  (Engine: QPDF): Felder `facturxXml` (immer als `factur-x.xml` eingebettet),
  `facturxConformanceLevel` (`MINIMUM` … `EN 16931`, `EXTENDED`, `XRECHNUNG`), `facturxVersion`
  (Default `1.0`), `facturxDocumentType` (`INVOICE`, `ORDER`, …) auf den Chromium-/LibreOffice-
  Routen plus eigene Route `POST /forms/pdfengines/factur-x`
  ([Release](https://github.com/gotenberg/gotenberg/releases/tag/v8.34.0),
  [Issue #1552](https://github.com/gotenberg/gotenberg/issues/1552)). Wird Factur-X ohne
  PDF/A-3 angefordert, **erzwingt Gotenberg PDF/A-3b** (`FacturXPdfFormats` in
  [pkg/modules/pdfengines/routes.go](https://github.com/gotenberg/gotenberg/blob/main/pkg/modules/pdfengines/routes.go)).

→ **HTML → PDF/A-3b mit eingebettetem `factur-x.xml` + Factur-X-XMP in einem Request.**

Caveats: nachträgliches Metadaten-Schreiben via ExifTool „usually breaks PDF/A compliance" —
Metadaten im selben Request mitgeben; PDF/A + Encryption wird abgelehnt.

### 3.3 gotenberg-php

Methoden (Quellcode, aktuell v2.25.0): `->pdfa()` / `->pdfua()` (seit v1.1.7 bzw. alle 2.x),
`->metadata()` + `readMetadata()/writeMetadata()` (v2.2.0), `->embeds(Stream ...)` und
`$pdfEngines->embed(...)` (**v2.15.0**), `->embedsMetadata(EmbedMetadata ...)` (**v2.21.0**),
`->facturX(FacturX)` / `$pdfEngines->injectFacturX(...)` mit `Gotenberg\FacturX`
(`CONFORMANCE_MINIMUM` … `CONFORMANCE_XRECHNUNG`) (**v2.23.0**,
[Release](https://github.com/gotenberg/gotenberg-php/releases/tag/v2.23.0)).

**Repo-Status hier:** `composer.json` verlangt `gotenberg/gotenberg-php: ^2.8`
(`/home/akinne/Projects/invoiceshelffork/composer.json`), **`composer.lock` pinnt v2.18.0** —
`embeds` vorhanden, `embedsMetadata`/`facturX` fehlen → `composer update gotenberg/gotenberg-php`.

### 3.4 DomPDF

dompdf **v3.1.0** (01/2025) hat per [PR #3269](https://github.com/dompdf/dompdf/pull/3269) einen
*„experimental PDF/A compliance mode"* (nur PDF/A-3b): O-Ton Maintainer: *„Only takes care of
adding the required metadata"*, *„Doesn't force font embedding"*, *„the user should generally
verify actual compliance with a tool like veraPDF"*. Kein Factur-X-XMP-Extension-Schema
(low-level `addEmbeddedFile()` mit `AFRelationship` existiert seit
[PR #2117](https://github.com/dompdf/dompdf/pull/2117), reicht aber nicht). → Für garantiert
konformes ZUGFeRD ungeeignet; die frühere Pauschalaussage „dompdf kann gar kein PDF/A" ist seit
v3.1 nur noch in dieser abgeschwächten Form richtig.

---

## 4. InvoiceShelf 3.x: die tatsächliche PDF-Pipeline (Code-Analyse)

### 4.1 Treiberwahl und Rendering

- Zwei Treiber, Auswahl über `config('pdf.driver')` ← `PDF_DRIVER` (Default `dompdf`):
  `config/pdf.php:14`; Factory ist ein statisches `match` in
  `app/Platform/Pdf/Rendering/PdfDriverFactory.php:9-13` (nicht erweiterbar ohne Core-Änderung).
  Einstieg: `App\Platform\Pdf\Facades\Pdf::loadView()` →
  `app/Platform/Pdf/Rendering/PdfService.php:7-12`.
- **GotenbergPdfDriver** (`app/Platform/Pdf/Rendering/GotenbergPdfDriver.php`):
  `buildRequest()` (Z. 27-108) baut den Chromium-Request (`Gotenberg::chromium($host)->pdf()`),
  inkl. SSRF-Guard (Z. 41-47), **`->pdfa($pdfa)` aus `config('pdf.connections.gotenberg.pdfa')`
  (Z. 75-77)**, Metadaten (Z. 79-81), Header/Footer-Companion-Views und Font-Anhängen
  (`attachFonts()`, Z. 128-142 — Fonts reisen als Assets mit, wichtig für spätere
  PDF/A-Font-Einbettung). `->html()` ist terminal (Z. 102-107).
- Die PDF/A-Option ist bereits produktisiert: `GOTENBERG_PDFA` (`config/pdf.php:96`,
  Kommentar: „PDF/A-3 is what the EU e-invoicing formats ask for"), Admin-UI-Validierung
  `PDFA_FORMATS = ['PDF/A-1b', 'PDF/A-2b', 'PDF/A-3b']`
  (`app/Platform/Pdf/Http/Requests/PdfConfigurationRequest.php:14,76`), Laufzeit-Override aus
  DB-Settings (`app/Platform/Pdf/Application/PdfConfigurationService.php:37-53`).
- **DompdfDriver** (`app/Platform/Pdf/Rendering/DompdfDriver.php:18-34`): barryvdh/laravel-dompdf
  (`^v3.0` in `composer.json`), `@page`-Margin-Injection, `addInfo()`-Metadaten.
- Beide liefern einen **`ResponseStream`** (`app/Platform/Pdf/Rendering/ResponseStream.php:14-21`):
  `stream()` / `download()` / `output(): string`. `GotenbergPdfResponse::output()`
  (`app/Platform/Pdf/Rendering/GotenbergPdfResponse.php:27-39`) ist rewind-sicher und mehrfach
  aufrufbar.
- Docker: Gotenberg-Sidecar als `gotenberg/gotenberg:8`
  (`docker/development/docker-compose.sqlite.gotenberg.yml:64` u. a.), Env-Vorlage in
  `.env.example:45-52`.

### 4.2 Der Rechnungs-Pfad und die Einhängepunkte

Alle Auslieferungswege laufen durch **eine** Stelle:

1. `Invoice::getPDFData()` (`app/Domains/Sales/Models/Invoice.php:397-400`) delegiert an den
   Contract `InvoicePdfDataProvider` — gebunden auf `InvoiceService`
   (`app/Domains/Sales/SalesServiceProvider.php:32`).
2. `InvoiceService::getPdfData()` (`app/Domains/Sales/Application/InvoiceService.php:268-325`)
   aggregiert Steuern, shared die View-Daten und ruft am Ende
   **`Pdf::loadView($templatePath, PdfMetadata::forDocument(...))`** (Z. ~320) — Rückgabe:
   `ResponseStream`.
3. Konsumenten:
   - **HTTP-Stream:** `DocumentPdfController::invoice()`
     (`app/Domains/Sales/Http/Controllers/DocumentPdfController.php:19-26`) →
     `GeneratesPdf::getGeneratedPDFOrStream('invoice')`
     (`app/Platform/Pdf/Concerns/GeneratesPdf.php:16-42`; frische Erzeugung via
     `$pdf->output()` in Z. 38). Kundenportal analog
     (`app/Domains/Sales/Http/Controllers/CustomerPortal/InvoicePdfController.php`).
   - **Persistenz:** `GeneratesPdf::generatePDF()` (`GeneratesPdf.php:78-119`) — nur wenn Setting
     `save_pdf_to_disk == 'YES'`; schreibt `$pdf->output()` nach `temp/…` (Z. 93) und hängt die
     Datei per **spatie/laravel-medialibrary** an die Collection `invoice`
     (`addMedia(...)->toMediaCollection(...)`, Z. 108-111). Getriggert von
     `app/Domains/Sales/Jobs/GenerateInvoicePdfJob.php:38`.
   - **E-Mail-Anhang:** `InvoiceService::sendInvoiceData()` (Z. 233) →
     `SendInvoiceMail` hängt `$this->data['attach']['data']->output()` an
     (`app/Domains/Sales/Mail/SendInvoiceMail.php:58-61`).

**Sauberster Einbau:** Da Gotenberg das Embedding im Render-Request selbst erledigt, ist der
minimalinvasive Hook `GotenbergPdfDriver::buildRequest()` — dort ein `->facturX(...)` neben dem
bestehenden `->pdfa(...)` (Z. 75-77). Das XML muss dafür in den Driver gelangen; das heutige
`loadView(string $template, array $metadata, ?PdfPageSetup $page)`-Interface
(`app/Platform/Pdf/Rendering/PdfDriver.php`) müsste um einen optionalen Anhang-/E-Invoice-Parameter
erweitert werden (oder ein eigener `EInvoicePdfService` umgeht `PdfService` für Rechnungen).
Alternativ (treiberunabhängig, aber s. Abschnitt 2.2) wäre Post-Processing auf
`ResponseStream::output()` in `InvoiceService::getPdfData()` möglich — ein dekorierender
`ResponseStream` würde alle drei Konsumenten transparent abdecken, da sie ausschließlich
`output()`/`stream()` benutzen.

### 4.3 Modulsystem

`invoiceshelf/modules ^3.3` ist ein Fork von nwidart/laravel-modules (Namespace `Nwidart\Modules`
bleibt, plus Host-Contracts `InvoiceShelf\Modules\Contracts\Host\*` — siehe
`app/Platform/Modules/ModuleServiceProvider.php:14-27`). Module liegen unter `Modules/`
(`config/modules.php:87`), werden per Marketplace
(`app/Platform/Modules/Marketplace/MarketplaceInstaller.php`) oder `module:install` installiert,
Aktivierung via `App\Platform\Modules\Runtime\DatabaseActivator`. Aktuell existiert **kein**
`Modules/`-Verzeichnis im Repo.

**Bewertung:** Ein Modul kann als normaler Service-Provider Bindings überschreiben — z. B.
`InvoicePdfDataProvider` (Bindung in `SalesServiceProvider.php:32`) dekorieren. Aber die
PDF-Pipeline feuert **keine Events** und `PdfDriverFactory` ist ein hartes `match` — es gibt
keinen designierten Hook. Für einen Fork ist **Core-Integration klar einfacher** (Erweiterung von
`GotenbergPdfDriver`/`PdfDriver`-Interface + ein `Sales`-seitiger XML-Builder); die Modul-Variante
lohnt nur, wenn Upstream-Mergebarkeit Priorität hat.

### 4.4 Datenmodell vs. EN 16931

Vorhanden:

- **Invoice** (`database/migrations/2017_04_12_090759_create_invoices_table.php` + spätere):
  `invoice_number`, `invoice_date`, `due_date`, `reference_number`, `notes`,
  Beträge als **Integer-Cents** (`sub_total`, `total`, `tax`, `due_amount`;
  Casts `app/Domains/Sales/Models/Invoice.php:80-90`), Rabatte pro Dokument/Position,
  `currency_id` + `exchange_rate` (Migrationen 2021_07_16_*), `tax_included`
  (2025_05_04_152240), **Credit Notes**: `type` (`INVOICE`/`CREDIT_NOTE`) + `related_invoice_id`
  (`database/migrations/2026_08_02_120000_add_credit_note_support.php:66-71`) → sauber auf
  EN-16931-Typcodes 380/381 abbildbar.
- **InvoiceItem** (2017_04_12_091015): `name`, `description`, `quantity`, `price`, `discount`,
  `tax`, `total`, `unit_name` (Freitext, 2021_03_03_155223).
- **Taxes** (2019_09_21_052548): pro Rechnung *oder* pro Position (`tax_per_item`), `name`,
  `percent`, `amount`, `compound_tax`.
- **Company**: `vat_id` + `tax_id` (2024_04_12_162703) — Verkäufer-USt-IdNr. (BT-31) vorhanden.
- **Customer**: nur generisches `tax_id` (2024_10_04_093723).
- **Adressen** mit Land (ISO-Code über `countries.code`, 2017_05_06_173745:17).

**Offensichtlich fehlend** für EN 16931 / XRechnung:

| EN-16931-Element | Status im Schema |
|---|---|
| BG-16/BT-84 ff. Zahlungsweg / **IBAN/BIC** des Verkäufers | fehlt komplett (`grep -ri iban app/ database/` → 0 Treffer) |
| BT-10 Buyer Reference / **Leitweg-ID** (XRechnung-Pflicht) | fehlt (nur freies `reference_number` auf der Rechnung) |
| BT-34/BT-49 elektronische Adressen (Seller/Buyer, XRechnung-Pflicht) | fehlt (nur E-Mail-Felder) |
| BT-48 USt-IdNr. des Käufers (Reverse Charge, innergem. Lieferung) | nur un-typisiertes `customers.tax_id` |
| BT-118 **USt-Kategorie-Code** (UNCL 5305: S/Z/E/AE/K/G …) je Steuersatz | fehlt — Taxes haben nur Name+Prozent |
| BT-130 Mengeneinheit als **UN/ECE-Rec.-20-Code** (C62, HUR, …) | nur Freitext `unit_name` |
| BT-20 Zahlungsbedingungen (strukturiert) | nur `due_date` + Freitext-Notes |
| Rundung: EN-16931-BR-CO-Regeln (dezimal) vs. Integer-Cents + `exchange_rate` float | Mapping-/Rundungskonzept nötig |

---

## 5. Validierungs-Tooling (CI)

- **KoSIT-Validator** ([github.com/itplr-kosit/validator](https://github.com/itplr-kosit/validator),
  aktuell v1.6.2, Java ≥ 11, Standalone-JAR): XSD + Schematron je „Szenario";
  CLI-Return-Codes CI-tauglich (0 = ok, n = abgelehnte Dateien), zusätzlich Daemon-Modus (`-D`,
  HTTP) ([docs/cli.md](https://github.com/itplr-kosit/validator/blob/main/docs/cli.md),
  [docs/daemon.md](https://github.com/itplr-kosit/validator/blob/main/docs/daemon.md)).
  Kein offizielles Docker-Image, läuft aber in jedem `eclipse-temurin`-Container.
  Konfiguration: [validator-configuration-xrechnung](https://github.com/itplr-kosit/validator-configuration-xrechnung)
  (Release 2026-01-31, XRechnung 3.0.x, CEN-Schematron 1.3.15) — prüft UBL/CII gegen
  **EN 16931 + CIUS XRechnung**. **ZUGFeRD-Profile BASIC/BASIC-WL/MINIMUM/EXTENDED haben eigene
  Guideline-IDs, für die es keine KoSIT-Szenarien gibt** → dafür Mustang.
- **Mustangproject** ([github.com/ZUGFeRD/mustangproject](https://github.com/ZUGFeRD/mustangproject),
  Apache-2.0, Java, v2.25.x): `java -jar Mustang-CLI.jar --action validate --source rechnung.pdf`
  prüft **XML (XSD + Schematron aller Profile) + PDF/A-3-Container (via eingebettetem veraPDF,
  belegt durch `org.verapdf:*`-Dependency in `validator/pom.xml`) + XMP-Metadaten**; Exit-Code 0 =
  valide ([mustangproject.org/commandline](https://www.mustangproject.org/commandline/)).
  Einziges Tool, das ein komplettes ZUGFeRD-PDF end-to-end über alle Profile prüft.
- **veraPDF** ([docs.verapdf.org/cli](https://docs.verapdf.org/cli/)): PDF/A-1/2/3/4 + PDF/UA;
  `verapdf -f 3b file.pdf`; offizielles Docker-Image
  `ghcr.io/verapdf/cli:latest` ([veraPDF-apps](https://github.com/veraPDF/veraPDF-apps)).
  Prüft nur den Container, nicht den Rechnungsinhalt.

**Empfohlene CI-Matrix:** Mustang (Gesamtpaket) + KoSIT-Validator (XML, wenn Profil EN 16931/
XRECHNUNG) als Pest-/Workflow-Schritt gegen golden-file-Rechnungen; veraPDF separat, wenn man den
Container unabhängig von Mustang prüfen will. Alle drei sind headless (Java/Docker) und damit
GitHub-Actions-tauglich.

---

## Offene Fragen

1. **Profilwahl:** EN 16931 („COMFORT") als Default? XRECHNUNG-Referenzprofil zusätzlich für
   B2G-Kunden (braucht Leitweg-ID + elektronische Adressen)? EXTENDED nur bei Bedarf.
2. **Treiber-Kopplung:** ZUGFeRD nur bei `PDF_DRIVER=gotenberg` anbieten — wie verhält sich das
   Feature-Flag/die UI, wenn dompdf aktiv ist (Hinweis? Hard Fail?)?
3. **Empirisch zu verifizieren:** Ist Chromium→LibreOffice-`PDF/A-3b`-Output mit den von
   InvoiceShelf angehängten Fonts (`GotenbergPdfDriver::attachFonts()`) tatsächlich
   veraPDF-clean? (Gotenberg konvertiert, garantiert aber keine Validierung.) → Golden-File-Test
   mit veraPDF/Mustang aufsetzen.
4. **Versionierung:** Gotenberg-Image auf `>=8.34` pinnen; `gotenberg-php` per
   `composer update` von v2.18.0 anheben (Constraint `^2.8` reicht). Passt Gotenbergs
   `facturxVersion`-Default `1.0` zum XMP-`fx:Version`, das ZUGFeRD 2.x-Validatoren erwarten
   (Mustang-Testlauf)?
5. **Schema-Migrationen:** IBAN/BIC + Zahlungsweg (Company), Leitweg-ID/Buyer Reference
   (Invoice oder Customer), USt-Kategorie-Code je TaxType, UN/ECE-Unit-Code je Item/Unit,
   typisierte Käufer-USt-IdNr. — Umfang und UI-Auswirkungen klären.
6. **Konsistenz PDF ↔ XML (§ 14c-Risiko):** XML ist der führende Teil (BMF 15.10.2025). Beide
   müssen aus demselben Datenstand entstehen; Wechselwirkung mit `retrospective_edits`
   (`Invoice::getAllowEditAttribute`, `app/Domains/Sales/Models/Invoice.php:185-217`) und dem
   Media-Library-Cache (`getGeneratedPDF`) prüfen — ein gespeichertes PDF darf nach einer
   Rechnungsänderung nicht mit neuem XML divergieren.
7. **Rundung/Beträge:** Integer-Cents + `exchange_rate: float` gegen die BR-CO-Summenregeln der
   EN 16931 mappen (insb. Rabatt-/Steuerverteilung, `tax_included`-Preise, `compound_tax`).
8. **Kleinunternehmer/Steuerbefreiungen:** § 19 UStG-Fälle brauchen Kategorie-Code + Exemption
   Reason (BT-120) — heute nirgends modelliert.
9. **Speicherung des XML:** eigenes Media-Collection-Item bzw. nur im PDF eingebettet? Empfangsseite
   (Import eingehender E-Rechnungen) ist bewusst außerhalb dieses Scopes, wäre aber mit
   `ZugferdDocumentPdfReader` naheliegend.
10. **horstoeko-Nachfolge:** Maintainer kündigt modernisiertes Nachfolgeprojekt an — Abstraktion
    dünn halten (eigener `InvoiceXmlBuilder`-Service, Bibliothek dahinter austauschbar).
