<?php
/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

namespace BackQ\Worker\Amazon\SNS\Client\Exception;

/**
 * Class SnsException
 * @package BackQ\Worker\Amazon\SNS\Client\Exception
 * @see http://docs.aws.amazon.com/aws-sdk-php/v3/api/class-Aws.Sns.Exception.SnsException.html
 */
class SnsException extends \Aws\Sns\Exception\SnsException
{
    /**
     * Indicates an internal service error.
     */
    const INTERNAL = 'InternalError';

    const INVALID_PARAM     = 'InvalidParameter';

    /**
     * Exception error indicating endpoint disabled.
     */
    const ENDPOINT_DISABLED = 'EndpointDisabled';

    /**
     * Indicates that the user has been denied access to the requested resource.
     */
    const AUTHERROR = 'AuthorizationError';

    /**
     * Indicates that the requested resource does not exist.
     */
    const NOTFOUND  = 'NotFound';


    /**
     * @see http://docs.aws.amazon.com/aws-sdk-php/v3/api/class-Aws.Exception.AwsException.html#_getAwsErrorCode
     *
     * @return string|null
     */
    public function getAwsErrorCode() {
        return parent::getAwsErrorCode();
    }

    /**
     * @see http://docs.aws.amazon.com/aws-sdk-php/v3/api/class-Aws.Exception.AwsException.html#_getAwsErrorType
     *
     * @return string|null
     */
    public function getAwsErrorType() {
        return parent::getAwsErrorType();
    }
}
