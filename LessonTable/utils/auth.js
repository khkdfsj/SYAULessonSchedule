const AUTH_SESSION_KEY = 'AuthSession'
const MANUAL_LOGIN_KEY = 'ManualLoginState'
const USER_ID_KEY = 'UserID'
const COMWX_REDIRECT_KEY = 'ComWxAutoAuthAttempt'
const COOKIE_PREFIX = 'LessonSchedule.'
const COOKIE_TTL_DAYS = 180

const isWebRuntime = () => typeof document !== 'undefined'

const cookieName = (key) => `${COOKIE_PREFIX}${key}`

const hasStorageValue = (value) => value !== '' && value !== null && value !== undefined

const writeCookie = (key, value, days = COOKIE_TTL_DAYS) => {
	if (!isWebRuntime()) return
	const expires = new Date(Date.now() + days * 24 * 60 * 60 * 1000).toUTCString()
	document.cookie = `${cookieName(key)}=${encodeURIComponent(value)}; expires=${expires}; path=/; SameSite=Lax`
}

const readCookie = (key) => {
	if (!isWebRuntime()) return ''
	const target = `${cookieName(key)}=`
	const cookieText = document.cookie || ''
	const segments = cookieText.split('; ')
	const matched = segments.find(item => item.indexOf(target) === 0)
	if (!matched) return ''
	return decodeURIComponent(matched.slice(target.length))
}

const removeCookie = (key) => {
	if (!isWebRuntime()) return
	document.cookie = `${cookieName(key)}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/; SameSite=Lax`
}

const writePersistentValue = (key, value, useCookie = true) => {
	uni.setStorageSync(key, value)
	if (!useCookie) return
	try {
		writeCookie(key, JSON.stringify(value))
	} catch (error) {
		removeCookie(key)
	}
}

const readPersistentValue = (key) => {
	const storageValue = uni.getStorageSync(key)
	if (hasStorageValue(storageValue)) {
		return storageValue
	}

	const cookieValue = readCookie(key)
	if (!cookieValue) return null
	try {
		const parsed = JSON.parse(cookieValue)
		uni.setStorageSync(key, parsed)
		return parsed
	} catch (error) {
		uni.setStorageSync(key, cookieValue)
		return cookieValue
	}
}

const removePersistentValue = (key) => {
	uni.removeStorageSync(key)
	removeCookie(key)
}

const trimText = (value, maxLength = 120) => {
	const normalized = `${value ?? ''}`.trim()
	if (!normalized) return ''
	return normalized.slice(0, maxLength)
}

export const normalizeUserId = (value) => {
	const normalized = `${value ?? ''}`.trim()
	if (!normalized) return ''
	return /^[a-zA-Z0-9_-]{5,20}$/.test(normalized) ? normalized : ''
}

export const normalizeAccount = (value) => trimText(value, 32)

const normalizeProfile = (value) => {
	if (!value || typeof value !== 'object') return null
	const profile = {
		student_number: normalizeUserId(value.student_number || value.studentNumber || value.user_id || value.userId),
		full_name: trimText(value.full_name || value.fullName, 64),
		grade: trimText(value.grade, 32),
		department: trimText(value.department, 64),
		major: trimText(value.major, 64),
		admission_grade: trimText(value.admission_grade || value.admissionGrade, 16),
		schooling_type: trimText(value.schooling_type || value.schoolingType, 32)
	}

	return Object.values(profile).some(item => !!item) ? profile : null
}

const normalizeSession = (value) => {
	if (!value || typeof value !== 'object') return null
	const userId = normalizeUserId(value.userId || value.user_id)
	const authExp = Number(value.authExp || value.auth_exp || 0)
	const authSig = `${value.authSig || value.auth_sig || ''}`.trim()
	const authSource = value.authSource === 'qywx' ? 'qywx' : 'manual'
	if (!userId || !authExp || !authSig) return null
	return {
		userId,
		authExp,
		authSig,
		authSource
	}
}

const normalizeManualState = (value) => {
	if (!value || typeof value !== 'object') return null
	const profile = normalizeProfile(value.profile)
	const userId = normalizeUserId(value.userId || value.user_id || profile?.student_number)
	const account = normalizeAccount(value.account || userId)
	const rememberPassword = value.rememberPassword !== false
	const password = typeof value.password === 'string' ? value.password : ''
	const savedAt = Number(value.savedAt || value.saved_at || 0) || Date.now()

	if (!userId && !account && !profile) return null

	return {
		authSource: 'manual',
		userId,
		account,
		password: rememberPassword ? password : '',
		rememberPassword,
		profile,
		savedAt
	}
}

export const getCurrentUserId = () => {
	const value = readPersistentValue(USER_ID_KEY)
	return normalizeUserId(value)
}

export const setCurrentUserId = (userId) => {
	const normalized = normalizeUserId(userId)
	if (!normalized) {
		removePersistentValue(USER_ID_KEY)
		return ''
	}
	writePersistentValue(USER_ID_KEY, normalized)
	return normalized
}

export const extractRouteUserId = (routeParams = {}) => {
	return normalizeUserId(routeParams.UserID || routeParams.user_id)
}

export const extractRouteAuthSession = (routeParams = {}) => {
	return normalizeSession({
		user_id: routeParams.UserID || routeParams.user_id,
		auth_exp: routeParams.auth_exp,
		auth_sig: routeParams.auth_sig,
		authSource: 'qywx'
	})
}

export const saveAuthSessionFromRoute = (routeParams = {}) => {
	const session = extractRouteAuthSession(routeParams)
	if (!session) return null
	return setAuthSession(session)
}

export const setAuthSession = (sessionLike) => {
	const session = normalizeSession(sessionLike)
	if (!session) {
		removePersistentValue(AUTH_SESSION_KEY)
		return null
	}

	writePersistentValue(AUTH_SESSION_KEY, session)
	setCurrentUserId(session.userId)
	return session
}

export const getAuthSession = () => {
	const session = normalizeSession(readPersistentValue(AUTH_SESSION_KEY))
	if (!session) return null

	const currentUserId = getCurrentUserId()
	if (currentUserId && currentUserId !== session.userId) {
		removePersistentValue(AUTH_SESSION_KEY)
		return null
	}

	return session
}

export const hasValidAuthSession = () => {
	const session = getAuthSession()
	return !!session && session.authExp * 1000 > Date.now()
}

export const clearAuthSession = () => {
	removePersistentValue(AUTH_SESSION_KEY)
}

export const getManualLoginState = () => {
	return normalizeManualState(readPersistentValue(MANUAL_LOGIN_KEY))
}

export const saveManualLoginState = ({
	account = '',
	password = '',
	rememberPassword = true,
	profile = null,
	session = null
} = {}) => {
	const normalizedProfile = normalizeProfile(profile)
	const normalizedSession = session ? setAuthSession({
		...session,
		authSource: 'manual'
	}) : getAuthSession()
	const userId = normalizeUserId(
		normalizedSession?.userId ||
		normalizedProfile?.student_number ||
		account
	)

	if (userId) {
		setCurrentUserId(userId)
	}

	const manualState = normalizeManualState({
		account,
		password,
		rememberPassword,
		profile: normalizedProfile,
		userId,
		savedAt: Date.now()
	})

	if (!manualState) {
		removePersistentValue(MANUAL_LOGIN_KEY)
		return null
	}

	writePersistentValue(MANUAL_LOGIN_KEY, manualState)
	return manualState
}

export const clearManualLoginState = () => {
	removePersistentValue(MANUAL_LOGIN_KEY)
}

export const hydrateCurrentUserFromManualState = () => {
	const currentUserId = getCurrentUserId()
	if (currentUserId) return currentUserId
	const manualState = getManualLoginState()
	if (!manualState?.userId) return ''
	return setCurrentUserId(manualState.userId)
}

export const clearAllLocalIdentity = () => {
	clearAuthSession()
	clearManualLoginState()
	removePersistentValue(USER_ID_KEY)
	removePersistentValue(COMWX_REDIRECT_KEY)
}

export const buildAuthPayload = (extra = {}) => {
	const session = getAuthSession()
	const userId = normalizeUserId(
		extra.user_id ||
		extra.UserID ||
		session?.userId ||
		getCurrentUserId() ||
		getManualLoginState()?.userId
	)

	const payload = {
		...extra,
		user_id: userId
	}

	if (session) {
		payload.auth_exp = session.authExp
		payload.auth_sig = session.authSig
	}

	return payload
}

export const getAuthStatusText = () => {
	const session = getAuthSession()
	if (!session) return '未认证'
	return session.authExp * 1000 > Date.now() ? '已认证' : '认证已过期'
}

export const getIdentitySummary = () => {
	const session = getAuthSession()
	const manualState = getManualLoginState()
	const userId = getCurrentUserId() || manualState?.userId || ''
	const authSource = session?.authSource || manualState?.authSource || 'unknown'
	return {
		userId,
		authenticated: hasValidAuthSession(),
		authSource,
		sourceLabel: authSource === 'qywx' ? '企业微信直达' : (authSource === 'manual' ? '手动登录' : '未识别'),
		rememberPassword: manualState?.rememberPassword === true,
		profile: manualState?.profile || null,
		account: manualState?.account || '',
		canLogout: authSource === 'manual' && !!(userId || manualState)
	}
}

export const isEnterpriseAuthOfflineTime = () => {
	const beijingNow = new Date(Date.now() + 8 * 60 * 60 * 1000)
	const hour = beijingNow.getUTCHours()
	return hour >= 22 || hour < 6
}

export const buildEnterpriseAuthEntryUrl = () => {
	if (typeof window === 'undefined' || !window.location?.origin) {
		return 'https://syauinfo.syau.edu.cn/LessonSchedule/index.php'
	}
	const appRootUrl = new URL('/LessonSchedule/', window.location.origin).href
	return `https://syauinfo.syau.edu.cn/LessonSchedule/index.php?kind=${encodeURIComponent(appRootUrl)}`
}

export const startReauthentication = () => {
	const identity = getIdentitySummary()
	clearAuthSession()
	if (identity.authSource === 'manual') {
		uni.reLaunch({ url: '/pages/login/login' })
		return
	}
	if (typeof window !== 'undefined') {
		window.location.href = buildEnterpriseAuthEntryUrl()
		return
	}
	uni.reLaunch({ url: '/pages/index/index' })
}

export const markComWxAutoAuthAttempt = () => {
	writePersistentValue(COMWX_REDIRECT_KEY, {
		at: Date.now()
	}, false)
}

export const shouldAttemptComWxAutoAuth = (cooldownMs = 15000) => {
	const state = readPersistentValue(COMWX_REDIRECT_KEY)
	const lastAt = Number(state?.at || 0)
	return !lastAt || (Date.now() - lastAt) > cooldownMs
}

export const clearComWxAutoAuthAttempt = () => {
	removePersistentValue(COMWX_REDIRECT_KEY)
}
