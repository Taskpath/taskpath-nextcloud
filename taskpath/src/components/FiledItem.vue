<template>
	<div class="item" :class="{ 'item--auto': item.filed_by_itself && !undone, 'item--fresh': fresh }">
		<div class="item__top">
			<span v-if="undone" class="badge badge--quiet">{{ t('taskpath', 'Undone') }}</span>
			<span v-else-if="item.filed_by_itself" class="badge badge--auto">{{ t('taskpath', 'Automatic') }}</span>
			<span v-else class="badge badge--ok">{{ t('taskpath', 'Approved by {name}', { name: item.approved_by || t('taskpath', 'someone') }) }}</span>
			<span v-if="item.filed_at" class="muted small">{{ time }}</span>
		</div>
		<div class="item__path"><span class="muted">{{ folder }}</span>{{ base }}</div>
		<div class="muted small">{{ undone ? t('taskpath', 'Back in the intake folder as {name}', { name: item.name }) : t('taskpath', 'arrived as {name}', { name: item.name }) }}</div>
		<div v-if="item.filed_by_itself && !undone" class="item__why">{{ why }}</div>
		<div v-if="item.undoable && !undone">
			<NcButton variant="tertiary" @click="$emit('undo', item)">{{ t('taskpath', 'Undo') }}</NcButton>
		</div>
	</div>
</template>

<script setup>
import { computed } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'

const props = defineProps({ item: { type: Object, required: true }, fresh: { type: Boolean, default: false } })
defineEmits(['undo'])

const CHECKS = {
	granted: () => t('taskpath', 'kind earned'),
	folder_exists: () => t('taskpath', 'folder exists'),
	party_known_exactly: () => t('taskpath', 'sender known exactly'),
	name_free: () => t('taskpath', 'name free'),
}

const undone = computed(() => props.item.status !== 'filed')
const path = computed(() => props.item.path || '')
const folder = computed(() => path.value.slice(0, path.value.lastIndexOf('/') + 1).replace(/^\//, ''))
const base = computed(() => path.value.slice(path.value.lastIndexOf('/') + 1))
const time = computed(() => new Date(props.item.filed_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }))
const why = computed(() => t('taskpath', 'Automatic because: {checks}', {
	checks: (props.item.autonomy_checks || []).filter((c) => c.passed && CHECKS[c.name]).map((c) => CHECKS[c.name]()).join(' · '),
}))
</script>

<style scoped lang="scss">
.muted { color: var(--color-text-maxcontrast); }
.small { font-size: .88em; }
.item {
	display: flex; flex-direction: column; gap: 4px; padding: 10px 12px;
	border: 1px solid var(--color-border); border-radius: var(--border-radius-large); background: var(--color-main-background);
	&--auto { border-color: var(--color-primary-element); background: var(--color-primary-element-light); }
	&--fresh { animation: arrive 1.8s ease-out; }
	&__top { display: flex; justify-content: space-between; align-items: center; gap: 8px; flex-wrap: wrap; }
	&__path { font-family: var(--font-face-monospace, monospace); font-size: .88em; overflow-wrap: anywhere; }
	&__why { font-size: .88em; color: var(--color-primary-element); }
}
.badge {
	font-size: .8em; font-weight: bold; letter-spacing: .02em; padding: 1px 8px; border-radius: var(--border-radius-pill, 999px);
	&--auto { background: var(--color-primary-element); color: var(--color-primary-element-text); }
	&--ok { background: var(--color-success); color: var(--color-success-text, #fff); }
	&--quiet { background: var(--color-background-hover); color: var(--color-text-maxcontrast); }
}
@keyframes arrive { from { box-shadow: 0 0 0 4px var(--color-primary-element); } to { box-shadow: 0 0 0 0 transparent; } }
@media (prefers-reduced-motion: reduce) { .item--fresh { animation: none; } }
</style>
