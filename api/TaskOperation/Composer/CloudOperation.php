<?php

/*
 * This file is part of Contao Manager.
 *
 * Copyright (c) 2016-2018 Contao Association
 *
 * @license LGPL-3.0+
 */

namespace Contao\ManagerApi\TaskOperation\Composer;

use Contao\ManagerApi\Composer\CloudChanges;
use Contao\ManagerApi\Composer\CloudException;
use Contao\ManagerApi\Composer\CloudJob;
use Contao\ManagerApi\Composer\CloudResolver;
use Contao\ManagerApi\Composer\Environment;
use Contao\ManagerApi\Task\TaskConfig;
use Contao\ManagerApi\Task\TaskStatus;
use Contao\ManagerApi\TaskOperation\TaskOperationInterface;
use Symfony\Component\Filesystem\Filesystem;

class CloudOperation implements TaskOperationInterface
{
    /**
     * @var CloudResolver
     */
    private $cloud;

    /**
     * @var CloudChanges
     */
    private $changes;

    /**
     * @var TaskConfig
     */
    private $taskConfig;

    /**
     * @var Environment
     */
    private $environment;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var CloudJob
     */
    private $job;

    /**
     * @var \Exception
     */
    private $exception;

    /**
     * Constructor.
     *
     * @param CloudResolver $cloud
     * @param CloudChanges  $changes
     * @param TaskConfig    $taskConfig
     * @param Environment   $environment
     * @param Filesystem    $filesystem
     */
    public function __construct(CloudResolver $cloud, CloudChanges $changes, TaskConfig $taskConfig, Environment $environment, Filesystem $filesystem)
    {
        $this->cloud = $cloud;
        $this->changes = $changes;
        $this->taskConfig = $taskConfig;
        $this->environment = $environment;
        $this->filesystem = $filesystem;
    }

    public function isStarted()
    {
        try {
            return $this->getCurrentJob() instanceof CloudJob;
        } catch (\Exception $e) {
            $this->exception = $e;

            return true;
        }
    }

    public function isRunning()
    {
        try {
            $job = $this->getCurrentJob();

            return $job instanceof CloudJob
                && ($job->isQueued()
                    || $job->isProcessing()
                    || ($job->isSuccessful() && !$this->taskConfig->getState('cloud-job-successful', false))
                );
        } catch (\Exception $e) {
            $this->exception = $e;

            return false;
        }
    }

    public function isSuccessful()
    {
        return (bool) $this->taskConfig->getState('cloud-job-successful', false);
    }

    public function hasError()
    {
        return $this->exception instanceof \Exception;
    }

    public function run()
    {
        try {
            $job = $this->getCurrentJob();

            if (!$job instanceof CloudJob) {
                $this->job = $job = $this->cloud->createJob($this->changes);
                $this->taskConfig->setState('cloud-job', $this->job->getId());
                $this->taskConfig->setState('cloud-job-queued', time());
            }

            if ($job->isSuccessful() && !$this->taskConfig->getState('cloud-job-successful', false)) {
                $this->filesystem->dumpFile(
                    $this->environment->getLockFile(),
                    $this->cloud->getComposerLock($job)
                );
                $this->filesystem->dumpFile(
                    $this->environment->getJsonFile(),
                    $this->cloud->getComposerJson($job)
                );

                $this->taskConfig->setState('cloud-job-successful', true);
            }
        } catch (\Exception $e) {
            $this->exception = $e;
            $this->taskConfig->setState('cloud-job-successful', false);
        }
    }

    public function abort()
    {
        $this->taskConfig->clearState('cloud-job');
        $this->taskConfig->clearState('cloud-job-successful');
    }

    public function delete()
    {
        try {
            $this->cloud->deleteJob($this->taskConfig->getState('cloud-job'));
        } catch (\Exception $e) {
            $this->exception = $e;
        }
    }

    public function updateStatus(TaskStatus $status)
    {
        if ($this->exception instanceof CloudException) {
            $status->addConsole(
                sprintf(
                    "> The Composer Cloud failed with status code %s\n\n  %s",
                    $this->exception->getStatusCode(),
                    $this->exception->getErrorMessage()
                )
            );

            return;
        }

        if ($this->exception instanceof \Exception) {
            $status->addConsole($this->exception->getMessage());

            return;
        }

        try {
            $job = $this->getCurrentJob();
        } catch (\Exception $e) {
            $this->exception = $e;
            $status->addConsole($this->exception->getMessage());

            return;
        }

        if (!$job instanceof CloudJob) {
            return;
        }

        $console = '> Resolving dependencies using Composer Cloud';
        $console .= "\n!!! Current server is sponsored by: ".$job->getSponsor()." !!!\n";

        $requires = $this->changes->getRequiredPackages();
        $removes = $this->changes->getRemovedPackages();

        if (!empty($requires)) {
            $console .= "\n Added packages to composer.json\n - ".implode("\n  - ", $requires);
        }

        if (!empty($removes)) {
            $console .= "\n Removed packages from composer.json\n - ".implode("\n  - ", $removes);
        }

        switch ($job->getStatus()) {
            case CloudJob::STATUS_QUEUED:
                $status->setSummary(
                    sprintf(
                        'Job queued in Composer Cloud for %s seconds',
                        time() - $this->taskConfig->getState('cloud-job-queued')
                    )
                );
                $status->setDetail(
                    sprintf(
                        'Starting in approx. %s seconds (currently %s jobs on %s workers)',
                        $job->getWaitingTime(),
                        $job->getJobsInQueue(),
                        $job->getWorkers()
                    )
                );
                break;

            case CloudJob::STATUS_PROCESSING:
                $details = sprintf(
                    'Job ID %s is running for %s seconds',
                    $job->getId(),
                    time() - $this->taskConfig->getState('cloud-job-processing')
                );

                $status->setSummary('Resolving dependencies using Composer Cloud');
                $status->setDetail($details);
                $status->addConsole($console."\n\n ".$details);
                break;

            case CloudJob::STATUS_ERROR:
                $status->setSummary('Failed resolving dependencies …');
                $status->setConsole($this->cloud->getOutput($job));
                $status->setStatus(TaskStatus::STATUS_ERROR);
                break;

            case CloudJob::STATUS_FINISHED:
                $details = sprintf(
                    'Job ID %s completed in %s seconds',
                    $job->getId(),
                    $this->taskConfig->getState('cloud-job-finished') - $this->taskConfig->getState('cloud-job-processing')
                );

                $status->setSummary('Composer Cloud job completed');
                $status->setDetail($details);
                $status->addConsole($console."\n\n# ".$details."\n");
                break;

            default:
                throw new \RuntimeException(sprintf('Unknown cloud status "%s"', $job->getStatus()));
        }
    }

    /**
     * @return CloudJob|null
     */
    private function getCurrentJob()
    {
        if (null === $this->job) {
            $this->job = $this->cloud->getJob($this->taskConfig->getState('cloud-job'));
        }

        if ($this->job instanceof CloudJob
            && $this->job->isProcessing()
            && !$this->taskConfig->getState('cloud-job-processing')
        ) {
            $this->taskConfig->setState('cloud-job-processing', time());
        }

        if ($this->job instanceof CloudJob
            && ($this->job->isSuccessful() || $this->job->isFailed())
            && !$this->taskConfig->getState('cloud-job-finished')
        ) {
            $this->taskConfig->setState('cloud-job-finished', time());
        }

        return $this->job;
    }
}