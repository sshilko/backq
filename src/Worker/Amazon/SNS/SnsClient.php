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
