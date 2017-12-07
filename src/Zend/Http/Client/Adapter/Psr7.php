<?php
class Zend_Http_Client_Adapter_Psr7 extends Zend_Http_Client_Adapter_Test
{
    private $requestRaw = null;

    /**
     * Send request to the remote server
     *
     * @param string        $method
     * @param Zend_Uri_Http $uri
     * @param string        $http_ver
     * @param array         $headers
     * @param string        $body
     * @return string Request as string
     */
    public function write($method, $uri, $http_ver = '1.1', $headers = array(), $body = '')
    {
        $host = $uri->getHost();
        $host = (strtolower($uri->getScheme()) == 'https' ? 'https://' . $host : $host);

        // Build request headers
        $path = $uri->getPath();
        if ($uri->getQuery()) $path .= '?' . $uri->getQuery();
        $request = "{$method} {$host}{$path} HTTP/{$http_ver}\r\n";
        foreach ($headers as $k => $v) {
            if (is_string($k)) $v = ucfirst($k) . ": $v";
            $request .= "$v\r\n";
        }

        // Add the request body
        $request .= "\r\n" . $body;

        // Do nothing - just return the request as string
        $this->requestRaw = $request;
        return $request;
    }

    public function getRequestRaw() {
        /**
         * Result can be used by \GuzzleHttp\Psr7\str($result) to create GuzzleHttp Psr7 request
         */
        return $this->requestRaw;
    }

}
