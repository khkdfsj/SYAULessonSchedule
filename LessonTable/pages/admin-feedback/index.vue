<template>
	<view class="page">
		<view class="nav">
			<view class="nav-btn" @click="goBack">
				<uni-icons type="left" size="22" color="#111827"></uni-icons>
			</view>
			<view class="nav-title">反馈管理</view>
			<view class="nav-placeholder"></view>
		</view>

		<scroll-view scroll-y class="page-scroll">
			<view class="hero">
				<view class="hero-title">管理员工作台</view>
				<view class="hero-subtitle">处理私密问题、查看全部历史记录，并维护建议广场回复顺序。</view>
			</view>

			<view v-if="!sessionInfo.is_admin" class="state-card">
				<view class="state-title">当前账号不是管理员</view>
				<view class="state-subtitle">请使用已加入白名单的学号重新进入课表后再访问。</view>
			</view>
			<template v-else>
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

				<view v-if="loading" class="state-card">加载中...</view>
				<view v-else-if="list.length === 0" class="state-card">
					<view class="state-title">当前没有内容</view>
					<view class="state-subtitle">切换其他分段查看历史记录。</view>
				</view>
				<view v-else class="thread-list">
					<view
						v-for="item in list"
						:key="item.id"
						class="thread-card"
						@click="openDetail(item)"
					>
						<view class="thread-title">{{ item.title }}</view>
						<view class="thread-meta">
							<text>{{ item.author_display_name }}</text>
							<text>{{ item.created_at }}</text>
							<text>{{ item.reply_count }} 条回复</text>
						</view>
						<view class="thread-content">{{ item.content }}</view>
						<view class="thread-footer">
							<text class="thread-status" :class="`status-${item.status}`">{{ statusTextMap[item.status] || item.status }}</text>
							<text>{{ item.type === 'issue' ? '问题单' : '建议帖' }}</text>
						</view>
					</view>
				</view>
			</template>
		</scroll-view>
	</view>
</template>

<script setup>
import { ref } from 'vue'
import { getFeedbackSession, getFeedbackThreadList } from '@/api/feedback.js'
import { getErrorMessage } from '@/utils/http.js'

const tabs = [{
	key: 'admin_pending_issues',
	label: '待处理问题'
}, {
	key: 'admin_all_issues',
	label: '全部问题'
}, {
	key: 'admin_suggestions',
	label: '建议管理'
}]

const statusTextMap = {
	open: '待处理',
	replied: '已回复',
	closed: '已关闭'
}

const sessionInfo = ref({
	authenticated: false,
	is_admin: false
})
const activeTab = ref('admin_pending_issues')
const loading = ref(false)
const list = ref([])

const goBack = () => {
	uni.navigateBack()
}

const openDetail = (item) => {
	uni.navigateTo({
		url: `/pages/feedback-detail/index?threadId=${item.id}`
	})
}

const switchTab = (tabKey) => {
	if (activeTab.value === tabKey) return
	activeTab.value = tabKey
	loadList()
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
			is_admin: false
		}
	}
}

const loadList = async () => {
	if (!sessionInfo.value.is_admin) {
		list.value = []
		return
	}

	loading.value = true
	try {
		const response = await getFeedbackThreadList(activeTab.value)
		list.value = response.data.list || []
	} catch (error) {
		list.value = []
		uni.showToast({
			title: getErrorMessage(error, '加载失败'),
			icon: 'none'
		})
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
	padding: 0 24rpx calc(env(safe-area-inset-bottom) + 36rpx);
	box-sizing: border-box;
}

.hero,
.state-card,
.thread-card {
	background: rgba(255, 255, 255, 0.92);
	border-radius: 28rpx;
	box-shadow: 0 14rpx 34rpx rgba(15, 23, 42, 0.06);
}

.hero {
	padding: 28rpx;
}

.hero-title,
.state-title {
	font-size: 36rpx;
	font-weight: 700;
	color: #0f172a;
}

.hero-subtitle,
.state-subtitle {
	margin-top: 12rpx;
	font-size: 24rpx;
	line-height: 1.7;
	color: #64748b;
}

.state-card {
	margin-top: 18rpx;
	padding: 36rpx 28rpx;
	text-align: center;
}

.segment {
	margin-top: 18rpx;
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
	font-size: 24rpx;
	color: #475569;
}

.segment-item.active {
	background: #fff;
	color: #0f172a;
	font-weight: 700;
	box-shadow: 0 8rpx 20rpx rgba(15, 23, 42, 0.08);
}

.thread-list {
	margin-top: 18rpx;
	display: flex;
	flex-direction: column;
	gap: 16rpx;
}

.thread-card {
	padding: 28rpx;
}

.thread-title {
	font-size: 30rpx;
	font-weight: 700;
	line-height: 1.5;
	color: #0f172a;
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
	font-size: 25rpx;
	line-height: 1.7;
	color: #334155;
	display: -webkit-box;
	-webkit-line-clamp: 3;
	-webkit-box-orient: vertical;
	overflow: hidden;
}

.thread-status {
	padding: 8rpx 16rpx;
	border-radius: 999rpx;
	font-weight: 600;
}

.status-open {
	background: rgba(245, 158, 11, 0.12);
	color: #b45309;
}

.status-replied {
	background: rgba(14, 165, 233, 0.12);
	color: #0369a1;
}

.status-closed {
	background: rgba(100, 116, 139, 0.16);
	color: #475569;
}
</style>
