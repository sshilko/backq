<?php
/**
 *  The MIT License (MIT)
 *
 * Copyright (c) 2017 Sergei Shilko <contact@sshilko.com>
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

class GCMMessage extends AbstractMessage
{
    /**
     * Rcp. GCM IDs (limited to [1,1000] recipients)
     * GCM CCS (XMPP) only accepts 1 recipient per message
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

        if (!empty($data) && is_array($data)) {
            $this->setData($data);
        }

        if ($collapseKey) {
            $this->setCollapseKey($collapseKey);
        }
    }

    public function getRecipientsNumber() {
        return count($this->to);
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
        if (is_array($data)) {
            $this->data = $data;
        }
        return $this;
    }

    public function getDelayWhileIdle() {
        return $this->delayWhileIdle;
    }

    public function setDelayWhileIdle($delayWhileIdle) {
        $this->delayWhileIdle = (bool) ($delayWhileIdle);

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