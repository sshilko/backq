<?php
namespace BackQ\Adapter\Redis;
class App extends \Illuminate\Container\Container
{
    public function isDownForMaintenance()
    {
        return false;
    }
}
