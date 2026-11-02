/*
 * Taskpath tab in the Files sidebar.
 * In the intake folder: what was recognised, the proposal, Approve / Skip.
 * Under the archive: how Taskpath filed this document, with Undo.
 */
(function () {
	'use strict'
	const t = (text, vars) => window.t('taskpath', text, vars)
	const url = (path) => window.OC.generateUrl('/apps/taskpath' + path)
	const intake = window.OCP.InitialState.loadState('taskpath', 'intake', null)
	if (!intake) return

	const REASONS = {
		no_text: () => t('There was no text to read in it.'),
		ai_unavailable: () => t('Taskpath couldn\'t read it just now: the AI service didn\'t answer. Try again later.'),
		unsure_type: () => t('It isn\'t clear what kind of document this is.'),
		missing_fields: () => t('Some details for the name weren\'t found.'),
		party_unsure: () => t('The name was matched to a similar name Taskpath already knows. Check it.'),
		already_there: () => t('A document with this name is already in that folder. It may be a duplicate.'),
		party_unknown: () => t('This name doesn\'t appear in the archive yet for this kind of document. Check that it\'s right.'),
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
		for (const child of children.flat(Infinity)) if (child != null && child !== false) node.append(child)
		return node
	}

	const dirOf = (fileInfo) => (fileInfo?.path || '').replace(/\/$/, '') || '/'
	const norm = (path) => path.replace(/\/$/, '') || '/'
	const inIntake = (fileInfo) => dirOf(fileInfo) === norm(intake.path)
	const inArchive = (fileInfo) => {
		const archive = norm(intake.archive)
		const dir = dirOf(fileInfo)
		return !inIntake(fileInfo) && (archive === '/' || dir === archive || dir.startsWith(archive + '/'))
	}

	// The document has left the folder on screen: take it out of the file list
	// the way the Files app does for a deletion. Best effort; on an unknown Files
	// version the list just updates on the next reload.
	function removeFromList(fileId) {
		try {
			const node = window._nc_files_pinia?._s?.get('files')?.getNodesById?.(Number(fileId))?.[0]
			if (node) setTimeout(() => window._nc_event_bus?.emit('files:node:deleted', node), 1200)
		} catch (error) {
			console.debug('Taskpath: could not update the file list', error)
		}
	}

	const date = (iso) => new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'long', year: 'numeric' })

	class TabView {
		constructor(root) {
			this.root = root
			this.timer = null
			this.picked = false
		}

		async load(fileInfo) {
			if (this.fileInfo?.id !== fileInfo.id) this.picked = false
			this.fileInfo = fileInfo
			clearTimeout(this.timer)
			try {
				let { filing } = await call('GET', '/filing?fileId=' + encodeURIComponent(fileInfo.id))
				if (!filing && inIntake(fileInfo) && !this.picked) {
					this.picked = true
					this.render(this.note(t('Sending it to Taskpath…')))
					filing = (await call('POST', '/filing/pick', { fileId: fileInfo.id })).filing
				}
				inIntake(fileInfo) ? this.showIntake(filing) : this.showArchive(filing)
			} catch (error) {
				this.render(this.note(error.message, 'error'))
			}
		}

		poll(delay = 2500) {
			this.timer = setTimeout(() => this.load(this.fileInfo), delay)
		}

		note(text, kind = 'hint') {
			return el('p', { class: 'taskpath-' + kind }, text)
		}

		render(...children) {
			this.root.replaceChildren(el('div', { class: 'taskpath taskpath-tab' }, ...children))
		}

		showIntake(filing) {
			if (!filing || filing.status === 'pending' || filing.status === 'processing') {
				this.render(el('p', { class: 'taskpath-hint taskpath-busy' }, t('Reading the document…')))
				return this.poll()
			}
			if (filing.status === 'filed') return this.render(this.note(t('Filed.'), 'ok'))
			if (filing.status === 'skipped') return this.render(this.note(t('Skipped. It stays here.')))
			if (filing.status === 'failed') return this.render(this.note(t('Taskpath couldn\'t read this document.'), 'error'))
			this.proposal(filing)
		}

		showArchive(filing) {
			if (!filing || filing.status !== 'filed') {
				return this.render(this.note(t('Taskpath didn\'t file this document.')))
			}
			const message = el('p', { class: 'taskpath-hint', 'aria-live': 'polite' })
			const undo = filing.undoable
				? el('button', { onclick: async (e) => {
					e.target.disabled = true
					try {
						await call('POST', '/filing/' + filing.id + '/undo')
						this.render(this.note(t('Back in {folder} as {name}, ready to file again.', { folder: intake.path, name: filing.name }), 'ok'))
						removeFromList(this.fileInfo.id)
					} catch (error) {
						e.target.disabled = false
						message.textContent = error.message
					}
				} }, t('Undo: back to the intake folder'))
				: null
			this.render(
				el('p', { class: 'taskpath-ok' }, filing.filed_by_itself ? t('Filed by Taskpath by itself (earned)') : t('Filed by Taskpath')),
				el('dl', { class: 'taskpath-facts' },
					filing.filed_at ? [el('dt', {}, t('On')), el('dd', {}, date(filing.filed_at))] : null,
					filing.approved_by ? [el('dt', {}, t('Approved by')), el('dd', {}, filing.approved_by)] : null,
					[el('dt', {}, t('Arrived as')), el('dd', {}, filing.name)]),
				undo,
				message,
			)
		}

		// "Invoices were right 10 times in a row. File them by itself from now on?"
		offer(offer) {
			if (!offer) return null
			const box = el('div', { class: 'taskpath-offer' })
			const done = (text) => {
				box.replaceChildren(this.note(text, 'ok'))
				removeFromList(this.fileInfo.id)
			}
			box.append(
				el('p', {}, t('Documents of the kind "{kind}" were right {count} times in a row, without changes.', { kind: offer.label, count: offer.streak })),
				el('p', {}, t('Let Taskpath file this kind of document by itself from now on? It still checks each one and asks you when anything is unusual. You can undo each filing, and one undo turns this off.')),
				el('div', { class: 'taskpath-buttons' },
					el('button', { class: 'primary', onclick: async (e) => {
						e.target.disabled = true
						try {
							await call('POST', '/autonomy/' + encodeURIComponent(offer.type))
							done(t('From now on, Taskpath files documents of the kind "{kind}" by itself.', { kind: offer.label }))
						} catch (error) {
							e.target.disabled = false
							box.append(this.note(error.message, 'error'))
						}
					} }, t('Yes, file by itself')),
					el('button', { onclick: async () => {
						await call('POST', '/autonomy/' + encodeURIComponent(offer.type) + '/decline').catch(() => {})
						done(t('Fine. Taskpath keeps asking.'))
					} }, t('Not now'))))
			return box
		}

		proposal(filing) {
			const action = filing.action || {}
			const reasons = (filing.proposal.reasons || []).map((reason) => REASONS[reason]?.()).filter(Boolean)
			const newFolders = filing.proposal.new_folders || []
			const recognised = Object.entries(FIELDS).filter(([field]) => filing.fields[field])

			const name = el('input', { type: 'text', value: action.name || '', id: 'taskpath-name' })
			const folder = el('input', { type: 'text', value: action.folder || '', id: 'taskpath-folder' })
			const message = el('p', { class: 'taskpath-hint', 'aria-live': 'polite' }, action.state === 'failed' && action.error ? action.error : '')
			const decide = async (path, body, button) => {
				button.disabled = true
				message.textContent = path.endsWith('approve') ? t('Filing…') : ''
				try {
					const result = await call('POST', path, body)
					if (result.path) {
						this.render(this.note(t('Filed as {path}', { path: result.path }), 'ok'), this.offer(result.filing?.offer))
						if (!result.filing?.offer) removeFromList(this.fileInfo.id)
					} else {
						this.showIntake(result.filing)
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
				el('section', {},
					el('h4', {}, t('File as')),
					el('label', { for: 'taskpath-name' }, t('Name')), name,
					el('label', { for: 'taskpath-folder' }, t('Folder in {archive}', { archive: intake.archive })), folder,
					newFolders.length ? el('p', { class: 'taskpath-hint taskpath-small' }, t('Creates a new folder: {folder}', { folder: newFolders[0] })) : null,
					el('div', { class: 'taskpath-buttons' }, approve, skip),
					message),
				el('section', {},
					el('h4', {}, t('Recognised')),
					recognised.length
						? el('dl', { class: 'taskpath-facts' }, recognised.map(([field, label]) => [
							el('dt', {}, label()),
							el('dd', {}, filing.fields[field],
								filing.evidence[field] ? el('span', { class: 'taskpath-evidence', title: t('Where it says so') }, '“' + filing.evidence[field] + '”') : null),
						]))
						: this.note(t('Nothing recognised.'))),
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
			iconSvg: '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M4 4h7l2 2h7v14H4z M7 11h10v2H7z M7 15h6v2H7z"/></svg>',
			enabled: (fileInfo) => fileInfo && fileInfo.type !== 'dir' && (inIntake(fileInfo) || inArchive(fileInfo)),
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
