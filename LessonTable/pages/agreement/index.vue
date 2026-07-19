<template>
	<view class="page">
		<view class="nav">
			<view class="nav-btn" @click="goBack">
				<uni-icons type="left" size="22" color="#111827"></uni-icons>
			</view>
			<view class="nav-title">用户协议</view>
			<view class="nav-spacer"></view>
		</view>

		<view class="hero-card">
			<view class="hero-title">课表服务用户协议</view>
			<view class="hero-subtitle">登录视为您已阅读并同意本协议。</view>
		</view>

		<scroll-view scroll-y class="agreement-scroll">
			<view v-if="loading" class="state-card">协议加载中...</view>
			<view v-else-if="loadError" class="state-card error">{{ loadError }}</view>
			<view v-else class="article-card">
				<text class="article-text">{{ agreementText }}</text>
			</view>
		</scroll-view>
	</view>
</template>

<script setup>
import { ref } from 'vue'
import { getErrorMessage } from '@/utils/http.js'
import { loadLoginAgreementText } from '@/utils/legal.js'

const agreementText = ref('')
const loading = ref(false)
const loadError = ref('')

const loadAgreement = async () => {
	loading.value = true
	loadError.value = ''
	try {
		agreementText.value = await loadLoginAgreementText()
	} catch (error) {
		loadError.value = getErrorMessage(error, '用户协议加载失败，请稍后重试。')
	} finally {
		loading.value = false
	}
}

const goBack = () => {
	uni.navigateBack()
}

onLoad(() => {
	loadAgreement()
})
</script>

<style lang="scss" scoped>
.page {
	min-height: 100vh;
	background: linear-gradient(180deg, #f7f9fd 0%, #eef3fb 100%);
}

.nav {
	height: calc(env(safe-area-inset-top) + 92rpx);
	padding: env(safe-area-inset-top) 24rpx 0;
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
	background: rgba(255, 255, 255, 0.9);
	box-shadow: 0 10rpx 24rpx rgba(15, 23, 42, 0.06);
}

.nav-title {
	font-size: 34rpx;
	font-weight: 700;
	color: #111827;
}

.hero-card {
	margin: 0 24rpx 18rpx;
	padding: 28rpx;
	background: rgba(255, 255, 255, 0.92);
	border-radius: 30rpx;
	box-shadow: 0 14rpx 34rpx rgba(15, 23, 42, 0.06);
}

.hero-title {
	font-size: 36rpx;
	font-weight: 700;
	color: #0f172a;
}

.hero-subtitle {
	margin-top: 12rpx;
	font-size: 24rpx;
	line-height: 1.7;
	color: #64748b;
}

.agreement-scroll {
	height: calc(100vh - env(safe-area-inset-top) - 180rpx);
	padding: 0 24rpx calc(env(safe-area-inset-bottom) + 24rpx);
	box-sizing: border-box;
}

.article-card,
.state-card {
	background: rgba(255, 255, 255, 0.92);
	border-radius: 30rpx;
	box-shadow: 0 14rpx 34rpx rgba(15, 23, 42, 0.06);
	padding: 28rpx;
	margin-bottom: 24rpx;
}

.article-text,
.state-card {
	font-size: 25rpx;
	line-height: 1.85;
	color: #334155;
	white-space: pre-wrap;
	word-break: break-word;
}

.state-card.error {
	color: #dc2626;
}
</style>
