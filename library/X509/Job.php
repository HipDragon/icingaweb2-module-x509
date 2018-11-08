<?php

namespace Icinga\Module\X509;

use Icinga\Application\Config;
use Icinga\Application\Logger;
use Icinga\Data\ConfigObject;
use Icinga\Util\StringHelper;
use ipl\Sql\Connection;
use ipl\Sql\Expression;
use ipl\Sql\Insert;
use ipl\Sql\Select;
use ipl\Sql\Update;
use React\EventLoop\Factory;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use React\Socket\SecureConnector;
use React\Socket\TimeoutConnector;

class Job
{
    /**
     * @var Connection
     */
    private $db;
    private $loop;
    private $pendingTargets = 0;
    private $totalTargets = 0;
    private $finishedTargets = 0;
    private $targets;
    private $jobId;
    private $jobDescription;
    private $snimap;
    private $parallel;
    private $name;

    public function __construct($name, Connection $db, ConfigObject $jobDescription, Config $snimap, $parallel)
    {
        $this->db = $db;
        $this->jobDescription = $jobDescription;
        $this->snimap = $snimap;
        $this->parallel = $parallel;
        $this->name = $name;
    }

    private function getConnector($peerName) {
        $simpleConnector = new Connector($this->loop);
        $secureConnector = new SecureConnector($simpleConnector, $this->loop, array(
            'verify_peer' => false,
            'verify_peer_name' => false,
            'capture_peer_cert_chain' => true,
            'SNI_enabled' => true,
            'peer_name' => $peerName
        ));
        return new TimeoutConnector($secureConnector, 5.0, $this->loop);
    }

    private static function addrToNumber($addr) {
        return gmp_import(inet_pton($addr));
    }

    private static function numberToAddr($num) {
        return inet_ntop(str_pad(gmp_export($num), 16, "\0", STR_PAD_LEFT));
    }

    private static function generateTargets(ConfigObject $jobDescription, Config $hostnamesConfig)
    {
        foreach (StringHelper::trimSplit($jobDescription->get('cidrs')) as $cidr) {
            $pieces = explode('/', $cidr);
            $start_ip = $pieces[0];
            $prefix = $pieces[1];
            $ip_count = 1 << (128 - $prefix);
            $start = static::addrToNumber($start_ip);
            for ($i = 0; $i < $ip_count; $i++) {
                $ip = static::numberToAddr(gmp_add($start, $i));
                foreach (StringHelper::trimSplit($jobDescription->get('ports')) as $portRange) {
                    $pieces = StringHelper::trimSplit($portRange, '-');
                    if (count($pieces) === 2) {
                        list($start_port, $end_port) = $pieces;
                    } else {
                        $start_port = $pieces[0];
                        $end_port = $pieces[0];
                    }

                    foreach (range($start_port, $end_port) as $port) {
                        $hostnames = StringHelper::trimSplit($hostnamesConfig->get($ip, 'hostnames'));

                        //var_dump($hostnames);die;
                        if (!in_array('', $hostnames)) {
                            $hostnames[] = '';
                        }

                        foreach ($hostnames as $hostname) {
                            $target = (object)[];
                            $target->ip = $ip;
                            $target->port = $port;
                            $target->hostname = $hostname;
                            yield $target;
                        }
                    }
                }
            }
        }
    }

    private function updateJobStats($finished = false) {
        $fields = ['finished_targets' => $this->finishedTargets];

        if ($finished) {
            $fields['end_time'] = new Expression('NOW()');
        }

        $this->db->update(
            (new Update())
                ->table('x509_job_run')
                ->set($fields)
                ->where(['id = ?' => $this->jobId])
        );
    }

    private static function formatTarget($target) {
        $result = "tls://[{$target->ip}]:{$target->port}";

        if ($target->hostname !== '') {
            $result .= " [SNI hostname: {$target->hostname}]";
        }

        return $result;
    }


    function finishTarget()
    {
        $this->pendingTargets--;
        $this->finishedTargets++;
        $this->startNextTarget();
    }

    private function startNextTarget()
    {
        if (!$this->targets->valid()) {
            if ($this->pendingTargets == 0) {
                $this->updateJobStats(true);
                $this->loop->stop();
            }

            return;
        }

        $target = $this->targets->current();
        $this->targets->next();

        $url = "tls://[{$target->ip}]:{$target->port}";
        Logger::debug("Connecting to %s", static::formatTarget($target));
        $this->pendingTargets++;
        $this->getConnector($target->hostname)->connect($url)->then(
            function (ConnectionInterface $conn) use ($target) {
                $this->finishTarget();

                Logger::info("Connected to %s", static::formatTarget($target));

                $stream = $conn->stream;
                $options = stream_context_get_options($stream);

                $conn->close();

                $chain = $options['ssl']['peer_certificate_chain'];

                $this->db->transaction(function () use($target, $chain) {
                    $row = $this->db->select(
                        (new Select())
                            ->columns(['id'])
                            ->from('x509_target')
                            ->where(['ip = ?' => inet_pton($target->ip), 'port = ?' => $target->port, 'sni_name = ?' => $target->hostname ])
                    )->fetch();

                    if ($row === false) {
                        $this->db->insert(
                            (new Insert())
                                ->into('x509_target')
                                ->columns(['ip', 'port', 'sni_name'])
                                ->values([inet_pton($target->ip), $target->port, $target->hostname])
                        );
                        $targetId = $this->db->lastInsertId();
                    } else {
                        $targetId = $row['id'];
                    }

                    $this->db->insert(
                        (new Insert())
                            ->into('x509_certificate_chain')
                            ->columns(['target_id', 'length'])
                            ->values([$targetId, count($chain)])
                    );
                    $chainId = $this->db->lastInsertId();

                    $this->db->update(
                        (new Update())
                            ->table('x509_target')
                            ->set(['latest_certificate_chain_id' => $chainId])
                            ->where(['id = ?' => $targetId])
                    );

                    foreach ($chain as $index => $cert) {
                        $certInfo = openssl_x509_parse($cert);

                        $certId = CertificateUtils::findOrInsertCert($this->db, $cert, $certInfo);

                        $this->db->insert(
                            (new Insert())
                                ->into('x509_certificate_chain_link')
                                ->columns(['certificate_chain_id', '`order`', 'certificate_id'])
                                ->values([$chainId, $index, $certId])
                        );
                    }
                });
            },
            function (\Exception $exception) use($target) {
                Logger::debug("Cannot connect to server: %s", $exception->getMessage());

                $this->db->update(
                    (new Update())
                        ->table('x509_target')
                        ->set(['latest_certificate_chain_id' => null])
                        ->where(['ip = ?' => inet_pton($target->ip), 'port = ?' => $target->port, 'sni_name = ?' => '' ])
                );

                $this->finishTarget();

                $step = max($this->totalTargets / 100, 1);

                if ($this->finishedTargets % $step == 0) {
                    $this->updateJobStats();
                }
                //$loop->stop();
            }
        )->otherwise(function($ex) {
            var_dump($ex);
        });
    }

    public function getJobId()
    {
        return $this->jobId;
    }

    public function run()
    {
        $this->loop = Factory::create();

        $this->totalTargets = iterator_count(static::generateTargets($this->jobDescription, $this->snimap));

        if ($this->totalTargets == 0) {
            return null;

        }

        $this->targets = static::generateTargets($this->jobDescription, $this->snimap);

        $this->db->insert(
            (new Insert())
                ->into('x509_job_run')
                ->values([
                    'name' => $this->name,
                    'total_targets' => $this->totalTargets,
                    'finished_targets' => 0
                ])
        );

        $this->jobId = $this->db->lastInsertId();

        // Start scanning the first couple of targets...
        for ($i = 0; $i < $this->parallel; $i++) {
            $this->startNextTarget();
        }

        $this->loop->run();

        return $this->totalTargets;
    }
}
