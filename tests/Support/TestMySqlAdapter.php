<?php

namespace BackQ\Tests\Support;

use BackQ\Adapter\MySql;

/**
 * Concrete MySql adapter, the adapter itself is abstract.
 *
 * The link and the job config are injected, so a test can build the adapter
 * without a MySQL server.
 */
class TestMySqlAdapter extends MySql
{
}
