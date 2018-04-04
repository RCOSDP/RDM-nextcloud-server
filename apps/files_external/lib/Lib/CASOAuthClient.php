<?php

namespace OCA\Files_External\Lib;

use GuzzleHttp\Client;
use fkooman\OAuth\Client\Exception\TokenException;
use fkooman\OAuth\Client\Http\CurlHttpClient;
use fkooman\OAuth\Client\OAuthClient;
use fkooman\OAuth\Client\Provider;

class CASOAuthClient {
	public $client;

	private $userId;

	private $tokenStorage;

	private $resource_url;

	private $nextcloud_base_url;

	private $requestScope;

	public function __construct($config, $session, $userId) {
		$this->requestScope = 'osf.full_read osf.full_write osf.users.profile_read';
		$this->tokenStorage = new CASOAuthTokenStorage($session);
		$this->client = new OAuthClient(
	        $this->tokenStorage,
	        // for DEMO purposes we also allow connecting to HTTP URLs, do **NOT**
	        // do this in production
	        new CurlHttpClient(['allowHttp' => true])
	    );
		$client_id = $config->getAppValue('files_external', 'osf_oauth_client_id', '');
		$client_secret = $config->getAppValue('files_external', 'osf_oauth_client_secret', '');
		$oauth_token_url = $config->getAppValue('files_external', 'osf_oauth_token_url', '');
		$oauth_authorize_url = $config->getAppValue('files_external', 'osf_oauth_authorize_url', '');
		if($client_id == '' || $client_secret == '' || $oauth_token_url == '' || $oauth_authorize_url == '') {
			throw new \Exception('Credentials are not set');
		}
		$this->resource_url = $config->getAppValue('files_external', 'osf_serviceurl', '');
		if($this->resource_url == '') {
			throw new \Exception('serviceurl is not set');
		}
		$this->nextcloud_base_url = $config->getAppValue('files_external', 'osf_nextcloud_base_url', '');
		if($this->nextcloud_base_url == '') {
			throw new \Exception('nextcloud_base_url is not set');
		}

		$this->client->setProvider(
	        new Provider(
	            $client_id, $client_secret, $oauth_authorize_url, $oauth_token_url
	        )
	    );
		$this->userId = $userId;
		$this->client->setUserId($userId);

		\OCP\Util::writeLog('external_storage', "CASOAuthClient($userId)", \OCP\Util::INFO);
	}

	public function createClient($uri) {
		$accessToken = $this->getAccessToken();
		if($accessToken == null) {
			return false;
		}
		$httpClient = new Client([
			'base_url' => $uri
		]);
		return $httpClient;
	}

	public function send($httpClient, $request) {
		return $this->client->send($this->requestScope, $httpClient, $request);
	}

	public function getAuthorizeUri() {
		return $this->client->getAuthorizeUri($this->requestScope,
		         $this->nextcloud_base_url.'/index.php/apps/files_external/ajax/osfCallback.php');
	}

	public function getAccessToken() {
		foreach ($this->tokenStorage->getAccessTokenList($this->userId) as $k => $v) {
			return $v;
		}
		return null;
	}

}
