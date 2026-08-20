export const DATA_SOURCE_ONLINE = 'online'
export const DATA_SOURCE_CACHE = 'cache'

const isValidDataSource = (source) => {
	return source === DATA_SOURCE_ONLINE || source === DATA_SOURCE_CACHE
}

export const normalizeDataSourcePreference = (settings = {}) => {
	const preference = isValidDataSource(settings.dataSourcePreference)
		? settings.dataSourcePreference
		: DATA_SOURCE_ONLINE
	return {
		...settings,
		dataSource: preference,
		dataSourcePreference: preference
	}
}

export const getPreferredDataSource = (settings = {}) => {
	return isValidDataSource(settings.dataSourcePreference)
		? settings.dataSourcePreference
		: DATA_SOURCE_ONLINE
}

export const getEffectiveDataSource = (settings = {}, isNight = false) => {
	const preference = getPreferredDataSource(settings)
	if (preference === DATA_SOURCE_CACHE) return DATA_SOURCE_CACHE
	return isNight ? DATA_SOURCE_CACHE : DATA_SOURCE_ONLINE
}

export const isNightForcedCache = (settings = {}, isNight = false) => {
	return isNight && getPreferredDataSource(settings) === DATA_SOURCE_ONLINE
}

export const setDataSourcePreference = (settings = {}, source) => {
	const preference = isValidDataSource(source) ? source : DATA_SOURCE_ONLINE
	return {
		...settings,
		dataSource: preference,
		dataSourcePreference: preference
	}
}
