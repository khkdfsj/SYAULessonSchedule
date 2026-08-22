<template>
	<view class="debug-page">
		<view class="header">
			<view class="header-btn" @click="goBack">
				<uni-icons type="left" size="22" color="#111827"></uni-icons>
			</view>
			<view class="header-title">用户调试</view>
			<view class="header-space"></view>
		</view>

		<view class="content">
			<view v-if="loadingSession" class="state-card">正在确认管理员身份…</view>
			<view v-else-if="!isAdmin" class="state-card warn">
				<view class="state-title">当前账号没有管理员权限</view>
				<view class="state-text">请返回课表并使用管理员身份重新进入。</view>
			</view>
			<view v-else class="form-card">
				<view class="form-title">切换测试身份</view>
				<view class="form-description">输入需要排查的学号。切换后会清空本机课表缓存，并重新加载该用户的课表和互动数据。</view>
				<input
					v-model="targetUserId"
					class="user-input"
					type="number"
					maxlength="12"
					placeholder="请输入学号"
					:disabled="submitting"
				/>
				<button class="start-btn" :disabled="submitting || !targetUserId" @click="confirmStart">
					{{ submitting ? '正在切换…' : '进入调试模式' }}
				</button>
				<view class="notice">
					<view>调试会话有效期为2小时。</view>
					<view>调试期间的反馈、评论和课程群操作均按测试学号执行。</view>
					<view>可在课表页或设置页随时退出并恢复管理员本人身份。</view>
				</view>
			</view>
		</view>
	</view>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { getFeedbackSession, startAdminDebug } from '@/api/feedback.js'
import { getErrorMessage } from '@/utils/http.js'
import { enterAdminDebugSession } from '@/utils/auth.js'

const loadingSession = ref(true)
const isAdmin = ref(false)
const targetUserId = ref('')
const submitting = ref(false)

const goBack = () => uni.navigateBack()

const loadSession = async () => {
	loadingSession.value = true
	try {
		const response = await getFeedbackSession()
		isAdmin.value = response?.data?.authenticated === true && response?.data?.is_admin === true
	} catch (error) {
		isAdmin.value = false
	} finally {
		loadingSession.value = false
	}
}

const startDebug = async () => {
	submitting.value = true
	uni.showLoading({ title: '正在切换身份', mask: true })
	try {
		const response = await startAdminDebug(targetUserId.value.trim())
		const debugState = enterAdminDebugSession(response?.data?.session)
		if (!debugState) throw new Error('调试身份保存失败')
		uni.hideLoading()
		uni.reLaunch({ url: `/pages/index/index?debug=${Date.now()}` })
	} catch (error) {
		uni.hideLoading()
		uni.showToast({
			title: getErrorMessage(error, '切换失败，请稍后重试'),
			icon: 'none',
			duration: 2800
		})
	} finally {
		submitting.value = false
	}
}

const confirmStart = () => {
	const normalized = targetUserId.value.trim()
	if (!/^\d{8,12}$/.test(normalized)) {
		uni.showToast({ title: '请输入正确的学号', icon: 'none' })
		return
	}
	uni.showModal({
		title: '进入调试模式',
		content: `确认清空当前缓存并切换到学号 ${normalized} 吗？`,
		confirmText: '确认切换',
		cancelText: '取消',
		success: (result) => {
			if (result.confirm) startDebug()
		}
	})
}

onMounted(loadSession)
</script>

<style lang="scss" scoped>
.debug-page {
	min-height: 100vh;
	background: linear-gradient(180deg, #f4f7fb 0%, #eef3f9 100%);
}

.header {
	height: calc(env(safe-area-inset-top) + 92rpx);
	padding: env(safe-area-inset-top) 24rpx 0;
	display: flex;
	align-items: center;
	justify-content: space-between;
	box-sizing: border-box;
}

.header-btn,
.header-space {
	width: 76rpx;
	height: 64rpx;
	display: flex;
	align-items: center;
	justify-content: center;
}

.header-title {
	font-size: 34rpx;
	font-weight: 700;
	color: #111827;
}

.content {
	padding: 12rpx 24rpx calc(env(safe-area-inset-bottom) + 36rpx);
}

.state-card,
.form-card {
	padding: 30rpx;
	border-radius: 28rpx;
	background: rgba(255, 255, 255, 0.94);
	box-shadow: 0 14rpx 34rpx rgba(15, 23, 42, 0.06);
	font-size: 26rpx;
	color: #64748b;
}

.state-card.warn {
	color: #b45309;
}

.state-title,
.form-title {
	font-size: 32rpx;
	font-weight: 700;
	color: #0f172a;
}

.state-text,
.form-description {
	margin-top: 14rpx;
	font-size: 24rpx;
	line-height: 1.65;
	color: #64748b;
}

.user-input {
	height: 92rpx;
	margin-top: 30rpx;
	padding: 0 24rpx;
	box-sizing: border-box;
	border: 1rpx solid #cbd5e1;
	border-radius: 18rpx;
	background: #f8fafc;
	font-size: 30rpx;
	color: #0f172a;
}

.start-btn {
	height: 88rpx;
	margin-top: 22rpx;
	border: none;
	border-radius: 18rpx;
	background: linear-gradient(135deg, #2563eb, #0ea5e9);
	color: #fff;
	font-size: 28rpx;
	font-weight: 700;
	line-height: 88rpx;
}

.start-btn[disabled] {
	opacity: 0.55;
}

.notice {
	margin-top: 28rpx;
	padding: 22rpx 24rpx;
	border-radius: 18rpx;
	background: #eff6ff;
	font-size: 22rpx;
	line-height: 1.8;
	color: #1e40af;
}
</style>
