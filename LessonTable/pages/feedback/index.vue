<template>
	<view class="page">
		<view class="nav">
			<view class="nav-btn" @click="goBack"><uni-icons type="left" size="22" color="#111827"></uni-icons></view>
			<view class="nav-title">反馈中心</view>
			<view class="nav-placeholder"></view>
		</view>

		<scroll-view scroll-y class="page-scroll">
			<view class="tab-sticky">
				<view class="segment">
					<view v-for="item in tabs" :key="item.key" class="segment-item" :class="{ active: activeTab === item.key }" @click="switchTab(item.key)">{{ item.label }}</view>
				</view>
			</view>

			<view v-if="loading" class="state-block">
				<view class="state-title">正在加载</view><view class="state-subtitle">请稍候…</view>
			</view>
			<view v-else-if="listError" class="state-block" @click="loadList">
				<view class="state-title">内容加载失败</view><view class="state-subtitle">点击此处重新加载</view>
			</view>
			<view v-else-if="list.length === 0" class="state-block">
				<view class="state-title">暂无内容</view><view class="state-subtitle">{{ emptyText }}</view>
			</view>
			<view v-else class="thread-list">
				<view v-for="item in list" :key="item.id" class="thread-row" @click="openDetail(item)">
					<view class="thread-header">
						<view class="thread-title">{{ item.title }}</view>
						<view class="thread-status" :class="`status-${item.status}`">{{ statusTextMap[item.status] || item.status }}</view>
					</view>
					<view class="thread-content">{{ item.content }}</view>
					<view v-if="item.pinned_reply?.content" class="reply-mark">已有管理员回复</view>
					<view class="thread-meta">
						<text v-if="activeTab === 'suggestions'">{{ item.author_display_name }}</text>
						<text>{{ item.created_at }}</text>
						<text>{{ item.reply_count }} 条回复</text>
						<text v-if="item.type === 'suggestion'">{{ item.like_count }} 个赞</text>
					</view>
				</view>
			</view>
		</scroll-view>

		<view class="bottom-action-wrap">
			<view class="bottom-action" :class="{ checking: authState === 'checking', warn: authState === 'expired', retry: authState === 'unavailable' }" @click="handlePrimaryAction">
				<text v-if="authState === 'authenticated'" class="action-plus">＋</text><text>{{ primaryActionText }}</text>
			</view>
		</view>
	</view>
</template>

<script setup>
import { computed, ref } from 'vue'
import { getFeedbackSession, getFeedbackThreadList } from '@/api/feedback.js'
import { isAuthRequiredError } from '@/utils/http.js'
import { isEnterpriseAuthOfflineTime, startReauthentication } from '@/utils/auth.js'

const tabs = [{ key: 'my_issues', label: '我的问题' }, { key: 'suggestions', label: '建议广场' }]
const statusTextMap = { open: '待处理', replied: '已回复', closed: '已关闭' }
const activeTab = ref('my_issues')
const authState = ref('checking')
const loading = ref(false)
const listError = ref(false)
const list = ref([])

const primaryActionText = computed(() => {
	if (authState.value === 'checking') return '正在验证身份…'
	if (authState.value === 'expired') return '认证已失效，点击重新认证'
	if (authState.value === 'unavailable') return '暂时无法验证，点击重试'
	return activeTab.value === 'my_issues' ? '提交问题' : '提交建议'
})

const emptyText = computed(() => {
	if (activeTab.value === 'suggestions') return '建议广场暂时还没有内容。'
	if (authState.value === 'authenticated') return '你还没有提交过问题。'
	return '重新认证后即可查看自己的问题。'
})

const goBack = () => uni.navigateBack()
const switchTab = (tabKey) => {
	if (activeTab.value === tabKey) return
	activeTab.value = tabKey
	loadList()
}
const openCompose = () => {
	const type = activeTab.value === 'my_issues' ? 'issue' : 'suggestion'
	uni.navigateTo({ url: `/pages/feedback-compose/index?type=${type}` })
}
const openDetail = (item) => uni.navigateTo({ url: `/pages/feedback-detail/index?threadId=${item.id}` })
const showOfflineTimeNotice = () => uni.showModal({
	title: '当前无法重新认证',
	content: '22:00至次日06:00认证服务暂停，请在白天重新认证。',
	showCancel: false,
	confirmText: '知道了'
})
const handlePrimaryAction = () => {
	if (authState.value === 'checking') return
	if (authState.value === 'unavailable') return loadSession()
	if (authState.value === 'expired') {
		if (isEnterpriseAuthOfflineTime()) return showOfflineTimeNotice()
		startReauthentication()
		return
	}
	openCompose()
}
const loadSession = async () => {
	authState.value = 'checking'
	try {
		const response = await getFeedbackSession()
		authState.value = response?.data?.authenticated ? 'authenticated' : 'expired'
	} catch (error) {
		authState.value = isAuthRequiredError(error) ? 'expired' : 'unavailable'
	}
}
const loadList = async () => {
	loading.value = true
	listError.value = false
	try {
		const response = await getFeedbackThreadList(activeTab.value)
		list.value = response.data.list || []
	} catch (error) {
		list.value = []
		if (!(isAuthRequiredError(error) && activeTab.value === 'my_issues')) listError.value = true
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
.page { min-height: 100vh; background: linear-gradient(180deg, #f4f7fb 0%, #eef3f9 100%); }
.nav { height: calc(env(safe-area-inset-top) + 92rpx); padding: env(safe-area-inset-top) 28rpx 0; display: flex; align-items: center; justify-content: space-between; box-sizing: border-box; }
.nav-btn, .nav-placeholder { width: 64rpx; height: 64rpx; display: flex; align-items: center; justify-content: center; }
.nav-title { font-size: 34rpx; font-weight: 700; color: #111827; }
.page-scroll { height: calc(100vh - env(safe-area-inset-top) - 92rpx); padding: 0 24rpx calc(env(safe-area-inset-bottom) + 152rpx); box-sizing: border-box; }
.tab-sticky { position: sticky; top: 0; z-index: 5; padding: 0 0 18rpx; background: linear-gradient(180deg, #f4f7fb 72%, rgba(244, 247, 251, 0)); }
.segment { padding: 8rpx; border-radius: 22rpx; background: rgba(226, 232, 240, 0.82); display: flex; }
.segment-item { flex: 1; height: 72rpx; border-radius: 17rpx; display: flex; align-items: center; justify-content: center; font-size: 27rpx; color: #64748b; }
.segment-item.active { background: #fff; color: #0f172a; font-weight: 700; box-shadow: 0 6rpx 16rpx rgba(15, 23, 42, 0.08); }
.state-block { padding: 112rpx 28rpx 80rpx; text-align: center; }
.state-title { font-size: 29rpx; font-weight: 700; color: #334155; }
.state-subtitle { margin-top: 12rpx; font-size: 24rpx; line-height: 1.6; color: #94a3b8; }
.thread-list { background: rgba(255, 255, 255, 0.94); border-radius: 26rpx; box-shadow: 0 12rpx 30rpx rgba(15, 23, 42, 0.055); overflow: hidden; }
.thread-row { padding: 26rpx 28rpx 24rpx; border-top: 1rpx solid rgba(226, 232, 240, 0.9); }
.thread-row:first-child { border-top: none; }
.thread-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16rpx; }
.thread-title { flex: 1; font-size: 29rpx; font-weight: 700; line-height: 1.45; color: #0f172a; }
.thread-status { flex-shrink: 0; padding: 5rpx 12rpx; border-radius: 999rpx; font-size: 20rpx; font-weight: 600; }
.status-open { background: rgba(245, 158, 11, 0.12); color: #b45309; }
.status-replied { background: rgba(14, 165, 233, 0.12); color: #0369a1; }
.status-closed { background: rgba(100, 116, 139, 0.14); color: #475569; }
.thread-content { margin-top: 10rpx; font-size: 24rpx; line-height: 1.6; color: #64748b; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.reply-mark { margin-top: 12rpx; display: inline-flex; font-size: 21rpx; font-weight: 600; color: #2563eb; }
.thread-meta { margin-top: 14rpx; display: flex; flex-wrap: wrap; gap: 8rpx 18rpx; font-size: 21rpx; color: #94a3b8; }
.bottom-action-wrap { position: fixed; left: 0; right: 0; bottom: 0; z-index: 20; padding: 18rpx 24rpx calc(env(safe-area-inset-bottom) + 18rpx); background: linear-gradient(180deg, rgba(238, 243, 249, 0), rgba(238, 243, 249, 0.96) 28%); box-sizing: border-box; }
.bottom-action { height: 88rpx; border-radius: 24rpx; display: flex; align-items: center; justify-content: center; gap: 8rpx; background: linear-gradient(135deg, #2563eb 0%, #0ea5e9 100%); color: #fff; font-size: 29rpx; font-weight: 700; box-shadow: 0 16rpx 36rpx rgba(37, 99, 235, 0.24); }
.bottom-action.checking { background: #94a3b8; box-shadow: none; }
.bottom-action.warn { background: linear-gradient(135deg, #f97316 0%, #ea580c 100%); box-shadow: 0 16rpx 34rpx rgba(234, 88, 12, 0.2); }
.bottom-action.retry { background: #475569; box-shadow: 0 14rpx 30rpx rgba(71, 85, 105, 0.2); }
.action-plus { font-size: 36rpx; font-weight: 400; line-height: 1; }
</style>
