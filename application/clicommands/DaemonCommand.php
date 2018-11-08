<?php
/* X509 module | (c) 2018 Icinga Development Team | GPLv2+ */

namespace Icinga\Module\X509\Clicommands;

use DateTime;
use Exception;
use Icinga\Application\Config as IniConfig;
use Icinga\Application\Config;
use Icinga\Cli\Command;
use Icinga\Data\ResourceFactory;
use ipl\Sql\Config as DbConfig;
use ipl\Sql\Connection;
use ipl\Sql\Expression;
use ipl\Sql\Insert;
use ipl\Sql\Select;
use ipl\Sql\Update;
use React\EventLoop\Factory;
use React\Socket\Connector;
use React\Socket\TimeoutConnector;
use React\Socket\SecureConnector;
use React\Socket\ConnectionInterface;

class DaemonCommand extends Command
{
    /**
     * Possible public key types
     *
     * @var string[]
     */
    protected $pubkeyTypes = [
        -1                  => 'unknown',
        OPENSSL_KEYTYPE_RSA => 'RSA',
        OPENSSL_KEYTYPE_DSA => 'DSA',
        OPENSSL_KEYTYPE_DH  => 'DH',
        OPENSSL_KEYTYPE_EC  => 'EC'
    ];

    /**
     * @var Connection
     */
    private $db;

    private $loop;
    private $connector;
    private $pendingTargets = 0;
    private $targets;

    private static function addrToNumber($addr) {
        return gmp_import(inet_pton($addr));
    }

    private static function numberToAddr($num) {
        return inet_ntop(str_pad(gmp_export($num), 16, "\0", STR_PAD_LEFT));
    }

    private static function generateTargets()
    {
        foreach (Config::module('x509', 'ipranges') as $cidr => $ports) {
            $cidr = explode('/', $cidr);
            $start_ip = $cidr[0];
            $prefix = $cidr[1];
            $ip_count = 1 << (128 - $prefix);
            $start = DaemonCommand::addrToNumber($start_ip);
            for ($i = 0; $i < $ip_count; $i++) {
                $ip = DaemonCommand::numberToAddr(gmp_add($start, $i));
                foreach ($ports as $start_port => $end_port) {
                    foreach (range($start_port, $end_port) as $port) {
                        $target = (object) [];
                        $target->ip = $ip;
                        $target->port = $port;
                        yield $target;
                    }
                }
            }
        }
    }

    private static function pem2der($pem) {
        $lines = explode("\n", $pem);

        $der = '';

        foreach ($lines as $line) {
            if (strstr($line, '-----') === 0) {
                continue;
            }

            $der .= base64_decode($line);
        }

        return $der;
    }

    private function findOrInsertCert($cert, $certInfo) {
        $fingerprint = openssl_x509_fingerprint($cert, 'sha256', true);

        $row = $this->db->select(
            (new Select())
                ->columns(['id'])
                ->from('certificate')
                ->where(['fingerprint = ?' => $fingerprint ])
        )->fetch();

        if ($row === false) {
            $pem = null;
            if (!openssl_x509_export($cert, $pem)) {
                die("Failed to encode X.509 certificate.");
            }
            $der = DaemonCommand::pem2der($pem);

            $signaturePieces = explode('-', $certInfo['signatureTypeSN']);

            $pubkeyDetails = openssl_pkey_get_details(openssl_pkey_get_public($cert));

            $this->db->insert(
                (new Insert())
                    ->into('certificate')
                    ->values([
                        'certificate'           => $der,
                        'fingerprint'           => $fingerprint,
                        'version'               => $certInfo['version'],
                        'pubkey_algo'           => $this->pubkeyTypes[$pubkeyDetails['type']],
                        'pubkey_bits'           => $pubkeyDetails['bits'],
                        'signature_algo'        => $signaturePieces[0],
                        'signature_hash_algo'   => $signaturePieces[1],
                        'valid_start'           => $certInfo['validFrom_time_t'],
                        'valid_end'             => $certInfo['validTo_time_t']
                    ])
            );

            $certId = $this->db->lastInsertId();

            $this->insertDn($certId, 'issuer', $certInfo);
            $this->insertDn($certId, 'subject', $certInfo);

            $this->insertSANs($certId, $certInfo);
            } else {
            $certId = $row['id'];
        }

        return $certId;
    }

    private function insertSANs($certId, array $certInfo) {
        if (isset($certInfo['extensions']['subjectAltName'])) {
            $names = explode(', ', $certInfo['extensions']['subjectAltName']);

            foreach ($names as $san) {
                list($type, $name) = explode(':', $san);

                $this->db->insert(
                    (new Insert())
                        ->into('certificate_subject_alt_name')
                        ->columns(['certificate_id', 'type', 'value'])
                        ->values([$certId, $type, $name])
                );
            }
        }
    }

    private function insertDn($certId, $type, array $certInfo) {
        $index = 0;
        foreach ($certInfo[$type] as $key => $value) {
            if (!is_array($value)) {
                $values = [$value];
            } else {
                $values = $value;
            }

            foreach ($values as $value) {
                $this->db->insert(
                    (new Insert())
                        ->into("certificate_{$type}_dn")
                        ->columns(['certificate_id', '`key`', '`value`', '`order`'])
                        ->values([$certId, $key, $value, $index])
                );
                $index++;
            }
        }
    }

    private function startNextTarget()
    {
        if (!$this->targets->valid()) {
            if ($this->pendingTargets == 0) {
                $this->loop->stop();
            }

            return;
        }

        $target = $this->targets->current();
        $this->targets->next();

        $url = "tls://[{$target->ip}]:{$target->port}";
        //echo "Connecting to {$url}\n";
        $this->pendingTargets++;
        $this->connector->connect($url)->then(
            function (ConnectionInterface $conn) use ($target) {
                $this->finishTarget();

                $stream = $conn->stream;
                $options = stream_context_get_options($stream);
                echo "Connected to {$conn->getRemoteAddress()}\n";
                $chain = $options['ssl']['peer_certificate_chain'];

                $this->db->transaction(function () use ($target, $chain) {
                    $row = $this->db->select(
                        (new Select())
                            ->columns(['id'])
                            ->from('certificate_chain')
                            ->where(['ip = ?' => inet_pton($target->ip), 'port = ?' => $target->port, 'sni_name = ?' => '' ])
                    )->fetch();

                    if ($row === false) {
                        $this->db->insert(
                            (new Insert())
                                ->into('certificate_chain')
                                ->columns(['ip', 'port', 'sni_name'])
                                ->values([inet_pton($target->ip), $target->port, ''])
                        );
                        $chainId = $this->db->lastInsertId();
                    } else {
                        $chainId = $row['id'];
                    }

                    $this->db->insert(
                        (new Insert())
                            ->into('certificate_chain_log')
                            ->columns(['certificate_chain_id', 'length'])
                            ->values([$chainId, count($chain)])
                    );
                    $chainLogId = $this->db->lastInsertId();

                    $this->db->update(
                        (new Update())
                            ->table('certificate_chain')
                            ->set(['latest_log_id' => $chainLogId])
                            ->where(['id = ?' => $chainId])
                    );

                    foreach ($chain as $index => $cert) {
                        $certInfo = openssl_x509_parse($cert);

                        $certId = $this->findOrInsertCert($cert, $certInfo);

                        $this->db->insert(
                            (new Insert())
                                ->into('certificate_chain_link')
                                ->columns(['certificate_chain_log_id', '`order`', 'certificate_id'])
                                ->values([$chainLogId, $index, $certId])
                        );
                    }
                });
            },
            function (Exception $exception) {
                $this->finishTarget();

                //echo "Cannot connect to server: {$exception->getMessage()}\n";
                //$loop->stop();
            }
        )->otherwise(function($ex) {
            var_dump($ex);
        });
    }

    function finishTarget()
    {
        $this->pendingTargets--;
        $this->startNextTarget();
    }

    public function indexAction()
    {
        $config = new DbConfig(ResourceFactory::getResourceConfig(
            IniConfig::module('x509')->get('backend', 'resource')
        ));
        $this->db = new Connection($config);

        $this->loop = Factory::create();

        $simpleConnector = new Connector($this->loop);
        $secureConnector = new SecureConnector($simpleConnector, $this->loop, array(
            'verify_peer' => false,
            'verify_peer_name' => false,
            'capture_peer_cert_chain' => true,
        ));
        $this->connector = new TimeoutConnector($secureConnector, 5.0, $this->loop);

        $this->targets = self::generateTargets();

        // Start scanning the first couple of targets...
        for ($i = 0; $i < 256; $i++) {
            $this->startNextTarget();
        }

        $this->loop->run();
    }
}
