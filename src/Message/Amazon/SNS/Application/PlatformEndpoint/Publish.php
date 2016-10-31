<?php
/**
 * Copyright (c) 2016, Tripod Technology GmbH <support@tandem.net>
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without modification,
 * are permitted provided that the following conditions are met:
 *
 *    1. Redistributions of source code must retain the above copyright notice,
 *       this list of conditions and the following disclaimer.
 *
 *    2. Redistributions in binary form must reproduce the above copyright notice,
 *       this list of conditions and the following disclaimer in the documentation
 *       and/or other materials provided with the distribution.
 *
 *    3. Neither the name of Tripod Technology GmbH nor the names of its contributors
 *       may be used to endorse or promote products derived from this software
 *       without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
 * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR
 * ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON
 * ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
 * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 */

namespace BackQ\Message\Amazon\SNS\Application\PlatformEndpoint;

class Publish
{
    protected $attributes;

    /**
     * Amazon Resource name that uniquely identifies a Resource on AWS that we'll
     * publish to, in this case it's an endpoint
     * @var string
     */
    protected $targetArn;

    /**
     * Takes the data and properly assigns it to a json encoded array to wrap
     * a subset of Gcm format into a customContent key
     *
     * @return string
     */
    public function toJson()
    {
        $json = [];

        /**
         * Group all notification's attributes into 'data' key
         * Other attributes such as ttl and collapse key are not needed
         */
        if (!empty($this->gcmMessage->getNotification())) {
            foreach ($this->gcmMessage->getNotification() as $nk => $nv) {
                $this->gcmMessage->addData('notification.' . $nk, $nv);
            }
        }
        if ($this->gcmMessage->getData()) {
            $json['data'] = $this->gcmMessage->getData();
        }

        $result = json_encode(['customContent' => $json]);
        return $result;
    }

    /**
     * Returns the Amazon Resource Name for the endpoint a message should be published to
     *
     * @return string
     */
    public function getTargetArn()
    {
        return $this->targetArn;
    }

    /**
     * Gets specific attributes to complete a Publish operation to an endpoint
     *
     * @return array
     */
    public function getAttributes()
    {
        return $this->attributes;
    }
}
