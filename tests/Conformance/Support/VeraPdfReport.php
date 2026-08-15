<?php

namespace Tests\Conformance\Support;

use RuntimeException;
use SimpleXMLElement;

/**
 * The verdict veraPDF returns on the PDF/A conformance of a container.
 *
 * veraPDF is the reference implementation for PDF/A and judges the container
 * only — it knows nothing about invoices. It is here because a Hybrid PDF is
 * only an e-invoice if the file it travels in is PDF/A-3: embedding a file is
 * not permitted before PDF/A-3, and Mustang's own container check is the same
 * engine, so running veraPDF directly is what makes the conformance claim
 * independent of Mustang's version of it.
 *
 * Its machine-readable report (`--format xml`) looks like:
 *
 *     <report>
 *       <jobs><job>
 *         <item .../>
 *         <validationReport profileName="PDF/A-3b validation profile"
 *                           statement="..." isCompliant="true">
 *           <details passedRules="146" failedRules="0" .../>
 *         </validationReport>
 *       </job></jobs>
 *       <batchSummary totalJobs="1" failedToParse="0" ...>
 *         <validationReports compliant="1" nonCompliant="0" failedJobs="0">1</validationReports>
 *       </batchSummary>
 *     </report>
 *
 * A file veraPDF cannot parse produces a `<taskException>` and no
 * `<validationReport>` at all — a batch with nothing compliant and nothing
 * non-compliant. Conformance therefore has to be read positively: at least one
 * file was validated, all of them were compliant, and nothing failed on the way.
 *
 * @see https://docs.verapdf.org/cli/
 */
final class VeraPdfReport
{
    /**
     * @param  list<string>  $problems
     */
    private function __construct(
        private readonly bool $compliant,
        private readonly ?string $profileName,
        private readonly array $problems,
    ) {}

    /**
     * Read a report from what veraPDF printed.
     *
     * @throws RuntimeException when the output is not a report at all, which is
     *                          a broken validator run rather than a verdict
     */
    public static function fromOutput(string $output): self
    {
        $previous = libxml_use_internal_errors(true);
        $report = simplexml_load_string(trim($output));
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($report === false || $report->getName() !== 'report') {
            throw new RuntimeException("veraPDF did not return a validation report:\n".trim($output));
        }

        $summary = $report->batchSummary->validationReports ?? null;

        $compliant = (int) ($summary['compliant'] ?? 0);
        $nonCompliant = (int) ($summary['nonCompliant'] ?? 0);
        $failedJobs = (int) ($summary['failedJobs'] ?? 0);
        $failedToParse = (int) ($report->batchSummary['failedToParse'] ?? 0);

        return new self(
            $compliant > 0 && $nonCompliant === 0 && $failedJobs === 0 && $failedToParse === 0,
            self::profileNameOf($report),
            self::problemsOf($report),
        );
    }

    /**
     * Whether every file in the batch was validated and found conformant.
     */
    public function isCompliant(): bool
    {
        return $this->compliant;
    }

    /**
     * The profile the file was validated against, e.g. `PDF/A-3b validation
     * profile`. Null when nothing was validated.
     */
    public function profileName(): ?string
    {
        return $this->profileName;
    }

    /**
     * Every broken rule and every job that never got as far as a verdict.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        return $this->problems;
    }

    /**
     * The report as an assertion message.
     */
    public function summary(): string
    {
        $lines = array_map(static fn (string $problem): string => '  - '.$problem, $this->problems);

        return sprintf(
            "veraPDF reported the container as %s (profile: %s).\n%s",
            $this->compliant ? 'compliant' : 'not compliant',
            $this->profileName ?? 'none — nothing was validated',
            $lines === [] ? '  (no failed rules reported)' : implode("\n", $lines),
        );
    }

    /**
     * The profile of the first file validated, if any was.
     */
    private static function profileNameOf(SimpleXMLElement $report): ?string
    {
        foreach ($report->xpath('//validationReport') ?: [] as $validation) {
            $name = trim((string) ($validation['profileName'] ?? ''));

            if ($name !== '') {
                return $name;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function problemsOf(SimpleXMLElement $report): array
    {
        $problems = [];

        foreach ($report->xpath('//rule[@status="failed"]') ?: [] as $rule) {
            $messages = array_map(
                static fn (SimpleXMLElement $check): string => trim((string) $check->errorMessage),
                $rule->xpath('check[@status="failed"]') ?: [],
            );

            $problems[] = sprintf(
                '%s clause %s test %s: %s — %s',
                (string) ($rule['specification'] ?? 'PDF/A'),
                (string) ($rule['clause'] ?? '?'),
                (string) ($rule['testNumber'] ?? '?'),
                trim((string) $rule->description),
                implode('; ', array_filter($messages)),
            );
        }

        // A job that ended in an exception never reached a verdict; without this
        // an unparsable file would be reported as "not compliant" with nothing
        // to explain it.
        foreach ($report->xpath('//taskException') ?: [] as $exception) {
            $problems[] = sprintf(
                '%s task failed: %s',
                (string) ($exception['type'] ?? 'unknown'),
                trim((string) $exception->exceptionMessage),
            );
        }

        return $problems;
    }
}
