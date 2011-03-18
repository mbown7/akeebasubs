<?php
/**
 * @package		akeebasubs
 * @copyright	Copyright (c)2010-2011 Nicholas K. Dionysopoulos / AkeebaBackup.com
 * @license		GNU GPLv3 <http://www.gnu.org/licenses/gpl.html> or later
 */

defined('_JEXEC') or die();

jimport('joomla.plugin.plugin');

class plgAkpaymentPaypal extends JPlugin
{
	private $ppName = 'paypal';
	private $ppKey = 'PLG_AKPAYMENT_PAYPAL_TITLE';

	public function __construct(&$subject, $config = array())
	{
		parent::__construct($subject, $config);
		JPlugin::loadLanguage( 'plg_akpayment_paypal', JPATH_ADMINISTRATOR );
	}

	public function onAKPaymentGetIdentity()
	{
		$title = $this->params->get('title','');
		if(empty($title)) $title = JText::_($this->ppKey);
		$ret = array(
			'name'		=> $this->ppName,
			'title'		=> $title
		);
		return (object)$ret;
	}
	
	/**
	 * Returns the payment form to be submitted by the user's browser. The form must have an ID of
	 * "paymentForm" and a visible submit button.
	 * 
	 * @param string $paymentmethod
	 * @param JUser $user
	 * @param KDatabaseRow $level
	 * @param KDatabaseRow $subscription
	 * @return string
	 */
	public function onAKPaymentNew($paymentmethod, $user, $level, $subscription)
	{
		if($paymentmethod != $this->ppName) return false;
		
		$nameParts = explode(' ', $user->name, 2);
		$firstName = $nameParts[0];
		if(count($nameParts) > 1) {
			$lastName = $nameParts[1];
		} else {
			$lastName = '';
		}
		
		$data = (object)array(
			'url'			=> $this->getPaymentURL(),
			'merchant'		=> $this->getMerchantID(),
			'postback'		=> str_replace('&amp;','&', rtrim(JURI::base(),'/').JRoute::_('index.php?option=com_akeebasubs&view=callback&paymentmethod=paypal')),
			'success'		=> str_replace('&amp;','&', rtrim(JURI::base(),'/').JRoute::_('index.php?option=com_akeebasubs&view=message&id='.$subscription->akeebasubs_level_id.'&layout=order')),
			'cancel'		=> str_replace('&amp;','&', rtrim(JURI::base(),'/').JRoute::_('index.php?option=com_akeebasubs&view=message&id='.$subscription->akeebasubs_level_id.'&layout=cancel')),
			'currency'		=> strtoupper(KFactory::get('site::com.akeebasubs.model.configs')->getConfig()->currency),
			'firstname'		=> $firstName,
			'lastname'		=> $lastName
		);
		
		$kuser = KFactory::tmp('admin::com.akeebasubs.model.users')
			->user_id($user->id)
			->getItem();

		@ob_start();
		include dirname(__FILE__).DS.'paypal'.DS.'form.php';
		$html = @ob_get_clean();
		
		return $html;
	}
	
	public function onAKPaymentCallback($paymentmethod, $data)
	{
		// Check if we're supposed to handle this
		if($paymentmethod != $this->ppName) return false;
		
		// Check IPN data for validity (i.e. protect against fraud attempt)
		$isValid = $this->isValidIPN($data);
		if(!$isValid) $data['akeebasubs_failure_reason'] = 'PayPal reports transaction as invalid';
		
		// Load the relevant subscription row
		if($isValid) {
			$id = array_key_exists('custom', $data) ? (int)$data['custom'] : -1;
			$subscription = null;
			if($id > 0) {
				$subscription = KFactory::tmp('admin::com.akeebasubs.model.subscriptions')
					->id($id)
					->getItem();
				if( ($subscription->id <= 0) || ($subscription->id != $id) ) {
					$subscription = null;
				}
			}
			if(!$isValid) $data['akeebasubs_failure_reason'] = 'The referenced subscription ID ("custom" field) is invalid';
		}
		
		// Check that receiver_email / receiver_id is what the site owner has configured
		if($isValid) {
			$receiver_email = $data['receiver_email'];
			$receiver_id = $data['receiver_id'];
			$valid_id = $this->getMerchantID();
			$isValid =
				($receiver_email == $valid_id)
				|| (strtolower($receiver_email) == strtolower($receiver_email))
				|| ($receiver_id == $valid_id)
				|| (strtolower($receiver_id) == strtolower($receiver_id))
			;
			if(!$isValid) $data['akeebasubs_failure_reason'] = 'Merchant ID does not match receiver_email or receiver_id';
		}
		
		// Check txn_type; we only accept web_accept transactions with this plugin
		if($isValid) {
			$isValid = $data['txn_type'] == 'web_accept';
			if(!$isValid) $data['akeebasubs_failure_reason'] = "Transaction type ".$data['txn_type']." can't be processed by this payment plugin.";
		}

		// Check that txn_id has not been previously processed
		if($isValid && !is_null($subscription)) {
			if($subscription->processor_key == $data['txn_id']) {
				$isValid = false;
				$data['akeebasubs_failure_reason'] = "I will not process the same txn_id twice";
			}
		}
		
		// Check that mc_gross is correct
		$isPartialRefund = false;
		if($isValid && !is_null($subscription)) {
			$mc_gross = $data['mc_gross'];
			$gross = $subscription->gross_amount;
			if($mc_gross > 0) {
				// A positive value means "payment". The prices MUST match!
				// Important: NEVER, EVER compare two floating point values for equality.
				$isValid = ($gross - $temp_mc_gross) < 0.01;
			} else {
				$isPartialRefund = false;
				$temp_mc_gross = -1 * $mc_gross;
				$isPartialRefund = ($gross - $temp_mc_gross) > 0.01;
			}
			if(!$isValid) $data['akeebasubs_failure_reason'] = 'Paid amount does not match the subscription amount';
		}
		
		// Check that mc_currency is correct
		if($isValid && !is_null($subscription)) {
			$mc_currency = strtoupper($data['mc_currency']);
			$currency = strtoupper(KFactory::get('site::com.akeebasubs.model.configs')->getConfig()->currency);
			if($mc_currency != $currency) {
				$isValid = false;
				$data['akeebasubs_failure_reason'] = "Invalid currency; expected $currency, got $mc_currency";
			}
		}
		
		// Log the IPN data
		$this->logIPN($data, $isValid);
		
		// Fraud attempt? Do nothing more!
		if(!$isValid) return false;

		// Check the payment_status
		switch($data['payment_status'])
		{
			case 'Canceled_Reversal':
			case 'Completed':
				$newStatus = 'C';
				break;
			
			case 'Created':
			case 'Pending':
			case 'Processed':
				$newStatus = 'P';
				break;
			
			case 'Denied':
			case 'Expired':
			case 'Failed':
			case 'Refunded':
			case 'Reversed':
			case 'Voided':
			default:
				// Partial refunds can only by issued by the merchant. In that case,
				// we don't want the subscription to be cancelled. We have to let the
				// merchant adjust its parameters if needed.
				if($isPartialRefund) {
					$newStatus = 'C';
				} else {
					$newStatus = 'X';
				}
				break;
		}

		// Update subscription status (this also automatically calls the plugins)
		$updates = array(
			'id'				=> $id,
			'processor_key'		=> $data['txn_id'],
			'state'				=> $newStatus,
			'enabled'			=> 0
		);
		if($newStatus == 'C') {
			// Fix the starting date if the payment was accepted after the subscription's start date. This
			// works around the case where someone pays by e-Check on January 1st and the check is cleared
			// on January 5th. He'd lose those 4 days without this trick. Or, worse, if it was a one-day pass
			// the user would have paid us and we'd never given him a subscription!
			$jNow = new JDate();
			$jStart = new JDate($subscription->publish_up);
			$jEnd = new JDate($subscription->publish_down);
			$now = $jNow->toUnix();
			$start = $jStart->toUnix();
			$end = $jEnd->toUnix();
			
			if($start < $now) {
				$duration = $end - $start;
				$start = $now;
				$end = $start + $duration;
				$jStart = new JDate($start);
				$jEnd = new JDate($end);
			}
			
			$updates['publish_up'] = $jStart->toMySQL();
			$updates['publish_down'] = $jEnd->toMySQL();
			$updates['enabled'] = 0;
		}
		$subscription->setData($updates)->save();
		
		return true;
	}
	
	/**
	 * Gets the form action URL for the payment
	 */
	private function getPaymentURL()
	{
		$sandbox = $this->params->get('sandbox',0);
		if($sandbox) {
			return 'https://www.sandbox.paypal.com/cgi-bin/webscr';
		} else {
			return 'https://www.paypal.com/cgi-bin/webscr';
		}
	}
	
	/**
	 * Gets the PayPal Merchant ID (usually the email address)
	 */
	private function getMerchantID()
	{
		$sandbox = $this->params->get('sandbox',0);
		if($sandbox) {
			return $this->params->get('sandbox_merchant','');
		} else {
			return $this->params->get('merchant','');
		}
	}	

	/**
	 * Gets the IPN callback URL
	 */
	private function getCallbackURL()
	{
		$sandbox = $this->params->get('sandbox',0);
		$ssl = $this->params->get('secureipn',0);
		$scheme = $ssl ? 'ssl://' : '';
		if($sandbox) {
			return $scheme.'www.sandbox.paypal.com';
		} else {
			return $scheme.'www.paypal.com';
		}
	}
	
	/**
	 * Validates the incoming data against PayPal's IPN to make sure this is not a
	 * fraudelent request.
	 */
	private function isValidIPN($data)
	{
		$url = $this->getCallbackURL();
		
		$req = 'cmd=_notify-validate';
		foreach($data as $key => $value) {
			$value = urlencode($value);
			$req .= "&$key=$value";
		}
		$header = '';
		$header .= "POST /cgi-bin/webscr HTTP/1.0\r\n";
		$header .= "Content-Type: application/x-www-form-urlencoded\r\n";
		$header .= "Content-Length: " . strlen($req) . "\r\n\r\n";
		
		$ssl = $this->params->get('secureipn',0);
		$port = $ssl ? 443 : 80;
		
		$fp = fsockopen ($url, $port, $errno, $errstr, 30);
		
		if (!$fp) {
			// HTTP ERROR
			return false;
		} else {
			fputs ($fp, $header . $req);
			while (!feof($fp)) {
				$res = fgets ($fp, 1024);
				if (strcmp ($res, "VERIFIED") == 0) {
					return true;
				} else if (strcmp ($res, "INVALID") == 0) {
					return false;
				}
			}
			fclose ($fp);
		}
	}
	
	private function logIPN($data, $isValid)
	{
		$config = JFactory::getConfig();
		$logpath = $config->getValue('log_path');
		$logFile = $logpath.DS.'akpayment_paypal_ipn_log.php';
		jimport('joomla.filesystem.file');
		if(!JFile::exists($logFile)) {
			$die = "<?php die(); ?>\n";
			JFile::write($logFile, $die);
		} else {
			if(@filesize($logFile) > 1048756) {
				$altLog = $logpath.DS.'akpayment_paypal_ipn_log-1.php';
				if(JFile::exists($altLog)) {
					JFile::delete($altLog);
				}
				JFile::copy($logFile, $altLog);
				JFile::delete($logFile);
				$die = "<?php die(); ?>\n";
				JFile::write($logFile, $die);
			}
		}
		$logData = JFile::read($logFile);
		$logData .= "\n" . str_repeat('-', 80);
		$logData .= $isValid ? 'VALID PAYPAL IPN' : 'INVALID PAYPAL IPN *** FRAUD ATTEMPT OR INVALID NOTIFICATION ***';
		$logData .= "\nDate/time : ".gmdate('Y-m-d H:i:s')." GMT\n\n";
		foreach($data as $key => $value) {
			$logData .= '  ' . str_pad($key, 30, ' ') . $value . "\n";
		}
		$logData .= "\n";
		JFile::write($logFile, $logData);
	}
}