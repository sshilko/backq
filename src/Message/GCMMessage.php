<?php
/**
 * BackQ
 *
 * Copyright (c) 2016, Sergey Shilko (contact@sshilko.com)
 *
 * @author Sergey Shilko
 * @see https://github.com/sshilko/backq
 *
 **/

namespace BackQ\Message;

class GCMMessage extends AbstractMessage
{
    /**
     * Rcp. GCM IDs (limited to [1,1000] recipients)
     * @var array
     */
    private $to = array();

    /**
     * Only the last message gets delivered to the client.
     * @nullable
     */
    private $collapseKey = null;

    /**
     * Key-value pairs od messages payload data.
     * Max payload size is 4kB
     *
     * @nullable
     * @var array|null
     */
    private $data = null;

    /**
     * If included, indicates that the message should not be sent immediately if the device is idle.
     * The server will wait for the device to become active, and then only the last message
     * for each collapse_key value will be sent.
     *
     * @nullable
     * @var boolean
     */
    private $delayWhileIdle = false;

    /**
     * Message should be kept on GCM storage, default 4 weeks
     *
     * @nullable
     * @var int
     */
    private $timeToLive = null;

    /**
     * Only sent to registration IDs that match the package name.
     *
     * @nullable
     * @var string|null
     */
    private $restrictedPackageName = null;

    /**
     * If included, allows developers to test their request without actually sending a message.
     *
     * @nullable
     * @var boolean
     */
    private $dryRun = false;

    /**
     * GCMMessage constructor.
     *
     * @param null        $toRegId
     * @param null|array  $data
     * @param null|string $collapseKey
     */
    public function __construct($toRegId = null, $data = null, $collapseKey = null) {
        if (is_array($toRegId)) {
            foreach ($toRegId as $to) {
                $this->addTo($to);
            }
        } elseif ($toRegId) {
            $this->setTo($toRegId);
        }

        if (!empty($data)) {
            $this->setData($data);
        }

        if ($collapseKey) {
            $this->setCollapseKey($collapseKey);
        }
    }

    public function getTo($onlyOne = false) {
        if ($onlyOne) {
            return current($this->to);
        }

        return $this->to;
    }

    public function setTo($to) {
        $this->to = [];
        $this->addTo($to);

        return $this;
    }

    public function addTo($to) {
        if (!is_string($to)) {
            throw new \RuntimeException("Invalid GCM Registration ID format, string expected");
        }

        $this->to[] = $to;

        return $this;
    }

    public function getCollapseKey() {
        return $this->collapseKey;
    }

    public function setCollapseKey($collapseKey) {
        $this->collapseKey = $collapseKey;

        return $this;
    }

    public function getData() {
        return $this->data;
    }

    public function setData($data) {
        $this->data = $data;

        return $this;
    }

    public function getDelayWhileIdle() {
        return $this->delayWhileIdle;
    }

    public function setDelayWhileIdle($delayWhileIdle) {
        $this->delayWhileIdle = intval($delayWhileIdle);

        return $this;
    }

    public function getTimeToLive() {
        return $this->timeToLive;
    }

    public function setTimeToLive($timeToLive) {
        $this->timeToLive = intval($timeToLive);

        return $this;
    }

    public function getRestrictedPackageName() {
        return $this->restrictedPackageName;
    }

    public function setRestrictedPackageName($restrictedPackageName) {
        $this->restrictedPackageName = $restrictedPackageName;

        return $this;
    }

    public function getDryRun() {
        return $this->dryRun;
    }

    public function setDryRun($dryRun) {
        $this->dryRun = $dryRun;

        return $this;
    }
}