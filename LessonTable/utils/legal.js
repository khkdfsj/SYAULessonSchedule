export const LOGIN_AGREEMENT_STORAGE_KEY = 'LoginAgreementAcceptance'
export const LOGIN_AGREEMENT_VERSION = '0.2.0-20260321'
export const LOGIN_AGREEMENT_FILE_URL = new URL('../static/legal/login-user-agreement.txt', import.meta.url).href

const normalizeAcceptance = (value) => {
	if (!value || typeof value !== 'object') return null
	const version = `${value.version || ''}`.trim()
	const acceptedAt = Number(value.acceptedAt || value.accepted_at || 0)
	if (!version || !acceptedAt) return null
	return {
		version,
		acceptedAt
	}
}

export const getLoginAgreementAcceptance = () => {
	return normalizeAcceptance(uni.getStorageSync(LOGIN_AGREEMENT_STORAGE_KEY))
}

export const isLoginAgreementAccepted = () => {
	const acceptance = getLoginAgreementAcceptance()
	return acceptance?.version === LOGIN_AGREEMENT_VERSION
}

export const markLoginAgreementAccepted = () => {
	uni.setStorageSync(LOGIN_AGREEMENT_STORAGE_KEY, {
		version: LOGIN_AGREEMENT_VERSION,
		acceptedAt: Date.now()
	})
}

export const clearLoginAgreementAccepted = () => {
	uni.removeStorageSync(LOGIN_AGREEMENT_STORAGE_KEY)
}

export const loadLoginAgreementText = async () => {
	const response = await new Promise((resolve, reject) => {
		uni.request({
			url: LOGIN_AGREEMENT_FILE_URL,
			method: 'GET',
			success: resolve,
			fail: reject
		})
	})

	const body = typeof response?.data === 'string'
		? response.data
		: JSON.stringify(response?.data || '')
	const text = `${body || ''}`.trim()
	if (!text) {
		throw new Error('用户协议内容为空')
	}
	return text
}
