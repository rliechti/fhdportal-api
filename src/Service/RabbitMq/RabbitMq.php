<?php

namespace App\Service\RabbitMq;

use MeekroDB;
use Exception;
use PhpAmqpLib\Connection\AMQPConnectionConfig;
use PhpAmqpLib\Connection\AMQPConnectionFactory;
use PhpAmqpLib\Message\AMQPMessage;

// $dotenv = new Symfony\Component\Dotenv\Dotenv();
// $dotenv->loadEnv(dirname(dirname(dirname(__DIR__))).'/.env.local', overrideExistingVars: true);

class RabbitMq implements RabbitMqInterface
{
    public string $mq_exchange;
    private string $mq_host;
    private int $mq_port;
    private string $mq_user;
    private string $mq_pwd;
    private string $mq_vhost;
    private bool $isSecure;
    private AMQPConnectionConfig $config;
    private AMQPConnectionFactory $factory;
    private mixed $connection;
    private mixed $channel;
    private mixed $callback_queue;
    private mixed $response;
    private string $corr_id;
    private MeekroDB $db;


    public function __construct(MeekroDB $db)
    {
        $this->db = $db;
        $this->mq_host        = $_ENV['MQ_HOST'];
        $this->mq_port        = $_ENV['MQ_PORT'];
        $this->mq_user        = $_ENV['MQ_USER'];
        $this->mq_pwd         = $_ENV['MQ_PWD'];
        $this->mq_vhost       = $_ENV['MQ_VHOST'];
        $this->mq_exchange    = $_ENV['MQ_EXCHANGE'];
        $this->config = new AMQPConnectionConfig();
        $this->config->setHost($this->mq_host);
        $this->config->setPort($this->mq_port);
        $this->config->setUser($this->mq_user);
        $this->config->setPassword($this->mq_pwd);
        $this->config->setVhost($this->mq_vhost);
        $this->isSecure = ($this->mq_host != 'localhost' && strpos($this->mq_host, 'rabbitmq') === false);
        $this->config->setIsSecure($this->isSecure);
        $this->config->setSslVerify(false);
        $this->factory = new AMQPConnectionFactory();
        $this->connection = $this->factory->create($this->config);
        $this->channel = $this->connection->channel();
        // $this->fegaPrefix = (isset($_ENV['FEGA_PREFIX'])) ? $_ENV['FEGA_PREFIX'] : "CHF";
    }

    private function formatPublicId4Sda(string $id): string
    {
        // if (substr($id,0,strlen($this->fegaPrefix)) == $this->fegaPrefix){
        //     return substr($id,strlen($this->fegaPrefix));
        // }
        return $id;
    }

    public function sendMessage(array $msg, string $routing_key, ?string $correlation_id = null): string
    {

        $this->response = null;
        if (!$correlation_id) {
            $correlation_id = uniqid();
        }
        $rmq_msg = new AMQPMessage(
            json_encode($msg),
            array(
                "correlation_id" => $correlation_id,
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT
            )
        );
        error_log("RMQ [" . $routing_key . "] " . json_encode($msg));
        $this->channel->basic_publish($rmq_msg, $this->mq_exchange, $routing_key);
        return "";
    }



    public function mapSDAfiles(array $sdafiles): void
    {
        $datasets = array();
        foreach ($sdafiles as $sdafile) {
            $dataset_id =  $this->formatPublicId4Sda($sdafile['dataset_public_id']);
            if (!isset($datasets[$dataset_id])) {
                $datasets[$dataset_id] = array();
            }
            if (!in_array($sdafile['sdafile_public_id'], $datasets[$dataset_id])) {
                $datasets[$dataset_id][] = $sdafile['sdafile_public_id'];
            }
        }
        foreach ($datasets as $dataset_id => $accession_ids) {
            foreach ($accession_ids as $idx => $id) {

                // send accession message to trigger copy in archive and backup

                $file_data = $this->db->queryFirstRow(
                    "SELECT
                	sdafile_view.properties->>'filepath' as filepath,
                	sdafile_view.creator_username as username,
                	sdafile_view.properties->>'decrypted_checksums' as decrypted_checksums,
                	rmq_correlation.correlation_id
                FROM
                	sdafile_view
                	inner join rmq_correlation on sdafile_view.file_id = rmq_correlation.resource_id
                WHERE
                	sdafile_view.public_id = %s",
                    $id
                );
                if (!$file_data) {
                    throw new Exception("Error: no file found with accession " . $id, 500);
                }
                $accession_ids[$idx] = $this->formatPublicId4Sda($id);
                $accession_msg = array(
                    "type" => "accession",
                    "user" => $file_data['username'],
                    "filepath" => $file_data['filepath'],
                    "accession_id" => $accession_ids[$idx],
                    "decrypted_checksums" => json_decode($file_data['decrypted_checksums'], true)
                );
                $this->sendMessage($accession_msg, 'accession', $file_data['correlation_id']);
            }
            $msg = array(
                "type" => "mapping",
                "dataset_id" => $dataset_id,
                "accession_ids" => $accession_ids
            );
            $this->sendMessage($msg, 'dataset.mapping');
        }
    }

    public function releaseDataset(string $dataset_id, string $email, string $timestamp): void
    {
        $msg = array(
            "type" => "release",
            "dataset_id" => $this->formatPublicId4Sda($dataset_id),
            "user" => $email,
            "timestamp" => $timestamp
        );
        $this->sendMessage($msg, 'dataset.release');
    }

    public function permissionDataset(array $params): array
    {
        $dataset_id = $this->db->queryFirstField("SELECT public_id from dataset_view where id = %s_dataset_id and status_type_id = 'PUB'", $params);
        if (!$dataset_id) {
            return [
                "status"    => "error",
                "message"   => 'Dataset unknown or not public',
                "exit_code" => 500
            ];
        }
        $correlation_id = $this->db->queryFirstField("SELECT * from rmq_correlation where resource_id = %s_dataset_id and dataset_request_id = %s_request_id", $params);
        if (!$correlation_id) {
            return [
                "status"    => "error",
                "message"   => 'RMQ Correlation is unknown',
                "exit_code" => 500
            ];
        }
        $json_user = $this->db->queryFirstField("SELECT properties from \"user\" where id = %i_requester_id", $params);
        if (!$json_user) {
            return [
                "status"    => "error",
                "message"   => 'User is unknown',
                "exit_code" => 500
            ];
        }
        $user = json_decode($json_user, true);

        $date = new \DateTime('now', new \DateTimeZone('UTC'));
        $currentDate = $date->format("Y-m-d\TH:i:s.uP");

        /*
{
    "type": "swiss_download",
    "user": {
        "email": "lbrechbuehl@ethz.ch",
        "username": "lbrechbuehl",
        "c4gh_key": "-----BEGIN CRYPT4GH PUBLIC KEY-----\nJkHpSPcrt5iueNSvfggmRtGKCcyAr8njac59q10MKSw=\r\n-----END CRYPT4GH PUBLIC KEY-----"
    },
    "dataset_id": "CHFEGAD99999900002"
}
        */

        $msg = array(
            "type" => "swiss_download",
            "dataset_id" => $this->formatPublicId4Sda($dataset_id),
            "user" => array(
                "email" => $user['email'],
                "username" => $user['preferred_username'],
                "c4gh_key" => $params['c4gh_public_key']
            )
        );
        $this->sendMessage($msg, 'dataset.permission', $correlation_id);
        return array(
            "status" => "success",
            "message" => "RMQ message sent successfully",
            "exit_code" => 200
        );
    }
}
