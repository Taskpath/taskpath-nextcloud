<?php

declare(strict_types=1);

namespace OCA\Taskpath\Service;

use OCP\IL10N;

/**
 * The filing rules Taskpath learned, as people read them: placeholders in
 * words ({jaar}, {relatie}), the organisation's own word for each kind, and
 * which rules are defaults or follow another kind's rule.
 */
class LearnedRules {
	public function __construct(private TaskpathClient $client, private IL10N $l) {
	}

	public function view(): array {
		try {
			$profile = $this->client->profile();
		} catch (\Exception) {
			return [];
		}
		if (($profile['source'] ?? '') === 'learning') {
			return ['source' => 'learning', 'rules' => []];
		}
		$words = [
			'party' => $this->l->t('party'), 'year' => $this->l->t('year'), 'month' => $this->l->t('month'),
			'yyyy-mm' => $this->l->t('year-month'), 'date' => $this->l->t('date'), 'reference' => $this->l->t('number'),
		];
		$readable = function (string $pattern, string $label) use ($words): string {
			return preg_replace_callback('/\{([^}]+)\}/', fn ($m) => $m[1] === 'label' ? $label : '{' . ($words[$m[1]] ?? $m[1]) . '}', $pattern);
		};
		$rules = array_map(fn ($rule) => [
			'label' => $rule['label'],
			'folder' => $readable((string)$rule['folder'], (string)$rule['label']),
			'name' => $readable((string)$rule['name'], (string)$rule['label']),
			'default' => !empty($rule['default']) || ($profile['source'] ?? '') === 'default',
			'derived' => !empty($rule['derived']),
		], array_values(array_filter($profile['rules'] ?? [], fn ($rule) => ($rule['type'] ?? '') !== 'other')));
		usort($rules, fn ($a, $b) => $a['default'] <=> $b['default']);
		return ['source' => $profile['source'] ?? 'default', 'rules' => $rules];
	}
}
