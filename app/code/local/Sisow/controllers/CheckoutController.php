<?php
class Sisow_CheckoutController extends Mage_Core_Controller_Front_Action
{	
	public function redirectAction()
	{
		$this->getResponse()
			->setHeader('Content-type', 'text/html; charset=utf8')
			->setBody($this->getLayout()->createBlock('sisow/redirect')->toHtml());
	}
	
	public function returnAction()
	{
		if ($_GET['status'] == 'Success') {
			Mage::getSingleton('checkout/session')->getQuote()->setIsActive(false)->save();
			
			foreach (Mage::getSingleton('checkout/session')->getQuote()->getItemsCollection() as $item ) {
				Mage::getSingleton('checkout/cart')->removeItem( $item->getId() )->save();
			}
		
			return $this->_redirect("checkout/onepage/success", array("_secure" => true));
		} else {
			//alternatieve keep cart functie
			/*
			$order = Mage::getModel('sales/order')->loadByIncrementId($_GET['ec']);
			
			$items = $order->getItemsCollection();
			foreach ($items as $item) {
				try {
					$cart = Mage::getSingleton('checkout/cart');

					$cart->addOrderItem($item);
				} catch (Mage_Core_Exception $e) {
					if (Mage::getSingleton('checkout/session')->getUseNotice(true)) {
						Mage::getSingleton('checkout/session')->addNotice($e->getMessage());
					} else {
						Mage::getSingleton('checkout/session')->addError($e->getMessage());
					}
					$this->_redirect($pageCanceled);
				} catch (Exception $e) {
					Mage::getSingleton('checkout/session')->addException($e, Mage::helper('checkout')->__('Cannot add the item to shopping cart.')
					);
					$this->_redirect($pageCanceled);
				}
			}
			$cart->save();
			*/
			Mage::getSingleton('core/session')->addError('Betaling niet gelukt');
			
			return $this->_redirect('checkout/cart', array("_secure" => true));
		}
	}
	
	public function successAction()
	{
		foreach (Mage::getSingleton('checkout/session')->getQuote()->getItemsCollection() as $item ) {
			Mage::getSingleton('checkout/cart')->removeItem( $item->getId() )->save();
		}
		return $this->_redirect('checkout/onepage/success');
	}
	
	public function notifyAction()
	{
		$orderid = $_GET['ec'];
		$trxid = $_GET['trxid'];
		$status = $_GET['status'];
		$sha1 = $_GET['sha1'];
		
		/* 
		 * Sisow
		 * Last Adjustment: 12-02-2014
		 * Url Check for Notify URL
		*/
		if($orderid == '' || $trxid == '' || $status == '' || $sha1 == '' || (!isset($_GET['notify']) && !isset($_GET['callback'])) )
		{
			echo 'No Notify Url';
			Mage::log($orderid . ': Incorrect NotifyUrl (request uri: '.$_SERVER['REQUEST_URI'].')', null, 'log_sisow.log');
			exit;
		}

		if(Mage::app()->getRequest()->getParam('invalidchar') == 'true')
			$orderid = substr($orderid, 0, strlen($orderid) - 1) . "-" . substr($orderid, strlen($orderid) - 1);
		
		/* 
		 * Sisow
		 * Last Adjustment: 12-02-2014
		 * Loading Order, Sisow status and TransactionId
		*/
		$order = Mage::getModel('sales/order')->loadByIncrementId($orderid);
        $payment = $order->getPayment();
		
		if(!is_object($payment))
		{
			echo 'incorrect payment method, probably the order could not be loaded';
			exit;
		}


        if (method_exists($payment, 'getAdditionalInformation')) {
            $trxid = $payment->getAdditionalInformation('trxId');
        }
        $trxid = (isset($trxid) && $trxid != '') ? $trxid : filter_input(INPUT_GET, 'trxid');

		$base = Mage::getModel('sisow/base');
		
		if(($ex = $base->StatusRequest($trxid)) < 0)
		{
			echo 'statusrequest failed';
			Mage::log($orderid . ': Sisow StatusRequest failed('.$ex.', '.$base->errorCode.', '.$base->errorMessage.')', null, 'log_sisow.log');
			exit;
		}
		
		/* 
		 * Sisow
		 * Last Adjustment: 12-02-2014
		 * Check Order state
		 * When order is processed, exit
		*/		
		$ostate = $order->getState();
        $ostatus = $order->getStatus();

		if ($ostate == Mage_Sales_Model_Order::STATE_PROCESSING && $ostatus == Mage::getStoreConfig('sisow_core/status_success') && $base->status != 'Reversed' && $base->status != 'Refunded' && $base->status != 'Success')
		{
			echo 'Order state & order status already processed';
			Mage::log($orderid . ': Order state & order status already processed.', null, 'log_sisow.log');
			exit;
		}

		/* 
		 * Sisow
		 * Last Adjustment: 12-02-2014
		 * Process order
		*/		
		if ($base->status == "Pending" || $base->status == "Open") {
			echo 'Payment still Open/Pending.';
            exit;
        }
		
		if(is_object($payment))
		{
			if(Mage::helper("sisow")->GetNewMailConfig($payment->getMethodInstance()->getCode()) == "after_notify_with_cancel")
					$order->sendNewOrderEmail();
		}
		$mState = Mage_Sales_Model_Order::STATE_CANCELED;
		$mStatus = true;
		$comm = "Betaling gecontroleerd door Sisow.<br />";
		switch ($base->status) {
            case "Success":
				if(Mage::helper("sisow")->GetNewMailConfig($payment->getMethodInstance()->getCode()) == "after_notify_without_cancel")
					$order->sendNewOrderEmail();
									
				if( $payment->getMethodInstance()->getCode() == 'sisow_overboeking' )
					$base->trxId = $_GET['trxid'];
				
                $mState = Mage_Sales_Model_Order::STATE_PROCESSING;
                $mStatus = Mage::getStoreConfig('sisow_core/status_success');
                if (!$mStatus) {
                    $mStatus = Mage_Sales_Model_Order::STATE_PROCESSING;
                }
                $comm .= "Transaction ID: " . $base->trxId . "<br />";
				$info = $order->getPayment()->getMethodInstance()->getInfoInstance();
				$info->setAdditionalInformation('trxid', $base->trxId );
					
                if ($base->consumerName) {
					$info->setAdditionalInformation('consumerName', $base->consumerName );
					$info->setAdditionalInformation('consumerIban', $base->consumerIban );
					$info->setAdditionalInformation('consumerBic', $base->consumerBic);
                }
				$info->save();
                break;
            case "Paid":
				if(Mage::helper("sisow")->GetNewMailConfig($payment->getMethodInstance()->getCode()) == "after_notify_without_cancel")
					$order->sendNewOrderEmail();
				
				if( $payment->getMethodInstance()->getCode() == 'sisow_overboeking' )
					$base->trxId = $_GET['trxid'];
					
                $mState = Mage_Sales_Model_Order::STATE_PROCESSING;
                $mStatus = Mage::getStoreConfig('sisow_core/status_success');
                if (!$mStatus) {
                    $mStatus = Mage_Sales_Model_Order::STATE_PROCESSING;
                }
                $comm .= "Transaction ID: " . $base->trxId . "<br />";
                
				$info = $order->getPayment()->getMethodInstance()->getInfoInstance();
				$info->setAdditionalInformation('trxid', $base->trxId );
					
                if ($base->consumerName) {
					$info->setAdditionalInformation('consumerName', $base->consumerName );
					$info->setAdditionalInformation('consumerIban', $base->consumerIban );
					$info->setAdditionalInformation('consumerBic', $base->consumerBic);
                }
				$info->save();
                break;
            case "Cancelled":
                $mStatus = Mage::getStoreConfig('sisow_core/status_cancelled');
                if (!$mStatus) {
                    $mStatus = Mage_Sales_Model_Order::STATE_CANCELED;
                }
                $comm .= "Betaling geannuleerd (Cancelled).";
                break;
			case "Reversed":
                $mStatus = Mage::getStoreConfig('sisow_core/status_cancelled');
                if (!$mStatus) {
                    $mStatus = Mage_Sales_Model_Order::STATE_CANCELED;
                }
                $comm .= "Betaling geannuleerd (reversed).";
                break;
			case "Refunded":
                $mStatus = Mage::getStoreConfig('sisow_core/status_cancelled');
                if (!$mStatus) {
                    $mStatus = Mage_Sales_Model_Order::STATE_CANCELED;
                }
                $comm .= "Betaling geannuleerd (refunded).";
                break;
            case "Expired":
                $mStatus = Mage::getStoreConfig('sisow_core/status_expired');
                if (!$mStatus) {
                    $mStatus = Mage_Sales_Model_Order::STATE_CANCELED;
                }
                $comm .= "Betaling verlopen (Expired).";
                break;
            case "Failure":
                $mStatus = Mage::getStoreConfig('sisow_core/status_failure');
                if (!$mStatus) {
                    $mStatus = Mage_Sales_Model_Order::STATE_CANCELED;
                }
                $comm .= "Fout in netwerk (Failure).";
                break;
            case "PendingKlarna":
				exit('Still Pending');
                break;
            case "Reservation":
				$comm = 'Klarna reservation created.<br />';
				$mState = Mage_Sales_Model_Order::STATE_PROCESSING;
				$mStatus = Mage::getStoreConfig('sisow_core/status_reservation');
				if (!$mStatus) {
					$mStatus = Mage_Sales_Model_Order::STATE_PROCESSING;
				}
				
				$info = $order->getPayment()->getMethodInstance()->getInfoInstance();
				$info->setAdditionalInformation('trxid', $base->trxId );
				$info->save();
				
				if(Mage::helper("sisow")->GetNewMailConfig($payment->getMethodInstance()->getCode()) == "after_notify_without_cancel")
					$order->sendNewOrderEmail();
        }
		
		$payment_transaction = Mage::getModel('sales/order_payment')
                ->setMethod($order->getPayment())
                ->setTransactionId($base->trxId)
                ->setIsTransactionClosed(true);
				
        $order->setPayment($payment_transaction);

		if( strpos($payment->getMethod(), 'klarna') )
			$mail = 0;
		else
			$mail = (Mage::getStoreConfig('payment/'.$payment->getMethod().'/autoinvoice') > 0) ? Mage::getStoreConfig('payment/'.$payment->getMethod().'/autoinvoice') : Mage::getStoreConfig('sisow_core/autoinvoice');
		
		if ($mState == Mage_Sales_Model_Order::STATE_CANCELED) {
            $order->cancel();
            $order->setState($mState, $mStatus, $comm, true);
            $payment_transaction->addTransaction(Mage_Sales_Model_Order_Payment_Transaction::TYPE_VOID);

			echo '$order->setState(' . $mState . ', ' . $mStatus . ', ' . $comm . ')';
			
        } elseif ($mState !== null && ($mState != $ostate || $mStatus != $ostate)) {
			$order->setState($mState, $mStatus, $comm, true);
			$payment_transaction->addTransaction(Mage_Sales_Model_Order_Payment_Transaction::TYPE_CAPTURE);
			echo '$order->setState(' . $mState . ', ' . $mStatus . ', ' . $comm . ')';
        }
		
		//$order->save();
		if($mail > 1)
		{
			if ($order->canInvoice()) {
				$invoice = $order->prepareInvoice();
				$invoice->register()->capture();
				$invoice->setTransactionId($trxid);
				Mage::getModel('core/resource_transaction')
						->addObject($invoice)
						->addObject($invoice->getOrder())
						->save();

				if ($mail == 3) {
					$invoice->sendEmail();
					$invoice->setEmailSent(true);
				}
				$invoice->save();
				echo 'Invoice created!';
			}
			else
			{
				echo 'Can\'t create Invoice!';
			}
		}

		$order->save();
        
		exit;
	}
}
?>