<?php

declare(strict_types=1);

namespace OCA\Taskpath\Service;

use OCP\Http\Client\IClientService;

/**
 * Talks to Taskpath's /api/v1 for this Nextcloud. All traffic is outbound from
 * here; Taskpath never connects to this server.
 */
class TaskpathClient {
	public function __construct(
		private IClientService $clients,
		private Connection $connection,
	) {
	}

	public function exchange(string $code, string $verifier): array {
		return $this->request('POST', '/api/v1/connect/exchange', ['json' => ['code' => $code, 'code_verifier' => $verifier]], auth: false);
	}

	public function connection(): array {
		return $this->request('GET', '/api/v1/connection', timeout: 5);
	}

	public function updateConnection(array $settings): array {
		return $this->request('PATCH', '/api/v1/connection', ['json' => $settings]);
	}

	public function disconnect(): void {
		$this->request('DELETE', '/api/v1/connection');
	}

	public function sendSnapshot(array $folders, array $files): array {
		return $this->request('PUT', '/api/v1/filing_profile', ['json' => ['folders' => $folders, 'files' => $files]]);
	}

	/** @param resource|string $content */
	public function upload(string $fileId, string $etag, string $path, string $name, $content, string $mime): array {
		return $this->request('POST', '/api/v1/filings', ['multipart' => [
			['name' => 'file_id', 'contents' => $fileId],
			['name' => 'etag', 'contents' => $etag],
			['name' => 'path', 'contents' => $path],
			['name' => 'file', 'contents' => $content, 'filename' => $name, 'headers' => ['Content-Type' => $mime]],
		]], timeout: 60);
	}

	public function filingForFile(string $fileId): ?array {
		$filings = $this->request('GET', '/api/v1/filings?file_id=' . rawurlencode($fileId))['filings'] ?? [];
		return $filings[0] ?? null;
	}

	public function recentFilings(): array {
		return $this->request('GET', '/api/v1/filings')['filings'] ?? [];
	}

	public function filing(int $id): array {
		return $this->request('GET', '/api/v1/filings/' . $id);
	}

	public function approve(int $id, array $user, ?string $name, ?string $folder): array {
		$body = ['user' => $user];
		if ($name !== null) {
			$body['name'] = $name;
		}
		if ($folder !== null) {
			$body['folder'] = $folder;
		}
		return $this->request('POST', '/api/v1/filings/' . $id . '/approve', ['json' => $body]);
	}

	public function undo(int $id, array $user): array {
		return $this->request('POST', '/api/v1/filings/' . $id . '/undo', ['json' => ['user' => $user]]);
	}

	public function profile(): array {
		return $this->request('GET', '/api/v1/filing_profile');
	}

	public function autonomy(): array {
		return $this->request('GET', '/api/v1/autonomy');
	}

	public function grantAutonomy(string $type, array $user): array {
		return $this->request('POST', '/api/v1/autonomy', ['json' => ['type' => $type, 'user' => $user]]);
	}

	public function declineAutonomy(string $type): void {
		$this->request('POST', '/api/v1/autonomy/' . rawurlencode($type) . '/decline');
	}

	public function revokeAutonomy(string $type, array $user): void {
		$this->request('DELETE', '/api/v1/autonomy/' . rawurlencode($type), ['json' => ['user' => $user]]);
	}

	public function skip(int $id, array $user): array {
		return $this->request('POST', '/api/v1/filings/' . $id . '/skip', ['json' => ['user' => $user]]);
	}

	public function approvedActions(): array {
		return $this->request('GET', '/api/v1/actions')['actions'] ?? [];
	}

	public function claim(int $actionId): ?array {
		try {
			return $this->request('POST', '/api/v1/actions/' . $actionId . '/claim');
		} catch (TaskpathException $e) {
			if ($e->status === 409) {
				return null;
			}
			throw $e;
		}
	}

	public function reportResult(int $actionId, bool $ok, string $pathOrError): array {
		$body = $ok ? ['ok' => true, 'path' => $pathOrError] : ['ok' => false, 'error' => $pathOrError];
		return $this->request('POST', '/api/v1/actions/' . $actionId . '/result', ['json' => $body]);
	}

	private function request(string $method, string $path, array $options = [], bool $auth = true, int $timeout = 20): array {
		$headers = ['Accept' => 'application/json'];
		if ($auth) {
			$token = $this->connection->token();
			if ($token === null) {
				throw new TaskpathException('Not connected to Taskpath', 401);
			}
			$headers['Authorization'] = 'Bearer ' . $token;
		}
		$options = array_merge($options, ['headers' => $headers, 'timeout' => $timeout, 'http_errors' => false]);
		$client = $this->clients->newClient();
		$url = $this->connection->serverUrl() . $path;
		$response = match ($method) {
			'GET' => $client->get($url, $options),
			'POST' => $client->post($url, $options),
			'PUT' => $client->put($url, $options),
			'PATCH' => $client->patch($url, $options),
			'DELETE' => $client->delete($url, $options),
		};
		$status = $response->getStatusCode();
		$body = (string)$response->getBody();
		if ($status === 401 && $auth) {
			// Disconnected (or replaced) on Taskpath's side: the token is gone for good,
			// so the page offers to connect again instead of failing on every call.
			$this->connection->forget();
			throw new TaskpathException('Disconnected from Taskpath; connect again', 401);
		}
		if ($status >= 400) {
			$error = json_decode($body, true)['error'] ?? 'Taskpath answered ' . $status;
			throw new TaskpathException($error, $status);
		}
		return $body === '' ? [] : (json_decode($body, true) ?? []);
	}
}
