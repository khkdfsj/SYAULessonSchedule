<template>
	<view class="page">
		<view class="nav">
			<view class="nav-btn" @click="goBack">
				<uni-icons type="left" size="22" color="#111827"></uni-icons>
			</view>
			<view class="nav-title">课程评论</view>
			<view class="nav-placeholder"></view>
		</view>

		<scroll-view scroll-y class="page-scroll">
			<view class="hero-card">
				<view class="hero-top">
					<view class="hero-copy">
						<view class="hero-title">{{ courseName || '未识别课程' }}</view>
						<view class="hero-subtitle">{{ teacherName || '未识别教师' }}</view>
					</view>
					<view class="hero-badge" :class="{ warn: !sessionInfo.authenticated }">
						{{ sessionInfo.authenticated ? '已认证' : '待认证' }}
					</view>
				</view>
				<view class="hero-meta">
					<text>评论数：{{ thread.comment_count || 0 }}</text>
					<text>点赞数：{{ thread.like_count || 0 }}</text>
					<text>身份：{{ sessionInfo.is_admin ? '管理员' : '普通用户' }}</text>
				</view>
			</view>

			<view class="composer-card" v-if="canShowComposer">
				<view class="composer-title">匿名发表评论</view>
				<textarea
					v-model="draft"
					class="composer-textarea"
					maxlength="500"
					placeholder="仅限这门接口课程的同学查看。请输入你的评论，发布后 5 分钟内可撤回。"
					placeholder-class="textarea-placeholder"
				></textarea>
				<view class="composer-footer">
					<text>{{ draftLength }}/500</text>
					<view class="composer-btn" :class="{ disabled: submitting }" @click="submitComment">
						{{ submitting ? '发布中...' : '匿名发布' }}
					</view>
				</view>
			</view>

			<view v-else-if="showAccessState" class="state-card">
				<view class="state-title">{{ accessState.title }}</view>
				<view class="state-subtitle">{{ accessState.message }}</view>
				<view class="state-action" @click="goLogin">去登录</view>
			</view>

			<view v-if="loading && comments.length === 0 && canReadComments" class="state-card">
				<view class="state-title">加载中...</view>
				<view class="state-subtitle">正在获取课程评论。</view>
			</view>

			<view v-else-if="canReadComments && comments.length === 0" class="state-card">
				<view class="state-title">还没有评论</view>
				<view class="state-subtitle">这门课的评论区已创建，第一条匿名评论会出现在这里。</view>
			</view>

			<view v-if="comments.length > 0" class="comment-list">
				<view v-for="item in comments" :key="item.id" class="comment-card">
					<view class="comment-header">
						<view>
							<view class="comment-author">{{ item.display_name }}</view>
							<view v-if="sessionInfo.is_admin && item.author_user_id" class="comment-admin-meta">
								学号：{{ item.author_user_id }}
							</view>
						</view>
						<view class="comment-time">{{ item.created_at }}</view>
					</view>
					<view class="comment-content">{{ item.content }}</view>
					<view class="comment-footer">
						<view class="action-chip" :class="{ active: item.liked_by_me }" @click="toggleLike(item)">
							赞 {{ item.like_count || 0 }}
						</view>
						<view v-if="item.can_delete" class="action-chip warn" @click="deleteComment(item)">
							撤回
						</view>
						<view v-else-if="sessionInfo.is_admin" class="action-chip warn" @click="deleteComment(item)">
							删除
						</view>
						<text v-if="item.can_delete" class="deadline-text">可撤回至 {{ formatDeadline(item.delete_deadline_at) }}</text>
					</view>
				</view>
			</view>

			<view v-if="canLoadMore" class="load-more" @click="loadComments(false)">加载更多</view>
		</scroll-view>
	</view>
</template>

<script setup>
import { computed, ref } from 'vue'
import { adminDeleteCourseComment, createCourseComment, deleteCourseComment, getCourseCommentList, toggleCourseCommentLike } from '@/api/courseComments.js'
import { getFeedbackSession } from '@/api/feedback.js'
import { getErrorMessage, isAuthRequiredError } from '@/utils/http.js'

const courseName = ref('')
const teacherName = ref('')
const loading = ref(false)
const submitting = ref(false)
const sessionInfo = ref({
	authenticated: false,
	is_admin: false,
	user_id: ''
})
const thread = ref({
	id: null,
	comment_count: 0,
	like_count: 0
})
const comments = ref([])
const page = ref(1)
const pageSize = 20
const total = ref(0)
const draft = ref('')
const initialized = ref(false)
const accessState = ref({
	title: '',
	message: ''
})

const draftLength = computed(() => `${draft.value}`.trim().length)
const canReadComments = computed(() => !!courseName.value && !!teacherName.value && accessState.value.title === '')
const canShowComposer = computed(() => canReadComments.value && sessionInfo.value.authenticated)
const canLoadMore = computed(() => canReadComments.value && comments.value.length < total.value && !loading.value)
const showAccessState = computed(() => !loading.value && !!accessState.value.title)

const goBack = () => {
	uni.navigateBack()
}

const goLogin = () => {
	uni.navigateTo({
		url: '/pages/login/login'
	})
}

const formatDeadline = (value) => {
	if (!value) return ''
	const parts = `${value}`.split(' ')
	return parts[1] || value
}

const updateAccessState = (title = '', message = '') => {
	accessState.value = {
		title,
		message
	}
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

const loadComments = async (reset = true) => {
	if (!courseName.value || !teacherName.value) {
		updateAccessState('参数不完整', '课程名或教师名缺失，无法进入评论区。')
		return
	}

	const nextPage = reset ? 1 : (page.value + 1)
	loading.value = true
	try {
		const response = await getCourseCommentList({
			course_name: courseName.value,
			teacher_name: teacherName.value,
			page: nextPage,
			page_size: pageSize
		})
		thread.value = response.data.thread || thread.value
		total.value = Number(response.data.total || 0)
		const nextList = Array.isArray(response.data.list) ? response.data.list : []
		comments.value = reset ? nextList : [...comments.value, ...nextList]
		page.value = nextPage
		updateAccessState('', '')
	} catch (error) {
		comments.value = reset ? [] : comments.value
		total.value = reset ? 0 : total.value
		if (isAuthRequiredError(error)) {
			updateAccessState('签名已失效', '课程评论需要有效签名。请在白天重新认证后再查看。')
		} else if (Number(error?.code) === 403) {
			updateAccessState('无权查看', getErrorMessage(error, '当前账号课表中未找到这门课，无法查看评论。'))
		} else {
			updateAccessState('读取失败', getErrorMessage(error, '课程评论加载失败。'))
		}
	} finally {
		loading.value = false
	}
}

const submitComment = async () => {
	if (submitting.value) return
	if (!canShowComposer.value) {
		uni.showToast({
			title: '当前无法发布评论',
			icon: 'none'
		})
		return
	}

	const content = `${draft.value}`.trim()
	if (!content) {
		uni.showToast({
			title: '评论内容不能为空',
			icon: 'none'
		})
		return
	}

	submitting.value = true
	try {
		const response = await createCourseComment({
			course_name: courseName.value,
			teacher_name: teacherName.value,
			content
		})
		thread.value = response.data.thread || thread.value
		if (response.data.comment) {
			comments.value = [response.data.comment, ...comments.value]
			total.value += 1
		}
		draft.value = ''
		uni.showToast({
			title: '评论已发布',
			icon: 'success'
		})
	} catch (error) {
		uni.showToast({
			title: getErrorMessage(error, '发布失败'),
			icon: 'none'
		})
	} finally {
		submitting.value = false
	}
}

const toggleLike = async (item) => {
	if (!sessionInfo.value.authenticated) {
		uni.showToast({
			title: '请先重新认证',
			icon: 'none'
		})
		return
	}

	try {
		const response = await toggleCourseCommentLike(item.id)
		item.liked_by_me = !!response.data.liked
		item.like_count = Number(response.data.like_count || 0)
		thread.value = {
			...thread.value,
			like_count: Number(response.data.thread_like_count || thread.value.like_count || 0)
		}
	} catch (error) {
		uni.showToast({
			title: getErrorMessage(error, '点赞失败'),
			icon: 'none'
		})
	}
}

const deleteComment = async (item) => {
	const isAdminDelete = sessionInfo.value.is_admin && !item.can_delete
	const actionText = isAdminDelete ? '删除' : '撤回'
	uni.showModal({
		title: `${actionText}评论`,
		content: `确认${actionText}这条评论吗？`,
		success: async (res) => {
			if (!res.confirm) return
			try {
				const response = isAdminDelete
					? await adminDeleteCourseComment(item.id)
					: await deleteCourseComment(item.id)
				comments.value = comments.value.filter(comment => comment.id !== item.id)
				thread.value = response.data.thread || thread.value
				total.value = Math.max(0, total.value - 1)
				uni.showToast({
					title: `${actionText}成功`,
					icon: 'success'
				})
			} catch (error) {
				uni.showToast({
					title: getErrorMessage(error, `${actionText}失败`),
					icon: 'none'
				})
			}
		}
	})
}

onLoad(async (query) => {
	courseName.value = `${query?.courseName || ''}`.trim()
	teacherName.value = `${query?.teacherName || ''}`.trim()
	await loadSession()
	await loadComments(true)
	initialized.value = true
})

onShow(async () => {
	if (!initialized.value || !courseName.value || !teacherName.value) return
	await loadSession()
	await loadComments(true)
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
.composer-card,
.state-card,
.comment-card {
	background: rgba(255, 255, 255, 0.92);
	border-radius: 28rpx;
	box-shadow: 0 14rpx 34rpx rgba(15, 23, 42, 0.06);
}

.hero-card,
.composer-card,
.state-card,
.comment-card {
	padding: 28rpx;
}

.hero-card,
.composer-card,
.state-card {
	margin-bottom: 18rpx;
}

.hero-top,
.comment-header,
.comment-footer,
.composer-footer {
	display: flex;
	justify-content: space-between;
	gap: 16rpx;
}

.hero-copy {
	flex: 1;
}

.hero-title,
.composer-title,
.state-title,
.comment-author {
	font-size: 32rpx;
	font-weight: 700;
	color: #0f172a;
}

.hero-subtitle,
.state-subtitle,
.comment-admin-meta {
	margin-top: 10rpx;
	font-size: 22rpx;
	line-height: 1.6;
	color: #64748b;
}

.hero-badge {
	padding: 10rpx 18rpx;
	height: 38rpx;
	border-radius: 999rpx;
	background: rgba(34, 197, 94, 0.14);
	color: #166534;
	font-size: 22rpx;
	font-weight: 700;
}

.hero-badge.warn {
	background: rgba(249, 115, 22, 0.14);
	color: #c2410c;
}

.hero-meta {
	margin-top: 18rpx;
	display: flex;
	flex-wrap: wrap;
	gap: 10rpx 18rpx;
	font-size: 22rpx;
	color: #64748b;
}

.composer-textarea {
	width: 100%;
	height: 220rpx;
	margin-top: 20rpx;
	padding: 22rpx 24rpx;
	border-radius: 24rpx;
	background: #f8fafc;
	box-sizing: border-box;
	font-size: 26rpx;
	line-height: 1.7;
	color: #0f172a;
}

.textarea-placeholder {
	color: #94a3b8;
}

.composer-footer {
	align-items: center;
	margin-top: 18rpx;
	font-size: 22rpx;
	color: #64748b;
}

.composer-btn,
.state-action,
.load-more {
	height: 76rpx;
	padding: 0 28rpx;
	border-radius: 999rpx;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	background: linear-gradient(135deg, #2563eb 0%, #0ea5e9 100%);
	color: #fff;
	font-size: 26rpx;
	font-weight: 700;
}

.composer-btn.disabled {
	background: #cbd5e1;
}

.state-card {
	text-align: center;
}

.state-subtitle {
	margin-top: 12rpx;
}

.state-action {
	margin-top: 18rpx;
}

.comment-list {
	display: flex;
	flex-direction: column;
	gap: 16rpx;
}

.comment-time {
	font-size: 22rpx;
	color: #94a3b8;
	text-align: right;
}

.comment-content {
	margin-top: 18rpx;
	font-size: 26rpx;
	line-height: 1.75;
	color: #334155;
	word-break: break-word;
}

.comment-footer {
	align-items: center;
	flex-wrap: wrap;
	margin-top: 18rpx;
}

.action-chip {
	height: 60rpx;
	padding: 0 22rpx;
	border-radius: 999rpx;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	background: rgba(226, 232, 240, 0.56);
	font-size: 22rpx;
	font-weight: 700;
	color: #475569;
}

.action-chip.active {
	background: rgba(37, 99, 235, 0.14);
	color: #1d4ed8;
}

.action-chip.warn {
	background: rgba(254, 242, 242, 0.92);
	color: #dc2626;
}

.deadline-text {
	font-size: 22rpx;
	color: #94a3b8;
}

.load-more {
	width: 100%;
	margin: 20rpx auto 0;
}
</style>
