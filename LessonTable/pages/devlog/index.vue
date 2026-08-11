<template>
	<view class="page">
		<view class="nav">
			<view class="nav-btn" @click="goBack">
				<uni-icons type="left" size="22" color="#111827"></uni-icons>
			</view>
			<view class="nav-title">开发日志</view>
			<view class="nav-placeholder"></view>
		</view>

		<scroll-view scroll-y class="page-scroll">
			<view class="hero">
				<view class="hero-title">当前版本 {{ APP_VERSION }}</view>
				<view class="hero-subtitle">记录每次对课表、设置与反馈系统的更新。</view>
			</view>

			<view v-if="isAdmin" class="announcement-entry" @click="goAnnouncementAdmin">
				<view class="announcement-entry-title">公告推送管理</view>
				<uni-icons type="right" size="20" color="#64748b"></uni-icons>
			</view>

			<view v-for="entry in DEVLOG_ENTRIES" :key="entry.version" class="log-card">
				<view class="log-header">
					<view>
						<view class="log-version">v{{ entry.version }}</view>
						<view class="log-date">{{ entry.date }}</view>
					</view>
					<view v-if="entry.version === latestVersion" class="log-badge">最新</view>
				</view>
				<view class="log-title">{{ entry.title }}</view>
				<view class="log-list">
					<view v-for="item in entry.items" :key="item" class="log-item">{{ item }}</view>
				</view>
				<view v-if="entry.signature" class="log-signature">{{ entry.signature }}</view>
			</view>
		</scroll-view>
	</view>
</template>

<script setup>
import { ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { getFeedbackSession } from '@/api/feedback.js'
import { DEVLOG_ENTRIES } from '@/data/devlog.js'
import { APP_VERSION } from '@/utils/app.js'

const isAdmin = ref(false)

// 将版本号拆成数字逐位比较，日志顺序调整后“最新”标签仍能自动指向最高版本。
const compareVersions = (leftVersion, rightVersion) => {
	const leftParts = String(leftVersion || '').split('.').map(part => Number.parseInt(part, 10) || 0)
	const rightParts = String(rightVersion || '').split('.').map(part => Number.parseInt(part, 10) || 0)
	const maxLength = Math.max(leftParts.length, rightParts.length)

	for (let index = 0; index < maxLength; index += 1) {
		const difference = (leftParts[index] || 0) - (rightParts[index] || 0)
		if (difference !== 0) return difference
	}

	return 0
}

const latestVersion = DEVLOG_ENTRIES.reduce((latest, entry) => {
	return compareVersions(entry.version, latest) > 0 ? entry.version : latest
}, '')

const goBack = () => {
	uni.navigateBack()
}

const goAnnouncementAdmin = () => {
	if (typeof window !== 'undefined') {
		window.location.href = '/LessonSchedule/announcement-admin.html?v=20260812-7'
	}
}

onLoad(async () => {
	try {
		const response = await getFeedbackSession()
		isAdmin.value = response?.data?.is_admin === true
	} catch (_) {
		isAdmin.value = false
	}
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
.log-card {
	background: rgba(255, 255, 255, 0.92);
	border-radius: 28rpx;
	box-shadow: 0 14rpx 34rpx rgba(15, 23, 42, 0.06);
}

.announcement-entry {
	margin-top: 18rpx;
	padding: 26rpx 28rpx;
	display: flex;
	align-items: center;
	justify-content: space-between;
	background: rgba(255, 255, 255, 0.92);
	border-radius: 28rpx;
	box-shadow: 0 14rpx 34rpx rgba(15, 23, 42, 0.06);
}

.announcement-entry-title {
	font-size: 28rpx;
	font-weight: 700;
	color: #1e293b;
}

.hero {
	padding: 28rpx;
}

.hero-title {
	font-size: 38rpx;
	font-weight: 700;
	color: #0f172a;
}

.hero-subtitle {
	margin-top: 12rpx;
	font-size: 24rpx;
	line-height: 1.7;
	color: #64748b;
}

.log-card {
	margin-top: 18rpx;
	padding: 28rpx;
}

.log-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.log-version {
	font-size: 34rpx;
	font-weight: 700;
	color: #0f172a;
}

.log-date {
	margin-top: 8rpx;
	font-size: 22rpx;
	color: #64748b;
}

.log-badge {
	padding: 8rpx 16rpx;
	border-radius: 999rpx;
	background: rgba(37, 99, 235, 0.12);
	color: #1d4ed8;
	font-size: 22rpx;
	font-weight: 700;
}

.log-title {
	margin-top: 18rpx;
	font-size: 28rpx;
	font-weight: 700;
	color: #1e293b;
}

.log-list {
	margin-top: 18rpx;
	display: flex;
	flex-direction: column;
	gap: 14rpx;
}

.log-item {
	padding: 18rpx 20rpx;
	border-radius: 22rpx;
	background: #f8fafc;
	font-size: 24rpx;
	line-height: 1.7;
	color: #475569;
}

.log-signature {
	margin-top: 22rpx;
	text-align: right;
	font-size: 24rpx;
	font-weight: 600;
	letter-spacing: 2rpx;
	color: #64748b;
}
</style>
