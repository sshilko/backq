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

use GuzzleHttp\Psr7\Request;
use LogicException;
use function GuzzleHttp\Psr7\parse_request;
use function GuzzleHttp\Psr7\str;

class Guzzle extends AbstractMessage
{

    private Request $request;

    private $scheme = null;

    /**
     * Guzzle constructor.
     *
     * @param Request|null $request
     * @param string|null                   $rawRequest
     */
    public function __construct($request = null, ?string $rawRequest = null)
    {
        if ($request) {
            if ('https' === $request->getUri()->getScheme()) {
                $request->withRequestTarget('absolute-form');
                /**
                 * Preserver HTTPS schema correctly
                 */
                $this->scheme = 'https';
            }
            $this->request = str($request);
        } else {
            $this->request = $rawRequest;
        }
        if (empty($this->request)) {
            throw new LogicException('Provide either PSR7 request or PSR-7 compatible request body');
        }
    }

    /**
     */
    public function getRequest(): Request
    {
        $request = parse_request($this->request);
        if (!empty($this->scheme)) {
            $uri    = $request->getUri();
            $newuri = $uri->withScheme($this->scheme);

            return $request->withUri($newuri);
        }

        return $request;
    }
}
