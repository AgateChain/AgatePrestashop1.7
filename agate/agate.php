<?php
use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if (!defined('_PS_VERSION_'))
  exit;

function agatelog($contents) {
  if(isset($contents)) {
    if(is_resource($contents))
      return error_log(serialize($contents));
    else
      return error_log(var_dump($contents, true));
  } else {
    return false;
  }
}

// Convert all currency to USD.
function convertCurToIUSD($url, $amount, $api_key, $currencySymbol) {
    error_log("Entered into Convert Amount");
    $ch = curl_init($url.'?api_key='.$api_key.'&currency='.$currencySymbol.'&amount='. $amount);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
      'Content-Type: application/json')
  );

  $result = curl_exec($ch);
  $data = json_decode( $result , true);
  error_log('Response =>'. var_export($data, TRUE));
  // Return the equivalent value acquired from Agate server.
  return (float) $data["result"];

  }

  function redirectPayment($baseUri, $amount_iUSD, $amount, $currencySymbol, $api_key, $redirect_url) {
    error_log("Entered into auto submit-form");
    // Using Auto-submit form to redirect user
    echo "<form id='form' method='post' action='". $baseUri . "?api_key=" . $api_key."'>".
            "<input type='hidden' autocomplete='off' name='amount' value='".$amount."'/>".
            "<input type='hidden' autocomplete='off' name='amount_iUSD' value='".$amount_iUSD."'/>".
            "<input type='hidden' autocomplete='off' name='callBackUrl' value='".$redirect_url."'/>".
            "<input type='hidden' autocomplete='off' name='api_key' value='".$api_key."'/>".
            "<input type='hidden' autocomplete='off' name='cur' value='".$currencySymbol."'/>".
           "</form>".
           "<script type='text/javascript'>".
                "document.getElementById('form').submit();".
           "</script>";
  }

class agate extends PaymentModule {
    private $_html       = '';
    private $_postErrors = array();
    private $key;

    public function __construct() {
      include(dirname(__FILE__).'/config.php');
      $this->name            = 'agate';
      $this->version         = '1.7';
      $this->author          = 'Agate';
      $this->className       = 'agate';
      $this->currencies      = true;
      $this->currencies_mode = 'checkbox';
      $this->tab             = 'payments_gateways';
      $this->display         = 'view';
      $this->sslport         = $sslport;
      $this->verifypeer      = $verifypeer;
      $this->verifyhost      = $verifyhost;
      if (_PS_VERSION_ > '1.5')
      $this->controllers = array('payment', 'validation');

      parent::__construct();

      $this->page = basename(__FILE__, '.php');
      $this->displayName      = $this->l('Agate');
      $this->description      = $this->l('Accepts payments via Agate.');
      $this->confirmUninstall = $this->l('Are you sure you want to delete your details?');

      // Backward compatibility
      require(_PS_MODULE_DIR_ . 'agate/backward_compatibility/backward.php');

      $this->context->smarty->assign('base_dir',__PS_BASE_URI__);
    }

    public function install() {

      if(!function_exists('curl_version')) {
        $this->_errors[] = $this->l('Sorry, this module requires the cURL PHP extension but it is not enabled on your server.  Please ask your web hosting provider for assistance.');
        return false;
      }

      $db = Db::getInstance();
      $result = array();
      $check = array();
      $result = $db->ExecuteS('SELECT * FROM `' . _DB_PREFIX_ . 'order_state_lang` WHERE `name` = "Awaiting Agate payment";');
      error_log("Result = ".print_r($result,true));
      if($result==$check){
        error_log("Entered install");
        $order_pending = new OrderState();
        $order_pending->name = array_fill(0, 10, 'Awaiting Agate payment');
        $order_pending->send_email = 0;
        $order_pending->invoice = 0;
        $order_pending->color = 'RoyalBlue';
        $order_pending->unremovable = false;
        $order_pending->logable = 0;
        $order_expired = new OrderState();
        $order_expired->name = array_fill(0, 10, 'Agate payment expired');
        $order_expired->send_email = 1;
        $order_expired->invoice = 0;
        $order_expired->color = '#DC143C';
        $order_expired->unremovable = false;
        $order_expired->logable = 0;
        $order_confirming = new OrderState();
        $order_confirming->name = array_fill(0, 10, 'Awaiting Agate payment confirmations');
        $order_confirming->send_email = 1;
        $order_confirming->invoice = 0;
        $order_confirming->color = '#d9ff94';
        $order_confirming->unremovable = false;
        $order_confirming->logable = 0;
        if ($order_pending->add()) {
          copy(
              _PS_ROOT_DIR_ . '/modules/agate/logo.png',
              _PS_ROOT_DIR_ . '/img/os/' . (int)$order_pending->id . '.gif'
          );
        }
        if ($order_expired->add()) {
            copy(
                _PS_ROOT_DIR_ . '/modules/agate/logo.png',
                _PS_ROOT_DIR_ . '/img/os/' . (int)$order_expired->id . '.gif'
            );
        }
        if ($order_confirming->add()) {
            copy(
                _PS_ROOT_DIR_ . '/modules/agate/logo.png',
                _PS_ROOT_DIR_ . '/img/os/' . (int)$order_confirming->id . '.gif'
            );
        }

        Configuration::updateValue('AGATE_PENDING', $order_pending->id);
        Configuration::updateValue('AGATE_EXPIRED', $order_expired->id);
        Configuration::updateValue('AGATE_CONFIRMING', $order_confirming->id);
    }

      if (!parent::install() || !$this->registerHook('invoice') || !$this->registerHook('payment') || !$this->registerHook('paymentReturn') || !$this->registerHook('paymentOptions')) {
        return false;
      }

      return true;
    }

    public function uninstall() {

        Configuration::deleteByName('agate_APIKEY');

      return parent::uninstall();
    }

    public function getContent() {
      $this->_html .= '<h2>'.$this->l('agate').'</h2>';

      $this->_postProcess();
      // $this->_setagateSubscription();
      $this->_setConfigurationForm();

      return $this->_html;
    }


    public function hookPaymentOptions($params)
    {
        if (!$this->active) {
            return;
        }

        $payment_options = [
            $this->linkToAgate(),
        ];

        return $payment_options;
    }

    public function linkToAgate()
    {
        $agate_option = new PaymentOption();
        $agate_option->setCallToActionText($this->l('Agate'))
                      ->setAction(Configuration::get('PS_FO_PROTOCOL').__PS_BASE_URI__."modules/{$this->name}/payment.php");

        return $agate_option;
    }

    public function hookPayment($params) {
      global $smarty;

      $smarty->assign(array(
                            'this_path' => $this->_path,
                            'this_path_ssl' => Configuration::get('PS_FO_PROTOCOL').$_SERVER['HTTP_HOST'].__PS_BASE_URI__."modules/{$this->name}/")
                           );

      return $this->display(__FILE__, 'payment.tpl');
    }

    private function _setConfigurationForm() {
      $this->_html .= '<form method="post" action="'.htmlentities($_SERVER['REQUEST_URI']).'">
                       <script type="text/javascript">
                       var pos_select = '.(($tab = (int)Tools::getValue('tabs')) ? $tab : '0').';
                       </script>';

      if (_PS_VERSION_ <= '1.5') {
        $this->_html .= '<script type="text/javascript" src="'._PS_BASE_URL_._PS_JS_DIR_.'tabpane.js"></script>
                         <link type="text/css" rel="stylesheet" href="'._PS_BASE_URL_._PS_CSS_DIR_.'tabpane.css" />';
      } else {
        $this->_html .= '<script type="text/javascript" src="'._PS_BASE_URL_._PS_JS_DIR_.'jquery/plugins/tabpane/jquery.tabpane.js"></script>
                         <link type="text/css" rel="stylesheet" href="'._PS_BASE_URL_._PS_JS_DIR_.'jquery/plugins/tabpane/jquery.tabpane.css" />';
      }

      $this->_html .= '<input type="hidden" name="tabs" id="tabs" value="0" />
                       <div class="tab-pane" id="tab-pane-1" style="width:100%;">
                       <div class="tab-page" id="step1">
                       <h4 class="tab">'.$this->l('Settings').'</h2>
                       '.$this->_getSettingsTabHtml().'
                       </div>
                       </div>
                       <div class="clear"></div>
                       <script type="text/javascript">
                       function loadTab(id){}
                       setupAllTabs();
                       </script>
                       </form>';
    }

    private function _getSettingsTabHtml() {
      global $cookie;

      $html = '<h2>'.$this->l('Settings').'</h2>
               <div style="clear:both;margin-bottom:30px;">
               <h3 style="clear:both;">'.$this->l('API KEY :').'</h3>
               <input type="text" style="width:400px;" name="apikey_agate" value="'.htmlentities(Tools::getValue('apikey', Configuration::get('agate_APIKEY')), ENT_COMPAT, 'UTF-8').'" />
               </div>
               <p class="center"><input class="button" type="submit" name="submitagate" value="'.$this->l('Save settings').'" /></p>';

      return $html;
    }

    private function _postProcess() {
      global $currentIndex, $cookie;

      if (Tools::isSubmit('submitagate')) {
        $template_available = array('A', 'B', 'C');
        $this->_errors      = array();

        if (Tools::getValue('apikey_agate') == NULL)
          $this->_errors[]  = $this->l('Missing API Key');

        if (count($this->_errors) > 0) {
          $error_msg = '';

          foreach ($this->_errors AS $error)
            $error_msg .= $error.'<br />';

          $this->_html = $this->displayError($error_msg);
        } else {
          Configuration::updateValue('agate_APIKEY', trim(Tools::getValue('apikey_agate')));
          $this->_html = $this->displayConfirmation($this->l('Settings updated'));
        }

      }

    }

    public function execPayment($cart) {
      $total = $cart->getOrderTotal(true);

      if (_PS_VERSION_ <= '1.5')
        $redirect_url     = (Configuration::get('PS_SSL_ENABLED') ? 'https://' : 'http://').htmlspecialchars($_SERVER['HTTP_HOST'], ENT_COMPAT, 'UTF-8').__PS_BASE_URI__.'order-confirmation.php?id_cart='.$cart->id.'&id_module='.$this->id.'&id_order='.$this->currentOrder;
      else
        $redirect_url     = Context::getContext()->link->getModuleLink('agate', 'validation');

      $baseUri         = "http://gateway.agate.services/" ;
      $convertUrl      = "http://gateway.agate.services/convert/";
      $api_key         = Configuration::get('agate_APIKEY'); // API KEY
      $order_total     = $total;  // Total price
      $cur             = Currency::getCurrencyInstance((int)$cart->id_currency);
      $currencySymbol  = $cur->iso_code;

      error_log("Cost = ". $total);


      $amount_iUSD = convertCurToIUSD($convertUrl, $order_total, $api_key, $currencySymbol);

      redirectPayment($baseUri, $amount_iUSD, $order_total, $currencySymbol, $api_key, $redirect_url);

    }
  }


?>
