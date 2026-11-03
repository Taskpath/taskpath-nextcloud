<template>
	<div class="first-run">
		<header>
			<h2>{{ t('taskpath', 'Welcome to Taskpath') }}</h2>
			<p class="muted">{{ t('taskpath', 'One folder for incoming documents. Taskpath suggests a name and a folder for each, following how your archive is already organised. You approve; then it\'s filed.') }}</p>
		</header>

		<section class="step" :class="{ 'step--done': state.connected }">
			<span class="step__label">{{ t('taskpath', 'Step 1 of 2') }}</span>
			<h3>{{ state.connected ? t('taskpath', 'Connected to {workspace}', { workspace: state.workspace }) : t('taskpath', 'Connect') }}</h3>
			<template v-if="!state.connected">
				<template v-if="state.is_admin">
					<p>{{ t('taskpath', 'Opens Taskpath, where you sign in or create a free workspace. Then you come straight back here.') }}</p>
					<NcButton variant="primary" :disabled="connecting" @click="connect">
						{{ t('taskpath', 'Connect to Taskpath') }}
					</NcButton>
				</template>
				<NcNoteCard v-else type="info" :text="t('taskpath', 'An administrator of this Nextcloud connects Taskpath. Ask them to open Taskpath from the app menu.')" />
			</template>
			<p class="muted small">{{ t('taskpath', 'Taskpath gets no access to your Nextcloud. This app does the filing, as you.') }}</p>
		</section>

		<section v-if="state.connected" class="step">
			<span class="step__label">{{ t('taskpath', 'Step 2 of 2') }}</span>
			<template v-if="!rules">
				<h3>{{ suggestion ? t('taskpath', 'Is this your archive?') : t('taskpath', 'Where do your documents belong?') }}</h3>
				<NcLoadingIcon v-if="suggesting" />
				<template v-else-if="suggestion">
					<div class="found">
						<span class="found__icon" aria-hidden="true" />
						<div>
							<strong>{{ suggestion.path.replace(/^\//, '') }}</strong>
							<p class="muted small">{{ t('taskpath', '{files} documents in {folders} folders', { files: suggestion.files, folders: suggestion.folders }) }}</p>
						</div>
					</div>
					<ul class="examples">
						<li v-for="example in suggestion.examples" :key="example">{{ example }}</li>
					</ul>
				</template>
				<p v-else>{{ t('taskpath', 'Pick the folder you file documents in. Taskpath learns how it\'s organised from the names, not the contents.') }}</p>
				<div class="buttons">
					<NcButton v-if="suggestion" variant="primary" :disabled="settingUp" @click="setup(suggestion.path)">
						{{ t('taskpath', 'Yes, use {folder}', { folder: suggestion.path.replace(/^\//, '') }) }}
					</NcButton>
					<NcButton :variant="suggestion ? 'tertiary' : 'primary'" :disabled="settingUp" @click="pick">
						{{ suggestion ? t('taskpath', 'Choose another folder') : t('taskpath', 'Choose folder') }}
					</NcButton>
				</div>
				<p v-if="settingUp" class="muted busy"><NcLoadingIcon :size="16" /> {{ t('taskpath', 'Setting up and learning how your archive is organised…') }}</p>
				<NcNoteCard v-if="error" type="error" :text="error" />
			</template>
			<template v-else>
				<h3>{{ t('taskpath', 'This is what Taskpath learned') }}</h3>
				<p class="muted">{{ t('taskpath', 'From the folder and file names in {archive}, not their contents:', { archive: archive }) }}</p>
				<p v-if="rules.source === 'learning'" class="muted busy"><NcLoadingIcon :size="16" /> {{ t('taskpath', 'Learning…') }}</p>
				<table v-else class="rules">
					<tr v-for="rule in rules.rules.filter((r) => !r.default)" :key="rule.label">
						<th>{{ rule.label }}</th>
						<td><code>{{ rule.folder }}</code> <span class="muted">·</span> <code>{{ rule.name }}</code>
							<span v-if="rule.derived" class="muted small"> ({{ t('taskpath', 'filed with invoices') }})</span></td>
					</tr>
				</table>
				<p v-if="sharedWith.length" class="muted">
					{{ t('taskpath', '{intake} is shared with the same people as {archive}: {names}. They can drop documents in it and approve where they have access.', { intake: intakeName, archive, names: sharedWith.join(', ') }) }}
				</p>
				<p v-else-if="shareYourself" class="muted">
					{{ t('taskpath', '{archive} was shared with you, so share {intake} with your colleagues in Files to let them drop documents in it.', { intake: intakeName, archive }) }}
				</p>
				<div class="buttons">
					<NcButton variant="primary" :disabled="rules.source === 'learning'" @click="$emit('ready')">
						{{ t('taskpath', 'Done: on to {intake}', { intake: intakeName }) }}
					</NcButton>
				</div>
			</template>
		</section>
	</div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { t } from '@nextcloud/l10n'
import { getFilePickerBuilder } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { api } from '../api.js'

const props = defineProps({ state: { type: Object, required: true } })
defineEmits(['ready'])

const connecting = ref(false)
const suggestion = ref(null)
const suggesting = ref(false)
const settingUp = ref(false)
const error = ref('')
const rules = ref(null)
const archive = ref('')
const intake = ref('')
const sharedWith = ref([])
const shareYourself = ref(false)
const intakeName = computed(() => intake.value.replace(/^\//, '') || t('taskpath', 'To file'))

async function connect() {
	connecting.value = true
	try {
		window.location.href = (await api.startConnect()).url
	} catch (e) {
		connecting.value = false
		error.value = e.message
	}
}

async function suggest() {
	suggesting.value = true
	try {
		suggestion.value = (await api.suggestArchive()).suggestion
	} finally {
		suggesting.value = false
	}
}

async function pick() {
	const picker = getFilePickerBuilder(t('taskpath', 'Where do your documents belong?'))
		.allowDirectories(true).setMimeTypeFilter(['httpd/unix-directory']).setMultiSelect(false)
		.addButton({ label: t('taskpath', 'Choose'), callback: (nodes) => nodes[0] && setup(nodes[0].path) })
		.build()
	try { await picker.pick() } catch { /* closed */ }
}

async function setup(path) {
	settingUp.value = true
	error.value = ''
	try {
		const result = await api.setup(path)
		archive.value = result.archive
		intake.value = result.intake
		sharedWith.value = result.shared_with || []
		shareYourself.value = !!result.share_yourself
		await pollRules()
	} catch (e) {
		error.value = e.message
	} finally {
		settingUp.value = false
	}
}

// Learning takes a few seconds after setup; show it once it's there.
async function pollRules(tries = 20) {
	rules.value = await api.rules()
	if (rules.value.source === 'learning' && tries > 0) {
		await new Promise((resolve) => setTimeout(resolve, 1500))
		return pollRules(tries - 1)
	}
}

onMounted(() => { if (props.state.connected) suggest() })
</script>

<style scoped lang="scss">
.first-run { max-width: 720px; margin: 0 auto; display: flex; flex-direction: column; gap: 16px; }
h2 { font-size: 1.6em; margin: 0 0 4px; }
h3 { font-size: 1.15em; margin: 0; }
.muted { color: var(--color-text-maxcontrast); }
.small { font-size: .92em; }
.step {
	display: flex; flex-direction: column; gap: 10px; align-items: flex-start;
	padding: 18px 20px; border: 1px solid var(--color-border); border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	&--done { opacity: .7; }
	&__label { font-size: .85em; font-weight: bold; text-transform: uppercase; letter-spacing: .05em; color: var(--color-text-maxcontrast); }
}
.found {
	display: flex; gap: 12px; align-items: center; padding: 12px; width: 100%;
	border-radius: var(--border-radius-large); background: var(--color-background-hover);
	&__icon { width: 40px; height: 32px; border-radius: 6px; background: var(--color-primary-element); flex: none; }
}
.examples {
	margin: 0; padding: 8px 12px; width: 100%; list-style: none; overflow-x: auto;
	font-family: var(--font-face-monospace, monospace); font-size: .88em; color: var(--color-text-maxcontrast);
	background: var(--color-background-hover); border-radius: var(--border-radius-large);
}
.buttons { display: flex; flex-wrap: wrap; gap: 8px; }
.busy { display: flex; align-items: center; gap: 8px; }
.rules {
	border-collapse: collapse; width: 100%; table-layout: fixed;
	th { width: 9em; }
	th { text-align: left; padding: 6px 16px 6px 0; vertical-align: top; white-space: nowrap; }
	td { padding: 6px 0; border-top: 1px solid var(--color-border); overflow-wrap: anywhere; }
	tr:first-child td { border-top: 0; }
	code { font-size: .88em; background: var(--color-background-hover); padding: 1px 5px; border-radius: 4px; white-space: normal; overflow-wrap: anywhere; }
}
</style>
