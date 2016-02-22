<?php
/**
 * GCM Daemon (XMPP CCS) Incoming Message
 *
 * Copyright (c) 2016, Sergey Shilko (contact@sshilko.com)
 *
 * @author Sergey Shilko
 * @see https://github.com/sshilko/backq
 *
 **/

namespace BackQ\Adapter\Gcm;

class RecievedMessage {

	protected $category;
	protected $data;
	protected $timeToLive;
	protected $messageId;
	protected $from;

	function __construct($category, $data, $timeToLive, $messageId, $from)
	{
		$this->category   = (string) $category;
		$this->data       = (object) $data;
		$this->timeToLive = (int)    $timeToLive;
		$this->messageId  = (string) $messageId;
		$this->from       = (string) $from;
	}

	public function getCategory()
	{
		return $this->category;
	}

	public function getData()
	{
		return $this->data;
	}

	public function getTimeToLive()
	{
		return $this->timeToLive;
	}

	public function getMessageId()
	{
		return $this->messageId;
	}

	public function getFrom()
	{
		return $this->from;
	}
}