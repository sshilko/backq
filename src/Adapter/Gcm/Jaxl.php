<?php
/**
 * GCM Daemon (XMPP CCS) Jaxl fixed
 *
 * Copyright (c) 2016, Sergey Shilko (contact@sshilko.com)
 *
 * @author Sergey Shilko
 * @see https://github.com/sshilko/backq
 *
 **/

namespace BackQ\Adapter\Gcm;

class Jaxl extends \JAXL {

    public function get_socket_path() {
        $protocol = $this->cfg['port'] == 5223 ? "ssl" : "tcp";

        if(!empty($this->cfg['protocol'])) {
            $protocol = $this->cfg['protocol'];
        }

        return $protocol . "://" . $this->cfg['host'] . ':' . $this->cfg['port'];
    }
}