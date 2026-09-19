<template>
	<view class="page">
		<view class="hero">
			<view class="hero-nav">
				<view class="nav-btn" @click="goBack">
					<uni-icons type="left" size="20" color="#0f172a"></uni-icons>
				</view>
				<view class="hero-title">身份认证</view>
				<view class="nav-spacer"></view>
			</view>
			<view class="hero-copy">
				<view class="hero-heading">课表登录</view>
				<view class="hero-subtitle">企业微信优先自动登录，其他环境可手动登录。</view>
			</view>
		</view>

		<scroll-view scroll-y class="page-scroll">
			<view v-if="savedSummary.userId" class="saved-card">
				<view class="saved-title">本机已保存最近一次登录信息</view>
				<view class="saved-meta">
					<text>当前学号：{{ savedSummary.userId }}</text>
					<text v-if="savedSummary.profile?.full_name">姓名：{{ savedSummary.profile.full_name }}</text>
					<text>记住密码：{{ rememberPassword ? '已开启' : '未开启' }}</text>
				</view>
				<view class="saved-actions">
					<view class="ghost-btn secondary" @click="fillSavedCredentials">填入账号</view>
					<view class="ghost-btn primary" @click="enterCachedCourse">进入缓存课表</view>
				</view>
			</view>

			<view v-if="nightMode" class="card night-card">
				<view class="card-title">现在是夜间服务关闭时段</view>
				<view class="night-copy">
					22:00–次日 06:00 统一身份认证与学校实时课表服务暂停，暂时无法登录。
					如果你的课表已有缓存，可以点上方「进入缓存课表」直接查看；否则请在白天重新进入。
				</view>
			</view>

			<view v-if="!nightMode" class="card">
				<view class="card-title">统一身份认证</view>

				<view class="field-label">账号</view>
				<input
					v-model="account"
					class="input"
					type="text"
					placeholder="请输入统一身份认证账号 / 学号"
					placeholder-class="input-placeholder"
					maxlength="64"
				/>

				<view class="field-label">密码</view>
				<input
					v-model="password"
					class="input"
					type="password"
					password
					placeholder="请输入统一身份认证密码"
					placeholder-class="input-placeholder"
					maxlength="128"
				/>

				<view v-if="captchaToken" class="captcha-box">
					<view class="field-label">验证码</view>
					<image class="captcha-image" :src="captchaImageSrc" mode="aspectFit"></image>
					<input
						v-model="captchaCode"
						class="input"
						type="text"
						placeholder="请输入验证码"
						placeholder-class="input-placeholder"
						maxlength="16"
					/>
					<view class="captcha-actions">
						<view class="ghost-btn secondary" @click="submitLogin(false)">提交验证码</view>
						<view class="ghost-btn secondary" @click="submitLogin(true)">使用 OCR</view>
					</view>
				</view>

				<view class="remember-row" @click="toggleRememberPassword">
					<view class="remember-check" :class="{ active: rememberPassword }">
						<view v-if="rememberPassword" class="remember-check__dot"></view>
					</view>
					<view class="remember-text">记住密码</view>
				</view>

				<view class="agreement-section">
					<view class="agreement-row">
						<text class="agreement-copy">登录视为您已阅读并同意</text>
						<text class="agreement-link" @click="openAgreementPage">《课表服务用户协议》</text>
					</view>
				</view>

				<view class="submit-btn" :class="{ disabled: submitting }" @click="submitLogin(false)">
					{{ submitting ? '认证中...' : (captchaToken ? '继续认证' : '登录并获取课表') }}
				</view>
			</view>
		</scroll-view>
	</view>
</template>

<script setup>
import { computed, ref } from 'vue'
import { loginWithStudentProfile } from '@/api/auth.js'
import { getErrorMessage } from '@/utils/http.js'
import { isEnterpriseServiceOfflineTime } from '@/utils/dataSource.js'
import {
	clearComWxAutoAuthAttempt,
	getIdentitySummary,
	getManualLoginState,
	saveManualLoginState,
	setCurrentUserId
} from '@/utils/auth.js'

// 夜间（22:00–06:00）认证服务不可用：不展示登录表单，只提示用缓存进入
const nightMode = ref(isEnterpriseServiceOfflineTime())

const account = ref('')
const password = ref('')
const rememberPassword = ref(true)
const captchaToken = ref('')
const captchaCode = ref('')
const captchaImageBase64 = ref('')
const captchaImageMimeType = ref('image/jpeg')
const submitting = ref(false)

const savedSummary = ref(getIdentitySummary())

const captchaImageSrc = computed(() => {
	if (!captchaImageBase64.value) return ''
	return `data:${captchaImageMimeType.value};base64,${captchaImageBase64.value}`
})

const refreshSavedSummary = () => {
	const manualState = getManualLoginState()
	const summary = getIdentitySummary()
	savedSummary.value = {
		...summary,
		profile: manualState?.profile || summary.profile || null,
		account: manualState?.account || summary.account || ''
	}
	rememberPassword.value = manualState?.rememberPassword !== false
}

const fillSavedCredentials = () => {
	const manualState = getManualLoginState()
	if (!manualState) return
	account.value = manualState.account || manualState.userId || ''
	password.value = manualState.password || ''
	rememberPassword.value = manualState.rememberPassword !== false
}

const resetCaptchaState = () => {
	captchaToken.value = ''
	captchaCode.value = ''
	captchaImageBase64.value = ''
	captchaImageMimeType.value = 'image/jpeg'
}

const openAgreementPage = () => {
	uni.navigateTo({
		url: '/pages/agreement/index?scene=login'
	})
}

const goBack = () => {
	const pages = getCurrentPages()
	if (pages.length > 1) {
		uni.navigateBack()
		return
	}
	uni.reLaunch({
		url: '/pages/index/index'
	})
}

const toggleRememberPassword = () => {
	rememberPassword.value = !rememberPassword.value
}

const enterCachedCourse = () => {
	const manualState = getManualLoginState()
	const localCachedUserId = `${uni.getStorageSync('ScheduleData')?.UserID || ''}`.trim()
	// 身份来源放宽：手动登录记录 → 已保存身份 → 本机课表缓存里的学号
	const userId = manualState?.userId || savedSummary.value.userId || localCachedUserId
	if (!userId) {
		uni.showToast({
			title: '当前没有可用的缓存身份',
			icon: 'none'
		})
		return
	}

	setCurrentUserId(userId)
	uni.reLaunch({
		url: '/pages/index/index?cache_entry=1'
	})
}

const submitLogin = async (useOcr = false) => {
	if (submitting.value) return
	if (!account.value.trim() || !password.value) {
		uni.showToast({
			title: '请输入账号和密码',
			icon: 'none'
		})
		return
	}
	if (captchaToken.value && !useOcr && !captchaCode.value.trim()) {
		uni.showToast({
			title: '请输入验证码',
			icon: 'none'
		})
		return
	}

	submitting.value = true
	try {
		const response = await loginWithStudentProfile({
			account: account.value.trim(),
			password: password.value,
			captcha_token: captchaToken.value || undefined,
			captcha_code: useOcr ? undefined : (captchaCode.value.trim() || undefined),
			enable_ocr: useOcr ? true : undefined
		})

		saveManualLoginState({
			account: account.value.trim(),
			password: password.value,
			rememberPassword: rememberPassword.value,
			profile: response.data.profile || null,
			session: {
				user_id: response.data.user_id,
				auth_exp: response.data.auth_exp,
				auth_sig: response.data.auth_sig,
				authSource: 'manual'
			}
		})
		clearComWxAutoAuthAttempt()
		resetCaptchaState()
		refreshSavedSummary()

		uni.showToast({
			title: '登录成功',
			icon: 'success'
		})

		setTimeout(() => {
			uni.reLaunch({
				url: '/pages/index/index'
			})
		}, 180)
	} catch (error) {
		if (Number(error?.code) === 409 && error?.data?.requires_captcha) {
			captchaToken.value = `${error.data.captcha_token || ''}`
			captchaImageBase64.value = `${error.data.captcha_image_base64 || ''}`
			captchaImageMimeType.value = `${error.data.captcha_image_mime_type || 'image/jpeg'}`
			uni.showToast({
				title: getErrorMessage(error, '请输入验证码'),
				icon: 'none'
			})
		} else {
			uni.showToast({
				title: getErrorMessage(error, '登录失败'),
				icon: 'none'
			})
		}
	} finally {
		submitting.value = false
	}
}

onShow(() => {
	refreshSavedSummary()
	fillSavedCredentials()
})
</script>

<style lang="scss" scoped>
.page {
	min-height: 100vh;
	background:
		radial-gradient(circle at top left, rgba(191, 219, 254, 0.9) 0, rgba(191, 219, 254, 0) 48%),
		linear-gradient(180deg, #f7f9fd 0%, #edf2fb 100%);
}

.hero {
	padding: env(safe-area-inset-top) 24rpx 0;
}

.hero-nav {
	height: 88rpx;
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.nav-btn,
.nav-spacer {
	width: 64rpx;
	height: 64rpx;
	display: flex;
	align-items: center;
	justify-content: center;
}

.nav-btn {
	border-radius: 18rpx;
	background: rgba(255, 255, 255, 0.84);
	box-shadow: 0 10rpx 24rpx rgba(15, 23, 42, 0.06);
}

.hero-title {
	font-size: 34rpx;
	font-weight: 700;
	color: #0f172a;
}

.hero-copy {
	padding: 22rpx 6rpx 26rpx;
}

.hero-heading {
	font-size: 46rpx;
	font-weight: 800;
	color: #0f172a;
}

.hero-subtitle {
	margin-top: 12rpx;
	font-size: 24rpx;
	line-height: 1.6;
	color: #64748b;
}

.page-scroll {
	height: calc(100vh - env(safe-area-inset-top) - 110rpx);
	padding: 0 24rpx calc(env(safe-area-inset-bottom) + 34rpx);
	box-sizing: border-box;
}

.saved-card,
.card {
	background: rgba(255, 255, 255, 0.94);
	border-radius: 30rpx;
	box-shadow: 0 18rpx 40rpx rgba(15, 23, 42, 0.06);
	backdrop-filter: blur(18px);
	padding: 28rpx;
}

.saved-card,
.card {
	margin-bottom: 18rpx;
}

.night-card {
	background: rgba(239, 246, 255, 0.96);
	box-shadow: 0 18rpx 40rpx rgba(37, 99, 235, 0.08);
}

.night-copy {
	margin-top: 12rpx;
	font-size: 24rpx;
	line-height: 1.7;
	color: #475569;
}

.saved-title,
.card-title,
.agreement-heading,
.toggle-title {
	font-size: 30rpx;
	font-weight: 700;
	color: #0f172a;
}

.saved-meta {
	margin-top: 16rpx;
	display: flex;
	flex-direction: column;
	gap: 8rpx;
	font-size: 24rpx;
	line-height: 1.7;
	color: #64748b;
}

.saved-actions,
.captcha-actions {
	margin-top: 20rpx;
	display: flex;
	gap: 16rpx;
}

.field-label {
	margin-top: 20rpx;
	margin-bottom: 10rpx;
	font-size: 24rpx;
	font-weight: 600;
	color: #334155;
}

.input {
	height: 92rpx;
	padding: 0 24rpx;
	border-radius: 24rpx;
	background: #f8fafc;
	box-sizing: border-box;
	font-size: 28rpx;
	color: #0f172a;
	border: 1rpx solid rgba(203, 213, 225, 0.5);
}

.input-placeholder {
	color: #94a3b8;
}

.captcha-box {
	margin-top: 10rpx;
}

.captcha-image {
	width: 100%;
	height: 180rpx;
	margin-bottom: 14rpx;
	border-radius: 24rpx;
	background: #f8fafc;
}

.remember-row {
	margin-top: 24rpx;
	padding: 12rpx 0 10rpx;
	display: flex;
	align-items: center;
	gap: 12rpx;
	border-top: 1rpx solid rgba(226, 232, 240, 0.8);
}

.remember-check {
	width: 28rpx;
	height: 28rpx;
	border-radius: 8rpx;
	border: 2rpx solid rgba(203, 213, 225, 0.96);
	background: rgba(255, 255, 255, 0.96);
	box-sizing: border-box;
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
}

.remember-check.active {
	border-color: #3b5bdb;
	background: rgba(59, 91, 219, 0.12);
}

.remember-check__dot {
	width: 12rpx;
	height: 12rpx;
	border-radius: 999rpx;
	background: #3b5bdb;
}

.remember-text {
	font-size: 22rpx;
	line-height: 1.4;
	color: #475569;
}

.agreement-section {
	margin-top: 6rpx;
	padding-top: 14rpx;
	border-top: 1rpx solid rgba(226, 232, 240, 0.8);
}

.agreement-row {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 6rpx;
	font-size: 22rpx;
	line-height: 1.6;
}

.agreement-link {
	color: #1d4ed8;
	font-weight: 600;
}

.submit-btn,
.ghost-btn {
	height: 84rpx;
	border-radius: 24rpx;
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 28rpx;
	font-weight: 700;
}

.submit-btn {
	margin-top: 20rpx;
	background: linear-gradient(135deg, #2563eb 0%, #0ea5e9 100%);
	color: #fff;
}

.submit-btn.disabled {
	opacity: 0.72;
}

.ghost-btn {
	flex: 1;
}

.ghost-btn.secondary {
	background: #eef2f7;
	color: #475569;
}

.ghost-btn.primary {
	background: rgba(37, 99, 235, 0.14);
	color: #1d4ed8;
}

.agreement-copy {
	color: #64748b;
}
</style>
