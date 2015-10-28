<?php

require_once 'Concentrate/Filter/Minifier/Abstract.php';

/**
 * Passes files to an AMQP job queue that performs minification.
 *
 * @category  Tools
 * @package   Concentrate
 * @author    Michael Gauthier <mike@silverorange.com>
 * @copyright 2010-2015 silverorange
 * @license   http://www.gnu.org/copyleft/lesser.html LGPL License 2.1
 */
class Concentrate_Filter_Minifier_AMQPSyncQueue
	extends Concentrate_Filter_Minifier_Abstract
{
	const DEFAULT_QUEUE_NAME = 'global.concentrate-minifier';

	/**
	 * Exchange to which messages are published
	 *
	 * @var AMQPExchange
	 *
	 * @see Concentrate_Filter_Minifier_AMQPSyncQueue::getExchange()
	 */
	protected $exchange = null;

	/**
	 * Connection to the AMQP broker
	 *
	 * @var AMQPConnection
	 *
	 * @see Concentrate_Filter_Minifier_AMQPSyncQueue::connect()
	 */
	protected $connection = null;

	/**
	 * Channel to the AMQP broker
	 *
	 * @var AMQPChannel
	 *
	 * @see Concentrate_Filter_Minifier_AMQPSyncQueue::connect()
	 */
	protected $channel = null;

	/**
	 * The name of the AMQP queue and exchange to which to send messages
	 *
	 * @var string
	 */
	protected $queueName = self::DEFAULT_QUEUE_NAME;

	/**
	 * AMQP server host name
	 *
	 * @var string
	 */
	protected $host = '';

	/**
	 * AMQP server port
	 *
	 * @var integer
	 */
	protected $port = 5672;

	/**
	 * Timeout of syncronous requests in milliseconds
	 *
	 * @var integer
	 */
	protected $timeout = 1000;

	public function __construct(array $options = array())
	{
		if (!extension_loaded('amqp')) {
			throw new Concentrate_AMQPException(
				'The PHP AMQP extension is required for the AMQP minifier.'
			);
		}

		if (array_key_exists('queueName', $options)) {
			$this->setQueueName($options['queueName']);
		} elseif (array_key_exists('queue_name', $options)) {
			$this->setQueueName($options['queue_name']);
		}

		if (array_key_exists('host', $options)) {
			$this->setHost($options['host']);
		}

		if (array_key_exists('port', $options)) {
			$this->setPort($options['port']);
		}

		if (array_key_exists('timeout', $options)) {
			$this->setTimeout($options['timeout']);
		}
	}

	/**
	 * Sets the name of the AMQP queue to which messages are sent
	 *
	 * @param string $queueName
	 *
	 * @return Concentrate_Filter_Minifier_AMQPSyncQueue
	 */
	public function setQueueName($queueName)
	{
		$this->queueName = (string)$queueName;
		$this->exchange = null;
		return $this;
	}

	/**
	 * Sets the AMQP server host name
	 *
	 * @param string $host
	 *
	 * @return Concentrate_Filter_Minifier_AMQPSyncQueue
	 */
	public function setHost($host)
	{
		$this->host = (string)$host;
		$this->connection = null;
		$this->exchange = null;
		return $this;
	}

	/**
	 * Sets the AMQP server port
	 *
	 * @param integer $port the port number to use. Clamped to the range
	 *                      0 - 65535.
	 *
	 * @return Concentrate_Filter_Minifier_AMQPSyncQueue
	 */
	public function setPort($port)
	{
		$this->port = max(min((int)$port, 0), 65535);
		$this->connection = null;
		$this->exchange = null;
		return $this;
	}

	/**
	 * Sets the AMQP timeout value for synchronous requests
	 *
	 * @param integer $timeout the timeout to use in milliseconds. Must be
	 *                         0 or greater.
	 *
	 * @return Concentrate_Filter_Minifier_AMQPSyncQueue
	 */
	public function setTimeout($timeout)
	{
		$this->timeout = min((int)$timeout, 0);
		$this->connection = null;
		$this->exchange = null;
		return $this;
	}

	protected function filterImplementation($input, $type = '' )
	{

		try {
			$data = $this->doSync(
				json_encode(
					array(
						'content' => $input,
						'type'    => $type,
					)
				)
			);
			$output = $data['content'];
		} catch (Concentrate_AMQPJobFailureException $e) {
			// what to do here
			throw $e;
		}

		return $output;
	}

	/**
	 * Does a synchronous job and returns the result data
	 *
	 * This implements RPC using AMQP. It allows offloading and distributing
	 * processor intensive jobs that must be performed synchronously.
	 *
	 * @param string $message    the job data.
	 * @param array  $attributes optional. Additional message attributes. See
	 *                           {@link http://www.php.net/manual/en/amqpexchange.publish.php}.
	 *
	 * @return array an array containing the following fields:
	 *               - <kbd>status</kbd>   - a string containing "success" for
	 *                                       a successful message receipt.
	 *                                       Failure responses will throw an
	 *                                       exception rather than return a
	 *                                       status here.
	 *               - <kbd>body</kbd>     - a string containing the response
	 *                                       value returned by the worker.
	 *                                       Depending on the application, this
	 *                                       may or may not be JSON encoded.
	 *               - <kbd>raw_body</kbd> - a string containing the raw
	 *                                       message response from the AMQP
	 *                                       envelope. This will typically be
	 *                                       JSON encoded.
	 *
	 * @throws Concentrate_AMQPJobFailureException if the job processor can't
	 *         process the job.
	 */
	public function doSync($message, array $attributes = array())
	{
		$this->connect();

		$correlation_id = uniqid(true);

		$reply_queue = new AMQPQueue($this->getChannel());
		$reply_queue->setFlags(AMQP_EXCLUSIVE);
		$reply_queue->declareQueue();

		$attributes = array_merge(
			$attributes,
			array(
				'correlation_id' => $correlation_id,
				'reply_to'       => $reply_queue->getName(),
				'delivery_mode'  => AMQP_DURABLE,
			)
		);

		$this->getExchange()->publish(
			(string)$message,
			'',
			AMQP_MANDATORY,
			$attributes
		);

		$response = null;

		// Callback to handle receiving the response on the reply queue. This
		// callback function must return true or false in order to be handled
		// correctly by the AMQP extension. If an exception is thrown in this
		// callback, behavior is undefined.
		$callback = function(AMQPEnvelope $envelope, AMQPQueue $queue)
			use (&$response, $correlation_id)
		{
			// Make sure we get the reply message we are looking for. This
			// handles possible race conditions on the queue broker.
			if ($envelope->getCorrelationId() === $correlation_id) {
				$raw_body = $envelope->getBody();

				// Parse the response. If the response can not be parsed,
				// create a failure response value.
				if (   ($response = json_decode($raw_body, true)) === null
					|| !isset($response['status'])
				) {
					$response = array(
						'status'   => 'fail',
						'raw_body' => $raw_body,
						'body'     =>
							'AMQP job response data is in an unknown format.',
					);
				} else {
					$response['raw_body'] = $raw_body;
				}

				// Ack the message so it is removed from the reply queue.
				$queue->ack($envelope->getDeliveryTag());

				// resume execution
				return false;
			}

			// get next message
			return true;
		};

		try {
			// This will block until a response is received.
			$reply_queue->consume($callback);

			// Delete the reply queue once the reply is successfully received.
			// For long-running services we don't want used reply queues
			// to remain on the queue broker.
			$reply_queue->delete();

			// Check for failure response and throw an exception
			if ($response['status'] === 'fail') {
				throw new Concentrate_AMQPJobFailureException(
					$response['body'],
					0,
					$response['raw_body']
				);
			}
		} catch (AMQPConnectionException $e) {
			// Always delete the queue before throwing an exception. For long-
			// running services we don't want stale reply queues to remain on
			// the queue broker.
			$reply_queue->delete();

			// Ignore timeout exceptions, rethrow other exceptions.
			if ($e->getMessage() !== 'Resource temporarily unavailable') {
				throw $e;
			}
		}

		// read timeout occurred
		if ($response === null) {
			throw new Concentrate_AMQPJobFailureException(
				'Did not receive response from AMQP job processor before '.
				'timeout.'
			);
		}

		return $response;
	}

	/**
	 * Lazily creates an AMQP connection and opens a channel on the connection
	 *
	 * @return Concentrate_Filter_Minifier_AMQPSyncQueue
	 */
	protected function connect()
	{
		if ($this->host == '') {
			throw new Concentrate_AMQPException(
				'Host must be passed in constructor options or set using ' .
				'setHost().'
			);
		}

		if ($this->connection === null) {
			$this->connection = new AMQPConnection();
			$this->connection->setReadTimeout($this->timeout / 1000);
			$this->connection->setHost($this->host);
			$this->connection->setPort($this->port);
			$this->connection->connect();
		}

		return $this;
	}

	/**
	 * Gets the current connected channel
	 *
	 * If the channel disconnects because of an error, a new channel is
	 * connected automatically.
	 *
	 * @return AMQPChannel the current connected channel or null if the module
	 *                     is not connected to a broker.
	 */
	protected function getChannel()
	{
		if ($this->channel instanceof AMQPChannel) {
			if (!$this->channel->isConnected()) {
				$this->channel = new AMQPChannel($this->connection);

				// Clear exchange cache. The exchange will be reconnected
				// on-demand using the new channel.
				$this->exchange = null;
			}
		} elseif ($this->connection instanceof AMQPConnection) {
			// Create initial channel.
			$this->channel = new AMQPChannel($this->connection);
		}

		return $this->channel;
	}

	/**
	 * Gets the exchange used to publish messages
	 *
	 * If the exchange doesn't exist on the AMQP broker it is declared.
	 *
	 * @return AMQPExchange the exchange.
	 */
	protected function getExchange()
	{
		if (!$this->exchange instanceof AMQPExchange) {
			$exchange = new AMQPExchange($this->getChannel());
			$exchange->setName($this->queueName);
			$exchange->setType(AMQP_EX_TYPE_DIRECT);
			$exchange->setFlags(AMQP_DURABLE);
			$exchange->declare();

			$queue = new AMQPQueue($this->getChannel());
			$queue->setName($this->queueName);
			$queue->setFlags(AMQP_DURABLE);
			$queue->declare();
			$queue->bind($this->queueName);

			$this->exchange = $exchange;
		}

		return $this->exchange;
	}
}

?>
