<?php

use BackQ\Worker\Amazon\SNS\SnsClient;

class endpoints
{

    private SnsClient $snsClient;

    private $platform = '';

    public function __construct(string $platform, array $auth)
    {
        /**
         * Set dependencies for the workers (SNS Client)
         */
        $client = new SnsClient($auth);
        $this->snsClient = $client;

        /**
         * Set specific platform based on name of the file that's calling this constructor
         */
        $this->platform = $platform;
    }

    /**
     * Get Platform that endpoints belong to
     */
    public function getPlatform(): string
    {
        return $this->platform;
    }

    /**
     * Get Client/Service provider to perform operations with endpoints
     */
    public function getClient(): SnsClient
    {
        return $this->snsClient;
    }
}
