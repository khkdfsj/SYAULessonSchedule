import { packApiUrl } from './common.js'

export function requestRaw(config = {}) {
	let {
		url,
		data = {},
		method = 'POST',
		header = {
			'content-type': 'application/json'
		}
	} = config

	const finalUrl = packApiUrl(url)

	return new Promise((resolve, reject) => {
		uni.request({
			url: finalUrl,
			data,
			method,
			header,
			success: (res) => {
				const body = res.data
				const isOk = res.statusCode >= 200 && res.statusCode < 300 && body && body.code === 200
				if (isOk) {
					resolve(body)
					return
				}

				reject(body && typeof body === 'object' ? body : {
					code: res.statusCode || 500,
					msg: '请求失败'
				})
			},
			fail: (error) => {
				reject(error)
			}
		})
	})
}

export function getErrorMessage(error, fallback = '请求失败') {
	if (!error) return fallback
	if (typeof error === 'string') return error
	if (typeof error.msg === 'string' && error.msg.trim()) return error.msg.trim()
	if (typeof error.message === 'string' && error.message.trim()) return error.message.trim()
	return fallback
}

export function isAuthRequiredError(error) {
	return Number(error?.code) === 401 || error?.data?.requiresAuth === true || error?.requiresAuth === true
}
