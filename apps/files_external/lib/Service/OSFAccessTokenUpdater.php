<?php

namespace OCA\Files_External\Service;

use fkooman\OAuth\Client\AccessToken;
use OCA\Files_External\Lib\CASOAuthClient;
use OCA\Files_External\Lib\CASArrayTokenStorage;
use OCA\Files_External\Lib\DummySession;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use OCA\Files_External\Service\DBConfigService;
use OCA\Files_External\Service\UserStoragesService;

use OC\BackgroundJob\TimedJob;
use OCA\Federation\AppInfo\Application;
use OCA\Files_External\Migration\DummyUserSession;

class OSFAccessTokenUpdater extends TimedJob {

	public function __construct() {
		// Per 5 miniutes
		$this->setInterval(5 * 60);
	}

	protected function run($argument) {
		if( !\OC_App::isEnabled('files_external')) {
			\OCP\Util::writeLog('external_storage', "OSFAccessTokenUpdater: not enabled", \OCP\Util::DEBUG);
			return;
		}
		$userMountCache = \OC::$server->getUserMountCache();
		$appContainer = \OC_Mount_Config::$app->getContainer();
		$backendService = $appContainer->query('OCA\\Files_External\\Service\\BackendService');

		$globalStoragesService = $appContainer->query('OCA\\Files_External\\Service\\GlobalStoragesService');
		$userManager = \OC::$server->getUserManager();
		$config = \OC::$server->getConfig();
		$lockingProvider = \OC::$server->getLockingProvider();

		\OCP\Util::writeLog('external_storage', "OSFAccessTokenUpdater: started", \OCP\Util::INFO);
		try{
			$dbConfig = new DBConfigService(\OC::$server->getDatabaseConnection(), \OC::$server->getCrypto());
			$users = $userManager->search('');
			foreach ($users as $user) {
				\OCP\Util::writeLog('external_storage', "OSFAccessTokenUpdater: user ".$user->getUID(), \OCP\Util::INFO);
				$dummySession = new DummyUserSession();
				$dummySession->setUser($user);
				$storagesService = new UserStoragesService($backendService, $dbConfig, $dummySession, $userMountCache);

			  $this->refreshAccessToken($user, $storagesService, $config, $lockingProvider);
			}
		}catch(\Throwable $e) {
			\OCP\Util::writeLog('external_storage', "OSFAccessTokenUpdater: ".$e->getMessage(), \OCP\Util::ERROR);
		}

		\OCP\Util::writeLog('external_storage', "OSFAccessTokenUpdater: finished", \OCP\Util::INFO);
	}

	protected function refreshAccessToken($user, $storagesService, $config, $lockingProvider) {
		foreach ($storagesService->getStorages() as $storage) {
			if($storage->getBackend()->getIdentifier() != 'osf') {
				continue;
			}
			\OCP\Util::writeLog('external_storage', "OSFAccessTokenUpdater: storage: ".$storage->getId(), \OCP\Util::DEBUG);
			$opts = $storage->getBackendOptions();
			if(! array_key_exists('token', $opts)) {
				\OCP\Util::writeLog('external_storage', "OSFAccessTokenUpdater: has no tokens: ".$storage->getId(), \OCP\Util::INFO);
				continue;
			}
			\OCP\Util::writeLog('external_storage', "OSFAccessTokenUpdater: test: ".$storage->getId(), \OCP\Util::INFO);
			$tokenStorage = new CASArrayTokenStorage($opts);
			$client = new CASOAuthClient($config, $tokenStorage, $lockingProvider,
			                             $user->getUID(), new DummySession());
			$api_uri = $config->getAppValue('files_external', 'osf_api_uri', '');
			if($api_uri == '') {
				\OCP\Util::writeLog('external_storage', "OSFAccessTokenUpdater: osf_api_uri is not set", \OCP\Util::WARN);
				continue;
			}
			$accessToken = $client->getAccessToken();
			if($accessToken != null) {
				$httpClient = $client->createClient($api_uri);
				$req = $httpClient->createRequest('GET', '/v2/nodes/');
				try {
					$resp = $client->send($httpClient, $req);
					$data = $resp->getBody();
					\OCP\Util::writeLog('external_storage', "OSFAccessTokenUpdater: result: ".$data, \OCP\Util::DEBUG);

					if($tokenStorage->dirty) {
						$storage->setBackendOption('token', $tokenStorage->map['token']);
						$storagesService->updateStorage($storage);
						\OCP\Util::writeLog('external_storage', "OSFAccessTokenUpdater: updated: ".$storage->getId(), \OCP\Util::INFO);
					}else{
						\OCP\Util::writeLog('external_storage', "OSFAccessTokenUpdater: not updated: ".$storage->getId(), \OCP\Util::INFO);
					}
				} catch (ClientException $e) {
					if ($e->getResponse()->getStatusCode() === 401) {
						\OCP\Util::writeLog('external_storage', "Unauthorized: OSF API", \OCP\Util::WARN);
					} else {
						\OCP\Util::writeLog('external_storage', "Cannot access: ".$e->getMessage(), \OCP\Util::WARN);
					}
				}
			}
		}
	}

}
