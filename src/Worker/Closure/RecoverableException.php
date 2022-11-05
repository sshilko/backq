<?php
/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Carolina Alarcon
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

namespace BackQ\Worker\Closure;

use Exception;

/**
 * Class RecoverableException
 * Represents an temporary exception while executing a closure
 * @package BackQ\Worker\Closure
 */
final class RecoverableException extends Exception
{

}
