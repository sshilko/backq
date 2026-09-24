<?php

/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

/**
 * Minimal router for `php -S` powering the Guzzle worker examples.
 *
 * The docker-compose stack only runs queue services (redis, nsq), so start a
 * plain HTTP endpoint for the async Guzzle worker to hit:
 *
 *   docker compose exec -d app-php83 php -S 0.0.0.0:18080 example/http/server.php
 *
 * Responds 200 OK to any request.
 */
http_response_code(200);
header('Content-Type: text/plain');
echo 'OK';