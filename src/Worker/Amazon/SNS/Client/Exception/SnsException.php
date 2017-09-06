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
