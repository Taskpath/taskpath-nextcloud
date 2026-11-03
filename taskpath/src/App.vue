<template>
	<NcContent app-name="taskpath">
		<NcAppContent>
			<div class="taskpath-page">
				<NcLoadingIcon v-if="!state" :size="44" class="taskpath-page__loading" />
				<NcEmptyContent v-else-if="loadError" :name="t('taskpath', 'Taskpath can\'t be reached')" :description="loadError" />
				<FirstRun v-else-if="!state.ready" :state="state" @ready="load" />
				<NcEmptyContent v-else-if="!state.has_access"
					:name="t('taskpath', 'Taskpath is set up by {owner}', { owner: state.owner })"
					:description="t('taskpath', 'Ask {owner} to share the folder {intake} with you, then you can drop documents in it and approve them here.', { owner: state.owner, intake: (state.intake || '').replace(/^\//, '') })" />
				<Inbox v-else :state="state" />
			</div>
		</NcAppContent>
	</NcContent>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { t } from '@nextcloud/l10n'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import FirstRun from './components/FirstRun.vue'
import Inbox from './components/Inbox.vue'
import { api } from './api.js'

const state = ref(null)
const loadError = ref('')

async function load() {
	try {
		state.value = await api.state()
		loadError.value = ''
	} catch (error) {
		state.value = {}
		loadError.value = error.message
	}
}

onMounted(load)
</script>

<style lang="scss">
.taskpath-page {
	max-width: 1180px;
	margin: 0 auto;
	padding: calc(var(--default-grid-baseline, 4px) * 6) calc(var(--default-grid-baseline, 4px) * 5);

	&__loading { margin-top: 20vh; }
}
</style>
