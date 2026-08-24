<template>
	<view class="page">
		<view class="nav">
			<view class="nav-btn" @click="goBack"><uni-icons type="left" size="22" color="#111827"></uni-icons></view>
			<view class="nav-title">反馈管理</view>
			<view class="nav-btn" @click="loadList"><uni-icons type="refreshempty" size="20" color="#475569"></uni-icons></view>
		</view>

		<scroll-view scroll-y class="page-scroll">
			<view v-if="!sessionInfo.is_admin" class="state-card">
				<view class="state-title">当前账号不是管理员</view>
				<view class="state-subtitle">请重新进入课表完成身份认证。</view>
			</view>
			<template v-else>
				<view class="segment">
					<view v-for="item in tabs" :key="item.key" class="segment-item" :class="{ active: activeTab === item.key }" @click="switchTab(item.key)">{{ item.label }}</view>
				</view>

				<view class="filter-panel">
					<view class="search-row">
						<uni-icons type="search" size="18" color="#94a3b8"></uni-icons>
						<input v-model="keyword" class="search-input" placeholder="搜索编号、学号、标题或内容" confirm-type="search" @confirm="applySearch" />
						<view v-if="keyword" class="search-clear" @click="clearSearch">清除</view>
						<view class="search-submit" @click="applySearch">搜索</view>
					</view>
					<view class="filter-row">
						<view class="status-filters">
							<view v-for="item in statusOptions" :key="item.value" class="filter-chip" :class="{ active: statusFilter === item.value }" @click="setStatus(item.value)">{{ item.label }}</view>
						</view>
						<picker mode="selector" :range="sortOptions" range-key="label" :value="sortIndex" @change="changeSort">
							<view class="sort-picker">{{ sortOptions[sortIndex].label }} <text class="sort-arrow">⌄</text></view>
						</picker>
					</view>
				</view>

				<view class="list-summary">
					<text>{{ loading ? '正在加载' : `共 ${list.length} 条` }}</text>
					<text>{{ sortOptions[sortIndex].description }}</text>
				</view>

				<view v-if="loading" class="state-card compact">加载中...</view>
				<view v-else-if="list.length === 0" class="state-card compact">
					<view class="state-title">没有符合条件的记录</view>
					<view class="state-subtitle">可调整状态筛选或搜索内容。</view>
				</view>
				<view v-else class="thread-table">
					<view v-for="item in list" :key="item.id" class="thread-row" @click="openDetail(item)">
						<view class="row-main">
							<view class="row-title-line">
								<text class="thread-title">{{ item.title }}</text>
								<text class="thread-status" :class="`status-${item.status}`">{{ statusTextMap[item.status] || item.status }}</text>
							</view>
							<view class="thread-meta">
								<text>#{{ item.id }}</text><text>{{ item.author_display_name }}</text><text>{{ formatTime(item.created_at) }}</text><text>{{ item.reply_count }} 回复</text>
							</view>
							<view class="thread-content">{{ compactContent(item.content) }}</view>
						</view>
						<uni-icons type="right" size="16" color="#cbd5e1"></uni-icons>
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

const tabs = [{ key: 'admin_all_issues', label: '问题工单' }, { key: 'admin_suggestions', label: '建议管理' }]
const statusOptions = [{ value: '', label: '全部' }, { value: 'open', label: '待处理' }, { value: 'replied', label: '已回复' }, { value: 'closed', label: '已关闭' }]
const sortOptions = [
	{ label: '最新提交', sortBy: 'created_at', sortOrder: 'desc', description: '按提交时间从新到旧' },
	{ label: '最早提交', sortBy: 'created_at', sortOrder: 'asc', description: '按提交时间从旧到新' },
	{ label: '最近处理', sortBy: 'updated_at', sortOrder: 'desc', description: '最近有变动的排在前面' }
]
const statusTextMap = { open: '待处理', replied: '已回复', closed: '已关闭' }

const sessionInfo = ref({ authenticated: false, is_admin: false })
const activeTab = ref('admin_all_issues')
const statusFilter = ref('')
const sortIndex = ref(0)
const keyword = ref('')
const appliedKeyword = ref('')
const loading = ref(false)
const list = ref([])

const goBack = () => uni.navigateBack()
const openDetail = (item) => uni.navigateTo({ url: `/pages/feedback-detail/index?threadId=${item.id}` })
const compactContent = (content) => String(content || '').replace(/【[^】]+】/g, ' ').replace(/\s+/g, ' ').trim()
const formatTime = (value) => String(value || '').replace(/^\d{4}-/, '').slice(0, 11)
const switchTab = (tabKey) => { if (activeTab.value !== tabKey) { activeTab.value = tabKey; statusFilter.value = ''; loadList() } }
const setStatus = (value) => { if (statusFilter.value !== value) { statusFilter.value = value; loadList() } }
const changeSort = (event) => { sortIndex.value = Number(event.detail.value || 0); loadList() }
const applySearch = () => { appliedKeyword.value = keyword.value.trim(); loadList() }
const clearSearch = () => { keyword.value = ''; appliedKeyword.value = ''; loadList() }

const loadSession = async () => {
	try { const response = await getFeedbackSession(); sessionInfo.value = { ...sessionInfo.value, ...response.data } }
	catch (error) { sessionInfo.value = { authenticated: false, is_admin: false } }
}
const loadList = async () => {
	if (!sessionInfo.value.is_admin || loading.value) return
	loading.value = true
	try {
		const sort = sortOptions[sortIndex.value]
		const response = await getFeedbackThreadList(activeTab.value, { pageSize: 50, status: statusFilter.value, sortBy: sort.sortBy, sortOrder: sort.sortOrder, keyword: appliedKeyword.value })
		list.value = response.data.list || []
	} catch (error) {
		list.value = []
		uni.showToast({ title: getErrorMessage(error, '加载失败'), icon: 'none' })
	} finally { loading.value = false }
}
onShow(async () => { await loadSession(); await loadList() })
</script>

<style lang="scss" scoped>
.page { min-height: 100vh; background: #f1f5f9; }
.nav { height: calc(env(safe-area-inset-top) + 92rpx); padding: env(safe-area-inset-top) 28rpx 0; display: flex; align-items: center; justify-content: space-between; background: rgba(248, 250, 252, 0.96); }
.nav-btn { width: 64rpx; height: 64rpx; display: flex; align-items: center; justify-content: center; }
.nav-title { font-size: 34rpx; font-weight: 700; color: #111827; }
.page-scroll { height: calc(100vh - env(safe-area-inset-top) - 92rpx); padding: 18rpx 24rpx calc(env(safe-area-inset-bottom) + 36rpx); box-sizing: border-box; }
.segment { padding: 8rpx; border-radius: 22rpx; background: #e2e8f0; display: flex; }
.segment-item { flex: 1; height: 66rpx; border-radius: 17rpx; display: flex; align-items: center; justify-content: center; font-size: 25rpx; color: #64748b; }
.segment-item.active { background: #fff; color: #0f172a; font-weight: 700; box-shadow: 0 4rpx 14rpx rgba(15, 23, 42, 0.08); }
.filter-panel { margin-top: 14rpx; padding: 18rpx; border-radius: 22rpx; background: #fff; }
.search-row { height: 70rpx; padding: 0 10rpx 0 18rpx; border-radius: 16rpx; background: #f1f5f9; display: flex; align-items: center; gap: 12rpx; }
.search-input { flex: 1; min-width: 0; height: 70rpx; font-size: 24rpx; color: #0f172a; }
.search-clear { font-size: 22rpx; color: #64748b; }
.search-submit { padding: 12rpx 18rpx; border-radius: 13rpx; background: #2563eb; font-size: 23rpx; color: #fff; }
.filter-row { margin-top: 16rpx; display: flex; align-items: center; justify-content: space-between; gap: 12rpx; }
.status-filters { display: flex; gap: 8rpx; flex-wrap: wrap; }
.filter-chip { padding: 10rpx 15rpx; border-radius: 14rpx; background: #f8fafc; font-size: 21rpx; color: #64748b; }
.filter-chip.active { background: #e0ecff; color: #1d4ed8; font-weight: 600; }
.sort-picker { padding: 10rpx 14rpx; white-space: nowrap; font-size: 21rpx; color: #334155; }
.sort-arrow { color: #94a3b8; }
.list-summary { padding: 16rpx 4rpx 10rpx; display: flex; justify-content: space-between; font-size: 21rpx; color: #94a3b8; }
.thread-table { overflow: hidden; border-radius: 22rpx; background: #fff; }
.thread-row { min-height: 150rpx; padding: 21rpx 18rpx 20rpx 22rpx; border-bottom: 1rpx solid #edf2f7; display: flex; align-items: center; gap: 12rpx; }
.thread-row:last-child { border-bottom: 0; }
.row-main { flex: 1; min-width: 0; }
.row-title-line { display: flex; align-items: flex-start; gap: 10rpx; }
.thread-title { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 27rpx; font-weight: 700; color: #0f172a; }
.thread-status { flex-shrink: 0; padding: 5rpx 10rpx; border-radius: 10rpx; font-size: 19rpx; font-weight: 600; }
.status-open { background: #fff7e6; color: #b45309; }
.status-replied { background: #e6f6ff; color: #0369a1; }
.status-closed { background: #eef2f6; color: #64748b; }
.thread-meta { margin-top: 10rpx; display: flex; flex-wrap: wrap; gap: 6rpx 15rpx; font-size: 20rpx; color: #94a3b8; }
.thread-content { margin-top: 10rpx; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 23rpx; color: #475569; }
.state-card { padding: 46rpx 28rpx; border-radius: 22rpx; background: #fff; text-align: center; color: #64748b; }
.state-card.compact { margin-top: 10rpx; }
.state-title { font-size: 28rpx; font-weight: 700; color: #0f172a; }
.state-subtitle { margin-top: 12rpx; font-size: 23rpx; line-height: 1.6; color: #64748b; }
</style>
