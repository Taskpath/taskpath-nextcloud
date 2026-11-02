/* Taskpath admin settings: connect, choose the archive folder, disconnect. */
(function () {
	'use strict'
	const t = (text, vars) => window.t('taskpath', text, vars)
	const url = (path) => window.OC.generateUrl('/apps/taskpath' + path)

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

	function status(text) {
		const el = document.getElementById('taskpath-setup-status')
		if (el) el.textContent = text
	}

	async function pick() {
		window.OC.dialogs.filepicker(t('Where do your documents belong?'), async (path) => {
			status(t('Setting up and learning how your archive is organised…'))
			try {
				await call('POST', '/connect/setup', { archive: path })
				window.location.reload()
			} catch (error) {
				status(error.message)
			}
		}, false, 'httpd/unix-directory', true, window.OC.dialogs.FILEPICKER_TYPE_CHOOSE)
	}

	document.addEventListener('DOMContentLoaded', () => {
		document.getElementById('taskpath-connect')?.addEventListener('click', async (event) => {
			event.target.disabled = true
			try {
				window.location.href = (await call('POST', '/connect/start')).url
			} catch (error) {
				event.target.disabled = false
				window.OC.Notification.showTemporary(error.message)
			}
		})
		document.getElementById('taskpath-pick')?.addEventListener('click', pick)
		document.getElementById('taskpath-disconnect')?.addEventListener('click', async () => {
			if (!window.confirm(t('Disconnect from Taskpath? Taskpath deletes what it holds about this folder. Your files stay where they are.'))) return
			await call('DELETE', '/connect')
			window.location.reload()
		})
		document.querySelectorAll('.taskpath-grant, .taskpath-revoke').forEach((button) => {
			button.addEventListener('click', async () => {
				button.disabled = true
				const grant = button.classList.contains('taskpath-grant')
				try {
					await call(grant ? 'POST' : 'DELETE', '/autonomy/' + encodeURIComponent(button.dataset.type))
					window.location.reload()
				} catch (error) {
					button.disabled = false
					window.OC.Notification.showTemporary(error.message)
				}
			})
		})
		// Still learning (a few seconds after setup): look again shortly.
		if (document.getElementById('taskpath-learning')) setTimeout(() => window.location.reload(), 3000)
		// Just connected: go straight to the one remaining question.
		const root = document.getElementById('taskpath-admin')
		if (root?.dataset.status === 'connected' && root.dataset.ready === '0') pick()
	})
})()
