<?php

declare(strict_types=1);

/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2026 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

namespace BackQ\Message;

use GuzzleHttp\Psr7\Message;
use GuzzleHttp\Psr7\Request;
use Opis\Closure\SerializableClosure;
use Override;
use Psr\Http\Message\RequestInterface;

class GuzzleForwarder extends AbstractMessage
{

    /**
     * MessageInterface::toString(Psr\Http\Message\RequestInterface)
     */
    private string $request;

    private ?string $scheme = null;

    public function __construct(
        Request $request,
        private ?float $timeout = 5,
        private ?SerializableClosure $callbackOnComplete = null,
        private ?int $expiresAt = null,
    ) {
        if ('https' === $request->getUri()->getScheme()) {
            $request->withRequestTarget('absolute-form');
            /**
             * Preserver HTTPS schema correctly
             */
            $this->scheme = 'https';
        }
        $this->request   = Message::toString($request);
    }

    /**
     * Optional callback after completing a request passing the response body
     */
    public function getCallback(): ?SerializableClosure
    {
        return $this->callbackOnComplete;
    }

    /**
     * Message::parseRequest() returns a PSR-7 request, not necessarily a Guzzle one
     */
    public function getRequest(): RequestInterface
    {
        $request = Message::parseRequest($this->request);
        if (!empty($this->scheme)) {
            $uri    = $request->getUri();
            $newuri = $uri->withScheme($this->scheme);

            return $request->withUri($newuri);
        }

        return $request;
    }

    /**
     */
    public function getTimeout(): ?float
    {
        return $this->timeout;
    }

    /**
     */
    #[Override]
    public function isExpired(): bool
    {
        return $this->expiresAt ? ($this->expiresAt <= time()) : false;
    }
}
