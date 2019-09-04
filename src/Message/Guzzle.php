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

    /**
     * Guzzle constructor.
     *
     * @param \GuzzleHttp\Psr7\Request|null $request
     * @param string|null                   $rawRequest
     */
    public function __construct($request = null, string $rawRequest = null) {
        if ($request) {
            if ($request->getUri()->getScheme() === 'https') {
                $request->withRequestTarget('absolute-form');
            }
            $this->request = \GuzzleHttp\Psr7\str($request);
        } else {
            $this->request = $rawRequest;
        }
        if (empty($this->request)) {
            throw new \LogicException('Provide either PSR7 request or PSR-7 compatible request body');
        }
    }

    public function getRequest() : \GuzzleHttp\Psr7\Request {
        return \GuzzleHttp\Psr7\parse_request($this->request);
    }
}
