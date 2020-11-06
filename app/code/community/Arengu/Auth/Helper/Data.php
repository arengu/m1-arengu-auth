<?php

require_once __DIR__ . '/../vendor/autoload.php';

class Arengu_Auth_Helper_Data extends Mage_Core_Helper_Abstract {
    const JWT_ALG = 'HS256';
    const PATH_LOGINJWT = 'arengu_auth/loginjwt';

    const CONFIG_JWT_SECRET = 'arengu_auth_settings/secrets/jwt_secret';
    const CONFIG_API_KEY = 'arengu_auth_settings/secrets/api_key';

    public function install($installer) {
        $db = $installer->getConnection();
        $table = $installer->getTable('core/config_data');

        $db->beginTransaction();

        $db->delete($table, ['path = ?' => self::CONFIG_JWT_SECRET]);
        $db->delete($table, ['path = ?' => self::CONFIG_API_KEY]);

        $db->insert($table, ['path' => self::CONFIG_JWT_SECRET, 'value' => $helper->getRandomKey()]);
        $db->insert($table, ['path' => self::CONFIG_API_KEY, 'value' => $helper->getRandomKey()]);

        $db->commit();

        $installer->endSetup();
    }

    public function parseBody() {
        $body = @file_get_contents('php://input');

        if ($body === false) {
            throw new Exception('Failed to read POST body');
        }

        $parsed = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Failed to parse JSON data');
        }

        return $parsed;
    }

    public function getTrimmedString($arr, $key) {
        return
            !empty($arr[$key]) && (
                is_string($arr[$key]) ||
                is_int($arr[$key]) ||
                is_float($arr[$key]) ||
                is_bool($arr[$key])
            ) ?
            (string) trim($arr[$key]) :
            '';
    }

    public function getTokenParams($body, $defaultExpiry = 300) {
        $params = [
            'expires_in' => (int) $this->getTrimmedString(
                $body,
                'expires_in'
            ),

            'redirect_uri' => $this->getTrimmedString(
                $body,
                'redirect_uri'
            ),
        ];

        if (!$params['expires_in']) {
            $params['expires_in'] = $defaultExpiry;
        }

        return $params;
    }

    public function renderData(Mage_Core_Controller_Response_Http $response, $data, $status = 200) {
        @ob_end_clean();

        $response
            ->setHttpResponseCode($status)
            ->setHeader('Content-Type', 'application/json')
            ->setBody(json_encode($data));
    }

    public function renderError(Mage_Core_Controller_Response_Http $response, $errorCode, $errorMessage, $status = 400) {
        $data = [
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
        ];

        $this->renderData($response, $data, $status);
    }

    public function buildToken($secret, Mage_Customer_Model_Customer $customer, $expiresIn, $redirectUri) {
        $payload = [
            'iss' => $_SERVER['SERVER_NAME'],
            'exp' => $_SERVER['REQUEST_TIME'] + $expiresIn,
            'email' => $customer->getEmail(),
            'sub' => (string) $customer->getId(),
        ];

        if ($redirectUri) {
            $payload['redirect_uri'] = $redirectUri;
        }

        return \Firebase\JWT\JWT::encode($payload, $secret, self::JWT_ALG);
    }

    public function buildTokenFromBody($body, $customer) {
        $params = $this->getTokenParams($body);

        return $this->buildToken(
            $this->getJwtSecret(),
            $customer,
            $params['expires_in'],
            $params['redirect_uri']
        );
    }

    public function buildOutput($customer, $token) {
        return [
            'user' => $this->presentCustomer($customer),
            'token' => $token,
            'login_url' => Mage::getUrl(
                self::PATH_LOGINJWT,
                ['token' => $token]
            ),
        ];
    }

    public function getTrimmedStrings($arr, $keys) {
        $output = array_flip($keys);

        foreach($output as $k => $v) {
            $output[$k] = $this->getTrimmedString($arr, $k);
        }

        return $output;
    }

    public function presentCustomer(Mage_Customer_Model_Customer $customer) {
        return [
            'id' => (int) $customer->getId(),
            'email' => $customer->getEmail(),
            'firstname' => $customer->getFirstname(),
            'lastname' => $customer->getLastname(),
        ];
    }

    public function isRequestAllowed(Mage_Core_Controller_Request_Http $request) {
        $key = $this->getApiKey();

        return $key && hash_equals(
            "Bearer {$key}",
            $request->getHeader('Authorization')
        );
    }

    public function trans($str) {
        return $this->__($str);
    }

    public function getRandomKey($len = 64) {
        return Mage::helper('core')->getRandomString($len);
    }

    public function regenerateJwtSecret() {
        $this->setJwtSecret($this->getRandomKey());
    }

    public function regenerateApiKey() {
        $this->setApiKey($this->getRandomKey());
    }

    public function getJwtSecret() {
        return Mage::getModel('core/config_data')
        ->load(self::CONFIG_JWT_SECRET, 'path')
        ->getValue();
    }

    public function getApiKey() {
        return Mage::getModel('core/config_data')
        ->load(self::CONFIG_API_KEY, 'path')
        ->getValue();
    }

    public function setJwtSecret($secret) {
        Mage::getModel('core/config_data')
        ->load(self::CONFIG_JWT_SECRET, 'path')
        ->setValue($secret)
        ->save();
    }

    public function setApiKey($key) {
        Mage::getModel('core/config_data')
        ->load(self::CONFIG_API_KEY, 'path')
        ->setValue($key)
        ->save();
    }
}