<?php
/**
 *  The MIT License (MIT)
 *
 * Copyright (c) 2016 Sergei Shilko <contact@sshilko.com>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 **/

namespace BackQ\Message;

class Process extends AbstractMessage
{
    private $commandline;
    private $cwd;
    private $env;
    private $input;
    private $timeout;
    private $options;

    /**
     * Timestamp until has to be done, otherwise ignored
     * @var int
     */
    private $until = 0;

    public function __construct($commandline, $cwd = null, array $env = null, $input = null, $timeout = 60, array $options = array()) {
        $this->commandline = $commandline;
        $this->cwd = $cwd;
        $this->env = $env;
        $this->input = $input;
        $this->timeout = $timeout;
        $this->options = $options;
    }

    public function getDeadline() {
        return $this->until;
    }

    public function setDeadline(int $timestamp) {
        $this->until = $timestamp;
    }

    public function getCommandline() {
        return $this->commandline;
    }

    public function getCwd() {
        return $this->cwd;
    }

    public function getEnv() {
        return $this->env;
    }

    public function getInput() {
        return $this->input;
    }

    public function getTimeout() {
        return $this->timeout;
    }

    public function getOptions() {
        return $this->options;
    }

    public function getRecipientsNumber() {
        return 1;
    }
}
