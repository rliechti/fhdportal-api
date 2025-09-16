<?php

use Ramsey\Uuid\Uuid;
use Symfony\Component\Dotenv\Dotenv;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Connection\AMQPConnectionConfig;
use PhpAmqpLib\Connection\AMQPConnectionFactory;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
// use DB;

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
        $this->mq_host        = $_SERVER['CEGA_MQ_HOST'];
        $this->mq_port        = $_SERVER['CEGA_MQ_PORT'];
        $this->mq_user        = $_SERVER['CEGA_MQ_USER'];
        $this->mq_pwd         = $_SERVER['CEGA_MQ_PWD'];
        $this->mq_vhost       = $_SERVER['CEGA_MQ_VHOST'];
        $this->mq_exchange    = $_SERVER['CEGA_MQ_EXCHANGE'];
        $this->mq_routing_key = $_SERVER['CEGA_MQ_ROUTING_KEY'];
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
    }


    public function sendMessage($msg, $routing_key)
    {

        $this->response = null;
        $this->corr_id = Uuid::uuid4();
        $rmq_msg = new AMQPMessage(
            json_encode($msg),
            array(
                "correlation_id" => $this->corr_id,
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
				'content_encoding' => "UTF-8",
				"content_type" => "application/json"
            )
        );
		fwrite(STDOUT,$routing_key.": ".json_encode($msg).PHP_EOL);
        $this->channel->basic_publish($rmq_msg, $this->mq_exchange ,$routing_key);
        return $this->corr_id;
    }

}
