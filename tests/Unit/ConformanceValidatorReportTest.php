<?php

use Tests\Conformance\Support\MustangReport;
use Tests\Conformance\Support\VeraPdfReport;

/**
 * The verdict the conformance job reads out of the two validators.
 *
 * The CI job itself needs a real Gotenberg service and two Java tools, so it
 * cannot run here — but the part that decides "conformant or not" is ordinary
 * parsing, and that is what these assert, service-free, against reports the
 * real tools actually produced:
 *
 * - `mustang-valid.xml`, `mustang-invalid.xml`, `mustang-plain-pdf.xml` —
 *   Mustang CLI 2.25.0 (`--action validate --source <pdf>`) over the ZUGFeRD
 *   samples `EN16931_Einfach.pdf`, `XMLinvalidV2PDF.pdf` and `EmptyPDFA1.pdf`.
 * - `verapdf-compliant.xml`, `verapdf-non-compliant.xml`,
 *   `verapdf-unparsable.xml` — veraPDF 1.30.2 (`-f 3b --format xml <pdf>`) over
 *   a conformant PDF/A-3b, a deliberately non-conformant one, and a file that
 *   is not a PDF.
 *
 * Only the file names inside them were rewritten. The two cases that matter
 * most are the ones a naive reading would wave through: a plain PDF carrying no
 * XML at all (what the Fallback produces — it must never pass as an e-invoice)
 * and a veraPDF batch in which nothing was validated.
 *
 * @see tests/Conformance/HybridPdfConformanceTest.php
 */
function conformanceReportFixture(string $name): string
{
    return (string) file_get_contents(base_path("tests/Conformance/fixtures/{$name}.xml"));
}

test('mustang reports a conformant zugferd pdf as valid', function () {
    $report = MustangReport::fromOutput(conformanceReportFixture('mustang-valid'));

    expect($report->isValid())->toBeTrue()
        ->and($report->profile())->toBe('urn:cen.eu:en16931:2017')
        ->and($report->problems())->toBeEmpty();
});

test('mustang names what makes a pdf non-conformant', function () {
    $report = MustangReport::fromOutput(conformanceReportFixture('mustang-invalid'));

    expect($report->isValid())->toBeFalse()
        ->and($report->problems())->not->toBeEmpty();

    expect($report->summary())
        ->toContain('schema validation fails')
        ->toContain('Unsupported profile type');
});

/**
 * The Fallback leaking into the conformance job: an ordinary invoice PDF with
 * no XML in it. It has to come back non-conformant, and the reason has to be
 * legible — the report carries no profile and no <xml> section at all.
 */
test('mustang rejects a plain pdf that carries no e-invoice', function () {
    $report = MustangReport::fromOutput(conformanceReportFixture('mustang-plain-pdf'));

    expect($report->isValid())->toBeFalse()
        ->and($report->profile())->toBeNull()
        ->and($report->summary())
        ->toContain('XML could not be extracted')
        ->toContain('Not a PDF/A-3');
});

test('output that is not a mustang report is an error, not a verdict', function () {
    expect(fn () => MustangReport::fromOutput('Error: Unable to access jarfile Mustang-CLI.jar'))
        ->toThrow(RuntimeException::class, 'Unable to access jarfile');
});

test('verapdf reports a pdf/a-3b file as compliant', function () {
    $report = VeraPdfReport::fromOutput(conformanceReportFixture('verapdf-compliant'));

    expect($report->isCompliant())->toBeTrue()
        ->and($report->profileName())->toBe('PDF/A-3b validation profile')
        ->and($report->problems())->toBeEmpty();
});

test('verapdf names the pdf/a rules a file breaks', function () {
    $report = VeraPdfReport::fromOutput(conformanceReportFixture('verapdf-non-compliant'));

    expect($report->isCompliant())->toBeFalse();

    expect($report->summary())
        ->toContain('6.6.4')
        ->toContain('is 1 instead of 3 for PDF/A-3 conforming file');
});

/**
 * A batch in which nothing was validated reports no non-compliant file — and
 * would sail through a check that only looks for those. Conformance has to be
 * proven, so an unvalidated batch counts as non-conformant.
 */
test('verapdf finding nothing to validate is not conformance', function () {
    $report = VeraPdfReport::fromOutput(conformanceReportFixture('verapdf-unparsable'));

    expect($report->isCompliant())->toBeFalse()
        ->and($report->summary())->toContain("Couldn't parse stream");
});

test('output that is not a verapdf report is an error, not a verdict', function () {
    expect(fn () => VeraPdfReport::fromOutput('verapdf: command not found'))
        ->toThrow(RuntimeException::class, 'command not found');
});
