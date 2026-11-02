<?php

declare(strict_types=1);

return [
	'routes' => [
		// The Taskpath page: first run and the inbox.
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
		['name' => 'inbox#state', 'url' => '/api/state', 'verb' => 'GET'],
		['name' => 'inbox#suggestArchive', 'url' => '/api/archive-suggestion', 'verb' => 'GET'],
		['name' => 'inbox#list', 'url' => '/api/inbox', 'verb' => 'GET'],
		['name' => 'inbox#rules', 'url' => '/api/rules', 'verb' => 'GET'],
		['name' => 'inbox#upload', 'url' => '/api/inbox/upload', 'verb' => 'POST'],
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
		['name' => 'filing#grant', 'url' => '/autonomy/{type}', 'verb' => 'POST'],
		['name' => 'filing#decline', 'url' => '/autonomy/{type}/decline', 'verb' => 'POST'],
		['name' => 'filing#revoke', 'url' => '/autonomy/{type}', 'verb' => 'DELETE'],
	],
];
