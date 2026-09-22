<?php

use BackQ\Worker\Amazon\SNS\SnsClient;

class Endpoints
{
    private SnsClient $snsClient;

    private string $platform = '';

    public function __construct(string $platform, array $auth)
    {
        $this->snsClient = new SnsClient($auth);
        $this->platform  = $platform;
    }

    public function getPlatform(): string
    {
        return $this->platform;
    }

    public function getClient(): SnsClient
    {
        return $this->snsClient;
    }
}
