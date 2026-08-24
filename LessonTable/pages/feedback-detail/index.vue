<template>
	<view class="page">
		<view class="nav">
			<view class="nav-btn" @click="goBack">
				<uni-icons type="left" size="22" color="#111827"></uni-icons>
			</view>
			<view class="nav-title">反馈详情</view>
			<view class="nav-placeholder"></view>
		</view>

		<scroll-view scroll-y class="page-scroll">
			<view v-if="loading" class="state-card">加载中...</view>
			<view v-else-if="errorText" class="state-card">
				<view class="state-title">{{ errorText }}</view>
			</view>
			<template v-else-if="thread">
				<view class="thread-card">
					<view class="thread-header">
						<view class="tag-row">
							<view class="type-tag" :class="{ suggestion: thread.type === 'suggestion' }">
								{{ thread.type === 'issue' ? '问题单' : '建议帖' }}
							</view>
							<view class="status-tag" :class="`status-${thread.status}`">
								{{ statusTextMap[thread.status] || thread.status }}
							</view>
						</view>
						<view class="thread-title">{{ thread.title }}</view>
						<view class="thread-meta">
							<text>{{ thread.author_display_name }}</text>
							<text>{{ thread.created_at }}</text>
						</view>
					</view>
					<view class="thread-content">{{ thread.content }}</view>
					<view class="thread-actions">
						<view class="thread-action like" :class="{ active: thread.liked_by_me }" @click="toggleLikeAction">
							{{ thread.liked_by_me ? '已点赞' : '点赞' }} · {{ thread.like_count }}
						</view>
						<view class="thread-action">
							回复 {{ thread.reply_count }}
						</view>
					</view>
				</view>

				<view v-if="sessionInfo.is_admin" class="admin-panel">
					<view class="admin-title">管理员操作</view>
					<view class="admin-status-row">
						<view
							v-for="item in adminStatuses"
							:key="item.value"
							class="admin-status-btn"
							:class="{ active: thread.status === item.value }"
							@click="updateThreadStatus(item.value)"
						>
							{{ item.label }}
						</view>
					</view>
				</view>

				<view class="reply-block">
					<view class="reply-title">回复列表</view>
					<view v-if="replies.length === 0" class="reply-empty">还没有回复。</view>
					<view v-else class="reply-list">
						<view v-for="reply in replies" :key="reply.id" class="reply-card">
							<view class="reply-header">
								<view class="reply-name-row">
									<text class="reply-name">{{ reply.display_name }}</text>
									<text v-if="reply.is_pinned" class="reply-pinned">置顶</text>
								</view>
								<text class="reply-time">{{ reply.created_at }}</text>
							</view>
							<view class="reply-content">{{ reply.content }}</view>
							<view v-if="sessionInfo.is_admin && reply.role === 'admin'" class="reply-admin-actions">
								<view class="reply-admin-action" @click="pinReply(reply)">
									{{ reply.is_pinned ? '取消置顶' : '设为置顶' }}
								</view>
								<view v-if="reply.can_delete" class="reply-admin-action delete" @click="deleteReplyAction(reply)">
									{{ deletingReplyId === reply.id ? '删除中...' : '删除回复' }}
								</view>
							</view>
						</view>
					</view>
				</view>

				<view class="composer-card">
					<view class="composer-title">{{ canReply ? '写回复' : '当前不可回复' }}</view>
					<textarea
						v-model="replyDraft"
						class="composer-area"
						placeholder="输入回复内容"
						maxlength="3000"
						:disabled="!canReply"
					/>
					<view class="composer-footer">
						<view
							v-if="sessionInfo.is_admin"
							class="composer-pin"
							:class="{ active: pinAfterSend }"
							@click="togglePinAfterSend"
						>
							发送后置顶
						</view>
						<view class="composer-submit" :class="{ disabled: !canReply || submitting }" @click="submitReply">
							{{ submitting ? '发送中...' : '发送回复' }}
						</view>
					</view>
				</view>
			</template>
		</scroll-view>
	</view>
</template>

<script setup>
import { computed, ref } from 'vue'
import {
	adminUpdateFeedback,
	createFeedbackReply,
	deleteFeedbackReply,
	getFeedbackSession,
	getFeedbackThreadDetail,
	toggleFeedbackLike
} from '@/api/feedback.js'
import { getErrorMessage, isAuthRequiredError } from '@/utils/http.js'

const statusTextMap = {
	open: '待处理',
	replied: '已回复',
	closed: '已关闭'
}

const adminStatuses = [{
	value: 'open',
	label: '待处理'
}, {
	value: 'replied',
	label: '已回复'
}, {
	value: 'closed',
	label: '已关闭'
}]

const threadId = ref(0)
const loading = ref(false)
const submitting = ref(false)
const deletingReplyId = ref(0)
const errorText = ref('')
const thread = ref(null)
const replies = ref([])
const replyDraft = ref('')
const pinAfterSend = ref(false)
const sessionInfo = ref({
	authenticated: false,
	is_admin: false
})

const canReply = computed(() => !!thread.value && thread.value.permissions?.can_reply !== false)

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

const loadDetail = async () => {
	if (!threadId.value) return
	loading.value = true
	errorText.value = ''
	try {
		const response = await getFeedbackThreadDetail(threadId.value)
		thread.value = {
			...response.data.thread,
			permissions: response.data.permissions || {}
		}
		replies.value = response.data.replies || []
	} catch (error) {
		errorText.value = getErrorMessage(error, '加载反馈详情失败')
		thread.value = null
		replies.value = []
	} finally {
		loading.value = false
	}
}

const toggleLikeAction = async () => {
	if (!thread.value || thread.value.type !== 'suggestion') return
	try {
		const response = await toggleFeedbackLike(thread.value.id)
		thread.value.liked_by_me = response.data.liked
		thread.value.like_count = response.data.like_count
	} catch (error) {
		const message = isAuthRequiredError(error) ? '点赞前请先重新认证。' : getErrorMessage(error, '点赞失败')
		uni.showToast({
			title: message,
			icon: 'none'
		})
	}
}

const submitReply = async () => {
	if (!thread.value || !canReply.value || submitting.value) return
	if (!replyDraft.value.trim()) {
		uni.showToast({
			title: '请输入回复内容',
			icon: 'none'
		})
		return
	}

	submitting.value = true
	try {
		await createFeedbackReply({
			thread_id: thread.value.id,
			content: replyDraft.value.trim(),
			pin: pinAfterSend.value
		})
		replyDraft.value = ''
		pinAfterSend.value = false
		await loadDetail()
		uni.showToast({
			title: '回复成功',
			icon: 'success'
		})
	} catch (error) {
		const message = isAuthRequiredError(error) ? '签名已失效，请白天重新认证后再回复。' : getErrorMessage(error, '回复失败')
		uni.showToast({
			title: message,
			icon: 'none'
		})
	} finally {
		submitting.value = false
	}
}

const togglePinAfterSend = () => {
	if (!sessionInfo.value.is_admin) return
	pinAfterSend.value = !pinAfterSend.value
}

const updateThreadStatus = async (status) => {
	if (!sessionInfo.value.is_admin || !thread.value) return
	try {
		await adminUpdateFeedback({
			thread_id: thread.value.id,
			status
		})
		await loadDetail()
	} catch (error) {
		uni.showToast({
			title: getErrorMessage(error, '更新状态失败'),
			icon: 'none'
		})
	}
}

const pinReply = async (reply) => {
	if (!sessionInfo.value.is_admin || !thread.value) return
	try {
		await adminUpdateFeedback({
			thread_id: thread.value.id,
			pinned_reply_id: reply.is_pinned ? 0 : reply.id
		})
		await loadDetail()
	} catch (error) {
		uni.showToast({
			title: getErrorMessage(error, '置顶失败'),
			icon: 'none'
		})
	}
}

const deleteReplyAction = (reply) => {
	if (!reply?.can_delete || deletingReplyId.value) return
	uni.showModal({
		title: '删除回复',
		content: '确定删除这条回复吗？删除后无法恢复。',
		confirmText: '删除',
		confirmColor: '#dc2626',
		cancelText: '取消',
		success: async (result) => {
			if (!result.confirm) return
			deletingReplyId.value = reply.id
			try {
				await deleteFeedbackReply(reply.id)
				await loadDetail()
				uni.showToast({ title: '回复已删除', icon: 'success' })
			} catch (error) {
				uni.showToast({ title: getErrorMessage(error, '删除失败'), icon: 'none' })
			} finally {
				deletingReplyId.value = 0
			}
		}
	})
}

onLoad((query) => {
	threadId.value = Number(query?.threadId || 0)
})

onShow(async () => {
	await loadSession()
	await loadDetail()
})
</script>

<style lang="scss" scoped>
.page {
	min-height: 100vh;
	background: linear-gradient(180deg, #f6f8fc 0%, #eef3f9 100%);
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

.thread-card,
.admin-panel,
.reply-block,
.composer-card,
.state-card {
	background: rgba(255, 255, 255, 0.92);
	border-radius: 28rpx;
	box-shadow: 0 14rpx 34rpx rgba(15, 23, 42, 0.06);
}

.state-card {
	padding: 40rpx 32rpx;
	text-align: center;
	font-size: 28rpx;
	color: #475569;
}

.state-title {
	font-size: 30rpx;
	font-weight: 700;
	color: #0f172a;
}

.thread-card {
	padding: 30rpx;
}

.tag-row {
	display: flex;
	gap: 12rpx;
}

.type-tag,
.status-tag,
.reply-pinned,
.reply-admin-action,
.composer-pin {
	padding: 8rpx 16rpx;
	border-radius: 999rpx;
	font-size: 22rpx;
	font-weight: 600;
}

.type-tag {
	background: rgba(245, 158, 11, 0.12);
	color: #b45309;
}

.type-tag.suggestion {
	background: rgba(37, 99, 235, 0.12);
	color: #1d4ed8;
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

.thread-title {
	margin-top: 18rpx;
	font-size: 34rpx;
	font-weight: 700;
	line-height: 1.5;
	color: #0f172a;
}

.thread-meta {
	margin-top: 14rpx;
	display: flex;
	flex-wrap: wrap;
	gap: 12rpx 20rpx;
	font-size: 22rpx;
	color: #64748b;
}

.thread-content {
	margin-top: 22rpx;
	font-size: 27rpx;
	line-height: 1.8;
	color: #334155;
	white-space: pre-wrap;
}

.thread-actions {
	margin-top: 24rpx;
	display: flex;
	gap: 16rpx;
}

.thread-action {
	padding: 14rpx 20rpx;
	border-radius: 20rpx;
	background: #f8fafc;
	font-size: 24rpx;
	color: #475569;
}

.thread-action.like.active {
	background: rgba(37, 99, 235, 0.14);
	color: #1d4ed8;
}

.admin-panel,
.reply-block,
.composer-card {
	margin-top: 18rpx;
	padding: 28rpx;
}

.admin-title,
.reply-title,
.composer-title {
	font-size: 28rpx;
	font-weight: 700;
	color: #0f172a;
}

.admin-status-row {
	margin-top: 18rpx;
	display: flex;
	gap: 14rpx;
	flex-wrap: wrap;
}

.admin-status-btn {
	padding: 14rpx 22rpx;
	border-radius: 20rpx;
	background: #f1f5f9;
	font-size: 24rpx;
	color: #475569;
}

.admin-status-btn.active {
	background: rgba(37, 99, 235, 0.14);
	color: #1d4ed8;
	font-weight: 700;
}

.reply-empty {
	margin-top: 18rpx;
	font-size: 24rpx;
	color: #64748b;
}

.reply-list {
	margin-top: 18rpx;
	display: flex;
	flex-direction: column;
	gap: 16rpx;
}

.reply-card {
	padding: 24rpx;
	border-radius: 24rpx;
	background: #f8fafc;
}

.reply-header {
	display: flex;
	justify-content: space-between;
	gap: 16rpx;
}

.reply-name-row {
	display: flex;
	align-items: center;
	gap: 10rpx;
}

.reply-name {
	font-size: 26rpx;
	font-weight: 700;
	color: #0f172a;
}

.reply-pinned {
	background: rgba(14, 165, 233, 0.12);
	color: #0369a1;
}

.reply-time {
	font-size: 22rpx;
	color: #94a3b8;
}

.reply-content {
	margin-top: 16rpx;
	font-size: 25rpx;
	line-height: 1.7;
	color: #334155;
	white-space: pre-wrap;
}

.reply-admin-action {
	display: inline-flex;
	background: rgba(37, 99, 235, 0.12);
	color: #1d4ed8;
}

.reply-admin-actions {
	margin-top: 16rpx;
	display: flex;
	gap: 12rpx;
	flex-wrap: wrap;
}

.reply-admin-action.delete {
	background: rgba(220, 38, 38, 0.1);
	color: #dc2626;
}

.composer-area {
	width: 100%;
	min-height: 220rpx;
	margin-top: 18rpx;
	padding: 22rpx 24rpx;
	border-radius: 24rpx;
	background: #f8fafc;
	box-sizing: border-box;
	font-size: 26rpx;
	line-height: 1.7;
	color: #0f172a;
}

.composer-footer {
	margin-top: 18rpx;
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 16rpx;
}

.composer-pin {
	background: #f1f5f9;
	color: #475569;
}

.composer-pin.active {
	background: rgba(37, 99, 235, 0.12);
	color: #1d4ed8;
}

.composer-submit {
	flex: 1;
	height: 82rpx;
	border-radius: 22rpx;
	display: flex;
	align-items: center;
	justify-content: center;
	background: linear-gradient(135deg, #2563eb 0%, #0ea5e9 100%);
	color: #fff;
	font-size: 28rpx;
	font-weight: 700;
}

.composer-submit.disabled {
	background: #cbd5e1;
}
</style>
