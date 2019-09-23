<?php

class endpoints
{
    /** @var \BackQ\Worker\Amazon\SNS\SnsClient */
    private $snsClient;
    private $platform = '';

    public function __construct(string $platform, array $auth)
    {
        /**
         * Set dependencies for the workers (SNS Client)
         */
        $client = new \BackQ\Worker\Amazon\SNS\SnsClient($auth);
        $this->snsClient = $client;

        /**
         * Set specific platform based on name of the file that's calling this constructor
         */
        $this->platform = $platform;
    }

    /**
     * Get Platform that endpoints belong to
     * @return string
     */
    public function getPlatform(): string
    {
        return $this->platform;
    }

    /**
     * Get Client/Service provider to perform operations with endpoints
     * @return \BackQ\Worker\Amazon\SNS\SnsClient
     */
    public function getClient(): \BackQ\Worker\Amazon\SNS\SnsClient
    {
        return $this->snsClient;
    }
}
