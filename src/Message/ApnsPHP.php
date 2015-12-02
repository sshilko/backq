<?php
/**
 * BackQ
 *
 * Copyright (c) 2015, Sergey Shilko (contact@sshilko.com)
 *
 * @author Sergey Shilko
 * @see https://github.com/sshilko/backq
 *
 **/
namespace BackQ\Message;

class ApnsPHP extends \ApnsPHP_Message_Custom
{
    /**
     * Since iOS8 payload was increased from 256b to 2kb
     */
    const PAYLOAD_MAXIMUM_SIZE = 2048;
}
