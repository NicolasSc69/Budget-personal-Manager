<?php

namespace App\Controller;

use App\Service\TestReportReader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/tests')]
final class TestReportController extends AbstractController
{
    private const int TIMEOUT_SECONDS = 300;

    #[Route(name: 'app_admin_tests', methods: ['GET'])]
    public function index(TestReportReader $reportReader, KernelInterface $kernel): Response
    {
        $logPath = $kernel->getProjectDir().'/var/test-report/output.log';

        return $this->render('admin/tests.html.twig', [
            'report' => $reportReader->read(),
            'outputLog' => is_file($logPath) ? trim(file_get_contents($logPath)) : null,
        ]);
    }

    #[Route('/run', name: 'app_admin_tests_run', methods: ['POST'])]
    public function run(Request $request, KernelInterface $kernel, TranslatorInterface $translator): Response
    {
        if (!$this->isCsrfTokenValid('admin_tests_run', $request->getPayload()->getString('_token'))) {
            $this->addFlash('danger', $translator->trans('Invalid security token, please try again.'));

            return $this->redirectToRoute('app_admin_tests');
        }

        $projectDir = $kernel->getProjectDir();
        $reportDir = $projectDir.'/var/test-report';

        if (!is_file($projectDir.'/vendor/phpunit/phpunit/phpunit')) {
            $this->addFlash('danger', $translator->trans('PHPUnit is not installed on this server (dev dependencies are missing from vendor/). Run "composer install" without --no-dev.'));

            return $this->redirectToRoute('app_admin_tests');
        }

        if (!is_dir($reportDir)) {
            mkdir($reportDir, 0777, true);
        }

        // Invoke the exact PHP binary running this request rather than relying on
        // "sh bin/run-tests.sh" to resolve "php" via PATH: a web server worker's
        // PATH can point to a different PHP version (or none at all) than an
        // interactive shell, which would make phpunit fail to even start —
        // producing no JUnit report and no useful error message.
        $phpBinary = (new PhpExecutableFinder())->find() ?: 'php';

        $process = new Process(
            [$phpBinary, 'bin/phpunit', '--log-junit', 'var/test-report/junit.xml', '--testdox-text', 'var/test-report/testdox.txt'],
            $projectDir,
            null,
            null,
            self::TIMEOUT_SECONDS,
        );
        $process->run();

        file_put_contents($reportDir.'/output.log', $process->getOutput()."\n".$process->getErrorOutput());

        if ($process->isSuccessful()) {
            $this->addFlash('success', $translator->trans('All tests passed.'));
        } elseif (is_file($reportDir.'/junit.xml')) {
            $this->addFlash('danger', $translator->trans('Some tests failed, see the report below.'));
        } else {
            $excerpt = trim(substr($process->getErrorOutput() ?: $process->getOutput(), -500));
            $this->addFlash('danger', $translator->trans('The test run could not complete: %error%', ['%error%' => $excerpt ?: $translator->trans('unknown reason')]));
        }

        return $this->redirectToRoute('app_admin_tests');
    }
}
