<template>
	<article class="doc" :class="{ 'doc--look': status === 'needs_look', 'doc--quiet': status === 'skipped' }">
		<div class="doc__top">
			<span class="doc__who">
				<a class="doc__name" :href="doc.file.url">{{ doc.file.name }}</a>
				<span v-if="filing?.dropped_by" class="muted small">{{ t('taskpath', 'from {person}', { person: filing.dropped_by.name }) }}</span>
			</span>
			<span class="pill" :class="'pill--' + pill.kind">
				<NcLoadingIcon v-if="pill.busy" :size="14" inline />{{ pill.text }}
			</span>
		</div>

		<ol v-if="!filing || ['pending', 'processing'].includes(status)" class="steps" :aria-label="t('taskpath', 'Progress')">
			<li class="steps__done">{{ t('taskpath', 'Received') }}</li>
			<li :class="filing ? 'steps__now' : ''">{{ t('taskpath', 'Reading') }}</li>
			<li>{{ t('taskpath', 'Proposal ready') }}</li>
		</ol>

		<template v-if="proposal">
			<ul v-if="reasons.length" class="reasons">
				<li v-for="reason in reasons" :key="reason">{{ reason }}</li>
			</ul>
			<div v-if="!editing" class="dest">
				<span class="dest__path"><span class="muted" aria-hidden="true">→ </span><span class="muted">{{ action.folder }}/</span>{{ action.name }}</span>
				<span v-if="newFolder" class="dest__new">{{ t('taskpath', 'new folder') }}</span>
			</div>
			<div v-else class="edit">
				<NcTextField v-model="name" :label="t('taskpath', 'Name')" />
				<NcTextField v-model="folder" :label="t('taskpath', 'Folder in {archive}', { archive })" />
			</div>
			<ul class="facts">
				<li v-for="fact in facts" :key="fact.label" :title="fact.quote ? '“' + fact.quote + '”' : ''">
					{{ fact.label }} <b>{{ fact.value }}</b>
				</li>
			</ul>
			<p v-if="awaiting && filing.can_decide === false" class="muted small">
				{{ t('taskpath', 'Waiting for someone who has access to {folder}.', { folder: action.folder || archive }) }}
				<a v-if="filing.task_url" class="trail" :href="filing.task_url" target="_blank" rel="noopener">{{ t('taskpath', 'View in Taskpath') }} ↗</a>
			</p>
			<div v-else-if="awaiting" class="buttons">
				<NcButton variant="primary" @click="approve">{{ t('taskpath', 'Approve') }}</NcButton>
				<NcButton v-if="!editing" @click="startEdit">{{ t('taskpath', 'Change') }}</NcButton>
				<NcButton v-else variant="tertiary" @click="editing = false">{{ t('taskpath', 'Cancel change') }}</NcButton>
				<NcButton variant="tertiary" @click="$emit('skip', doc)">{{ t('taskpath', 'Skip') }}</NcButton>
				<a v-if="filing.task_url" class="trail" :href="filing.task_url" target="_blank" rel="noopener">{{ t('taskpath', 'View in Taskpath') }} ↗</a>
			</div>
		</template>
	</article>
</template>

<script setup>
import { computed, ref } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'

const props = defineProps({ doc: { type: Object, required: true }, archive: { type: String, default: '' } })
const emit = defineEmits(['approve', 'skip'])

const REASONS = {
	no_text: () => t('taskpath', 'There was no text to read in it.'),
	ai_unavailable: () => t('taskpath', 'Taskpath couldn\'t read it just now: the AI service didn\'t answer. Try again later.'),
	unsure_type: () => t('taskpath', 'It isn\'t clear what kind of document this is.'),
	missing_fields: () => t('taskpath', 'Some details for the name weren\'t found.'),
	party_unsure: () => t('taskpath', 'The name was matched to a similar name Taskpath already knows. Check it.'),
	party_unknown: () => t('taskpath', 'This name doesn\'t appear in the archive yet for this kind of document. Check that it\'s right.'),
	already_there: () => t('taskpath', 'A document with this name is already in that folder. It may be a duplicate.'),
}
const LABELS = { party: () => t('taskpath', 'From'), date: () => t('taskpath', 'Date'), period: () => t('taskpath', 'Period'), reference: () => t('taskpath', 'Reference') }

const filing = computed(() => props.doc.filing)
const status = computed(() => filing.value?.status)
const action = computed(() => filing.value?.action || {})
const proposal = computed(() => ['proposed', 'needs_look'].includes(status.value) ? filing.value.proposal : null)
const awaiting = computed(() => ['awaiting_approval', 'failed'].includes(action.value.state))
const newFolder = computed(() => (proposal.value?.new_folders || []).length > 0)
const reasons = computed(() => (proposal.value?.reasons || []).map((r) => REASONS[r]?.()).filter(Boolean))
const facts = computed(() => Object.entries(LABELS).filter(([field]) => filing.value?.fields?.[field])
	.map(([field, label]) => ({ label: label(), value: filing.value.fields[field], quote: filing.value.evidence?.[field] })))

const pill = computed(() => {
	if (!filing.value) return { kind: 'busy', busy: true, text: t('taskpath', 'Sending…') }
	if (['pending', 'processing'].includes(status.value)) return { kind: 'busy', busy: true, text: t('taskpath', 'Reading…') }
	if (['approved', 'executing'].includes(action.value.state)) return { kind: 'busy', busy: true, text: t('taskpath', 'Filing…') }
	if (status.value === 'needs_look') return { kind: 'look', text: t('taskpath', 'Needs a look') }
	if (status.value === 'skipped') return { kind: 'quiet', text: t('taskpath', 'Skipped, stays here') }
	if (status.value === 'failed') return { kind: 'look', text: t('taskpath', 'Couldn\'t be read') }
	return { kind: 'ready', text: t('taskpath', 'Proposal ready') }
})

const editing = ref(false)
const name = ref('')
const folder = ref('')
function startEdit() {
	name.value = action.value.name
	folder.value = action.value.folder
	editing.value = true
}
function approve() {
	const edits = {}
	if (editing.value && name.value !== action.value.name) edits.name = name.value
	if (editing.value && folder.value !== action.value.folder) edits.folder = folder.value
	editing.value = false
	emit('approve', props.doc, edits)
}
</script>

<style scoped lang="scss">
.muted { color: var(--color-text-maxcontrast); }
.small { font-size: .88em; margin: 0; }
.doc {
	display: flex; flex-direction: column; gap: 8px; padding: 12px 14px;
	border: 1px solid var(--color-border); border-radius: var(--border-radius-large); background: var(--color-main-background);
	&--look { border-color: var(--color-warning); }
	&--quiet { opacity: .65; }
	&__top { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; justify-content: space-between; }
	&__name { font-weight: bold; overflow-wrap: anywhere; }
	&__who { display: flex; flex-wrap: wrap; align-items: baseline; gap: 2px 8px; min-width: 0; }
}
.pill {
	display: inline-flex; align-items: center; gap: 6px; padding: 2px 10px; border-radius: var(--border-radius-pill, 999px);
	font-size: .88em; font-weight: bold; white-space: nowrap;
	&--busy, &--quiet { background: var(--color-background-hover); color: var(--color-text-maxcontrast); }
	&--ready { background: var(--color-primary-element-light); color: var(--color-primary-element-light-text, var(--color-primary-element)); }
	&--look { background: var(--color-warning); color: var(--color-warning-text, var(--color-main-text)); }
}
.steps {
	display: flex; flex-wrap: wrap; gap: 4px 14px; margin: 0; padding: 0; list-style: none; font-size: .9em; color: var(--color-text-maxcontrast);
	&__done::before { content: "✓ "; color: var(--color-success); }
	&__now { color: var(--color-main-text); font-weight: bold; }
}
.reasons { margin: 0; padding-left: 18px; color: var(--color-warning-text, var(--color-main-text)); }
.dest {
	display: flex; flex-wrap: wrap; gap: 6px 8px; align-items: baseline; padding: 8px 10px;
	border-radius: var(--border-radius-element, 8px); background: var(--color-background-hover);
	&__path { font-family: var(--font-face-monospace, monospace); font-size: .9em; overflow-wrap: anywhere; }
	&__new { font-size: .85em; font-weight: bold; color: var(--color-primary-element); }
}
.edit { display: flex; flex-direction: column; gap: 8px; }
.facts {
	display: flex; flex-wrap: wrap; gap: 6px; margin: 0; padding: 0; list-style: none;
	li { font-size: .88em; padding: 2px 8px; border: 1px solid var(--color-border); border-radius: 6px; color: var(--color-text-maxcontrast); }
	b { color: var(--color-main-text); }
}
.buttons { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
.trail {
	margin-inline-start: auto; font-size: .88em; color: var(--color-text-maxcontrast); text-decoration: underline;
	&:hover, &:focus-visible { color: var(--color-main-text); }
}
</style>
