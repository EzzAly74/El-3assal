<?php
namespace Paymob\Library;

use Exception;
class Paymob {


	public $debug_order;
	public $file;

	public function __construct( $debug_order = false, $file = null ) {
		$this->debug_order = $debug_order;
		$this->file        = $file;
	}

	public function HttpRequest( $apiPath, $method, $header = array(), $data = array() ) {
		if ( ! in_array( 'curl', get_loaded_extensions() ) ) {
			throw new Exception( 'Curl extension is not loaded on your server, please check with server admin. Then try again!' );
		}
		$agent=self::filterVar('HTTP_USER_AGENT','SERVER');
		ini_set( 'precision', 14 );
		ini_set( 'serialize_precision', -1 );
		$curl = curl_init();
		curl_setopt( $curl, CURLOPT_URL, $apiPath );
		if ( 'GET' == $method ) {
			curl_setopt( $curl, CURLOPT_CUSTOMREQUEST, 'GET' );
		}elseif('PUT' == $method)
		{
			curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PUT');  // Correctly set PUT method
			curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));  // Set data as JSON
		} else {
			curl_setopt( $curl, CURLOPT_POST, true );
			curl_setopt( $curl, CURLOPT_POSTFIELDS, json_encode( $data ) );
		}
		curl_setopt( $curl, CURLOPT_HTTPHEADER, $header );
		curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true );
		curl_setopt($curl, CURLOPT_USERAGENT, $agent);

		$response = curl_exec( $curl );

		if ( false === $response ) {
			throw new Exception( 'Curl error: ' . curl_error( $curl ) );
		}
		curl_close( $curl );

		return json_decode( $response, false );
	}

	public function authToken( $conf ) {
		$this->matchCountries( $conf );
		$this->addLogs( $this->debug_order, $this->file, ' Authenticate Paymob configuration' );
		$apiUrl = $this->getApiUrl( $this->getCountryCode( $conf['secKey'] ) );
		$tokenRes = $this->HttpRequest( $apiUrl . 'api/auth/tokens', 'POST', array( 'Content-Type: application/json' ), array( 'api_key' => $conf['apiKey'] ) );
		
		$this->addLogs( $this->debug_order, $this->file, ' In api/auth/tokens Response: ' . json_encode( $tokenRes ) );

		if ( isset( $tokenRes->token ) ) {
			$hmacRes     = $this->getHmac( $tokenRes->token, $apiUrl );
			$integIDsRes = $this->getIntegrationIDs( $tokenRes->token, $apiUrl, $this->matchMode( $conf ) );
			$data        = array(
				'hmac'           => $hmacRes,
				'integrationIDs' => $integIDsRes,
				'token'=>$tokenRes->token,
			);

			return $data;
		} else {
			throw new Exception( 'Cannot get Token from PayMob account' );
		}
	}

	public function getHmac( $token, $apiUrl ) {
		$hmacRes = $this->HttpRequest( $apiUrl . 'api/auth/hmac_secret/get_hmac', 'GET', array( 'Content-Type: application/json', 'Authorization: Bearer ' . $token ) );
		$this->addLogs( $this->debug_order, $this->file, ' In api/auth/hmac_secret/get_hmac Response: ' . json_encode( $hmacRes ) );
		if ( isset( $hmacRes->hmac_secret ) ) {
			return $hmacRes->hmac_secret;
		} else {
			throw new Exception( 'Cannot get HMAC from PayMob account' );
		}
	}

	public function getIntegrationIDs( $token, $apiUrl, $isTest = false ) {
		$intRes = $this->HttpRequest( $apiUrl . 'api/ecommerce/integrations?is_plugin=true&is_next=yes&page_size=500&is_deprecated=false&is_standalone=false', 'GET', array( 'Content-Type: application/json', 'Authorization: Bearer ' . $token ) );
		$this->addLogs( $this->debug_order, $this->file, ' In api/ecommerce/integrations Response: ' . json_encode( $intRes ) );

		if ( ! empty( $intRes ) ) {
			$IntegrationIDs = array();
			foreach ( $intRes->results as $key => $integration ) {
				$type = $integration->gateway_type;
				if ( 'VPC' == $type ) {
					$type = 'Card';
				} elseif ( 'CAGG' == $type ) {
					$type = 'Aman';
				} elseif ( 'UIG' == $type ) {
					$type = 'Wallet';
				}
				if($integration->integration_type =="moto"){
					$is_moto="yes";
				}
				else{
					$is_moto="no";
				}
				if ( false == $integration->is_standalone ) {
					if($integration->is_live==false){
						$mode='test';
					}
					else
					{
						$mode='live';
					}

					$IntegrationIDs[ $integration->id ] = array(
						'id'           => $integration->id,
						'type'         => $type,
						'gateway_type' => $integration->gateway_type,
						'name'         => empty( $integration->installments ) ? $integration->integration_name : 'bank-installments',
						'currency'     => $integration->currency,
						'mode'         => $mode,
						'is_moto'      =>$is_moto,
					);
				}
				
			}
			return $IntegrationIDs;
		} else {
			throw new Exception( 'Cannot get available integration IDs from PayMob account' );
		}
	}

	public function createIntention( $secKey, $data, $orderId ,$cs,$method) {
		$flash  = $this->getApiUrl( $this->getCountryCode( $secKey ) );
		$header = array( 'Content-Type: application/json', 'Authorization: Token ' . $secKey );
		$this->addLogs( $this->debug_order, $this->file, print_r( $data, 1 ) );
		$intention = $this->HttpRequest( $flash . 'v1/intention/'.$cs, $method, $header, $data );
		$text= (!empty($cs)) ? 'Update ' : '';
		$note_i    = $text.'Intention response for order # ' . $orderId;
		$this->addLogs( $this->debug_order, $this->file, $note_i, print_r( $intention, 1 ) );
		if ( empty( $intention->payment_keys ) ) {
			$this->addLogs( $this->debug_order, $this->file, $note_i, $intention );
		}
		$status = array(
			'cs'      => null,
			'success' => false,
		);

		if ( isset( $intention->amount_cents ) ) {
			$status['message'] = $intention->amount_cents;
			return $status;
		}
		if ( isset( $intention->detail ) ) {
			$status['message'] = $intention->detail;
			return $status;
		}
		if ( isset( $intention->amount ) ) {
			$status['message'] = $intention->amount[0];
			return $status;
		}
		if ( isset( $intention->billing_data ) ) {
			$status['message'] = 'Ops, there is missing billing information!';
			return $status;
		}

		if ( isset( $intention->integrations ) ) {
			$status['message'] = $intention->integrations[0];
			return $status;
		}

		if ( isset( $intention->client_secret ) ) {
			$status['success']     = true;
			$status['cs']          = $intention->client_secret;
			$status['intentionId'] = $intention->id;
			$status['centsAmount'] = $intention->intention_detail->amount;
			if(empty($cs)){
				$status['intention_order_id'] = $intention->intention_order_id;
			}
		} else {
			$status['message'] = ( isset( $intention->code ) ) ? $intention->code : 'Something went wrong';
		}
		$this->addLogs( $this->debug_order, $this->file, $note_i, json_encode( $status ) );
		return $status;
	}
	/**
	 * Get a list of Paymob Gateways, their Logos, and names.
	 *
	 * @return array of Paymob data
	 */
	public function getPaymobGateways( $secKey, $path ) {
		// get gateways data
		$flash  = $this->getApiUrl( $this->getCountryCode( $secKey ) );
		$header = array( 'Content-Type: application/json', 'Authorization: Token ' . $secKey );

		$getways = $this->HttpRequest( $flash . 'api/ecommerce/gateways', 'GET', $header );
		$this->addLogs( $this->debug_order, $this->file, 'In api/ecommerce/gateways Response: ', json_encode( $getways ) );
		// Handle invalid or empty responses
		if ( is_null( $getways ) || ! isset( $getways->result ) ) {
			$this->addLogs( $this->debug_order, $this->file, 'In api/ecommerce/gateways Response: Cannot get Gateways Data from PayMob account.' );
			throw new Exception( 'Cannot get Gateways Data from PayMob account' );
		}

		// Process the gateways data if available
		$gateways = json_decode( json_encode( $getways->result, true ), true );
		return $this->extractGatewaysData( $gateways, $path );
	}

	public static function extractGatewaysData( $gateways, $path ) {
		$gatewaysData = array();

		// Check if $gateways is an array or object before processing
		if ( ! is_array( $gateways ) && ! is_object( $gateways ) ) {
			return $gatewaysData; // Return empty data if $gateways is not valid
		}

		foreach ( $gateways as $gateway ) {
			$logoPath = $path .'/'. strtolower( $gateway['code'] ) . '.png';
			// Skip downloading the logo if the logo URL is empty
			if ( ! empty( $gateway['logo'] ) ) {
				if ( ! file_exists( $logoPath ) ) {
					$ch = curl_init();
					curl_setopt( $ch, CURLOPT_HEADER, 0 );
					curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
					curl_setopt( $ch, CURLOPT_URL, $gateway['logo'] );
					$data = curl_exec( $ch );
					curl_close( $ch );
					file_put_contents( $logoPath, $data );
				}
			} else {
				// If logo URL is empty, use a placeholder or skip the download
				$logoPath = ''; // Leave it empty if no logo is available
			}

			$gatewaysData[ strtolower( $gateway['code'] ) ] = array(
				'title' => $gateway['label'],
				'desc'  => $gateway['description'],
				'logo'  => $gateway['logo'],
			);
		}
		return $gatewaysData;
	}
	public function registerFramework( $secKey, $data ) {
		$flash       = $this->getApiUrl( $this->getCountryCode( $secKey ) );
		$header      = array( 'Content-Type: application/json', 'Authorization: Token ' . $secKey );
		$registerRes = $this->HttpRequest( $flash . 'api/ecommerce/plugins', 'POST', $header, $data );
		$this->addLogs( $this->debug_order, $this->file, ' In api/ecommerce/plugins: ' . json_encode( $registerRes ) );
		return $registerRes;
	}
	public function refundPayment( $secKey, $data ) {
		$flash  = $this->getApiUrl( $this->getCountryCode( $secKey ) );
		$header = array( 'Content-Type: application/json', 'Authorization: Token ' . $secKey );
		$this->addLogs( $this->debug_order, $this->file, print_r( $data, 1 ) );
		$refundRes = $this->HttpRequest( $flash . 'api/acceptance/void_refund/refund', 'POST', $header, $data );
		$this->addLogs( $this->debug_order, $this->file, ' In api/acceptance/void_refund/refund: ' . json_encode( $refundRes ) );

		if ( isset( $refundRes->detail ) ) {
			$status['message'] = $refundRes->detail;
			return $status;
		}
		if ( isset( $refundRes->message ) ) {
			$status['message'] = $refundRes->message;
			return $status;
		}
		if ( isset( $refundRes->amount_cents[0] ) ) {
			$status['message'] = $refundRes->amount_cents[0] ;
			return $status;
		} 
		if ( isset( $refundRes->success ) ) {
			$status['success']   = true;
			$status['refund_id'] = $refundRes->id;
		} else {
			$status['success'] = false;
			$status['message'] = 'Something went wrong';
		}
		return $status;
	}
	public function getOnboardingUrl( $code, $data ) {
		$flash       = $this->getApiUrl($code);
		$header = array( 'Content-Type: application/json');
		$this->addLogs( $this->debug_order, $this->file, print_r( $data, 1 ) );
		$onboardingRes = $this->HttpRequest( $flash . 'api/onboarding/partners-utils/country_url', 'POST', $header, $data );
		$this->addLogs( $this->debug_order, $this->file, ' In api/onboarding/partners-utils/country_url: ' . json_encode( $onboardingRes ) );
		return $onboardingRes;
	}
	public function getPartnerInfo( $woo_code, $data ) {
		// return $this->getCountryCode( $woo_code );
		$flash       = $this->getApiUrl( strtolower($this->getCountryCode( $woo_code )) );
		$header      = array( 'Content-Type: application/json', 'Authorization:' . $woo_code );
		$this->addLogs( $this->debug_order, $this->file, print_r( $data, 1 ) );
		$partnerInfoRes = $this->HttpRequest( $flash . 'api/onboarding/partners-utils/merchant_info', 'POST', $header, $data );
		$this->addLogs( $this->debug_order, $this->file, ' In api/onboarding/partners-utils/merchant_info: ' . json_encode( $partnerInfoRes ) );
		return $partnerInfoRes;
	}
	public function matchMode( $conf ) {
		$pubKeyMode = $this->getMode( $conf['pubKey'] );
		$secKeyMode = $this->getMode( $conf['secKey'] );

		if ( $secKeyMode != $pubKeyMode ) {
			throw new Exception( 'Public and Secret Keys does not belong to the ( live/test ) mode' );
		}
		return ( 'live' == $pubKeyMode );
	}

	public function matchCountries( $conf ) {
		$pubKey = $this->getCountryCode( $conf['pubKey'] );
		$secKey = $this->getCountryCode( $conf['secKey'] );
		if ( $pubKey != $secKey ) {
			throw new Exception( 'Public and Secret Keys does not belong to the same country' );
		}
		return true;
	}

	public function getMode( $code ) {
		return substr( $code, 7, 4 );
	}

	public static function getCountryCode( $code ) {
		return substr( $code, 0, 3 );
	}

	public static function getIntentionId( $merchantIntentionId ) {
		return substr( $merchantIntentionId, 0, -11 );
	}

	public static function verifyHmac( $key, $data, $intention = null, $hmac = null ) {
		if ( isset( $hmac ) ) {
			return self::verifyAcceptHmac( $key, $data, $hmac );
		} else {
			return self::verifyFlashHmac( $key, $data, $intention );
		}
	}

	public static function verifyFlashHmac( $key, $data, $intention = null ) {

		if ( empty( $intention ) ) {
			// callback GET
			$str  = $data['amount_cents']
				. $data['created_at']
				. $data['currency']
				. $data['error_occured']
				. $data['has_parent_transaction']
				. $data['id']
				. $data['integration_id']
				. $data['is_3d_secure']
				. $data['is_auth']
				. $data['is_capture']
				. $data['is_refunded']
				. $data['is_standalone_payment']
				. $data['is_voided']
				. $data['order']
				. $data['owner']
				. $data['pending']
				. $data['source_data_pan']
				. $data['source_data_sub_type']
				. $data['source_data_type']
				. $data['success'];
			$hash = hash_hmac( 'sha512', $str, $key );
		} else {
			// webhook POST
			$amount = ( $intention['amount'] / $intention['cents'] );
			if ( is_float( $amount ) ) {
				$amountArr = explode( '.', $amount );
				if ( strlen( $amountArr[1] ) == 1 ) {
					$amount = $amount . '0';
				}
			} else {
				$amount = $amount . '.00';
			}
			$str  = $amount . $intention['id'];
			$hash = hash_hmac( 'sha512', $str, $key, false );
		}

		$hmac = $data['hmac'];

		return ( $hmac === $hash );
	}

	public static function verifyAcceptHmac( $key, $json_data, $hmac ) {
		$data                           = $json_data['obj'];
		$data['order']                  = $data['order']['id'];
		$data['is_3d_secure']           = ( true === $data['is_3d_secure'] ) ? 'true' : 'false';
		$data['is_auth']                = ( true === $data['is_auth'] ) ? 'true' : 'false';
		$data['is_capture']             = ( true === $data['is_capture'] ) ? 'true' : 'false';
		$data['is_refunded']            = ( true === $data['is_refunded'] ) ? 'true' : 'false';
		$data['is_standalone_payment']  = ( true === $data['is_standalone_payment'] ) ? 'true' : 'false';
		$data['is_voided']              = ( true === $data['is_voided'] ) ? 'true' : 'false';
		$data['success']                = ( true === $data['success'] ) ? 'true' : 'false';
		$data['error_occured']          = ( true === $data['error_occured'] ) ? 'true' : 'false';
		$data['has_parent_transaction'] = ( true === $data['has_parent_transaction'] ) ? 'true' : 'false';
		$data['pending']                = ( true === $data['pending'] ) ? 'true' : 'false';
		$data['source_data_pan']        = $data['source_data']['pan'];
		$data['source_data_type']       = $data['source_data']['type'];
		$data['source_data_sub_type']   = $data['source_data']['sub_type'];

		$str  = '';
		$str  = $data['amount_cents'] .
			$data['created_at'] .
			$data['currency'] .
			$data['error_occured'] .
			$data['has_parent_transaction'] .
			$data['id'] .
			$data['integration_id'] .
			$data['is_3d_secure'] .
			$data['is_auth'] .
			$data['is_capture'] .
			$data['is_refunded'] .
			$data['is_standalone_payment'] .
			$data['is_voided'] .
			$data['order'] .
			$data['owner'] .
			$data['pending'] .
			$data['source_data_pan'] .
			$data['source_data_sub_type'] .
			$data['source_data_type'] .
			$data['success'];
		$hash = hash_hmac( 'sha512', $str, $key );
		return $hash === $hmac;
	}

	public static function getApiUrl( $countryCode ) {
		$domain = 'paymob.com/';
		if ( 'are' == $countryCode || 'uae' == $countryCode ) {
			return 'https://uae.' . $domain;
		} elseif ( 'egy' == $countryCode ) {
			return 'https://accept.' . $domain;
		} elseif ( 'pak' == $countryCode ) {
			return 'https://pakistan.' . $domain;
		} elseif ( 'ksa' == $countryCode || 'sau' == $countryCode ) {
			return 'https://ksa.' . $domain;
		} elseif ( 'omn' == $countryCode ) {
			return 'https://oman.' . $domain;
		} else {
			throw new Exception( 'Another country' );
		}
	}

	public static function getTimeZone( $country ) {
		switch ( $country ) {
			case 'omn':
				return 'Asia/Muscat';
			case 'pak':
				return 'Asia/Karachi';
			case 'ksa':
			case 'sau':
				return 'Asia/Riyadh';
			case 'are':
			case 'uae':
				return 'Asia/Dubai';
			case 'egy':
			default:
				return 'Africa/Cairo';
		}
	}

	/**
	 * Filter the GLOBAL variables
	 *
	 * @param string $name The field name the need to be filter.
	 * @param string $global value could be (GET, POST, REQUEST, COOKIE, SERVER).
	 *
	 * @return string|null
	 */
	public static function filterVar( $name, $global = 'GET' ) {
		if ( isset( $GLOBALS[ '_' . $global ][ $name ] ) ) {
			if ( is_array( $GLOBALS[ '_' . $global ][ $name ] ) ) {
				return $GLOBALS[ '_' . $global ][ $name ];
			}
			return htmlspecialchars( $GLOBALS[ '_' . $global ][ $name ], ENT_QUOTES );
		}
		return null;
	}

	public static function sanitizeVar( $type = 'GET' ) {
		return $GLOBALS[ '_' . $type ];
	}

	public static function addLogs( $debug, $file, $note, $data = false ) {
		if ( is_bool( $data ) ) {
			( '1' === $debug ) ? error_log( PHP_EOL . gmdate( 'd.m.Y h:i:s' ) . ' - ' . $note, 3, $file ) : false;
		} else {
			( '1' === $debug ) ? error_log( PHP_EOL . gmdate( 'd.m.Y h:i:s' ) . ' - ' . $note . ' -- ' . json_encode( $data ), 3, $file ) : false;
		}
	}

	public function getIntegrationID( $conf,$IntegrationID ) {
		$this->matchCountries( $conf );
		$this->addLogs( $this->debug_order, $this->file, ' Authenticate Paymob configuration' );
		$apiUrl = $this->getApiUrl( $this->getCountryCode( $conf['secKey'] ) );

		$tokenRes = $this->HttpRequest( $apiUrl . 'api/auth/tokens', 'POST', array( 'Content-Type: application/json' ), array( 'api_key' => $conf['apiKey'] ) );
		if ( isset( $tokenRes->token ) )
		{
			$IntegrationID = $this->HttpRequest( $apiUrl . 'api/ecommerce/integrations/'.$IntegrationID, 'GET', array( 'Content-Type: application/json', 'Authorization: Bearer ' . $tokenRes->token ) );
			$this->addLogs( $this->debug_order, $this->file, ' In api/ecommerce/integrations Response: ' . json_encode( $IntegrationID ) );
			if ( ! empty( $IntegrationID ) ) 
			{
				return $IntegrationID;
			}else
			{
				throw new Exception( 'Cannot get this integration ID from PayMob account' );
			}

		}
		else 
		{
			throw new Exception( 'Cannot get Token from PayMob account' );
		}
		
	}

	public function updateWebHookUrl( $conf,$IntegrationID,$data ) {
		$this->matchCountries( $conf );
		$this->addLogs( $this->debug_order, $this->file, ' Authenticate Paymob configuration' );
		$apiUrl = $this->getApiUrl( $this->getCountryCode( $conf['secKey'] ) );
		$tokenRes = $this->HttpRequest( $apiUrl . 'api/auth/tokens', 'POST', array( 'Content-Type: application/json' ), array( 'api_key' => $conf['apiKey'] ) );
		if ( isset( $tokenRes->token ) )
		{ 
			$result = $this->HttpRequest( $apiUrl . 'api/ecommerce/integrations/'.$IntegrationID, 'PUT', array( 'Content-Type: application/json', 'Authorization: Bearer ' . $tokenRes->token ),$data);
			$this->addLogs( $this->debug_order, $this->file, ' In api/ecommerce/integrations Response: ' . json_encode( $result ) );
			if ( ! empty( $result ) ) 
			{
				return $result;
			}else
			{
				throw new Exception( 'Cannot update webhook url' );
			}

		}
		else 
		{
			throw new Exception( 'Cannot get Token from PayMob account' );
		}
		
	}

	public function valuWidget( $secKey, $data) {

		$header = array( 'Content-Type: application/json', 'Authorization: Token ' . $secKey );
		$this->addLogs( $this->debug_order, $this->file, print_r( $data, 1 ) );
		$apiUrl = $this->getApiUrl( $this->getCountryCode( $secKey ) );
		// $widgets = $this->HttpRequest( $flash, 'POST', $header, $data );
		$widgets = $this->HttpRequest( $apiUrl.'api/acceptance/valu_widget/inquire', 'POST', $header, $data );
		$note_i    = 'ValuWidget response ';
		$this->addLogs( $this->debug_order, $this->file, $note_i, print_r( $widgets, 1 ) );
		if ( empty( $widgets ) ) {
			$this->addLogs( $this->debug_order, $this->file, $note_i, $widgets );
		}
		
		if ( isset( $widgets ) ) {
			$widgets = $widgets;
		}
		else {
			$widgets = ( isset( $widgets ) ) ? $widgets : 'Something went wrong';
		}
		$this->addLogs( $this->debug_order, $this->file, $note_i, json_encode( $widgets ) );
		return $widgets;
	}

	public function createSubscriptionPlan($token,$secKey, $data)
	{
		$header = array( 'Content-Type: application/json', 'Authorization: Bearer ' . $token );
		$this->addLogs( $this->debug_order, $this->file, print_r( $data, 1 ) );
		$apiUrl = $this->getApiUrl( $this->getCountryCode( $secKey ) );
		$plans = $this->HttpRequest( $apiUrl.'api/acceptance/subscription-plans', 'POST', $header, $data );
		$note_i    = 'subscriptionPlans response ';
		$this->addLogs( $this->debug_order, $this->file, $note_i, print_r( $plans, 1 ) );
		if ( empty( $plans ) ) {
			$this->addLogs( $this->debug_order, $this->file, $note_i, $plans );
		}
		
		if ( isset( $plans ) ) {
			$plans = $plans;
		}
		else {
			$plans = ( isset( $plans ) ) ? $plans : 'Something went wrong';
		}
		$this->addLogs( $this->debug_order, $this->file, $note_i, json_encode( $plans ) );
		return $plans;
	}

	public function suspendSubscriptionPlan($token,$secKey,$planId)
	{
		$header = array( 'Content-Type: application/json', 'Authorization: Bearer ' . $token );
		$apiUrl = $this->getApiUrl( $this->getCountryCode( $secKey ) );
		$plans = $this->HttpRequest( $apiUrl.'api/acceptance/subscriptions/'.$planId.'/suspend', 'POST', $header );
		$note_i    = 'Suspend subscriptionPlans response ';
		$this->addLogs( $this->debug_order, $this->file, $note_i, print_r( $plans, 1 ) );
		if ( empty( $plans ) ) {
			$this->addLogs( $this->debug_order, $this->file, $note_i, $plans );
		}
		
		if ( isset( $plans ) ) {
			$plans = $plans;
		}
		else {
			$plans = ( isset( $plans ) ) ? $plans : 'Something went wrong';
		}
		$this->addLogs( $this->debug_order, $this->file, $note_i, json_encode( $plans ) );
		return $plans;
	}

	public function activateSubscriptionPlan($token,$secKey,$planId)
	{
		$header = array( 'Content-Type: application/json', 'Authorization: Bearer ' . $token );
		$apiUrl = $this->getApiUrl( $this->getCountryCode( $secKey ) );
		$plans = $this->HttpRequest( $apiUrl.'api/acceptance/subscriptions/'.$planId.'/resume', 'POST', $header);
		$note_i    = 'Activate subscriptionPlans response ';
		$this->addLogs( $this->debug_order, $this->file, $note_i, print_r( $plans, 1 ) );
		if ( empty( $plans ) ) {
			$this->addLogs( $this->debug_order, $this->file, $note_i, $plans );
		}
		
		if ( isset( $plans ) ) {
			$plans = $plans;
		}
		else {
			$plans = ( isset( $plans ) ) ? $plans : 'Something went wrong';
		}
		$this->addLogs( $this->debug_order, $this->file, $note_i, json_encode( $plans ) );
		return $plans;
	}


	public function cancelSubscriptionPlan($token,$secKey, $planId)
	{
		$header = array( 'Content-Type: application/json', 'Authorization: Bearer ' . $token );
		$apiUrl = $this->getApiUrl( $this->getCountryCode( $secKey ) );
		$plans = $this->HttpRequest( $apiUrl.'api/acceptance/subscriptions/'.$planId.'/cancel', 'POST', $header );
		$note_i    = 'Cancel subscriptionPlans response ';
		$this->addLogs( $this->debug_order, $this->file, $note_i, print_r( $plans, 1 ) );
		if ( empty( $plans ) ) {
			$this->addLogs( $this->debug_order, $this->file, $note_i, $plans );
		}
		
		if ( isset( $plans ) ) {
			$plans = $plans;
		}
		else {
			$plans = ( isset( $plans ) ) ? $plans : 'Something went wrong';
		}
		$this->addLogs( $this->debug_order, $this->file, $note_i, json_encode( $plans ) );
		return $plans;
	}
	
	public function updateSubscriptionPlan($token,$secKey, $data, $planId)
	{
		$header = array( 'Content-Type: application/json', 'Authorization: Bearer ' . $token );
		$this->addLogs( $this->debug_order, $this->file, print_r( $data, 1 ) );
		$apiUrl = $this->getApiUrl( $this->getCountryCode( $secKey ) );
		$plans = $this->HttpRequest( $apiUrl.'api/acceptance/subscriptions/'.$planId, 'PATCH', $header, $data );
		$note_i    = 'Cancel subscriptionPlans response ';
		$this->addLogs( $this->debug_order, $this->file, $note_i, print_r( $plans, 1 ) );
		if ( empty( $plans ) ) {
			$this->addLogs( $this->debug_order, $this->file, $note_i, $plans );
		}
		
		if ( isset( $plans ) ) {
			$plans = $plans;
		}
		else {
			$plans = ( isset( $plans ) ) ? $plans : 'Something went wrong';
		}
		$this->addLogs( $this->debug_order, $this->file, $note_i, json_encode( $plans ) );
		return $plans;
	}
	
	public function TransactionSubscriptionID($token,$secKey, $transactionID)
	{
		$header = array( 'Content-Type: application/json', 'Authorization: Bearer ' . $token );
		$apiUrl = $this->getApiUrl( $this->getCountryCode( $secKey ) );
		$plans = $this->HttpRequest( $apiUrl.'api/acceptance/subscriptions?transaction='.$transactionID, 'GET', $header );
		$note_i    = 'Cancel subscriptionPlans response ';
		$this->addLogs( $this->debug_order, $this->file, $note_i, print_r( $plans, 1 ) );
		if ( empty( $plans ) ) {
			$this->addLogs( $this->debug_order, $this->file, $note_i, $plans );
		}
		
		if ( isset( $plans ) ) {
			$plans = $plans;
		}
		else {
			$plans = ( isset( $plans ) ) ? $plans : 'Something went wrong';
		}
		$this->addLogs( $this->debug_order, $this->file, $note_i, json_encode( $plans ) );
		return $plans;
	}

}