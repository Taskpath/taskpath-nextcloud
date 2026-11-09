<?php

declare(strict_types=1);

// The public IRootFolder extends this server-internal interface, which nextcloud/ocp
// doesn't ship. Same signature as the server's, for tests and static analysis only.

namespace OC\Hooks;

interface Emitter {
	/**
	 * @param string $scope
	 * @param string $method
	 * @param callable $callback
	 * @return void
	 */
	public function listen($scope, $method, callable $callback);

	/**
	 * @param string $scope optional
	 * @param string $method optional
	 * @param callable $callback optional
	 * @return void
	 */
	public function removeListener($scope = null, $method = null, ?callable $callback = null);
}
