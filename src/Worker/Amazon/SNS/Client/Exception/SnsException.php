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
     * @return mixed
     */
    public function getAwsErrorCode() {
        return parent::getAwsErrorCode();
    }

    /**
     * @see http://docs.aws.amazon.com/aws-sdk-php/v3/api/class-Aws.Exception.AwsException.html#_getAwsErrorType
     *
     * @return mixed
     */
    public function getAwsErrorType() {
        return parent::getAwsErrorType();
    }
}
