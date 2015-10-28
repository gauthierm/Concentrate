<?php

/**
 * @category  Tools
 * @package   Concentrate
 * @author    Michael Gauthier <mike@silverorange.com>
 * @copyright 2010 silverorange
 * @license   http://www.gnu.org/copyleft/lesser.html LGPL License 2.1
 */
class Concentrate_Exception extends Exception
{
}

/**
 * @category  Tools
 * @package   Concentrate
 * @author    Michael Gauthier <mike@silverorange.com>
 * @copyright 2010 silverorange
 * @license   http://www.gnu.org/copyleft/lesser.html LGPL License 2.1
 */
class Concentrate_FileException extends Concentrate_Exception
{
	protected $filename = '';

	public function __construct($message, $code = 0, $filename = '')
	{
		parent::__construct($message, $code);
		$this->filename = $filename;
	}

	public function getFilename()
	{
		return $this->filename;
	}
}

/**
 * @category  Tools
 * @package   Concentrate
 * @author    Michael Gauthier <mike@silverorange.com>
 * @copyright 2010 silverorange
 * @license   http://www.gnu.org/copyleft/lesser.html LGPL License 2.1
 */
class Concentrate_FileFormatException extends Concentrate_FileException
{
}

/**
 * @category  Tools
 * @package   Concentrate
 * @author    Michael Gauthier <mike@silverorange.com>
 * @copyright 2010 silverorange
 * @license   http://www.gnu.org/copyleft/lesser.html LGPL License 2.1
 */
class Concentrate_CyclicDependencyException extends Concentrate_Exception
{
}

/**
 * @category  Tools
 * @package   Concentrate
 * @author    Michael Gauthier <mike@silverorange.com>
 * @copyright 2015 silverorange
 * @license   http://www.gnu.org/copyleft/lesser.html LGPL License 2.1
 */
class Concentrate_AMQPException extends Concentrate_Exception
{
}

/**
 * @category  Tools
 * @package   Concentrate
 * @author    Michael Gauthier <mike@silverorange.com>
 * @copyright 2015 silverorange
 * @license   http://www.gnu.org/copyleft/lesser.html LGPL License 2.1
 */
class Concentrate_AMQPJobFailureException extends Concentrate_AMQPException
{
	/**
	 * The raw AMQP response body
	 *
	 * @var string
	 */
	protected $rawBody = '';

	/**
	 * Creates a new exception
	 *
	 * @param string  $message the message of this exception.
	 * @param integer $code    optional. The error code of this exception.
	 * @param string  $rawBody optional. The raw AMQP response body.
	 */
	public function __construct($message, $code = 0, $rawBody = '')
	{
		parent::__construct($message, $code);
		$this->rawBody = (string)$rawBody;
	}

	/**
	 * Gets the raw AMQP response body of this exception
	 *
	 * @return string the raw AMQP response body
	 */
	public function getRawBody()
	{
		return $this->rawBody;
	}
}

?>
