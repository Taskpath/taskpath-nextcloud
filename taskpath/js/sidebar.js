/* Taskpath tab in the Files sidebar: what was recognised, the proposal, Approve / Change / Skip. */
(function () {
	'use strict'
	const t = (text, vars) => window.t('taskpath', text, vars)
	const url = (path) => window.OC.generateUrl('/apps/taskpath' + path)
	const intake = window.OCP.InitialState.loadState('taskpath', 'intake', null)
	if (!intake) return

	const REASONS = {
		no_text: () => t('There was no text to read in it.'),
		ai_unavailable: () => t('Taskpath couldn\'t read it just now (the AI service didn\'t answer).'),
		unsure_type: () => t('It isn\'t clear what kind of document this is.'),
		missing_fields: () => t('Some details for the name weren\'t found.'),
		party_unsure: () => t('The sender was matched to an existing folder by a similar name; check it.'),
	}
	const FIELDS = { party: () => t('From'), date: () => t('Date'), period: () => t('Period'), reference: () => t('Reference') }

	async function call(method, path, body) {
		const response = await fetch(url(path), {
			method,
			headers: { 'Content-Type': 'application/json', requesttoken: window.OC.requestToken },
			body: body ? JSON.stringify(body) : undefined,
		})
		const data = await response.json().catch(() => ({}))
		if (!response.ok) throw new Error(data.error || t('Something went wrong'))
		return data
	}

	const el = (tag, attrs = {}, ...children) => {
		const node = document.createElement(tag)
		for (const [key, value] of Object.entries(attrs)) {
			if (key === 'class') node.className = value
			else if (key.startsWith('on')) node.addEventListener(key.slice(2), value)
			else node.setAttribute(key, value)
		}
		for (const child of children.flat()) if (child != null) node.append(child)
		return node
	}

	// The document has left the intake folder: take it out of the file list the
	// way the Files app does for a deletion. Best effort; on an unknown Files
	// version the list just updates on the next reload.
	function removeFromList(fileId) {
		try {
			const node = window._nc_files_pinia?._s?.get('files')?.getNodesById?.(Number(fileId))?.[0]
			if (node) setTimeout(() => window._nc_event_bus?.emit('files:node:deleted', node), 1200)
		} catch (error) {
			console.debug('Taskpath: could not update the file list', error)
		}
	}

	function inIntake(fileInfo) {
		const dir = (fileInfo?.path || fileInfo?.attributes?.path || '').replace(/\/$/, '')
		return fileInfo && fileInfo.type !== 'dir' && dir === intake.path.replace(/\/$/, '')
	}

	class TabView {
		constructor(root) {
			this.root = root
			this.timer = null
		}

		async load(fileInfo) {
			this.fileInfo = fileInfo
			clearTimeout(this.timer)
			this.render(el('p', { class: 'taskpath-hint' }, t('Loading…')))
			try {
				const { filing } = await call('GET', '/filing?fileId=' + encodeURIComponent(fileInfo.id))
				this.show(filing)
			} catch (error) {
				this.render(el('p', { class: 'taskpath-error' }, error.message))
			}
		}

		poll(delay = 3000) {
			this.timer = setTimeout(() => this.load(this.fileInfo), delay)
		}

		render(...children) {
			this.root.replaceChildren(el('div', { class: 'taskpath taskpath-tab' }, ...children))
		}

		show(filing) {
			if (!filing || filing.status === 'pending' || filing.status === 'processing') {
				this.render(el('p', { class: 'taskpath-hint' }, filing ? t('Reading the document…') : t('Waiting for Taskpath to pick this up…')))
				return this.poll()
			}
			if (filing.status === 'filed') return this.render(el('p', { class: 'taskpath-ok' }, t('Filed.')))
			if (filing.status === 'skipped') return this.render(el('p', { class: 'taskpath-hint' }, t('Skipped. It stays here.')))
			if (filing.status === 'failed') return this.render(el('p', { class: 'taskpath-error' }, t('Taskpath couldn\'t read this document.')))
			this.proposal(filing)
		}

		proposal(filing) {
			const action = filing.action || {}
			const reasons = (filing.proposal.reasons || []).map((reason) => REASONS[reason]?.()).filter(Boolean)
			const newFolders = filing.proposal.new_folders || []
			const recognised = Object.entries(FIELDS)
				.filter(([field]) => filing.fields[field])
				.map(([field, label]) => el('div', { class: 'taskpath-field' },
					el('dt', {}, label()),
					el('dd', {}, filing.fields[field],
						filing.evidence[field] ? el('q', { class: 'taskpath-evidence', title: t('Where it says so') }, filing.evidence[field]) : null)))

			const name = el('input', { type: 'text', value: action.name || '', 'aria-label': t('Name') })
			const folder = el('input', { type: 'text', value: action.folder || '', 'aria-label': t('Folder') })
			const message = el('p', { class: 'taskpath-hint', 'aria-live': 'polite' })
			const decide = async (path, body, button) => {
				button.disabled = true
				message.textContent = path.endsWith('approve') ? t('Filing…') : ''
				try {
					const result = await call('POST', path, body)
					if (result.path) {
						this.render(el('p', { class: 'taskpath-ok' }, t('Filed as {path}', { path: result.path })))
						removeFromList(this.fileInfo.id)
					} else {
						this.show(result.filing)
					}
				} catch (error) {
					button.disabled = false
					message.textContent = error.message
				}
			}
			const approve = el('button', { class: 'primary', onclick: (e) => {
				const edits = {}
				if (name.value !== action.name) edits.name = name.value
				if (folder.value !== action.folder) edits.folder = folder.value
				decide('/filing/' + filing.id + '/approve', edits, e.target)
			} }, t('Approve'))
			const skip = el('button', { onclick: (e) => decide('/filing/' + filing.id + '/skip', {}, e.target) }, t('Skip'))

			this.render(
				reasons.length ? el('div', { class: 'taskpath-look' }, el('strong', {}, t('Needs a look')), el('ul', {}, reasons.map((r) => el('li', {}, r)))) : null,
				el('h3', {}, t('Recognised')),
				recognised.length ? el('dl', { class: 'taskpath-facts' }, recognised) : el('p', { class: 'taskpath-hint' }, t('Nothing recognised.')),
				el('h3', {}, t('File as')),
				el('label', {}, t('Name'), name),
				el('label', {}, t('Folder in {archive}', { archive: intake.archive }), folder),
				newFolders.length ? el('p', { class: 'taskpath-hint' }, t('New folder: {folder}', { folder: newFolders[newFolders.length - 1] })) : null,
				el('div', { class: 'taskpath-buttons' }, approve, skip),
				message,
			)
		}
	}

	window.addEventListener('DOMContentLoaded', () => {
		const Sidebar = window.OCA?.Files?.Sidebar
		if (!Sidebar?.registerTab || !Sidebar.Tab) return
		let view = null
		Sidebar.registerTab(new Sidebar.Tab({
			id: 'taskpath',
			name: t('Taskpath'),
			iconSvg: '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M4 4h10l2 2h4v14H4z M7 11h10v2H7z M7 15h6v2H7z"/></svg>',
			enabled: inIntake,
			mount(root, fileInfo) {
				view = new TabView(root)
				view.load(fileInfo)
			},
			update(fileInfo) {
				view?.load(fileInfo)
			},
			destroy() {
				clearTimeout(view?.timer)
				view = null
			},
		}))
	})
})()
