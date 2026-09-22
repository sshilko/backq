<?php

namespace BackQ\Tests\Adapter\Redis;

use BackQ\Adapter\Redis\App;
use PHPUnit\Framework\TestCase;

class AppTest extends TestCase
{
    public function testIsDownForMaintenanceReturnsFalse(): void
    {
        $app = new App();
        $this->assertFalse($app->isDownForMaintenance());
    }
}
