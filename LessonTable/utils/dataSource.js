export const DATA_SOURCE_ONLINE = 'online'
export const DATA_SOURCE_CACHE = 'cache'

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
