<?php

namespace OCA\Files_External\Lib;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;


class WaterButler {
	private $wbUrl;
	private $nodeId;
	private $providerId;
	private $token;
	private $id;
	private $baseUrl;
	private $client;
	private $defaultOptions;

	public function __construct($wbUrl, $nodeId, $providerId, $token) {
		$this->wbUrl = $wbUrl;
		$this->nodeId = $nodeId;
		$this->providerId = $providerId;
		$this->token = $token;
		$this->id = "waterbutler::$this->nodeId/$this->providerId";
		if ((strrpos($wbUrl, '/') === strlen($wbUrl) - 1)) {
			$wbUrl = substr($wbUrl, 0, strlen($wbUrl) - 1);
		}
		$this->baseUrl = "$wbUrl/v1/resources/$nodeId/providers/$providerId/";
		$this->defaultOptions = [
			'headers' => [
				'Authorization' => "Bearer $token"
			],
			'timeout' => 60 * 9 // 9 min.
		];
		$this->client = new Client([
			'base_url' => $this->baseUrl,
			'defaults' => $this->defaultOptions
		]);
	}

	public function getStorageId() {
		return $this->id;
	}

	public function readObject($path) {
		$path = $this->normalizePath($path);
		$res = $this->request('GET', $path);
		return $res->getBody()->detach();
	}

	public function headObject($path) {
		$path = $this->normalizePath($path);
		$res = $this->request('GET', "$path?meta=");
		$sbody = $res->getBody();
		\OCP\Util::writeLog('external_storage', "headObject($path, $sbody)",
		                    \OCP\Util::INFO);
		$body = json_decode($sbody, true);
		\OCP\Util::writeLog('external_storage', "headObject($path, $sbody, $body)",
		                    \OCP\Util::INFO);
		return $body['data'];
	}

	// return [file]
	public function getList($path = '') {
		$path = $this->normalizePath($path);
		$res = $this->request('GET', "$path?meta=");
		$sbody = $res->getBody();
		\OCP\Util::writeLog('external_storage', "getList($path, $sbody)",
		                    \OCP\Util::INFO);
		$body = json_decode($sbody, true);
		\OCP\Util::writeLog('external_storage', "getList($path, $sbody, $body)",
		                    \OCP\Util::INFO);
		return $body['data'];
	}

	public function writeObject($path, $name, $stream) {
		$path = $this->normalizePath($path);
		$this->request('PUT', "$path?kind=file&name=$name", ['body' => $stream]);
	}

	public function updateObject($path, $stream) {
		$path = $this->normalizePath($path);
		$this->request('PUT', "$path?kind=file", ['body' => $stream]);
	}

	public function deleteObject($path) {
		$path = $this->normalizePath($path);
		$this->request('DELETE', $path);
	}

	public function createDirectory($path, $name) {
		$path = $this->normalizePath($path);
		$this->request('PUT', "$path?kind=folder&name=$name");
	}

	public function deleteDirectory($path) {
		$path = $this->normalizePath($path);
		$this->request('DELETE', "$path");
	}

	public function copy($path, $targetDirPath) {
		$path = $this->normalizePath($path);
		$targetDirPath = $this->normalizePath($targetDirPath);
		if ($targetDirPath === './') $targetDirPath = '/';
		$this->request('POST', $path, [
			'body' => json_encode([
				'action' => 'copy',
				'path' => $targetDirPath
			])
		]);
	}

	public function move($path, $targetDirPath, $newName = null) {
		$path = $this->normalizePath($path);
		$targetDirPath = $this->normalizePath($targetDirPath);
		if ($targetDirPath === './') $targetDirPath = '/';
		$body = [
			'action' => 'move',
			'path' => $targetDirPath
		];
		if (!is_null($newName)) $body['rename'] = $newName;
		$this->request('POST', $path, [
			'body' => json_encode($body)
		]);
	}

	public function rename($path, $newFileName) {
		$path = $this->normalizePath($path);
		$this->request('POST', $path, [
			'body' => json_encode([
				'action' => 'rename',
				'rename' => $newFileName
			])
		]);
	}

	public function isObject($path) {
		$path = $this->normalizePath($path);
		try {
			$res = $this->headObject($path);
		} catch (ClientException $e) {
			if ($e->getResponse()->getStatusCode() === 404) {
				return false;
			} else {
				throw $e;
			}
		}
		return $res['attributes']['kind'] === 'file';
	}

	public function isDirectory($path) {
		$path = $this->normalizePath($path);
		try {
			$res = $this->headObject($path);
		} catch (ClientException $e) {
			if ($e->getResponse()->getStatusCode() === 404) {
				return false;
			} else {
				throw $e;
			}
		}
		return $res['attributes']['kind'] === 'folder';
	}

	protected function request($method, $path, array $options = []) {
		$req = $this->client->createRequest($method, $path, $options);
		return $this->client->send($req);
	}

	protected function normalizePath($path) {
		return $path ? (ltrim($path, '/') ?: './') : './';
	}

	public function findIdOrPath($materializedPath) {
		$materializedPath = trim($materializedPath, '/');
		if ($materializedPath === '') {
			return '/';
		}

		$parts = explode('/', '/'.$materializedPath);
		$idOrPath = '/';
		$materializedPath2 = '';

		for ($i = 1, $len = count($parts); $i < $len; ++$i) {
			$materializedPath2 .= '/'.$parts[$i];
			$res = $this->getList($idOrPath);
			$next = null;
			foreach ($res as $data) {
				$dpath = \OC_Util::normalizeUnicode($data['attributes']['materialized']);
				\OCP\Util::writeLog('external_storage', "searchMaterialized($dpath, $materializedPath2)",
				                    \OCP\Util::INFO);
				if ($dpath === $materializedPath2 ||
					$dpath === $materializedPath2.'/' ) {
					$next = $data;
				}
			}

			if (!$next) {
				return null;
			}

			$idOrPath = $next['attributes']['path'];
		}

		return $idOrPath;
	}
}
