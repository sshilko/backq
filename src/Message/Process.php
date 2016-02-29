<?php
/**
 * BackQ
 *
 * Copyright (c) 2014, Sergey Shilko (contact@sshilko.com)
 *
 * @author Sergey Shilko
 * @see https://github.com/sshilko/backq
 * @see http://symfony.com/doc/current/components/process.html
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

    public function __construct($commandline, $cwd = null, array $env = null, $input = null, $timeout = 60, array $options = array()) {
        $this->commandline = $commandline;
        $this->cwd = $cwd;
        $this->env = $env;
        $this->input = $input;
        $this->timeout = $timeout;
        $this->options = $options;
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
