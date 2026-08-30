# Paymob Payment Gateway package for Magento2 e-commerce

## Installation
1. Install the Paymob Payment Magento2 module via [paymob/magento-payment](https://packagist.org/packages/paymob/magento-payment) composer.
```bash
composer require paymob/magento-payment
```

2. In the command line, run the below Magento commands to enable Paymob Payment Gateway module.
```bash
php -f bin/magento module:enable --clear-static-content Paymob_Payment
```
3. Then, run the below Magento commands.
```bash
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento setup:static-content:deploy -f
php bin/magento cache:clean
php bin/magento cache:flush
```

## Configuration
### Paymob Account
1. Login to the Paymob account → Setting in the left menu. 
2. Get the Secret, public, API keys, HMAC and Payment Methods IDs (integration IDs).

### Magento Admin Configuration
1. Login into Magento admin panel.
2. In the left Menu → Stores → Configuration.
3. Expand Sales Menu → select Payment Methods →Accept Paymob payment, paste each key mentioned in the above Paymob Account section in its place in the setting page.
4. Please ensure adding the integration IDs separated by comma ,. These IDs will be shown in the Paymob payment page. 
5. Copy integration callback URL that exists in Paymob Magento setting page. Then, paste it into each payment integration/method in Paymob account.
6. Then, click on save. 
7. Ensure there 's no error while saving due to in-correct information provided.

## Checkout page 
Paymob payment method will be shown for the end-user to select and start his payment process. 
