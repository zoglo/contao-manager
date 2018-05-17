<?php

/*
 * This file is part of Contao Manager.
 *
 * Copyright (c) 2016-2018 Contao Association
 *
 * @license LGPL-3.0+
 */

namespace Contao\ManagerApi\Composer;

use Composer\Json\JsonFile;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;

class CloudResolver
{
    const API_URL = 'https://resolve.contao.org';

    /**
     * @var Client
     */
    private $http;

    public function __construct()
    {
        $this->http = new Client();
    }

    /**
     * Creates a Cloud job for given composer changes.
     *
     * @param CloudChanges $definition
     *
     * @return CloudJob
     * @throws GuzzleException
     */
    public function createJob(CloudChanges $definition)
    {
        $data = [
            'composerJson' => $definition->getJson(),
            'composerLock' => $definition->getLock(),
            'platform' => $definition->getPlatform(),
        ];

        // TODO add update set from CloudChanges
        $options = [RequestOptions::JSON => $data];

        $response = $this->request('/jobs', 'POST', $options);

        return new CloudJob(JsonFile::parseJson((string) $response->getBody()));
    }

    /**
     * Gets job information from the Composer Cloud.
     *
     * @param string $jobId
     *
     * @return CloudJob|null
     * @throws GuzzleException
     */
    public function getJob($jobId)
    {
        if (!$jobId) {
            return null;
        }

        $response = $this->request('/jobs/'.$jobId);

        return new CloudJob(json_decode((string) $response->getBody(), true));
    }

    /**
     * Deletes a cloud job and returns whether it was successful.
     *
     * @param string $jobId
     *
     * @return bool
     */
    public function deleteJob($jobId)
    {
        if (!$jobId) {
            return false;
        }

        try {
            $response = $this->request('/jobs/' . $jobId, 'DELETE');

            return $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
        } catch (GuzzleException $e) {
            return false;
        }
    }

    /**
     * Gets the composer.json file.
     *
     * @param CloudJob $job
     *
     * @return string
     * @throws GuzzleException
     */
    public function getComposerJson(CloudJob $job)
    {
        $response = $this->request($job->getLink(CloudJob::LINK_JSON));

        return (string) $response->getBody();
    }

    /**
     * Gets the composer.lock file or null if the cloud job was not successful.
     *
     * @param CloudJob $job
     *
     * @return null|string
     * @throws GuzzleException
     */
    public function getComposerLock(CloudJob $job)
    {
        if (!$job->isSuccessful()) {
            return null;
        }

        $response = $this->request($job->getLink(CloudJob::LINK_LOCK));

        return (string) $response->getBody();
    }

    /**
     * Gets the console output for a cloud job.
     *
     * @param CloudJob $job
     *
     * @return null|string
     * @throws GuzzleException
     */
    public function getOutput(CloudJob $job)
    {
        if ($job->isQueued()) {
            return null;
        }

        $response = $this->request($job->getLink(CloudJob::LINK_OUTPUT));

        return (string) $response->getBody();
    }

    /**
     * Sends Guzzle request with JSON data.
     *
     * @param string $path
     * @param string $method
     * @param array  $options
     *
     * @return mixed|\Psr\Http\Message\ResponseInterface
     * @throws CloudException
     * @throws GuzzleException
     */
    private function request($path, $method = 'GET', array $options = [])
    {
        $options = array_replace_recursive(
            ['headers' => ['Accept' => 'application/json']],
            $options
        );

        try {
            return $this->http->request($method, self::API_URL . $path, $options);
        } catch (RequestException $e) {
            throw new CloudException($e);
        }
    }
}