<?php
/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

namespace BackQ\Message\Amazon\SNS\Application\PlatformEndpoint;

interface PublishMessageInterface
{
    public function getMessage(): void;

    public function setMessage(array $message): void;

    public function getTargetArn(): string;

    public function setTargetArn(string $targetArn): void;

    public function getAttributes(): array;

    public function setAttributes(array $attrs): void;

    public function getMessageStructure(): string;

    public function setMessageStructure(string $structure): void;
}
