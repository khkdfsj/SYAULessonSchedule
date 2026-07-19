<template>
	<view class="page">
		<view class="nav">
			<view class="nav-btn" @click="goBack">
				<uni-icons type="left" size="22" color="#111827"></uni-icons>
			</view>
			<view class="nav-title">反馈中心</view>
			<view class="nav-placeholder"></view>
		</view>

		<scroll-view scroll-y class="page-scroll">
			<view class="hero-card">
				<view class="hero-top">
					<view>
						<view class="hero-title">问题反馈与建议</view>
						<view class="hero-subtitle">{{ sessionHint }}</view>
					</view>
					<view class="hero-badge" :class="{ warn: !sessionInfo.authenticated }">
						{{ sessionInfo.authenticated ? '已认证' : '待认证' }}
					</view>
				</view>
				<view class="hero-meta">
					<text>当前用户：{{ userText }}</text>
					<text>管理员：{{ sessionInfo.is_admin ? '是' : '否' }}</text>
				</view>
			</view>

			<view class="action-row">
				<view class="action-btn primary" @click="openCompose('issue')">提问题</view>
				<view class="action-btn" @click="openCompose('suggestion')">提建议</view>
			</view>

			<view class="segment">
				<view
					v-for="item in tabs"
					:key="item.key"
					class="segment-item"
					:class="{ active: activeTab === item.key }"
					@click="switchTab(item.key)"
				>
					{{ item.label }}
				</view>
			</view>

			<view class="section-label">{{ activeLabel }}</view>

			<view v-if="loading" class="state-card">加载中...</view>
			<view v-else-if="list.length === 0" class="state-card">
				<view class="state-title">暂无内容</view>
				<view class="state-subtitle">{{ emptyText }}</view>
			</view>
			<view v-else class="thread-list">
				<view
					v-for="item in list"
					:key="item.id"
					class="thread-card"
					@click="openDetail(item)"
				>
					<view class="thread-header">
						<view class="thread-title">{{ item.title }}</view>
						<view class="thread-status" :class="`status-${item.status}`">{{ statusTextMap[item.status] || item.status }}</view>
					</view>
					<view class="thread-meta">
						<text>{{ item.author_display_name }}</text>
						<text>{{ item.created_at }}</text>
					</view>
					<view class="thread-content">{{ item.content }}</view>
					<view v-if="item.pinned_reply?.content" class="thread-pinned">
						管理员置顶：{{ item.pinned_reply.content }}
					</view>
					<view class="thread-footer">
						<text>{{ item.type === 'issue' ? '问题单' : '建议帖' }}</text>
						<text>{{ item.reply_count }} 条回复</text>
						<text v-if="item.type === 'suggestion'">{{ item.like_count }} 个赞</text>
					</view>
				</view>
			</view>
		</scroll-view>
	</view>
</template>

<script setup>
import { computed, ref } from 'vue'
import { getFeedbackSession, getFeedbackThreadList } from '@/api/feedback.js'
import { getErrorMessage, isAuthRequiredError } from '@/utils/http.js'

const tabs = [{
	key: 'my_issues',
	label: '我的问题'
}, {
	key: 'suggestions',
	label: '建议广场'
}]

const statusTextMap = {
	open: '待处理',
	replied: '已回复',
	closed: '已关闭'
}

const activeTab = ref('my_issues')
const loading = ref(false)
const list = ref([])
const sessionInfo = ref({
	authenticated: false,
	is_admin: false,
	user_id: uni.getStorageSync('UserID') || '',
	masked_user_id: '',
	auth_exp: 0
})

const sessionHint = computed(() => {
	if (sessionInfo.value.authenticated) {
		return sessionInfo.value.is_admin ? '当前为管理员身份，可进入管理页处理反馈。' : '当前签名有效，可提交问题、建议并参与互动。'
	}
	return '签名已失效或未生成。白天重新从课表入口进入可恢复提交能力。'
})

const userText = computed(() => sessionInfo.value.user_id || '未识别')

const activeLabel = computed(() => {
	return activeTab.value === 'my_issues' ? '只展示你自己的问题单' : '公开展示全部建议与互动'
})

const emptyText = computed(() => {
	if (activeTab.value === 'my_issues') {
		return sessionInfo.value.authenticated ? '你还没有提交过问题反馈。' : '当前无法读取私密问题列表，请先在白天重新认证。'
	}
	return '建议广场还没有内容。'
})

const goBack = () => {
	uni.navigateBack()
}

const switchTab = (tabKey) => {
	if (activeTab.value === tabKey) return
	activeTab.value = tabKey
	loadList()
}

const openCompose = (type) => {
	uni.navigateTo({
		url: `/pages/feedback-compose/index?type=${type}`
	})
}

const openDetail = (item) => {
	uni.navigateTo({
		url: `/pages/feedback-detail/index?threadId=${item.id}`
	})
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
			...sessionInfo.value,
			authenticated: false,
			is_admin: false
		}
	}
}

const loadList = async () => {
	loading.value = true
	try {
		const response = await getFeedbackThreadList(activeTab.value)
		list.value = response.data.list || []
	} catch (error) {
		if (isAuthRequiredError(error) && activeTab.value === 'my_issues') {
			list.value = []
		} else {
			uni.showToast({
				title: getErrorMessage(error, '加载失败'),
				icon: 'none'
			})
			list.value = []
		}
	} finally {
		loading.value = false
	}
}

onShow(async () => {
	await loadSession()
	await loadList()
})
</script>

<style lang="scss" scoped>
.page {
	min-height: 100vh;
	background: linear-gradient(180deg, #f4f7fb 0%, #eef3f9 100%);
}

.nav {
	height: calc(env(safe-area-inset-top) + 92rpx);
	padding: env(safe-area-inset-top) 28rpx 0;
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.nav-btn,
.nav-placeholder {
	width: 64rpx;
	height: 64rpx;
	display: flex;
	align-items: center;
	justify-content: center;
}

.nav-title {
	font-size: 34rpx;
	font-weight: 700;
	color: #111827;
}

.page-scroll {
	height: calc(100vh - env(safe-area-inset-top) - 92rpx);
	padding: 0 24rpx calc(env(safe-area-inset-bottom) + 40rpx);
	box-sizing: border-box;
}

.hero-card,
.state-card,
.thread-card {
	background: rgba(255, 255, 255, 0.9);
	border-radius: 28rpx;
	box-shadow: 0 16rpx 40rpx rgba(15, 23, 42, 0.06);
}

.hero-card {
	padding: 30rpx;
}

.hero-top {
	display: flex;
	justify-content: space-between;
	gap: 20rpx;
	align-items: flex-start;
}

.hero-title {
	font-size: 38rpx;
	font-weight: 700;
	color: #0f172a;
}

.hero-subtitle {
	margin-top: 12rpx;
	font-size: 24rpx;
	line-height: 1.6;
	color: #64748b;
}

.hero-badge {
	padding: 10rpx 22rpx;
	height: 38rpx;
	width: 100rpx;
	border-radius: 999rpx;
	background: rgba(34, 197, 94, 0.14);
	color: #166534;
	font-size: 22rpx;
	font-weight: 600;
}

.hero-badge.warn {
	background: rgba(249, 115, 22, 0.14);
	color: #c2410c;
}

.hero-meta {
	margin-top: 20rpx;
	display: flex;
	flex-direction: column;
	gap: 8rpx;
	font-size: 24rpx;
	color: #64748b;
}

.action-row {
	margin-top: 20rpx;
	display: flex;
	gap: 18rpx;
}

.action-btn {
	flex: 1;
	height: 88rpx;
	border-radius: 24rpx;
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 30rpx;
	font-weight: 600;
	color: #0f172a;
	background: rgba(255, 255, 255, 0.85);
	box-shadow: 0 12rpx 30rpx rgba(15, 23, 42, 0.05);
}

.action-btn.primary {
	background: linear-gradient(135deg, #2563eb 0%, #0ea5e9 100%);
	color: #fff;
}

.segment {
	margin-top: 22rpx;
	padding: 10rpx;
	border-radius: 999rpx;
	background: rgba(226, 232, 240, 0.72);
	display: flex;
}

.segment-item {
	flex: 1;
	height: 68rpx;
	border-radius: 999rpx;
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 26rpx;
	color: #475569;
	transition: all 0.2s ease;
}

.segment-item.active {
	background: #fff;
	color: #0f172a;
	font-weight: 700;
	box-shadow: 0 8rpx 20rpx rgba(15, 23, 42, 0.08);
}

.section-label {
	margin: 24rpx 6rpx 18rpx;
	font-size: 24rpx;
	color: #64748b;
}

.state-card {
	padding: 40rpx 32rpx;
	text-align: center;
}

.state-title {
	font-size: 30rpx;
	font-weight: 700;
	color: #0f172a;
}

.state-subtitle {
	margin-top: 12rpx;
	font-size: 24rpx;
	line-height: 1.6;
	color: #64748b;
}

.thread-list {
	display: flex;
	flex-direction: column;
	gap: 18rpx;
}

.thread-card {
	padding: 28rpx;
}

.thread-header {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 16rpx;
}

.thread-title {
	flex: 1;
	font-size: 30rpx;
	font-weight: 700;
	line-height: 1.5;
	color: #0f172a;
}

.thread-status {
	padding: 8rpx 16rpx;
	border-radius: 999rpx;
	font-size: 22rpx;
	font-weight: 600;
}

.status-open {
	background: rgba(245, 158, 11, 0.14);
	color: #b45309;
}

.status-replied {
	background: rgba(14, 165, 233, 0.14);
	color: #0369a1;
}

.status-closed {
	background: rgba(100, 116, 139, 0.16);
	color: #475569;
}

.thread-meta,
.thread-footer {
	margin-top: 14rpx;
	display: flex;
	flex-wrap: wrap;
	gap: 12rpx 18rpx;
	font-size: 22rpx;
	color: #64748b;
}

.thread-content {
	margin-top: 18rpx;
	font-size: 26rpx;
	line-height: 1.7;
	color: #334155;
	display: -webkit-box;
	-webkit-line-clamp: 3;
	-webkit-box-orient: vertical;
	overflow: hidden;
}

.thread-pinned {
	margin-top: 18rpx;
	padding: 18rpx 20rpx;
	border-radius: 20rpx;
	background: rgba(191, 219, 254, 0.38);
	font-size: 24rpx;
	line-height: 1.6;
	color: #1d4ed8;
}
</style>
