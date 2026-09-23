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

use GuzzleHttp\Psr7\Message;
use LogicException;
use Psr\Http\Message\RequestInterface;

class Guzzle extends AbstractMessage
{

    private $request;

    private $scheme = null;

    /**
     * Guzzle constructor.
     *
     * @param RequestInterface|null $request
     * @param string|null                   $rawRequest
     */
    public function __construct($request = null, ?string $rawRequest = null)
    {
        if ($request) {
            if ('https' === $request->getUri()->getScheme()) {
                /**
                 * Preserver HTTPS schema correctly
                 */
                $this->scheme = 'https';
            }
            $this->request = Message::toString($request);
        } else {
            $this->request = $rawRequest;
        }
        if (empty($this->request)) {
            throw new LogicException('Provide either PSR7 request or PSR-7 compatible request body');
        }
    }

    /**
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
}
