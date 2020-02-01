<?php

declare(strict_types=1);

/*
 * This file is part of Contao Manager.
 *
 * (c) Contao Association
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\ManagerApi\Controller\Server;

use Contao\ManagerApi\HttpKernel\ApiProblemResponse;
use Contao\ManagerApi\Process\ConsoleProcessFactory;
use Contao\ManagerApi\System\ServerInfo;
use Crell\ApiProblem\ApiProblem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/server/php-cli", methods={"GET"})
 */
class PhpCliController
{
    public function __invoke(ServerInfo $serverInfo, ConsoleProcessFactory $processFactory): Response
    {
        if (!$serverInfo->getPhpExecutable()) {
            return new ApiProblemResponse(
                (new ApiProblem('Missing hosting configuration.', '/api/server/config'))
                    ->setStatus(Response::HTTP_SERVICE_UNAVAILABLE)
            );
        }

        return new JsonResponse($this->runIntegrityChecks($processFactory));
    }

    private function runIntegrityChecks(ConsoleProcessFactory $processFactory): array
    {
        $process = $processFactory->createManagerConsoleProcess(
            [
                'integrity-check',
                '--format=json',
            ]
        );

        $process->run();

        return json_decode($process->getOutput(), true);
    }
}
