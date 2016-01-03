<?php
class Sisow_Block_Paymentmethod_Default extends Mage_Payment_Block_Form
{
	protected function _construct()
    {
        $this->setTemplate('sisow/checkout/default_form.phtml');
		parent::_construct();
    }
	
	public function getFee()
	{	
		return $this->getMethod()->getFeeArray();
	}
}
?>