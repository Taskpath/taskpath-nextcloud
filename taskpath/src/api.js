import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const url = (path) => generateUrl('/apps/taskpath' + path)

async function call(method, path, data) {
	try {
		const response = await axios({ method, url: url(path), data })
		return response.data
	} catch (error) {
		throw new Error(error.response?.data?.error || error.message)
	}
}

export const api = {
	state: () => call('get', '/api/state'),
	suggestArchive: () => call('get', '/api/archive-suggestion'),
	rules: () => call('get', '/api/rules'),
	inbox: () => call('get', '/api/inbox'),
	upload(file) {
		const form = new FormData()
		form.append('file', file)
		return call('post', '/api/inbox/upload', form)
	},
	startConnect: () => call('post', '/connect/start'),
	setup: (archive) => call('post', '/connect/setup', { archive }),
	approve: (id, edits = {}) => call('post', `/filing/${id}/approve`, edits),
	skip: (id) => call('post', `/filing/${id}/skip`),
	undo: (id) => call('post', `/filing/${id}/undo`),
	grant: (type) => call('post', `/autonomy/${encodeURIComponent(type)}`),
	decline: (type) => call('post', `/autonomy/${encodeURIComponent(type)}/decline`),
}
