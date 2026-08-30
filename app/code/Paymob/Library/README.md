# Paymob Flash - PHP Library

Paymob PHP library is integrating Paymob Flash payment with PHP platforms.

## Install

You can require it via Composer

``` bash
composer require paymob/php-library
```

## Usage
### Configuration Array
``` php
$paymobKeys['apiKey'] = '';
$paymobKeys['pubKey'] = '';
$paymobKeys['secKey'] = '';

```

### Get HMAC and Available integration IDs

``` php
$paymobReq = new Paymob();
$result = $paymobReq->authToken($paymobKeys);
echo "<pre>";
print_r($result);

```
### Create an intention with Paymob

``` php
$cents = 100; // 100 for all countries and 1000 for Oman
$billing = [
        "email" => 'email@email.com',
        "first_name" => 'fname',
        "last_name" => 'lname',
        "street" => 'street',
        "phone_number" => '1234567890',
        "city" => 'city',
        "country" => 'country',
        "state" => 'state',
        "postal_code" => '12345',
    ];

    $data = [
        "amount" => 10 * $cents,
        "currency" => 'EGP',
        "payment_methods" => array(1234567), // replace this id 1234567 with your integration ID(s)
        "billing_data" => $billing,
        "extras" => ["merchant_intention_id" => '123_' . time()],
        "special_reference" => '123_' . time()
    ];

    $status = $paymobReq->createIntention($paymobKeys['secKey'],$cs='','POST', $data);
    echo "<pre/>";
    print_r($status);

```

### Get Paymob Flash URL

``` php
$countryCode = $paymobReq->getCountryCode($paymobKeys['secKey']);
$apiUrl = $paymobReq->getApiUrl($countryCode);
$cs = $status['cs'];

$to = $apiUrl . "unifiedcheckout/?publicKey=" . $paymobKeys['pubKey'] . "&clientSecret=$cs";
echo "Pay using the URL $to";
```
