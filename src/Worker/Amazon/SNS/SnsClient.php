<?php
/**
 * Copyright (c) 2017, Sergei Shilko <contact@sshilko.com>
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
 *    3. Neither the name of Sergei Shilko nor the names of its contributors
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
namespace BackQ\Worker\Amazon\SNS;

class SnsClient extends \Aws\Sns\SnsClient
{
    /**
     * Sends a message to all of a topic's subscribed endpoints. When a messageId is returned,
     * the message has been saved and Amazon SNS will attempt to deliver it to the topic's subscribers shortly.
     * The format of the outgoing message to each subscribed endpoint depends on the notification protocol.
     *
     * @see http://docs.aws.amazon.com/sns/latest/api/API_Publish.html
     * @see http://docs.aws.amazon.com/aws-sdk-php/v3/api/api-sns-2010-03-31.html#publish
     *
     * @param array $data
     * @return mixed
     */
    public function publish(array $data) {
        return parent::publish($data);
    }

    /**
     * Deletes the endpoint for a device and mobile app from Amazon SNS.
     * This action is idempotent.
     *
     * @see http://docs.aws.amazon.com/aws-sdk-php/v3/api/api-sns-2010-03-31.html#deleteendpoint
     * @see http://docs.aws.amazon.com/sns/latest/api/API_DeleteEndpoint.html#API_DeleteEndpoint_Errors
     *
     * @param array $data
     * @return mixed
     */
    public function deleteEndpoint(array $data) {
        return parent::deleteEndpoint($data);
    }

    /**
     * Creates an endpoint for a device and mobile app on one of the supported push notification services,
     * such as GCM and APNS.
     * CreatePlatformEndpoint requires the PlatformApplicationArn that is returned from CreatePlatformApplication.
     * The EndpointArn that is returned when using CreatePlatformEndpoint can then be used by the Publish action to send
     * a message to a mobile app or by the Subscribe action for subscription to a topic.
     *
     * The CreatePlatformEndpoint action is idempotent, so if the requester already owns an endpoint with the same device
     * token and attributes, that endpoint's ARN is returned without creating a new endpoint.
     *
     * @see http://docs.aws.amazon.com/aws-sdk-php/v3/api/api-sns-2010-03-31.html#createplatformendpoint
     * @see http://docs.aws.amazon.com/sns/latest/api/API_CreatePlatformEndpoint.html
     * @param array $data
     *
     * @return mixed
     */
    public function createPlatformEndpoint(array $data) {
        return parent::createPlatformEndpoint($data);
    }

}
