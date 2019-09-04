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

class ApnsPHP extends \ApnsPHP_Message_Custom
{
    /**
     * Since iOS8 payload was increased from 256b to 2kb
     * When using the HTTP/2 provider API, maximum payload size is 4096 bytes.
     * Using the legacy binary interface, maximum payload size is 2048 bytes.
     * Apple Push Notification service (APNs) refuses any notification that exceeds the maximum size.
     * @see https://developer.apple.com/library/ios/documentation/NetworkingInternet/Conceptual/RemoteNotificationsPG/Chapters/TheNotificationPayload.html#//apple_ref/doc/uid/TP40008194-CH107-SW1
     */
    const PAYLOAD_MAXIMUM_SIZE = 2048;
}
