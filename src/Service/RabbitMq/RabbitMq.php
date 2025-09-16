<?php

namespace App\Service\RabbitMq;

use Symfony\Component\Dotenv\Dotenv;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Connection\AMQPConnectionConfig;
use PhpAmqpLib\Connection\AMQPConnectionFactory;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use DB;

// $dotenv = new Symfony\Component\Dotenv\Dotenv();
// $dotenv->loadEnv(dirname(dirname(dirname(__DIR__))).'/.env.local', overrideExistingVars: true);

class RabbitMq
{
    /**
     * @var mixed
     */
    public $mq_exchange;
    /**
     * @var mixed
     */
    public $mq_routing_key;
    private $mq_host;
    private $mq_port;
    private $mq_user;
    private $mq_pwd;
    private $mq_vhost;
    private $isSecure;
    private $config;
    private $factory;
    private $connection;
    private $channel;
    private $callback_queue;
    private $response;
    private $corr_id;
    

    public function __construct()
    {
        $this->mq_host        = $_SERVER['MQ_HOST'];
        $this->mq_port        = $_SERVER['MQ_PORT'];
        $this->mq_user        = $_SERVER['MQ_USER'];
        $this->mq_pwd         = $_SERVER['MQ_PWD'];
        $this->mq_vhost       = $_SERVER['MQ_VHOST_SWISSFEGADEV'];
        $this->mq_exchange    = $_SERVER['MQ_EXCHANGE'];
        $this->mq_routing_key = $_SERVER['MQ_ROUTING_KEY'];
        $this->config = new AMQPConnectionConfig();
        $this->config->setHost($this->mq_host);
        $this->config->setPort($this->mq_port);
        $this->config->setUser($this->mq_user);
        $this->config->setPassword($this->mq_pwd);
        $this->config->setVhost($this->mq_vhost);
        $this->isSecure = ($this->mq_host != 'localhost' && $this->mq_host != 'rabbitmq');
        $this->config->setIsSecure($this->isSecure);
        $this->config->setSslVerify(false);
        $this->factory = new AMQPConnectionFactory();
        $this->connection = $this->factory->create($this->config);
        $this->channel = $this->connection->channel();   
        $this->fegaPrefix = (isset($_SERVER['FEGA_PREFIX'])) ? $_SERVER['FEGA_PREFIX'] : "CHF";
    }

    private function formatPublicId4Sda($id){
        if (substr($id,0,strlen($this->fegaPrefix)) == $this->fegaPrefix){
            return substr($id,strlen($this->fegaPrefix));
        }
        return $id;
    }

    public function sendMessage($msg, $routing_key,$correlation_id=null)
    {

        $this->response = null;
        if (!$correlation_id){
            $correlation_id = uniqid();
        }   
        $rmq_msg = new AMQPMessage(
            json_encode($msg),
            array(
                "correlation_id" => $correlation_id,
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT
            )
        );
        $this->channel->basic_publish($rmq_msg, $this->mq_exchange ,$routing_key);
        return "";
    }



    public function mapSDAfiles($sdafiles)
    {
        $datasets = array();
        foreach($sdafiles as $sdafile){
            $dataset_id =  $this->formatPublicId4Sda($sdafile['dataset_public_id']);
            if (!isset($datasets[$dataset_id])){
                $datasets[$dataset_id] = array();
            }
            if (!in_array($sdafile['sdafile_public_id'],$datasets[$dataset_id])){
                $datasets[$dataset_id][] = $sdafile['sdafile_public_id'];    
            }
        }
        foreach($datasets as $dataset_id => $accession_ids){            
            foreach($accession_ids as $idx => $id){
                
                // send accession message to trigger copy in archive and backup
                
                $file_data = DB::queryFirstRow("SELECT
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
                if (!$file_data){
                    throw new Exception("Error: no file found with accession ".$id, 500);
                }
                $accession_ids[$idx] = $this->formatPublicId4Sda($id);
                $accession_msg = array(
                       "type" => "accession",
                       "user" => $file_data['username'],
                       "filepath" => $file_data['filepath'],
                       "accession_id" => $accession_ids[$idx],
                       "decrypted_checksums" => json_decode($file_data['decrypted_checksums'],true)
                );
                $this->sendMessage($accession_msg,'accession',$file_data['correlation_id']);
            }
            $msg = array(
                   "type" => "mapping",
                   "dataset_id" => $dataset_id,
                   "accession_ids" => $accession_ids
            );
            $this->sendMessage($msg, 'dataset.mapping');
        }
        return null;
    }

    public function releaseDataset($dataset_id,$email,$timestamp)
    {
        $msg = array(
               "type" => "release",
               "dataset_id" => $this->formatPublicId4Sda($dataset_id),
               "user" => $email,
               "timestamp" => $timestamp
        );
        $this->sendMessage($msg, 'dataset.release');
        return null;
    }


}
