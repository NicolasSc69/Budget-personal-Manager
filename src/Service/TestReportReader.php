<?php

namespace App\Service;

/**
 * Parses the JUnit XML produced by bin/run-tests.sh into a simple report
 * structure, shared by the dedicated test report page and the admin index's
 * quick summary.
 */
final class TestReportReader
{
    public function __construct(private readonly string $projectDir)
    {
    }

    /**
     * @return array{ran: bool, generatedAt: ?\DateTimeImmutable, totals: array{tests: int, assertions: int, failures: int, errors: int, skipped: int, time: float}, suites: list<array{name: string, cases: list<array{name: string, status: string, time: float, message: ?string}>}>}
     */
    public function read(): array
    {
        $path = $this->projectDir.'/var/test-report/junit.xml';

        $empty = [
            'ran' => false,
            'generatedAt' => null,
            'totals' => ['tests' => 0, 'assertions' => 0, 'failures' => 0, 'errors' => 0, 'skipped' => 0, 'time' => 0.0],
            'suites' => [],
        ];

        if (!is_file($path)) {
            return $empty;
        }

        $xml = @simplexml_load_file($path);

        if (false === $xml) {
            return $empty;
        }

        $totals = ['tests' => 0, 'assertions' => 0, 'failures' => 0, 'errors' => 0, 'skipped' => 0, 'time' => 0.0];
        $casesByClass = [];

        // Walk every <testcase> directly rather than summing <testsuite> "tests"
        // attributes: PHPUnit nests a wrapper testsuite per data-provider method
        // inside the test class's own testsuite, so the class-level totals already
        // include the provider's, and summing both would double-count them.
        foreach ($xml->xpath('//testcase') ?: [] as $case) {
            $status = 'passed';
            $message = null;

            if (isset($case->failure)) {
                $status = 'failed';
                $message = trim((string) $case->failure);
            } elseif (isset($case->error)) {
                $status = 'error';
                $message = trim((string) $case->error);
            } elseif (isset($case->skipped)) {
                $status = 'skipped';
            }

            $class = (string) $case['class'];
            $time = (float) $case['time'];

            $casesByClass[$class][] = [
                'name' => (string) $case['name'],
                'status' => $status,
                'time' => $time,
                'message' => $message,
            ];

            ++$totals['tests'];
            $totals['assertions'] += (int) $case['assertions'];
            $totals['time'] += $time;

            match ($status) {
                'failed' => ++$totals['failures'],
                'error' => ++$totals['errors'],
                'skipped' => ++$totals['skipped'],
                default => null,
            };
        }

        $suites = [];
        foreach ($casesByClass as $class => $cases) {
            $suites[] = ['name' => $class, 'cases' => $cases];
        }

        return [
            'ran' => true,
            'generatedAt' => \DateTimeImmutable::createFromFormat('U', (string) filemtime($path)) ?: null,
            'totals' => $totals,
            'suites' => $suites,
        ];
    }
}
