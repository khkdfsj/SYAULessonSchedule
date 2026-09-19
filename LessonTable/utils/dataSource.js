export const DATA_SOURCE_ONLINE = 'online'
export const DATA_SOURCE_CACHE = 'cache'

/**
 * 学校内网服务关闭时段（北京时区 22:00–次日 06:00）。
 * 此时认证与实时课表均不可用，只能使用缓存（本机或服务端数据库缓存）。
 */
export const isEnterpriseServiceOfflineTime = () => {
	const beijingNow = new Date(Date.now() + 8 * 60 * 60 * 1000)
	const hour = beijingNow.getUTCHours()
	return hour >= 22 || hour < 6
}

export const normalizeDataSourcePreference = (settings = {}) => {
	return {
		...settings,
		dataSource: DATA_SOURCE_ONLINE,
		dataSourcePreference: DATA_SOURCE_ONLINE
	}
}

export const getPreferredDataSource = (settings = {}) => {
	return DATA_SOURCE_ONLINE
}

export const getEffectiveDataSource = (settings = {}, isNight = false) => {
	return isNight ? DATA_SOURCE_CACHE : DATA_SOURCE_ONLINE
}

export const isNightForcedCache = (settings = {}, isNight = false) => {
	return isNight
}

export const setDataSourcePreference = (settings = {}, source) => {
	return {
		...settings,
		dataSource: DATA_SOURCE_ONLINE,
		dataSourcePreference: DATA_SOURCE_ONLINE
	}
}
