<?php

namespace App\Exceptions;

use Exception;

/**
 * OutOfStockException
 *
 * PHP version 7
 *
 * @category OutOfStockException
 * @package  OutOfStockException
 * @license  https://opensource.org/licenses/MIT MIT License
 * @link     http://localhost/
 */
class OutOfStockException extends Exception
{
	/**
	 * Report the exception
	 *
	 * @return void
	 */
	public function report()
	{
		\Log::debug('The product is out of stock');
	}
}