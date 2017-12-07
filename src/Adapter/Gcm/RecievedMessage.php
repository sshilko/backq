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