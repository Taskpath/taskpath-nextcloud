<?php

declare(strict_types=1);

return [
	'routes' => [
		// Connecting to Taskpath (admin settings).
		['name' => 'connect#start', 'url' => '/connect/start', 'verb' => 'POST'],
		// Taskpath sends the browser back here; the path is fixed on Taskpath's side.
		['name' => 'connect#callback', 'url' => '/connect', 'verb' => 'GET'],
		['name' => 'connect#setup', 'url' => '/connect/setup', 'verb' => 'POST'],
		['name' => 'connect#disconnect', 'url' => '/connect', 'verb' => 'DELETE'],
		// The Files sidebar and notifications.
		['name' => 'filing#show', 'url' => '/filing', 'verb' => 'GET'],
		['name' => 'filing#approve', 'url' => '/filing/{id}/approve', 'verb' => 'POST'],
		['name' => 'filing#skip', 'url' => '/filing/{id}/skip', 'verb' => 'POST'],
		['name' => 'filing#undo', 'url' => '/filing/{id}/undo', 'verb' => 'POST'],
		['name' => 'filing#pick', 'url' => '/filing/pick', 'verb' => 'POST'],
	],
];
