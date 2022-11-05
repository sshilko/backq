<?php
/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

namespace BackQ\Message;

class Process extends AbstractMessage
{

    private $commandline;

    private $cwd;

    private $env;

    private $input;

    private $timeout;

    /**
     * Timestamp until has to be done, otherwise ignored
     */
    private int $until = 0;

    /**
     * Process constructor.
     * @param array $commandline
     * @param string|null $cwd
     * @param array|null $env
     * @param null $input
     * @param float $timeout
     */
    public function __construct(
        $commandline,
        ?string $cwd = null,
        ?array $env = null,
        $input = null,
        ?float $timeout = 60
    ) {
        $this->commandline = $commandline;
        $this->cwd = $cwd;
        $this->env = $env;
        $this->input = $input;
        $this->timeout = $timeout;
    }

    public function getDeadline()
    {
        return $this->until;
    }

    public function setDeadline(int $timestamp): void
    {
        $this->until = $timestamp;
    }

    public function getCommandline()
    {
        return $this->commandline;
    }

    public function getCwd()
    {
        return $this->cwd;
    }

    public function getEnv()
    {
        return $this->env;
    }

    public function getInput()
    {
        return $this->input;
    }

    public function getTimeout()
    {
        return $this->timeout;
    }
}
