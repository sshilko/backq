<?php
/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
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
