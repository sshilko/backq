<?php
/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

namespace BackQ;

class Logger implements \ApnsPHP_Log_Interface
{
    protected $logFile;

    public function __construct($logFile)
    {
        $this->logFile = $logFile;
    }

    public function log($sMessage, $debug = false)
    {
        if (!$debug && (false !== strpos($sMessage, 'INFO:') || false !== strstr($sMessage, 'STATUS:'))) {
            return;
        }

        if ($log_handler = fopen($this->logFile, 'a')) {
            fwrite($log_handler, date('Y-m-d H:i:s') . ' - ' . getmypid() . ' - ' . trim($sMessage)."\n");
            fclose($log_handler);
        }
    }

}
