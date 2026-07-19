<template>
	<view class="page">
		<view class="nav">
			<view class="nav-btn" @click="goBack">
				<uni-icons type="left" size="22" color="#111827"></uni-icons>
			</view>
			<view class="nav-title">{{ pageTitle }}</view>
			<view class="nav-placeholder"></view>
		</view>

		<scroll-view scroll-y class="page-scroll">
			<view class="hero">
				<view class="hero-title">{{ pageTitle }}</view>
				<view class="hero-subtitle">{{ pageSubtitle }}</view>
			</view>

			<view class="auth-banner" :class="{ warn: !sessionInfo.authenticated }">
				{{ authText }}
			</view>

			<view class="card">
				<view class="card-title">模板</view>
				<view class="template-content">{{ templateText }}</view>
				<view class="template-btn" @click="applyTemplate">套用模板</view>
			</view>

			<view class="card">
				<view class="field-label">标题</view>
				<input v-model="form.title" class="field-input" :placeholder="titlePlaceholder" maxlength="120" />
				<view class="field-label area-label">内容</view>
				<textarea
					v-model="form.content"
					class="field-textarea"
					:placeholder="contentPlaceholder"
					maxlength="4000"
				/>
			</view>

			<view class="tips-card">
				<view class="tips-title">提交说明</view>
				<text>1. 问题反馈仅你本人和管理员可见。</text>
				<text>2. 建议会进入公开广场，作者默认脱敏展示。</text>
				<text>3. 签名过期后只能浏览公开内容，提交需重新认证。</text>
			</view>

			<view class="submit-btn" :class="{ disabled: submitting || !sessionInfo.authenticated }" @click="submitForm">
				{{ submitting ? '提交中...' : '提交' }}
			</view>
		</scroll-view>
	</view>
</template>

<script setup>
import { computed, ref } from 'vue'
import { createFeedbackThread, getFeedbackSession } from '@/api/feedback.js'
import { getErrorMessage, isAuthRequiredError } from '@/utils/http.js'

const type = ref('issue')
const submitting = ref(false)
const sessionInfo = ref({
	authenticated: false,
	is_admin: false
})
const form = ref({
	title: '',
	content: ''
})

const templateMap = {
	issue: {
		title: '课表问题反馈',
		content: '【问题现象】\n请描述你看到的异常现象。\n\n【复现步骤】\n1. 打开页面\n2. 执行操作\n3. 出现问题\n\n【设备与环境】\n机型 / 微信版本 / 系统版本\n\n【补充说明】\n截图位置、时间或其他线索。',
		templateKey: 'issue_default'
	},
	suggestion: {
		title: '课表功能建议',
		content: '【当前痛点】\n当前使用过程中哪里不够顺手？\n\n【建议方案】\n你希望新增或调整什么能力？\n\n【预期效果】\n这个建议落地后，能解决什么问题？',
		templateKey: 'suggestion_default'
	}
}

const pageTitle = computed(() => type.value === 'issue' ? '提交问题' : '提交建议')
const pageSubtitle = computed(() => type.value === 'issue'
	? '问题单默认私密，仅你和管理员可见。'
	: '建议会进入公开广场，其他同学可以点赞评论。')
const titlePlaceholder = computed(() => type.value === 'issue' ? '例如：周数切换后课表空白' : '例如：希望增加课程提醒')
const contentPlaceholder = computed(() => type.value === 'issue' ? '请尽量写明现象、复现步骤和设备信息。' : '请尽量写明痛点、方案和预期效果。')
const templateText = computed(() => templateMap[type.value].content)
const authText = computed(() => sessionInfo.value.authenticated
	? '当前签名有效，可以直接提交。'
	: '当前签名无效，白天重新进入课表后再提交。')

const goBack = () => {
	uni.navigateBack()
}

const applyTemplate = () => {
	form.value.title = templateMap[type.value].title
	form.value.content = templateMap[type.value].content
}

const loadSession = async () => {
	try {
		const response = await getFeedbackSession()
		sessionInfo.value = {
			...sessionInfo.value,
			...response.data
		}
	} catch (error) {
		sessionInfo.value.authenticated = false
	}
}

const submitForm = async () => {
	if (submitting.value || !sessionInfo.value.authenticated) {
		if (!sessionInfo.value.authenticated) {
			uni.showModal({
				title: '无法提交',
				content: '当前签名已失效，请在白天重新从课表入口进入后再提交。',
				showCancel: false
			})
		}
		return
	}

	if (!form.value.title.trim() || !form.value.content.trim()) {
		uni.showToast({
			title: '请完整填写标题和内容',
			icon: 'none'
		})
		return
	}

	submitting.value = true
	try {
		const response = await createFeedbackThread({
			type: type.value,
			title: form.value.title.trim(),
			content: form.value.content.trim(),
			template_key: templateMap[type.value].templateKey
		})
		uni.showToast({
			title: '提交成功',
			icon: 'success'
		})
		setTimeout(() => {
			uni.redirectTo({
				url: `/pages/feedback-detail/index?threadId=${response.data.thread_id}`
			})
		}, 180)
	} catch (error) {
		const message = isAuthRequiredError(error) ? '签名已失效，请白天重新认证后再提交。' : getErrorMessage(error, '提交失败')
		uni.showToast({
			title: message,
			icon: 'none'
		})
	} finally {
		submitting.value = false
	}
}

onLoad((query) => {
	if (query?.type === 'suggestion') {
		type.value = 'suggestion'
	}
})

onShow(() => {
	loadSession()
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

.hero,
.card,
.tips-card,
.auth-banner {
	background: rgba(255, 255, 255, 0.92);
	border-radius: 28rpx;
	box-shadow: 0 14rpx 34rpx rgba(15, 23, 42, 0.06);
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
	line-height: 1.6;
	color: #64748b;
}

.auth-banner {
	margin-top: 18rpx;
	padding: 22rpx 24rpx;
	font-size: 24rpx;
	color: #166534;
	background: rgba(240, 253, 244, 0.95);
}

.auth-banner.warn {
	color: #c2410c;
	background: rgba(255, 247, 237, 0.96);
}

.card {
	margin-top: 18rpx;
	padding: 28rpx;
}

.card-title,
.field-label,
.tips-title {
	font-size: 28rpx;
	font-weight: 700;
	color: #0f172a;
}

.template-content {
	margin-top: 18rpx;
	font-size: 24rpx;
	line-height: 1.8;
	color: #475569;
	white-space: pre-wrap;
}

.template-btn {
	margin-top: 22rpx;
	height: 76rpx;
	border-radius: 20rpx;
	display: flex;
	align-items: center;
	justify-content: center;
	background: rgba(37, 99, 235, 0.1);
	color: #1d4ed8;
	font-size: 26rpx;
	font-weight: 700;
}

.field-input,
.field-textarea {
	width: 100%;
	margin-top: 18rpx;
	box-sizing: border-box;
	border-radius: 22rpx;
	background: #f8fafc;
	padding: 22rpx 24rpx;
	font-size: 28rpx;
	color: #0f172a;
}

.area-label {
	margin-top: 24rpx;
}

.field-textarea {
	min-height: 360rpx;
	line-height: 1.7;
}

.tips-card {
	margin-top: 18rpx;
	padding: 28rpx;
	display: flex;
	flex-direction: column;
	gap: 12rpx;
	font-size: 24rpx;
	line-height: 1.7;
	color: #64748b;
}

.submit-btn {
	margin-top: 24rpx;
	height: 88rpx;
	border-radius: 24rpx;
	display: flex;
	align-items: center;
	justify-content: center;
	background: linear-gradient(135deg, #2563eb 0%, #0ea5e9 100%);
	color: #fff;
	font-size: 30rpx;
	font-weight: 700;
	box-shadow: 0 18rpx 40rpx rgba(37, 99, 235, 0.24);
}

.submit-btn.disabled {
	background: #cbd5e1;
	box-shadow: none;
}
</style>
