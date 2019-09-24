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

class Guzzle extends AbstractMessage
{
    /**
     * @var \GuzzleHttp\Psr7\Request
     */
    private $request;

    private $scheme = null;

    /**
     * Guzzle constructor.
     *
     * @param \GuzzleHttp\Psr7\Request|null $request
     * @param string|null                   $rawRequest
     */
    public function __construct($request = null, string $rawRequest = null)
    {
        if ($request) {
            if ($request->getUri()->getScheme() === 'https') {
                $request->withRequestTarget('absolute-form');
                /**
                 * Preserver HTTPS schema correctly
                 */
                $this->scheme = 'https';
            }
            $this->request = \GuzzleHttp\Psr7\str($request);
        } else {
            $this->request = $rawRequest;
        }
        if (empty($this->request)) {
            throw new \LogicException('Provide either PSR7 request or PSR-7 compatible request body');
        }
    }

    /**
     * @return \GuzzleHttp\Psr7\Request
     */
    public function getRequest(): \GuzzleHttp\Psr7\Request
    {
        $request = \GuzzleHttp\Psr7\parse_request($this->request);
        if (!empty($this->scheme)) {
            $uri    = $request->getUri();
            $newuri = $uri->withScheme($this->scheme);
            return $request->withUri($newuri);
        }
        return $request;
    }
}
