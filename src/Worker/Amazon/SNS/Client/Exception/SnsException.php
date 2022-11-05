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
    public const INTERNAL = 'InternalError';

    public const INVALID_PARAM     = 'InvalidParameter';

    /**
     * Exception error indicating endpoint disabled.
     */
    public const ENDPOINT_DISABLED = 'EndpointDisabled';

    /**
     * Indicates that the user has been denied access to the requested resource.
     */
    public const AUTHERROR = 'AuthorizationError';

    /**
     * Indicates that the requested resource does not exist.
     */
    public const NOTFOUND  = 'NotFound';

    /**
     * @see http://docs.aws.amazon.com/aws-sdk-php/v3/api/class-Aws.Exception.AwsException.html#_getAwsErrorCode
     *
     */
    public function getAwsErrorCode(): ?string
    {
        return parent::getAwsErrorCode();
    }

    /**
     * @see http://docs.aws.amazon.com/aws-sdk-php/v3/api/class-Aws.Exception.AwsException.html#_getAwsErrorType
     *
     */
    public function getAwsErrorType(): ?string
    {
        return parent::getAwsErrorType();
    }
}
