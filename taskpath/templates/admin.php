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
