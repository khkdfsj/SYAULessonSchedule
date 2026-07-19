<template>
	<view class="page">
		<view class="nav">
			<view class="nav-btn" @click="goBack">
				<uni-icons type="left" size="22" color="#111827"></uni-icons>
			</view>
			<view class="nav-title">课程评论管理</view>
			<view class="nav-placeholder"></view>
		</view>

		<scroll-view scroll-y class="page-scroll">
			<view class="hero-card">
				<view class="hero-title">管理员评论区总览</view>
				<view class="hero-subtitle">只列出已经产生评论的课程。点击后可查看匿名评论详情并删除任意评论。</view>
			</view>

			<view v-if="!sessionInfo.is_admin" class="state-card">
				<view class="state-title">当前账号不是管理员</view>
				<view class="state-subtitle">请使用已加入白名单的学号重新进入课表后再访问。</view>
			</view>

			<template v-else>
				<view class="search-card">
					<input
						v-model="keyword"
						class="search-input"
						type="text"
						maxlength="80"
						placeholder="搜索课程名或教师名"
						placeholder-class="search-placeholder"
					/>
					<view class="search-btn" @click="applySearch">搜索</view>
				</view>

				<view v-if="loading && threads.length === 0" class="state-card">
					<view class="state-title">加载中...</view>
					<view class="state-subtitle">正在读取课程评论区。</view>
				</view>
				<view v-else-if="threads.length === 0" class="state-card">
					<view class="state-title">暂无课程评论</view>
					<view class="state-subtitle">目前还没有课程进入评论区。</view>
				</view>

				<view v-else class="thread-list">
					<view v-for="item in threads" :key="item.id" class="thread-card" @click="openThread(item)">
						<view class="thread-top">
							<view class="thread-title">{{ item.course_name }}</view>
							<view class="thread-count">{{ item.comment_count }} 条</view>
						</view>
						<view class="thread-teacher">{{ item.teacher_name }}</view>
						<view class="thread-meta">
							<text>评论点赞：{{ item.like_count || 0 }}</text>
							<text>最近活跃：{{ item.last_comment_at || '-' }}</text>
						</view>
					</view>
				</view>

				<view v-if="canLoadMore" class="load-more" @click="loadThreads(false)">加载更多</view>
			</template>
		</scroll-view>
	</view>
</template>

<script setup>
import { computed, ref } from 'vue'
import { getAdminCourseCommentThreads } from '@/api/courseComments.js'
import { getFeedbackSession } from '@/api/feedback.js'
import { getErrorMessage } from '@/utils/http.js'

const sessionInfo = ref({
	authenticated: false,
	is_admin: false
})
const loading = ref(false)
const threads = ref([])
const page = ref(1)
const pageSize = 20
const total = ref(0)
const keyword = ref('')

const canLoadMore = computed(() => {
	return sessionInfo.value.is_admin && threads.value.length < total.value && !loading.value
})

const goBack = () => {
	uni.navigateBack()
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

const loadThreads = async (reset = true) => {
	if (!sessionInfo.value.is_admin) {
		threads.value = []
		total.value = 0
		return
	}

	const nextPage = reset ? 1 : (page.value + 1)
	loading.value = true
	try {
		const response = await getAdminCourseCommentThreads({
			page: nextPage,
			pageSize,
			keyword: keyword.value.trim()
		})
		const nextList = Array.isArray(response.data.list) ? response.data.list : []
		threads.value = reset ? nextList : [...threads.value, ...nextList]
		total.value = Number(response.data.total || 0)
		page.value = nextPage
	} catch (error) {
		threads.value = reset ? [] : threads.value
		uni.showToast({
			title: getErrorMessage(error, '加载失败'),
			icon: 'none'
		})
	} finally {
		loading.value = false
	}
}

const applySearch = () => {
	loadThreads(true)
}

const openThread = (item) => {
	uni.navigateTo({
		url: `/pages/course-comments/index?courseName=${encodeURIComponent(item.course_name)}&teacherName=${encodeURIComponent(item.teacher_name)}`
	})
}

onShow(async () => {
	await loadSession()
	await loadThreads(true)
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

.hero-card,
.search-card,
.state-card,
.thread-card {
	background: rgba(255, 255, 255, 0.92);
	border-radius: 28rpx;
	box-shadow: 0 14rpx 34rpx rgba(15, 23, 42, 0.06);
}

.hero-card,
.search-card,
.state-card,
.thread-card {
	padding: 28rpx;
}

.hero-card,
.search-card,
.state-card {
	margin-bottom: 18rpx;
}

.hero-title,
.state-title,
.thread-title {
	font-size: 32rpx;
	font-weight: 700;
	color: #0f172a;
}

.hero-subtitle,
.state-subtitle,
.thread-teacher {
	margin-top: 12rpx;
	font-size: 24rpx;
	line-height: 1.7;
	color: #64748b;
}

.search-card {
	display: flex;
	gap: 14rpx;
	align-items: center;
}

.search-input {
	flex: 1;
	height: 84rpx;
	padding: 0 24rpx;
	border-radius: 22rpx;
	background: #f8fafc;
	font-size: 26rpx;
	color: #0f172a;
	box-sizing: border-box;
}

.search-placeholder {
	color: #94a3b8;
}

.search-btn,
.load-more {
	height: 76rpx;
	padding: 0 28rpx;
	border-radius: 999rpx;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	background: linear-gradient(135deg, #2563eb 0%, #0ea5e9 100%);
	color: #fff;
	font-size: 24rpx;
	font-weight: 700;
}

.state-card {
	text-align: center;
}

.thread-list {
	display: flex;
	flex-direction: column;
	gap: 16rpx;
}

.thread-top {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 16rpx;
}

.thread-count {
	padding: 8rpx 16rpx;
	border-radius: 999rpx;
	background: rgba(37, 99, 235, 0.14);
	color: #1d4ed8;
	font-size: 22rpx;
	font-weight: 700;
}

.thread-meta {
	margin-top: 18rpx;
	display: flex;
	flex-wrap: wrap;
	gap: 10rpx 18rpx;
	font-size: 22rpx;
	color: #64748b;
}

.load-more {
	width: 100%;
	margin: 20rpx auto 0;
}
</style>
