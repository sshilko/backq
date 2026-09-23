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

use function date;
use function fclose;
use function fopen;
use function fwrite;
use function getmypid;
use function str_contains;
use function trim;

class Logger
{

    public function __construct(protected $logFile)
    {
    }

    public function log($sMessage, $debug = false): void
    {
        if (!$debug && (false !== str_contains($sMessage, 'INFO:') || str_contains($sMessage, 'STATUS:'))) {
            return;
        }

        if ($log_handler = fopen($this->logFile, 'a')) {
            fwrite($log_handler, date('Y-m-d H:i:s') . ' - ' . getmypid() . ' - ' . trim($sMessage) . "\n");
            fclose($log_handler);
        }
    }
}
