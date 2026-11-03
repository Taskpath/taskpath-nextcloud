<template>
	<div class="inbox">
		<header class="inbox__head">
			<div>
				<h2>{{ intakeName }}</h2>
				<p class="muted">
					<a v-if="state.workspace_url" class="workspace" :href="state.workspace_url" target="_blank" rel="noopener"
						:title="t('taskpath', 'Open the workspace in Taskpath: every task, check and step of the audit trail')">
						<img :src="logo" alt="" class="workspace__logo">{{ state.workspace }}<span aria-hidden="true"> ↗</span>
					</a>
					<span v-else>{{ state.workspace }}</span>
					· {{ t('taskpath', 'archive') }} <code>{{ state.archive }}</code>
				</p>
			</div>
			<div class="stats" aria-live="polite">
				<span class="stat"><b>{{ waiting }}</b> {{ t('taskpath', 'waiting for you') }}</span>
				<span class="stat stat--auto"><b>{{ autoToday }}</b> {{ t('taskpath', 'automatic today') }}</span>
				<span class="stat"><b>{{ filedToday }}</b> {{ t('taskpath', 'filed today') }}</span>
			</div>
		</header>

		<div class="inbox__cols">
			<section :aria-label="t('taskpath', 'Arrived')">
				<div class="col-title">
					<span>{{ t('taskpath', 'Arrived') }}</span>
					<NcButton v-if="ready.length > 1" variant="primary" @click="approveAll">
						{{ t('taskpath', 'Approve all {count} proposals', { count: ready.length }) }}
					</NcButton>
				</div>

				<label class="drop" :class="{ 'drop--hot': dragging }"
					@dragenter.prevent="dragging = true" @dragover.prevent="dragging = true"
					@dragleave.prevent="dragging = false" @drop.prevent="dropped">
					<span>
						<strong>{{ t('taskpath', 'Drop documents here') }}</strong>
						<span class="muted">{{ t('taskpath', 'Or put them in the folder {intake}, from Files or the desktop client.', { intake: intakeName }) }}</span>
					</span>
					<span class="drop__button">{{ t('taskpath', 'Choose files') }}</span>
					<input type="file" multiple class="hidden-visually" @change="(e) => send(e.target.files)">
				</label>

				<div v-for="offer in offers" :key="offer.type" class="offer">
					<strong>{{ t('taskpath', 'Documents of the kind "{kind}" were right {count} times in a row, without changes.', { kind: offer.label, count: offer.streak }) }}</strong>
					<p>{{ t('taskpath', 'Let Taskpath file this kind of document by itself from now on? It still checks each one and asks you when anything is unusual. You\'ll see them arrive in the column on the right, and one undo turns this off.') }}</p>
					<div class="buttons">
						<NcButton variant="primary" @click="grant(offer)">{{ t('taskpath', 'Yes, file automatically') }}</NcButton>
						<NcButton variant="tertiary" @click="decline(offer)">{{ t('taskpath', 'Not now') }}</NcButton>
					</div>
				</div>

				<NcLoadingIcon v-if="!loaded" :size="32" class="loading" />
				<NcEmptyContent v-if="loaded && !documents.length"
					:name="t('taskpath', 'Nothing to file')"
					:description="t('taskpath', 'New documents show up here by themselves.')" />
				<TransitionGroup name="row" tag="div" class="rows">
					<DocumentRow v-for="doc in documents" :key="doc.file.id" :doc="doc" :archive="state.archive"
						@approve="approve" @skip="skip" />
				</TransitionGroup>
			</section>

			<section :aria-label="t('taskpath', 'Just filed')">
				<div class="col-title">
					<span>{{ t('taskpath', 'Just filed') }}</span>
					<span class="muted small">{{ t('taskpath', 'can be undone for 7 days') }}</span>
				</div>
				<p v-if="loaded && !filed.length" class="muted">{{ t('taskpath', 'Filed documents appear here, with where they went.') }}</p>
				<TransitionGroup name="feed" tag="div" class="feed">
					<FiledItem v-for="item in filed" :key="item.id" :item="item" :fresh="fresh.has(item.id)" @undo="undo" />
				</TransitionGroup>
			</section>
		</div>
	</div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { n, t } from '@nextcloud/l10n'
import { imagePath } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import DocumentRow from './DocumentRow.vue'
import FiledItem from './FiledItem.vue'
import { api } from '../api.js'

const props = defineProps({ state: { type: Object, required: true } })

const documents = ref([])
const filed = ref([])
const offers = ref([])
const loaded = ref(false)
const dragging = ref(false)
const fresh = ref(new Set())
let known = null
let timer = null

const logo = imagePath('taskpath', 'logo.svg')
const intakeName = computed(() => (props.state.intake || '').replace(/^\//, ''))
const ready = computed(() => documents.value.filter((d) => d.filing?.status === 'proposed' && d.filing.action?.state === 'awaiting_approval'))
const waiting = computed(() => documents.value.filter((d) => ['proposed', 'needs_look'].includes(d.filing?.status) && d.filing.action?.state === 'awaiting_approval').length)
const isToday = (iso) => iso && new Date(iso).toDateString() === new Date().toDateString()
const filedToday = computed(() => filed.value.filter((f) => f.status === 'filed' && isToday(f.filed_at)).length)
const autoToday = computed(() => filed.value.filter((f) => f.status === 'filed' && f.filed_by_itself && isToday(f.filed_at)).length)

async function refresh() {
	try {
		const data = await api.inbox()
		// Newly filed since the last look get a moment of attention.
		const ids = new Set(data.filed.map((f) => f.id))
		if (known) fresh.value = new Set([...ids].filter((id) => !known.has(id)))
		known = ids
		documents.value = data.documents
		filed.value = data.filed
		offers.value = data.offers
		loaded.value = true
	} catch (error) {
		showError(error.message)
	} finally {
		schedule()
	}
}

// Quick while something is being read or filed, calmer otherwise.
function schedule() {
	clearTimeout(timer)
	const busy = documents.value.some((d) => !d.filing || ['pending', 'processing'].includes(d.filing.status)
		|| ['approved', 'executing'].includes(d.filing.action?.state))
	timer = setTimeout(refresh, busy ? 2000 : 8000)
}

async function send(files) {
	dragging.value = false
	for (const file of Array.from(files || [])) {
		try { await api.upload(file) } catch (error) { showError(error.message) }
	}
	refresh()
}

function dropped(event) { send(event.dataTransfer?.files) }

async function approve(doc, edits, quiet = false) {
	try {
		const result = await api.approve(doc.filing.id, edits)
		if (result.path && !quiet) showSuccess(t('taskpath', 'Filed as {path}', { path: result.path }))
		return !!result.path
	} catch (error) {
		showError(error.message)
		return false
	} finally {
		if (!quiet) refresh()
	}
}

async function approveAll() {
	let filedCount = 0
	for (const doc of ready.value) if (await approve(doc, {}, true)) filedCount++
	if (filedCount) showSuccess(n('taskpath', '{count} document filed', '{count} documents filed', filedCount, { count: filedCount }))
	refresh()
}

async function skip(doc) {
	try { await api.skip(doc.filing.id) } catch (error) { showError(error.message) }
	refresh()
}

async function undo(item) {
	try {
		await api.undo(item.id)
		showSuccess(t('taskpath', 'Back in {intake} as {name}, ready to file again.', { intake: intakeName.value, name: item.name }))
	} catch (error) { showError(error.message) }
	refresh()
}

async function grant(offer) {
	try {
		await api.grant(offer.type)
		showSuccess(t('taskpath', 'From now on, Taskpath files documents of the kind "{kind}" by itself.', { kind: offer.label }))
	} catch (error) { showError(error.message) }
	refresh()
}

async function decline(offer) {
	try { await api.decline(offer.type) } catch { /* asked again later */ }
	refresh()
}

onMounted(refresh)
onBeforeUnmount(() => clearTimeout(timer))
</script>

<style scoped lang="scss">
.muted { color: var(--color-text-maxcontrast); }
.small { font-size: .9em; }
code { font-size: .9em; }
.inbox__head {
	display: flex; flex-wrap: wrap; gap: 10px 18px; align-items: flex-end; justify-content: space-between; margin-bottom: 18px;
	h2 { font-size: 1.6em; margin: 0; }
	p { margin: 2px 0 0; }
}
.workspace {
	color: inherit; font-weight: bold; text-decoration: none;
	&:hover, &:focus-visible { color: var(--color-main-text); text-decoration: underline; }
	&__logo { width: 1.25em; height: 1.25em; vertical-align: -.25em; margin-inline-end: 2px; }
}
.stats { display: flex; flex-wrap: wrap; gap: 8px; }
.stat {
	padding: 4px 12px; border-radius: var(--border-radius-pill, 999px); font-variant-numeric: tabular-nums;
	background: var(--color-background-hover); color: var(--color-text-maxcontrast);
	b { color: var(--color-main-text); }
	&--auto b { color: var(--color-primary-element); }
}
.inbox__cols { display: grid; grid-template-columns: minmax(0, 1.55fr) minmax(0, 1fr); gap: 24px; align-items: start; }
@media (max-width: 900px) { .inbox__cols { grid-template-columns: 1fr; } }
.col-title {
	display: flex; justify-content: space-between; align-items: center; gap: 8px; min-height: 34px; margin-bottom: 6px;
	> span:first-child { font-size: .85em; font-weight: bold; text-transform: uppercase; letter-spacing: .05em; color: var(--color-text-maxcontrast); }
}
.drop {
	display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: space-between; cursor: pointer;
	padding: 16px; margin-bottom: 12px; border: 2px dashed var(--color-border-dark); border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
	> span:first-child { display: flex; flex-direction: column; gap: 2px; }
	&--hot { border-color: var(--color-primary-element); background: var(--color-primary-element-light); }
	&__button {
		padding: 6px 14px; border-radius: var(--border-radius-element, 8px); font-weight: bold;
		background: var(--color-main-background); border: 1px solid var(--color-border-dark);
	}
}
.offer {
	display: flex; flex-direction: column; gap: 8px; padding: 12px 14px; margin-bottom: 12px;
	border: 2px solid var(--color-primary-element); border-radius: var(--border-radius-large);
	background: var(--color-primary-element-light);
	p { margin: 0; }
}
.buttons { display: flex; flex-wrap: wrap; gap: 8px; }
.loading { margin: 32px auto; }
.rows, .feed { display: flex; flex-direction: column; gap: 10px; }
.row-enter-from, .feed-enter-from { opacity: 0; transform: translateY(-6px); }
.row-leave-to { opacity: 0; transform: translateX(24px); }
.row-enter-active, .row-leave-active, .feed-enter-active { transition: all .35s ease; }
@media (prefers-reduced-motion: reduce) { .row-enter-active, .row-leave-active, .feed-enter-active { transition: none; } }
</style>
