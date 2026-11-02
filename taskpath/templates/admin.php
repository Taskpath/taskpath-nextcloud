<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
?>
<div id="taskpath-admin" class="section taskpath"
	data-connected="<?php p($_['connected'] ? '1' : '0'); ?>"
	data-ready="<?php p($_['ready'] ? '1' : '0'); ?>"
	data-status="<?php p($_['status']); ?>">
	<h2>Taskpath</h2>
	<p class="taskpath-lead">
		<?php p($l->t('One folder for incoming documents. Taskpath suggests a name and a folder for each, following how your archive is already organised. You approve; then it\'s filed.')); ?>
	</p>

	<?php if ($_['status'] === 'failed'): ?>
		<p class="taskpath-error"><?php p($l->t('Connecting didn\'t work. Please try again.')); ?></p>
	<?php endif; ?>

	<?php if (!$_['connected']): ?>
		<div class="taskpath-step">
			<button id="taskpath-connect" class="primary"><?php p($l->t('Connect to Taskpath')); ?></button>
			<p class="taskpath-hint"><?php p($l->t('Opens Taskpath, where you sign in or create a free workspace. Takes a minute.')); ?></p>
		</div>
	<?php elseif (!$_['is_owner']): ?>
		<p><?php p($l->t('Connected by %1$s. Only they can change it.', [$_['owner']])); ?></p>
	<?php elseif (!$_['ready']): ?>
		<div class="taskpath-step">
			<p class="taskpath-ok"><?php p($l->t('Connected to %1$s', [$_['workspace']])); ?></p>
			<h3><?php p($l->t('Where do your documents belong?')); ?></h3>
			<p class="taskpath-hint"><?php p($l->t('Pick the folder you file documents in. Taskpath learns how it\'s organised from the folder and file names (not their contents) and creates a "To file" folder next to it.')); ?></p>
			<button id="taskpath-pick" class="primary"><?php p($l->t('Choose folder')); ?></button>
			<p id="taskpath-setup-status" class="taskpath-hint" aria-live="polite"></p>
		</div>
	<?php else: ?>
		<div class="taskpath-step">
			<p class="taskpath-ok"><?php p($l->t('Ready. Drop documents in %1$s.', [$_['intake']])); ?></p>
			<p><a class="button primary" href="<?php p($_['intake_url']); ?>"><?php p($l->t('Open the intake folder')); ?></a></p>
			<dl class="taskpath-facts">
				<dt><?php p($l->t('Archive')); ?></dt><dd><?php p($_['archive']); ?></dd>
				<dt><?php p($l->t('Workspace')); ?></dt>
				<dd><a href="<?php p($_['workspace_url']); ?>" target="_blank" rel="noopener"><?php p($_['workspace']); ?></a></dd>
			</dl>
			<?php if (($_['profile']['source'] ?? '') === 'learning'): ?>
				<h3><?php p($l->t('What Taskpath learned')); ?></h3>
				<p id="taskpath-learning" class="taskpath-hint taskpath-busy"><?php p($l->t('Learning from the names in %1$s how your archive is organised…', [$_['archive']])); ?></p>
			<?php elseif (!empty($_['profile']['rules'])): ?>
				<h3><?php p($l->t('What Taskpath learned')); ?></h3>
				<p class="taskpath-hint">
					<?php if ($_['profile']['source'] === 'learned'): ?>
						<?php p($l->t('From the folder and file names in %1$s, Taskpath worked out where each kind of document goes and how it\'s named:', [$_['archive']])); ?>
					<?php else: ?>
						<?php p($l->t('There wasn\'t enough in %1$s yet to learn from, so Taskpath uses these defaults:', [$_['archive']])); ?>
					<?php endif; ?>
				</p>
				<table class="taskpath-rules">
					<tr><th><?php p($l->t('Kind')); ?></th><th><?php p($l->t('Folder')); ?></th><th><?php p($l->t('Name')); ?></th></tr>
					<?php foreach ($_['profile']['rules'] as $rule): ?>
						<tr class="<?php p($rule['default'] ? 'taskpath-default' : ''); ?>">
							<td><?php p($rule['label']); ?><?php if (!empty($rule['derived'])): ?> <span class="taskpath-hint">(<?php p($l->t('filed with invoices')); ?>)</span><?php endif; ?><?php if ($rule['default'] && $_['profile']['source'] === 'learned'): ?> <span class="taskpath-hint">(<?php p($l->t('not in the archive yet, default')); ?>)</span><?php endif; ?></td>
							<td><code><?php p($rule['folder']); ?></code></td>
							<td><code><?php p($rule['name']); ?></code></td>
						</tr>
					<?php endforeach; ?>
				</table>
			<?php endif; ?>
			<?php if (!empty($_['autonomy']['kinds'])): ?>
				<h3><?php p($l->t('Filed by itself')); ?></h3>
				<p class="taskpath-hint"><?php p($l->t('After %1$s approvals in a row that nobody changed, Taskpath offers to file that kind of document by itself. It still checks every document and asks you when anything is unusual. One undo turns it off again.', [$_['autonomy']['needed']])); ?></p>
				<table class="taskpath-kinds">
					<?php foreach ($_['autonomy']['kinds'] as $kind): ?>
						<tr>
							<td><?php p($kind['label']); ?></td>
							<?php if ($kind['granted']): ?>
								<td class="taskpath-ok"><?php p($l->t('Files by itself (turned on by %1$s)', [$kind['granted']['by']])); ?></td>
								<td><button class="taskpath-revoke" data-type="<?php p($kind['type']); ?>"><?php p($l->t('Turn off')); ?></button></td>
							<?php else: ?>
								<td class="taskpath-hint"><?php p($l->t('%1$s of %2$s in a row', [min($kind['streak'], $_['autonomy']['needed']), $_['autonomy']['needed']])); ?></td>
								<td><?php if ($kind['offer']): ?><button class="taskpath-grant primary" data-type="<?php p($kind['type']); ?>"><?php p($l->t('File by itself')); ?></button><?php endif; ?></td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
				</table>
			<?php endif; ?>
			<button id="taskpath-pick" class="taskpath-secondary"><?php p($l->t('Choose another archive folder')); ?></button>
			<p id="taskpath-setup-status" class="taskpath-hint" aria-live="polite"></p>
		</div>
	<?php endif; ?>

	<?php if ($_['connected'] && $_['is_owner']): ?>
		<p class="taskpath-disconnect">
			<button id="taskpath-disconnect" class="taskpath-secondary"><?php p($l->t('Disconnect')); ?></button>
			<span class="taskpath-hint"><?php p($l->t('Taskpath then deletes everything it holds about this folder.')); ?></span>
		</p>
	<?php endif; ?>

	<p class="taskpath-hint taskpath-privacy">
		<?php p($l->t('What leaves your Nextcloud: documents dropped in the intake folder (read, then deleted) and the names under the archive folder. Nothing else. Processed in the EU.')); ?>
	</p>
</div>
