<?php
/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Smartwave\Porto\Helper;

use Magento\Framework\Registry;

class Data extends \Magento\Framework\App\Helper\AbstractHelper
{
    /**
     * PortoTheme.com direct-marketplace product ID (the Freemius product/plugin ID
     * assigned after the theme is uploaded). Set this once the product exists.
     * While it is 0, only ThemeForest purchase codes are accepted.
     */
    const PORTO_PRODUCT_ID = 32893;

    protected $_objectManager;
    private $_registry;
    protected $_filterProvider;
    private $_checkedPurchaseCode;
    private $_messageManager;
    protected $_configFactory;
    protected $_storeManager;

    public function __construct(
        \Magento\Framework\App\Helper\Context $context,
        \Magento\Framework\ObjectManagerInterface $objectManager,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Cms\Model\Template\FilterProvider $filterProvider,
        \Magento\Framework\Message\ManagerInterface $messageManager,
        \Magento\Framework\App\Config\ConfigResource\ConfigInterface $configFactory,
        Registry $registry
    ) {
        $this->_storeManager = $storeManager;
        $this->_objectManager = $objectManager;
        $this->_filterProvider = $filterProvider;
        $this->_registry = $registry;
        $this->_messageManager = $messageManager;
        $this->_configFactory = $configFactory;

        parent::__construct($context);
    }
    public function checkPurchaseCode($save = false) {
        if($this->isLocalhost()){
            return "localhost";
        }
        if(!$this->_checkedPurchaseCode){
            $code = $this->scopeConfig->getValue('porto_license/general/purchase_code')?$this->scopeConfig->getValue('porto_license/general/purchase_code'):'';
            $code_confirm = $this->scopeConfig->getValue('porto_license/general/purchase_code_confirm')?$this->scopeConfig->getValue('porto_license/general/purchase_code_confirm'):'';

            if($save) {
                $site_url = $this->scopeConfig->getValue('web/unsecure/base_url');
                $domain = trim(preg_replace('/^.*?\\/\\/(.*)?\\//', '$1', $site_url));
                if(strpos($domain, "/"))
                    $domain = substr($domain, 0, strpos($domain, "/"));

                // Whether the submitted key is identical to the one already verified.
                $unchanged = ($code && base64_encode($code) == $code_confirm);

                // When the key is cleared or changed, release the previously activated key
                // against whichever marketplace it belonged to.
                if(!$unchanged) {
                    $old_code = base64_decode($code_confirm);
                    if($old_code) {
                        if($this->isEnvatoCode($old_code)) {
                            $this->curlPurchaseCode($old_code, "", "remove");
                        } else {
                            $this->deactivateLicense($old_code, $site_url);
                        }
                    }
                }

                if($code) {
                    if($unchanged) {
                        // Same key, already active — do not re-register it. Re-activating
                        // would create a duplicate install on the licensing server on
                        // every config save.
                        $this->_checkedPurchaseCode = "verified";
                    } else if($this->isEnvatoCode($code)) {
                        // ThemeForest / Envato purchase code
                        $result = $this->curlPurchaseCode($code, $domain, "add");
                        if(!$result || $result['result'] == 0) {
                            $this->_checkedPurchaseCode = "";
                            $code_confirm = "";
                            $this->_messageManager->getMessages(true);
                            $this->_messageManager->addWarning(__('License key is not valid!'));
                        } else if($result['result'] == 1) {
                            $code_confirm = base64_encode($code);
                            $this->_checkedPurchaseCode = "verified";
                        } else {
                            $this->_checkedPurchaseCode = "";
                            $code_confirm = "";
                            $this->_messageManager->getMessages(true);
                            $this->_messageManager->addWarning(__($result['message']));
                        }
                    } else {
                        // PortoTheme.com direct license key
                        $result = $this->activateLicense($code, $site_url);
                        if($result && !empty($result['install_id'])) {
                            $code_confirm = base64_encode($code);
                            $this->_checkedPurchaseCode = "verified";
                            $this->_configFactory->saveConfig('porto_license/general/install_id', $result['install_id'], "default", 0);
                        } else {
                            $this->_checkedPurchaseCode = "";
                            $code_confirm = "";
                            $this->_messageManager->getMessages(true);
                            $message = ($result && !empty($result['message'])) ? $result['message'] : 'License key is not valid!';
                            $this->_messageManager->addWarning(__($message));
                        }
                    }
                } else {
                    $code_confirm = "";
                    $this->_checkedPurchaseCode = "";
                }
                $this->_configFactory->saveConfig('porto_license/general/purchase_code_confirm',$code_confirm,"default",0);
            } else {
                if($code && $code_confirm && base64_encode($code) == $code_confirm)
                    $this->_checkedPurchaseCode = "verified";
            }
        }

        return $this->_checkedPurchaseCode;
    }
    public function curlPurchaseCode($code, $domain, $act) {
        $ch = curl_init();

        // Set cURL options
        curl_setopt($ch, CURLOPT_URL, "https://www.portotheme.com/envato/verify_purchase_new.php?item=9725864&version=m2&code=$code&domain=$domain&act=$act");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, 'PORTO-PURCHASE-VERIFY');

        // Decode returned JSON
        $result = json_decode( curl_exec($ch) , true );
        return $result;
    }
    /**
     * Detect a ThemeForest / Envato purchase code (UUID format: 8-4-4-4-12 hex).
     * Anything that is not this format is treated as a PortoTheme.com direct license key.
     *
     * @param string $code
     * @return bool
     */
    public function isEnvatoCode($code) {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', trim($code));
    }
    /**
     * Stable per-site identifier that binds a direct license activation to this installation.
     * Derived from the base URL so it can be reproduced for deactivation even if config is lost.
     *
     * @param string $site_url
     * @return string
     */
    public function getLicenseUid($site_url) {
        return md5('porto-' . $site_url);
    }
    /**
     * Identity used to bind a license to a user when one is not already associated.
     * Prefers the admin who is activating, falls back to the store general contact.
     *
     * @param string $site_url
     * @return array ['first_name' => string, 'last_name' => string, 'user_email' => string]
     */
    public function getLicenseUser($site_url) {
        $first = '';
        $last  = '';
        $email = '';

        try {
            $user = $this->_objectManager->get('Magento\Backend\Model\Auth\Session')->getUser();
            if($user) {
                $first = (string) $user->getFirstName();
                $last  = (string) $user->getLastName();
                $email = (string) $user->getEmail();
            }
        } catch (\Exception $e) {
            // not in an authenticated admin context; fall back to store config below
        }

        if(!$email) {
            $email = (string) $this->scopeConfig->getValue('trans_email/ident_general/email');
        }
        if(!$first) {
            $name = trim((string) $this->scopeConfig->getValue('trans_email/ident_general/name'));
            if($name) {
                $parts = explode(' ', $name, 2);
                $first = $parts[0];
                $last  = isset($parts[1]) ? $parts[1] : '';
            }
        }

        if(!$first) {
            $first = 'Porto';
        }
        if(!$last) {
            $last = 'User';
        }
        if(!$email) {
            $host  = trim(preg_replace('/^.*?\\/\\/(.*?)(\\/.*)?$/', '$1', $site_url));
            $email = 'admin@' . ($host ? $host : 'portotheme.com');
        }

        return array('first_name' => $first, 'last_name' => $last, 'user_email' => $email);
    }
    /**
     * Activate a PortoTheme.com direct license key for this site.
     *
     * @param string $code
     * @param string $site_url
     * @return array ['install_id' => mixed] on success, or ['message' => string] on failure
     */
    public function activateLicense($code, $site_url) {
        if(!self::PORTO_PRODUCT_ID) {
            return array('message' => 'License activation is not available yet.');
        }
        // If this site already has an install on record, ask the server whether it is
        // still validly licensed with this same key (active, not expired, within the site
        // limit). If so, reuse it and skip activation so no duplicate install is created.
        $existing_install = $this->scopeConfig->getValue('porto_license/general/install_id');
        if($existing_install) {
            $license = $this->getInstallLicense($code, $site_url, $existing_install);
            if($license && $this->isLicenseUsable($license)) {
                return array('install_id' => $existing_install);
            }
        }
        // first_name/last_name/user_email are required when the license has no
        // associated user yet; the activation server uses them to bind the license.
        $user = $this->getLicenseUser($site_url);
        $params = array(
            'license_key' => $code,
            'uid'         => $this->getLicenseUid($site_url),
            'url'         => $site_url,
            'title'       => $site_url,
            'first_name'  => $user['first_name'],
            'last_name'   => $user['last_name'],
            'user_email'  => $user['user_email'],
        );
        // Reuse the existing install for this site (if any) so the server updates it
        // instead of creating a duplicate install record.
        if($existing_install) {
            $params['install_id'] = $existing_install;
        }
        $response = $this->curlLicense('activate', $params);
        if(!$response) {
            return array('message' => 'Could not reach the activation server. Please try again.');
        }
        if(!empty($response['error'])) {
            // The server rejects invalid keys and over-quota production activations here.
            $message = (is_array($response['error']) && !empty($response['error']['message']))
                ? $response['error']['message']
                : 'License key is not valid!';
            return array('message' => $message);
        }
        $install_id = null;
        if(!empty($response['install_id'])) {
            $install_id = $response['install_id'];
        } elseif(isset($response['install']['id'])) {
            $install_id = $response['install']['id'];
        } elseif(isset($response['id'])) {
            $install_id = $response['id'];
        }
        if(!$install_id) {
            return array('message' => 'License key is not valid!');
        }
        // Double-check the resulting license and enforce expiration, cancellation and the
        // site (activation) limit even if the activation call itself returned an install.
        $license = $this->getInstallLicense($code, $site_url, $install_id);
        if($license && !$this->isLicenseUsable($license)) {
            // Roll back so this activation does not keep occupying a license slot.
            $this->deactivateInstall($code, $site_url, $install_id);
            return array('message' => $this->getLicenseProblem($license));
        }
        return array('install_id' => $install_id);
    }
    /**
     * Fetch the license entity for a known install via the public licensing endpoint.
     * Needs the install_id stored at activation time — there is no public lookup by
     * uid alone. Returns the decoded license array, or null when it cannot be read.
     *
     * @param string $code
     * @param string $site_url
     * @param mixed  $install_id
     * @return array|null
     */
    public function getInstallLicense($code, $site_url, $install_id) {
        if(!self::PORTO_PRODUCT_ID || !$install_id) {
            return null;
        }
        $url = "https://api.freemius.com/v1/products/" . self::PORTO_PRODUCT_ID
            . "/installs/" . $install_id . "/license.json"
            . "?uid=" . urlencode($this->getLicenseUid($site_url))
            . "&license_key=" . urlencode($code);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, 'PORTO-LICENSE-VERIFY');
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $result = json_decode( curl_exec($ch) , true );
        curl_close($ch);

        if(!$result || !empty($result['error']) || empty($result['id'])) {
            return null;
        }
        return $result;
    }
    /**
     * Whether a license entity currently entitles this site: not cancelled, not expired,
     * and within its site (activation) limit. The production activation count `activated`
     * is what counts against `quota`; localhost activations are tracked separately in
     * `activated_local` and are free, so they never trip the limit here.
     *
     * @param array $license
     * @return bool
     */
    public function isLicenseUsable($license) {
        if(!$license || empty($license['id'])) {
            return false;
        }
        if(!empty($license['is_cancelled'])) {
            return false;
        }
        if($this->isLicenseExpired($license)) {
            return false;
        }
        $quota = isset($license['quota']) ? (int)$license['quota'] : 0;
        $activated = isset($license['activated']) ? (int)$license['activated'] : 0;
        if($quota > 0 && $activated > $quota) {
            return false;
        }
        return true;
    }
    /**
     * Whether the license has a past expiration date. An empty expiration means a
     * lifetime license that never expires. Expiration is returned in UTC.
     *
     * @param array $license
     * @return bool
     */
    public function isLicenseExpired($license) {
        if(empty($license['expiration'])) {
            return false;
        }
        $expires = strtotime($license['expiration'] . ' UTC');
        return ($expires !== false && $expires < time());
    }
    /**
     * Human-readable reason a license cannot be used, for the admin notice.
     *
     * @param array $license
     * @return string
     */
    public function getLicenseProblem($license) {
        if($license && !empty($license['is_cancelled'])) {
            return 'This license has been cancelled.';
        }
        if($license && $this->isLicenseExpired($license)) {
            return 'This license expired on ' . $license['expiration'] . ' (UTC).';
        }
        $quota = ($license && isset($license['quota'])) ? (int)$license['quota'] : 0;
        if($quota > 0) {
            return 'This license has reached its limit of ' . $quota . ' site' . ($quota > 1 ? 's' : '')
                . '. Please deactivate it on another site first.';
        }
        return 'License key is not valid!';
    }
    /**
     * Release a previously activated PortoTheme.com license for this site so the
     * activation slot is freed up before a new key is registered.
     *
     * @param string $code
     * @param string $site_url
     * @return array|bool
     */
    public function deactivateLicense($code, $site_url) {
        $install_id = $this->scopeConfig->getValue('porto_license/general/install_id');
        $response = $this->deactivateInstall($code, $site_url, $install_id);
        $this->_configFactory->saveConfig('porto_license/general/install_id', '', "default", 0);
        return $response;
    }
    /**
     * Deactivate a specific install on the licensing server (frees its license slot).
     *
     * @param string $code
     * @param string $site_url
     * @param mixed  $install_id
     * @return array|bool
     */
    public function deactivateInstall($code, $site_url, $install_id) {
        if(!self::PORTO_PRODUCT_ID || !$install_id) {
            return false;
        }
        return $this->curlLicense('deactivate', array(
            'license_key' => $code,
            'uid'         => $this->getLicenseUid($site_url),
            'install_id'  => $install_id,
        ));
    }
    /**
     * Low-level POST to the PortoTheme.com licensing service.
     *
     * @param string $act  'activate' | 'deactivate'
     * @param array  $params
     * @return array|null
     */
    public function curlLicense($act, $params) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://api.freemius.com/v1/plugins/" . self::PORTO_PRODUCT_ID . "/" . $act . ".json");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, 'PORTO-LICENSE-VERIFY');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $result = json_decode( curl_exec($ch) , true );
        curl_close($ch);
        return $result;
    }
    public function isLocalhost() {
        $whitelist = array(
            '127.0.0.1',
            '::1'
        );

        return in_array($_SERVER['REMOTE_ADDR'], $whitelist);
    }
    public function isAdmin() {
        $om = \Magento\Framework\App\ObjectManager::getInstance();
        $app_state = $om->get('\Magento\Framework\App\State');
        $area_code = $app_state->getAreaCode();
        if($area_code == \Magento\Backend\App\Area\FrontNameResolver::AREA_CODE)
        {
            return true;
        }
        else
        {
            return false;
        }
    }
    public function getBaseUrl()
    {
        return $this->_storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA);
    }
    public function getBaseLinkUrl()
    {
        return $this->_storeManager->getStore()->getBaseUrl();
    }
    public function getConfig($config_path)
    {
        return $this->scopeConfig->getValue(
            $config_path,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
    }
    public function getModel($model) {
        return $this->_objectManager->create($model);
    }
    public function getCurrentStore() {
        return $this->_storeManager->getStore();
    }
    public function getCurrentProduct()
    {
        return $this->_registry->registry('current_product');
    }
    public function getCurrentCategory()
    {
        return $this->_registry->registry('current_category');
    }
    public function filterContent($content) {
        return $this->_filterProvider->getPageFilter()->filter($content);
    }
    public function getCategoryProductIds($current_category) {
        $category_products = $current_category->getProductCollection()
            ->addAttributeToSelect('*')
            ->addAttributeToSort('position','asc');
        $cat_prod_ids = $category_products->getAllIds();

        return $cat_prod_ids;
    }
    public function getPercentage(\Magento\Catalog\Model\Product $product)
    {
        $baseprice = 0;
        $finalprice = 0;
        $percentage = 0;

        if ($product->getTypeId() == 'configurable') {
            $baseprice = $product->getPriceInfo()
                ->getPrice('regular_price')
                ->getValue();

            $finalprice = $product->getPriceInfo()
                ->getPrice('final_price')
                ->getValue();
        } else {
            $baseprice = $product->getPrice();
            $finalprice = $product->getFinalPrice();
        }

        $specialfromdate = $product->getSpecialFromDate();
        $specialtodate = $product->getSpecialToDate();
        $today = time();

        if ($finalprice < $baseprice) {
            if((is_null($specialfromdate) && is_null($specialtodate)) || ($today >= strtotime($specialfromdate) && is_null($specialtodate)) || ($today <= strtotime($specialtodate) && is_null($specialfromdate)) || ($today >= strtotime($specialfromdate) && $today <= strtotime($specialtodate))){
              $percentage = round(-100 * (1 - ($finalprice / $baseprice)));
            }
        }

        return $percentage;
    }
    public function getPrevProduct($product) {
        $current_category = $product->getCategory();
        if(!$current_category) {
            foreach($product->getCategoryCollection() as $parent_cat) {
                $current_category = $parent_cat;
            }
        }
        if(!$current_category)
            return false;
        $cat_prod_ids = $this->getCategoryProductIds($current_category);
        $_pos = array_search($product->getId(), $cat_prod_ids);
        if (isset($cat_prod_ids[$_pos - 1])) {
            $prev_product = $this->getModel('Magento\Catalog\Model\Product')->load($cat_prod_ids[$_pos - 1]);
            return $prev_product;
        }
        return false;
    }
    public function getNextProduct($product) {
        $current_category = $product->getCategory();
        if(!$current_category) {
            foreach($product->getCategoryCollection() as $parent_cat) {
                $current_category = $parent_cat;
            }
        }
        if(!$current_category)
            return false;
        $cat_prod_ids = $this->getCategoryProductIds($current_category);
        $_pos = array_search($product->getId(), $cat_prod_ids);
        if (isset($cat_prod_ids[$_pos + 1])) {
            $next_product = $this->getModel('Magento\Catalog\Model\Product')->load($cat_prod_ids[$_pos + 1]);
            return $next_product;
        }
        return false;
    }

    public function getMasonryItemClass($arr) {
        $item_class = "";
        foreach ($arr as $key => $value) {
            $item_class .= ' ' . $key . '-' . $value;
        }
        return $item_class;
    }
}
