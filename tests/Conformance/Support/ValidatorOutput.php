<?php

namespace Tests\Conformance\Support;

use RuntimeException;
use SimpleXMLElement;

/**
 * What a validator printed, read as its report.
 *
 * Both validators answer in XML on stdout, and both have the same failure mode
 * worth separating from a verdict: output that is not a report at all — a
 * missing jar, an unusable file, a JVM that never started. That is a broken run
 * and has to be raised, never quietly read as "not conformant".
 */
final class ValidatorOutput
{
    /**
     * Parse a validator's output, insisting on the root element its reports
     * carry.
     *
     * @throws RuntimeException when the output is not that report
     */
    public static function parse(string $output, string $rootElement, string $tool): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        $report = simplexml_load_string(trim($output));
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($report === false || $report->getName() !== $rootElement) {
            throw new RuntimeException("{$tool} did not return a validation report:\n".trim($output));
        }

        return $report;
    }
}
