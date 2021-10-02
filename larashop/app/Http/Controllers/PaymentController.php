<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\Order;
use App\Models\Payment;

/**
 * PaymentController
 *
 * PHP version 7
 *
 * @category PaymentController
 * @package  PaymentController
 * @license  https://opensource.org/licenses/MIT MIT License
 * @link     http://localhost/
 */
class PaymentController extends Controller
{
	/**
	 * Receive notification from payment gateway
	 *
	 * @param Request $request payment data
	 *
	 * @return json
	 */
	public function notification(Request $request)
	{
		$payload = $request->getContent();
//		{"transaction_time":"2021-03-26 18:26:55",
//      "transaction_status":"capture",
//      "transaction_id":"62bf6740-ad24-4237-8823-74ac509411a0",
//      "status_message":"midtrans payment notification",
//      "status_code":"200",
//      "signature_key":"73a1b42bb163a22f5f6e7fefbee114d669ae7c9c175a42131c71613e33c94bf6f62edc09c7070a43c085ecb649350345721c20340f2b94b439d83b47d012ab7c",
//      "payment_type":"credit_card",
//      "order_id":"INV/20210326/III/XXVI/00006",
//      "merchant_id":"G118662443",
//      "masked_card":"481111-1114",
//      "gross_amount":"73000.00",
//      "fraud_status":"accept",
//      "eci":"05",
//      "currency":"IDR",
//      "channel_response_message":"Approved",
//      "channel_response_code":"00",
//      "card_type":"credit",
//      "bank":"mandiri",
//      "approval_code":"1616758021129"}
		$notification = json_decode($payload);
//		$paymentParams = [
//			'order_id' => $order->id,
//			'number' => Payment::generateCode(),
//			'amount' => $paymentNotification->gross_amount,
//			'method' => 'midtrans',
//			'status' => $paymentStatus,
//			'token' => $paymentNotification->transaction_id,
//			'payloads' => $payload,
//			'payment_type' => $paymentNotification->payment_type,
//			'va_number' => $vaNumber,
//			'vendor_name' => $vendorName,
//			'biller_code' => $paymentNotification->biller_code,
//			'bill_key' => $paymentNotification->bill_key,
//		];

		$validSignatureKey = hash("sha512", $notification->order_id . $notification->status_code . $notification->gross_amount . env('MIDTRANS_SERVER_KEY'));


		if ($notification->signature_key != $validSignatureKey) {
			return response(['message' => 'Invalid signature'], 403);
		}

		$this->initPaymentGateway();
		$statusCode = null;

		$paymentNotification = new \Midtrans\Notification();
		$order = Order::where('code', $paymentNotification->order_id)->firstOrFail();

		if ($order->isPaid()) {
			return response(['message' => 'The order has been paid before'], 422);
		}

		$transaction = $paymentNotification->transaction_status;
		$type = $paymentNotification->payment_type;
		$orderId = $paymentNotification->order_id;
		$fraud = $paymentNotification->fraud_status;

		$vaNumber = null;
		$vendorName = null;
		if (!empty($paymentNotification->va_numbers[0])) {
			$vaNumber = $paymentNotification->va_numbers[0]->va_number;
			$vendorName = $paymentNotification->va_numbers[0]->bank;
		}

		$paymentStatus = null;
		if ($transaction == 'capture') {
			// For credit card transaction, we need to check whether transaction is challenge by FDS or not
			if ($type == 'credit_card') {
				if ($fraud == 'challenge') {
					// TODO set payment status in merchant's database to 'Challenge by FDS'
					// TODO merchant should decide whether this transaction is authorized or not in MAP
					$paymentStatus = Payment::CHALLENGE;
				} else {
					// TODO set payment status in merchant's database to 'Success'
					$paymentStatus = Payment::SUCCESS;
				}
			}
		} else if ($transaction == 'settlement') {
			// TODO set payment status in merchant's database to 'Settlement'
			$paymentStatus = Payment::SETTLEMENT;
		} else if ($transaction == 'pending') {
			// TODO set payment status in merchant's database to 'Pending'
			$paymentStatus = Payment::PENDING;
		} else if ($transaction == 'deny') {
			// TODO set payment status in merchant's database to 'Denied'
			$paymentStatus = PAYMENT::DENY;
		} else if ($transaction == 'expire') {
			// TODO set payment status in merchant's database to 'expire'
			$paymentStatus = PAYMENT::EXPIRE;
		} else if ($transaction == 'cancel') {
			// TODO set payment status in merchant's database to 'Denied'
			$paymentStatus = PAYMENT::CANCEL;
		}

		$paymentParams = [
			'order_id' => $order->id,
			'number' => Payment::generateCode(),
			'amount' => $paymentNotification->gross_amount,
			'method' => 'midtrans',
			'status' => $paymentStatus,
			'token' => $paymentNotification->transaction_id,
			'payloads' => $payload,
			'payment_type' => $paymentNotification->payment_type,
			'va_number' => $vaNumber,
			'vendor_name' => $vendorName,
			'biller_code' => $paymentNotification->biller_code,
			'bill_key' => $paymentNotification->bill_key,
		];

		$payment = Payment::create($paymentParams);

		if ($paymentStatus && $payment) {
			\DB::transaction(
				function () use ($order, $payment) {
					if (in_array($payment->status, [Payment::SUCCESS, Payment::SETTLEMENT])) {
						$order->payment_status = Order::PAID;
						$order->status = Order::CONFIRMED;
						$order->save();
					}
				}
			);
		}

		$message = 'Payment status is : '. $paymentStatus;

		$response = [
			'code' => 200,
			'message' => $message,
		];

		return response($response, 200);
	}

	/**
	 * Show completed payment status
	 *
	 * @param Request $request payment data
	 *
	 * @return void
	 */
	public function completed(Request $request)
	{
		$code = $request->query('order_id');
		$order = Order::where('code', $code)->firstOrFail();

		if ($order->payment_status == Order::UNPAID) {
			return redirect('payments/failed?order_id='. $code);
		}

		\Session::flash('success', "Thank you for completing the payment process!");

		return redirect('orders/received/'. $order->id);
	}

	/**
	 * Show unfinish payment page
	 *
	 * @param Request $request payment data
	 *
	 * @return void
	 */
	public function unfinish(Request $request)
	{
		$code = $request->query('order_id');
		$order = Order::where('code', $code)->firstOrFail();

		\Session::flash('error', "Sorry, we couldn't process your payment.");

		return redirect('orders/received/'. $order->id);
	}

	/**
	 * Show failed payment page
	 *
	 * @param Request $request payment data
	 *
	 * @return void
	 */
	public function failed(Request $request)
	{
		$code = $request->query('order_id');
		$order = Order::where('code', $code)->firstOrFail();

		\Session::flash('error', "Sorry, we couldn't process your payment.");

		return redirect('orders/received/'. $order->id);
	}
}
