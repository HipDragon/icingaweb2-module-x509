<?php
/* Icinga Web 2 X.509 Module | (c) 2018 Icinga Development Team | GPLv2+ */

namespace Icinga\Module\X509;

use Icinga\Data\ResourceFactory;
use ipl\Sql;

class Command extends \Icinga\Cli\Command
{
    /**
     * Get the connection to the X.509 database
     *
     * @return  Sql\Connection
     */
    public function getDb()
    {
        $config = new Sql\Config(ResourceFactory::getResourceConfig(
            $this->Config()->get('backend', 'resource')
        ));

        $conn = new Sql\Connection($config);

        return $conn;
    }
}
