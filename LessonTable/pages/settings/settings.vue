<template>
	<view class="settings-page">
		<view class="header">
			<view class="header-btn" @click="goBack">
				<uni-icons type="left" size="22" color="#111827"></uni-icons>
			</view>
			<view class="header-title">设置</view>
			<view class="version-pill">v{{ APP_VERSION }}</view>
		</view>

		<scroll-view scroll-y class="settings-scroll">
			<view class="group-card identity-card">
				<view class="group-title">身份状态</view>
				<view class="row">
					<view class="row-main">
						<view class="row-title">当前学号</view>
						<view class="row-subtitle">当前设备正在使用的课表账号。</view>
					</view>
					<view class="row-value">{{ currentUserId }}</view>
				</view>
				<view class="row">
					<view class="row-main">
						<view class="row-title">认证方式</view>
						<view class="row-subtitle">{{ authenticationDescription }}</view>
					</view>
					<view class="row-value">{{ authenticationMethodLabel }}</view>
				</view>
				<view class="row" v-if="sessionInfo.is_admin">
					<view class="row-main">
						<view class="row-title">管理员权限</view>
						<view class="row-subtitle">可使用反馈、课程讨论和公告管理功能。</view>
					</view>
					<view class="row-value">已启用</view>
				</view>
				<view class="row" v-if="identitySummary.account">
					<view class="row-main">
						<view class="row-title">已保存账号</view>
						<view class="row-subtitle">用于当前设备下次快速登录。</view>
					</view>
					<view class="row-value">{{ identitySummary.account }}</view>
				</view>
				<view class="row" v-if="identitySummary.authSource !== 'qywx'">
					<view class="row-main">
						<view class="row-title">记住密码</view>
						<view class="row-subtitle">只保存在当前设备浏览器，本机可随时清除。</view>
					</view>
					<view class="row-value">{{ identitySummary.rememberPassword ? '已开启' : '未开启' }}</view>
				</view>
				<view class="row row-link" v-if="identitySummary.authSource !== 'qywx'" @click="goLogin">
					<view class="row-main">
						<view class="row-title">{{ identitySummary.authSource === 'manual' ? '重新登录' : '打开登录页' }}</view>
						<view class="row-subtitle">重新验证身份并更新当前设备上的账号信息。</view>
					</view>
					<uni-icons type="right" size="18" color="#94a3b8"></uni-icons>
				</view>
				<view v-if="identitySummary.canLogout" class="tool-btn danger" @click="logoutManualIdentity">退出并清除本地登录信息</view>
				<view v-else class="inline-tip">
					{{ identitySummary.authSource === 'qywx' ? '当前使用企业微信认证。' : '当前没有已保存的账号登录信息。' }}
				</view>
			</view>

			<view class="group-card">
				<view class="group-title">反馈与评论</view>
				<view class="row row-link" @click="goFeedbackCenter">
					<view class="row-main">
						<view class="row-title">问题反馈与建议</view>
						<view class="row-subtitle">提交使用问题或功能建议，并查看处理进度。</view>
					</view>
					<uni-icons type="right" size="18" color="#94a3b8"></uni-icons>
				</view>
				<view v-if="sessionInfo.is_admin" class="row row-link" @click="goAdminFeedback">
					<view class="row-main">
						<view class="row-title">反馈管理</view>
						<view class="row-subtitle">处理用户反馈，回复和维护建议内容。</view>
					</view>
					<uni-icons type="right" size="18" color="#94a3b8"></uni-icons>
				</view>
				<view v-if="sessionInfo.is_admin" class="row row-link" @click="goAdminCourseComments">
					<view class="row-main">
						<view class="row-title">课程评论管理</view>
						<view class="row-subtitle">管理课程讨论内容，处理不当留言。</view>
					</view>
					<uni-icons type="right" size="18" color="#94a3b8"></uni-icons>
				</view>
				<view class="row row-link" @click="goDevlog">
					<view class="row-main">
						<view class="row-title">开发日志</view>
						<view class="row-subtitle">查看当前版本的功能更新与历史记录。</view>
					</view>
					<uni-icons type="right" size="18" color="#94a3b8"></uni-icons>
				</view>
			</view>

			<view class="group-card">
				<view class="group-title">课表数据</view>
				<view class="row column-row">
					<view class="row-main">
						<view class="row-title">数据来源</view>
						<view class="row-subtitle">在线模式请求最新可用课表；缓存模式读取本设备已保存的数据。</view>
					</view>
					<view class="segment">
						<view
							class="segment-item"
							:class="{ active: settings.dataSource === 'online' }"
							@click="switchDataSource('online')"
						>
							在线数据
						</view>
						<view
							class="segment-item"
							:class="{ active: settings.dataSource === 'cache' }"
							@click="switchDataSource('cache')"
						>
							{{ isNightTime() ? '缓存数据（夜间强制使用）' : '缓存数据' }}
						</view>
					</view>
					<view v-if="isNightTime()" class="night-note">22:00至次日06:00实时课表服务暂停，当前仅可使用本机缓存。</view>
				</view>
				<view v-if="settings.dataSource === 'cache'" class="row row-link" :class="{ disabled: isNightTime() }" @click="onUpdateCacheClick">
					<view class="row-main">
						<view class="row-title">更新缓存数据</view>
						<view class="row-subtitle">重新获取课表并覆盖本设备上的课表缓存。</view>
					</view>
					<uni-icons type="right" size="18" color="#94a3b8"></uni-icons>
				</view>
				<!-- 手动开学日期已取消（日期一律由后端校历服务下发，禁止用户修改）
				<picker v-if="settings.dataSource === 'cache'" mode="date" :value="settings.startDate" @change="onStartDateChange">
					<view class="row row-link">
						<view class="row-main">
							<view class="row-title">开学日期</view>
							<view class="row-subtitle">在线模式下由后端统一提供，缓存模式下手动设置。</view>
						</view>
						<view class="row-value">{{ settings.startDate || '请选择' }}</view>
					</view>
				</picker>
				-->
			</view>

			<view class="group-card">
				<view class="group-title">显示与计算</view>
				<view class="row">
					<view class="row-main">
						<view class="row-title">显示周末</view>
						<view class="row-subtitle">在课表中展示周六与周日。</view>
					</view>
					<switch :checked="settings.showWeekend" color="#2563eb" @change="onShowWeekendChange" />
				</view>
				<view class="row">
					<view class="row-main">
						<view class="row-title">界面动画</view>
						<view class="row-subtitle">控制课程卡片和页面的切换动画。</view>
					</view>
					<switch :checked="settings.enableAnimation" color="#2563eb" @change="onEnableAnimationChange" />
				</view>
				<!-- 开学日期非周一设置已取消（日期一律由后端下发）
				<view v-if="settings.dataSource === 'cache'" class="row">
					<view class="row-main">
						<view class="row-title">开学日期非周一</view>
						<view class="row-subtitle">确实不是周一时开启，本学期内保留。</view>
					</view>
					<switch :checked="settings.startDateNotMonday" color="#2563eb" @change="onStartDateNotMondayChange" />
				</view>
				-->
			</view>

			<view class="group-card">
				<view class="group-title">作息时间表</view>
				<view class="schedule-title">{{ scheduleTimeTitle }}</view>
				<view class="schedule-list">
					<template v-for="row in displayScheduleRows" :key="row.key">
						<view v-if="row.type === 'course'" class="schedule-row">
							<text class="schedule-index">第{{ row.index }}节</text>
							<text class="schedule-time">{{ row.time[0] }} - {{ row.time[1] }}</text>
						</view>
						<view v-else class="schedule-break">{{ row.label }}</view>
					</template>
				</view>
			</view>

			<view class="group-card">
				<view class="group-title">维护工具</view>
				<view class="group-description">如遇课表异常，请先尝试清空缓存并重新认证；如仍未解决，可前往“问题反馈与建议”提交工单。</view>
				<view class="tool-btn danger" @click="clearCustomCourses">清除自定义课程</view>
				<view class="tool-btn" @click="clearLocalDataAndReauthenticate">清空缓存并重新认证</view>
			</view>

			<view class="tips-card">
				<view class="tips-title">说明</view>
				<text>1. 当前账号和个性化设置保存在本设备，更换设备后需要重新认证和设置。</text>
				<text>2. 22:00至次日06:00实时服务暂停，课表会自动使用本机缓存。</text>
				<text>3. 自定义课程只保存在本设备，不参与课程讨论和课程群。</text>
				<text>4. “清空缓存并重新认证”会删除本设备上的课表、设置、自定义课程和登录信息。</text>
			</view>
		</scroll-view>
	</view>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { getFeedbackSession } from '@/api/feedback.js'
import { APP_VERSION } from '@/utils/app.js'
import { clearAllLocalIdentity, getIdentitySummary } from '@/utils/auth.js'

const defaultSettings = {
	startDate: '',
	showWeekend: true,
	enableAnimation: true,
	totalWeeks: 20,
	currentWeek: 1,
	dataSource: 'online',
	startDateNotMonday: false,
	semesterMark: ''
}

const CUSTOM_COURSE_STORE_KEY = 'CustomCoursesByUser'
const CUSTOM_COURSE_META_KEY = 'CustomCourseMetaByUser'
const ANNOUNCEMENT_SEEN_PREFIX = 'LessonSchedule.AnnouncementSeen.'

const settings = ref({
	...defaultSettings
})
const isClearingLocalData = ref(false)
const sessionInfo = ref({
	authenticated: false,
	is_admin: false,
	user_id: ''
})
const identitySummary = ref(getIdentitySummary())

const currentUserId = computed(() => {
	return identitySummary.value.userId || sessionInfo.value.user_id || '未识别'
})

const authenticationMethodLabel = computed(() => {
	if (identitySummary.value.authSource === 'qywx') return '企业微信认证'
	if (identitySummary.value.authSource === 'manual') return '账号密码认证'
	return '未认证'
})

const authenticationDescription = computed(() => {
	if (!sessionInfo.value.authenticated) {
		return '当前认证已失效，请在白天重新认证后使用互动功能。'
	}
	if (identitySummary.value.authSource === 'qywx') {
		return '通过企业微信认证身份，无需输入账号密码。'
	}
	return '通过教务账号验证身份，登录信息仅保存在当前设备。'
})

const scheduleTimeTitle = computed(() => {
	const month = new Date().getMonth() + 1
	return month >= 5 && month <= 9 ? '夏季作息时间表' : '冬季作息时间表'
})

const displayScheduleTime = computed(() => {
	const month = new Date().getMonth() + 1
	if (month >= 5 && month <= 9) {
		return [
			['8:00', '8:45'],
			['8:55', '9:40'],
			['10:00', '10:45'],
			['10:55', '11:40'],
			['14:00', '14:45'],
			['14:55', '15:40'],
			['16:00', '16:45'],
			['16:55', '17:40'],
			['19:00', '19:45'],
			['19:55', '20:40'],
			['21:00', '21:45'],
			['21:55', '22:40']
		]
	}

	return [
		['8:00', '8:45'],
		['8:55', '9:40'],
		['10:00', '10:45'],
		['10:55', '11:40'],
		['13:30', '14:15'],
		['14:25', '15:10'],
		['15:30', '16:15'],
		['16:25', '17:10'],
		['18:30', '19:15'],
		['19:25', '20:10'],
		['20:30', '21:15'],
		['21:25', '22:10']
	]
})

const displayScheduleRows = computed(() => {
	const rows = []
	displayScheduleTime.value.forEach((item, index) => {
		rows.push({
			type: 'course',
			key: `course-${index + 1}`,
			index: index + 1,
			time: item
		})
		if (index === 3) {
			rows.push({
				type: 'rest',
				key: 'rest-noon',
				label: '午休'
			})
		}
		if (index === 7) {
			rows.push({
				type: 'rest',
				key: 'rest-evening',
				label: '晚休'
			})
		}
	})
	return rows
})

const refreshIdentitySummary = () => {
	identitySummary.value = getIdentitySummary()
}

const getCurrentSemesterMark = () => {
	// 优先使用后端下发的学期标记（方案B），判定规则与后端一致：1月归上一年 fall
	const sd = uni.getStorageSync('ScheduleData')
	if (sd && sd.semesterMark) return sd.semesterMark
	const today = new Date()
	const year = today.getFullYear()
	const month = today.getMonth() + 1
	if (month >= 8 || month <= 1) {
		return `${month <= 1 ? year - 1 : year}-fall`
	}
	return `${year}-spring`
}

const saveSettings = () => {
	uni.setStorageSync('scheduleSettings', settings.value)
	uni.$emit('settingsUpdated', settings.value)
}

const loadSettings = () => {
	const savedSettings = uni.getStorageSync('scheduleSettings')
	if (savedSettings) {
		settings.value = {
			...defaultSettings,
			...savedSettings
		}
		const currentSemester = getCurrentSemesterMark()
		if (savedSettings.semesterMark !== currentSemester) {
			settings.value.startDateNotMonday = false
			settings.value.semesterMark = currentSemester
			saveSettings()
		}
	} else {
		const scheduleData = uni.getStorageSync('ScheduleData')
		if (scheduleData) {
			settings.value.startDate = scheduleData.startDate || ''
			settings.value.totalWeeks = scheduleData.totalWeek || 20
			settings.value.currentWeek = scheduleData.nowWeek || 1
			if (scheduleData.semesterMark) {
				settings.value.semesterMark = scheduleData.semesterMark
			}
		} else {
			// 手动默认开学日期已取消：日期一律由后端校历服务下发，禁止手动回退
			// const today = new Date()
			// const year = today.getFullYear()
			// const month = today.getMonth() + 1
			// settings.value.startDate = month >= 1 && month <= 7 ? `${year}/03/01` : `${year}/08/25`
		}
		if (!settings.value.semesterMark) {
			settings.value.semesterMark = getCurrentSemesterMark()
		}
	}

	if (!settings.value.totalWeeks || settings.value.totalWeeks < 1) settings.value.totalWeeks = 20
	if (!settings.value.currentWeek || settings.value.currentWeek < 1) settings.value.currentWeek = 1
	if (settings.value.currentWeek > settings.value.totalWeeks) settings.value.currentWeek = settings.value.totalWeeks
}

const loadSession = async () => {
	try {
		const response = await getFeedbackSession()
		sessionInfo.value = {
			...sessionInfo.value,
			...response.data
		}
	} catch (error) {
		sessionInfo.value = {
			authenticated: false,
			is_admin: false,
			user_id: ''
		}
	}
}

const isNightTime = () => {
	const beijingHour = new Date(Date.now() + 8 * 3600 * 1000).getUTCHours()
	return beijingHour >= 22 || beijingHour < 6
}

const switchDataSource = (source) => {
	if (settings.value.dataSource === source) return
	if (source === 'online' && isNightTime()) {
		uni.showToast({
			title: '当前时间段在线数据不可用，请在白天操作',
			icon: 'none'
		})
		return
	}
	if (source === 'cache') {
		uni.showModal({
			title: '切换数据源',
			content: '缓存数据可能不是最新课表，确认切换到缓存数据吗？',
			success: (res) => {
				if (!res.confirm) return
				settings.value.dataSource = source
				saveSettings()
				uni.$emit('dataSourceUpdated', source)
			}
		})
		return
	}

	settings.value.dataSource = source
	saveSettings()
	uni.$emit('dataSourceUpdated', source)
}

const onUpdateCacheClick = () => {
	if (isNightTime()) {
		uni.showToast({
			title: '夜间时段无法更新缓存，请在白天操作',
			icon: 'none'
		})
		return
	}
	updateCourseData()
}

const updateCourseData = () => {
	uni.showModal({
		title: '更新缓存',
		content: '确认在白天拉取在线课表并刷新缓存吗？',
		success: (res) => {
			if (!res.confirm) return
			uni.$emit('updateCourseData')
			uni.showToast({
				title: '已发起刷新',
				icon: 'none'
			})
		}
	})
}

const clearCustomCourses = () => {
	const userID = currentUserId.value
	if (!userID || userID === '未识别') {
		uni.showToast({
			title: '未检测到学号信息',
			icon: 'none'
		})
		return
	}

	uni.showModal({
		title: '清除自定义课程',
		content: '确认清除当前账号下所有自定义课程吗？该操作不可恢复。',
		confirmText: '确认清除',
		cancelText: '取消',
		success: (res) => {
			if (!res.confirm) return
			const courseBucket = uni.getStorageSync(CUSTOM_COURSE_STORE_KEY)
			const courseMap = courseBucket && typeof courseBucket === 'object' ? courseBucket : {}
			courseMap[userID] = []
			uni.setStorageSync(CUSTOM_COURSE_STORE_KEY, courseMap)

			const metaBucket = uni.getStorageSync(CUSTOM_COURSE_META_KEY)
			const metaMap = metaBucket && typeof metaBucket === 'object' ? metaBucket : {}
			metaMap[userID] = getCurrentSemesterMark()
			uni.setStorageSync(CUSTOM_COURSE_META_KEY, metaMap)

			uni.$emit('customCoursesChanged')
			uni.showToast({
				title: '已清除',
				icon: 'success'
			})
		}
	})
}

const clearAnnouncementSeenMarkers = () => {
	if (typeof window === 'undefined' || !window.localStorage) return
	const keys = []
	for (let index = 0; index < window.localStorage.length; index += 1) {
		const key = window.localStorage.key(index)
		if (key && key.startsWith(ANNOUNCEMENT_SEEN_PREFIX)) keys.push(key)
	}
	keys.forEach(key => window.localStorage.removeItem(key))
}

const restartAfterLocalDataClear = () => {
	if (typeof window !== 'undefined' && window.location?.origin) {
		const restartUrl = new URL('/LessonSchedule/', window.location.origin)
		restartUrl.searchParams.set('reset', `${Date.now()}`)
		window.location.replace(restartUrl.href)
		return
	}
	uni.reLaunch({
		url: '/pages/index/index'
	})
}

const clearLocalDataAndReauthenticate = () => {
	if (isNightTime()) {
		uni.showModal({
			title: '当前无法重新认证',
			content: '22:00至次日06:00认证服务暂停。为避免清空后无法进入课表，请在白天使用此功能。',
			showCancel: false,
			confirmText: '知道了'
		})
		return
	}

	uni.showModal({
		title: '清空缓存并重新认证',
		content: '将清除本设备上的课表缓存、个性化设置、自定义课程及登录信息，并重新加载最新版课表。该操作不可恢复，是否继续？',
		confirmText: '清空并重新认证',
		cancelText: '取消',
		success: (res) => {
			if (!res.confirm) return
			isClearingLocalData.value = true
			clearAllLocalIdentity()
			uni.clearStorageSync()
			clearAnnouncementSeenMarkers()
			uni.showLoading({
				title: '正在重新加载',
				mask: true
			})
			setTimeout(restartAfterLocalDataClear, 120)
		}
	})
}

const goFeedbackCenter = () => {
	uni.navigateTo({
		url: '/pages/feedback/index'
	})
}

const goAdminFeedback = () => {
	uni.navigateTo({
		url: '/pages/admin-feedback/index'
	})
}

const goAdminCourseComments = () => {
	uni.navigateTo({
		url: '/pages/admin-course-comments/index'
	})
}

const goDevlog = () => {
	uni.navigateTo({
		url: '/pages/devlog/index'
	})
}

const goLogin = () => {
	uni.navigateTo({
		url: '/pages/login/login'
	})
}

const logoutManualIdentity = () => {
	if (!identitySummary.value.canLogout) return
	uni.showModal({
		title: '退出登录',
		content: '确认清除本地保存的手动登录信息和当前课表身份吗？',
		confirmText: '确认退出',
		cancelText: '取消',
		success: (res) => {
			if (!res.confirm) return
			clearAllLocalIdentity()
			uni.removeStorageSync('ScheduleData')
			refreshIdentitySummary()
			uni.reLaunch({
				url: '/pages/login/login'
			})
		}
	})
}

const goBack = () => {
	saveSettings()
	uni.navigateBack()
}

const onStartDateChange = (event) => {
	settings.value.startDate = event.detail.value
	saveSettings()
}

const onShowWeekendChange = (event) => {
	settings.value.showWeekend = event.detail.value
	saveSettings()
}

const onEnableAnimationChange = (event) => {
	settings.value.enableAnimation = event.detail.value
	saveSettings()
}

const onStartDateNotMondayChange = (event) => {
	const nextValue = event.detail.value
	if (!nextValue) {
		settings.value.startDateNotMonday = false
		saveSettings()
		return
	}

	uni.showModal({
		title: '确认开启',
		content: '开启后，本学期内不再校验开学日期是否为周一。',
		confirmText: '确认开启',
		cancelText: '取消',
		success: (res) => {
			if (!res.confirm) return
			settings.value.startDateNotMonday = true
			saveSettings()
		}
	})
}

onMounted(async () => {
	loadSettings()
	refreshIdentitySummary()
	await loadSession()
})

onShow(async () => {
	loadSettings()
	refreshIdentitySummary()
	await loadSession()
})

onUnload(() => {
	if (!isClearingLocalData.value) saveSettings()
})
</script>

<style lang="scss" scoped>
.settings-page {
	min-height: 100vh;
	background: linear-gradient(180deg, #f4f7fb 0%, #eef3f9 100%);
}

.header {
	height: calc(env(safe-area-inset-top) + 92rpx);
	padding: env(safe-area-inset-top) 24rpx 0;
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.header-btn,
.version-pill {
	min-width: 76rpx;
	height: 64rpx;
	display: flex;
	align-items: center;
	justify-content: center;
}

.version-pill {
	padding: 0 16rpx;
	border-radius: 999rpx;
	background: rgba(255, 255, 255, 0.92);
	font-size: 22rpx;
	font-weight: 700;
	color: #1d4ed8;
	box-shadow: 0 10rpx 20rpx rgba(15, 23, 42, 0.05);
}

.header-title {
	font-size: 34rpx;
	font-weight: 700;
	color: #111827;
}

.settings-scroll {
	height: calc(100vh - env(safe-area-inset-top) - 92rpx);
	padding: 0 24rpx calc(env(safe-area-inset-bottom) + 36rpx);
	box-sizing: border-box;
}

.summary-card,
.group-card,
.tips-card {
	background: rgba(255, 255, 255, 0.92);
	border-radius: 28rpx;
	box-shadow: 0 14rpx 34rpx rgba(15, 23, 42, 0.06);
	margin-bottom: 18rpx;
	padding: 28rpx;
}

.summary-top {
	display: flex;
	justify-content: space-between;
	gap: 16rpx;
}

.summary-title,
.group-title,
.tips-title {
	font-size: 30rpx;
	font-weight: 700;
	color: #0f172a;
}

.summary-subtitle {
	margin-top: 12rpx;
	font-size: 24rpx;
	line-height: 1.6;
	color: #64748b;
}

.summary-badge {
	padding: 10rpx 18rpx;
	height: 38rpx;
	border-radius: 999rpx;
	background: rgba(34, 197, 94, 0.14);
	color: #166534;
	font-size: 22rpx;
	font-weight: 700;
}

.summary-badge.warn {
	background: rgba(249, 115, 22, 0.14);
	color: #c2410c;
}

.summary-meta {
	margin-top: 18rpx;
	display: flex;
	flex-direction: column;
	gap: 8rpx;
	font-size: 24rpx;
	color: #64748b;
}

.group-title {
	margin-bottom: 12rpx;
}

.group-description {
	margin-bottom: 14rpx;
	font-size: 22rpx;
	line-height: 1.6;
	color: #64748b;
}

.row {
	min-height: 92rpx;
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 18rpx 0;
	border-top: 1rpx solid rgba(226, 232, 240, 0.8);
}

.group-card .row:first-of-type {
	border-top: none;
}

.row-main {
	flex: 1;
	padding-right: 20rpx;
}

.row-title {
	font-size: 28rpx;
	font-weight: 600;
	color: #0f172a;
}

.row-subtitle {
	margin-top: 8rpx;
	font-size: 22rpx;
	line-height: 1.6;
	color: #64748b;
}

.row-link:active {
	opacity: 0.72;
}

.row-link.disabled {
	opacity: 0.55;
}

.night-note {
	margin-top: 16rpx;
	padding: 14rpx 20rpx;
	border-radius: 12rpx;
	background: #eef2ff;
	font-size: 22rpx;
	line-height: 1.6;
	color: #4338ca;
}

.row-value {
	font-size: 24rpx;
	color: #475569;
	text-align: right;
}

.column-row {
	flex-direction: column;
	align-items: stretch;
}

.segment {
	margin-top: 20rpx;
	padding: 8rpx;
	border-radius: 999rpx;
	background: rgba(226, 232, 240, 0.72);
	display: flex;
}

.segment-item {
	flex: 1;
	height: 64rpx;
	border-radius: 999rpx;
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 24rpx;
	color: #475569;
}

.segment-item.active {
	background: #fff;
	color: #0f172a;
	font-weight: 700;
	box-shadow: 0 8rpx 20rpx rgba(15, 23, 42, 0.08);
}

.schedule-title {
	font-size: 24rpx;
	color: #64748b;
}

.schedule-list {
	margin-top: 16rpx;
	display: flex;
	flex-direction: column;
	gap: 10rpx;
}

.schedule-row {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 16rpx 18rpx;
	border-radius: 18rpx;
	background: #f8fafc;
}

.schedule-index,
.schedule-time,
.schedule-break {
	font-size: 24rpx;
	color: #475569;
}

.schedule-break {
	text-align: center;
	padding: 12rpx 0;
	border-radius: 999rpx;
	background: rgba(226, 232, 240, 0.58);
}

.tool-btn {
	margin-top: 14rpx;
	height: 82rpx;
	border-radius: 22rpx;
	display: flex;
	align-items: center;
	justify-content: center;
	background: #f8fafc;
	font-size: 28rpx;
	font-weight: 700;
	color: #334155;
}

.tool-btn.danger {
	background: rgba(254, 242, 242, 0.96);
	color: #dc2626;
}

.inline-tip {
	margin-top: 16rpx;
	font-size: 22rpx;
	line-height: 1.6;
	color: #64748b;
}

.tips-card {
	display: flex;
	flex-direction: column;
	gap: 12rpx;
	font-size: 24rpx;
	line-height: 1.7;
	color: #64748b;
}
</style>
