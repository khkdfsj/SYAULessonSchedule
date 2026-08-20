<template>
	<view class="page">
		<view class="nav">
			<view class="nav-btn" @click="goBack"><uni-icons type="left" size="22" color="#111827"></uni-icons></view>
			<view class="nav-title">{{ pageTitle }}</view>
			<view class="nav-placeholder"></view>
		</view>

		<scroll-view scroll-y class="page-scroll">
			<view class="visibility-note">{{ pageSubtitle }}</view>
			<view class="form-card">
				<view class="field-block">
					<view class="field-head"><view class="field-label">{{ titleLabel }}</view><view class="field-count">{{ form.title.length }} / 120</view></view>
					<input v-model="form.title" class="field-input" :placeholder="titlePlaceholder" maxlength="120" />
				</view>
				<view class="field-divider"></view>
				<view class="field-block content-field">
					<view class="field-head">
						<view class="field-label">{{ contentLabel }}</view>
						<view class="template-link" @click="applyTemplate">插入填写模板</view>
					</view>
					<textarea v-model="form.content" class="field-textarea" :placeholder="contentPlaceholder" maxlength="4000" />
					<view class="field-count textarea-count">{{ form.content.length }} / 4000</view>
				</view>
			</view>
		</scroll-view>

		<view class="bottom-action-wrap">
			<view class="bottom-action" :class="{ checking: authState === 'checking', warn: authState === 'expired', retry: authState === 'unavailable', disabled: submitting }" @click="handleSubmitAction">
				{{ submitButtonText }}
			</view>
		</view>
	</view>
</template>

<script setup>
import { computed, ref } from 'vue'
import { createFeedbackThread, getFeedbackSession } from '@/api/feedback.js'
import { getErrorMessage, isAuthRequiredError } from '@/utils/http.js'
import { isEnterpriseAuthOfflineTime, startReauthentication } from '@/utils/auth.js'

const type = ref('issue')
const submitting = ref(false)
const authState = ref('checking')
const form = ref({ title: '', content: '' })

const templateMap = {
	issue: '【问题现象】\n请描述你看到的异常现象。\n\n【操作过程】\n请写明出现问题前进行的操作。\n\n【设备信息】\n请填写机型、系统版本及企业微信版本。\n\n【补充说明】\n请补充出现时间或其他线索。',
	suggestion: '【目前的问题】\n请说明哪里不够方便。\n\n【希望如何改进】\n请描述你期待的功能或调整。\n\n【预期效果】\n请说明改进后能够解决什么问题。'
}

const pageTitle = computed(() => type.value === 'issue' ? '提交问题' : '提交建议')
const pageSubtitle = computed(() => type.value === 'issue' ? '问题内容仅你本人和管理员可见' : '建议将公开展示，其他同学可以点赞和回复')
const titleLabel = computed(() => type.value === 'issue' ? '问题标题' : '建议标题')
const contentLabel = computed(() => type.value === 'issue' ? '问题描述' : '建议内容')
const titlePlaceholder = computed(() => type.value === 'issue' ? '简要描述遇到的问题' : '用一句话概括你的建议')
const contentPlaceholder = computed(() => type.value === 'issue' ? '请说明异常现象、操作过程和设备信息' : '请说明目前的问题、希望如何改进及预期效果')
const submitButtonText = computed(() => {
	if (submitting.value) return '正在提交…'
	if (authState.value === 'checking') return '正在验证身份…'
	if (authState.value === 'expired') return '认证已失效，点击重新认证'
	if (authState.value === 'unavailable') return '暂时无法验证，点击重试'
	return type.value === 'issue' ? '提交问题' : '提交建议'
})

const goBack = () => uni.navigateBack()
const applyTemplate = () => {
	if (form.value.content.trim()) {
		uni.showModal({
			title: '插入填写模板',
			content: '插入模板会替换当前已填写的内容，是否继续？',
			confirmText: '继续',
			cancelText: '取消',
			success: (res) => { if (res.confirm) form.value.content = templateMap[type.value] }
		})
		return
	}
	form.value.content = templateMap[type.value]
}
const showOfflineTimeNotice = () => uni.showModal({
	title: '当前无法重新认证',
	content: '22:00至次日06:00认证服务暂停，请在白天重新认证。',
	showCancel: false,
	confirmText: '知道了'
})
const loadSession = async () => {
	authState.value = 'checking'
	try {
		const response = await getFeedbackSession()
		authState.value = response?.data?.authenticated ? 'authenticated' : 'expired'
	} catch (error) {
		authState.value = isAuthRequiredError(error) ? 'expired' : 'unavailable'
	}
}
const handleSubmitAction = () => {
	if (submitting.value || authState.value === 'checking') return
	if (authState.value === 'unavailable') return loadSession()
	if (authState.value === 'expired') {
		if (isEnterpriseAuthOfflineTime()) return showOfflineTimeNotice()
		startReauthentication()
		return
	}
	submitForm()
}
const submitForm = async () => {
	if (!form.value.title.trim() || !form.value.content.trim()) {
		uni.showToast({ title: '请完整填写标题和内容', icon: 'none' })
		return
	}
	submitting.value = true
	try {
		const response = await createFeedbackThread({
			type: type.value,
			title: form.value.title.trim(),
			content: form.value.content.trim(),
			template_key: `${type.value}_default`
		})
		uni.showToast({ title: '提交成功', icon: 'success' })
		setTimeout(() => uni.redirectTo({ url: `/pages/feedback-detail/index?threadId=${response.data.thread_id}` }), 180)
	} catch (error) {
		if (isAuthRequiredError(error)) authState.value = 'expired'
		uni.showToast({ title: isAuthRequiredError(error) ? '认证已失效，请重新认证。' : getErrorMessage(error, '提交失败'), icon: 'none' })
	} finally {
		submitting.value = false
	}
}

onLoad((query) => {
	if (query?.type === 'suggestion') type.value = 'suggestion'
})
onShow(loadSession)
</script>

<style lang="scss" scoped>
.page { min-height: 100vh; background: linear-gradient(180deg, #f4f7fb 0%, #eef3f9 100%); }
.nav { height: calc(env(safe-area-inset-top) + 92rpx); padding: env(safe-area-inset-top) 28rpx 0; display: flex; align-items: center; justify-content: space-between; box-sizing: border-box; }
.nav-btn, .nav-placeholder { width: 64rpx; height: 64rpx; display: flex; align-items: center; justify-content: center; }
.nav-title { font-size: 34rpx; font-weight: 700; color: #111827; }
.page-scroll { height: calc(100vh - env(safe-area-inset-top) - 92rpx); padding: 0 24rpx calc(env(safe-area-inset-bottom) + 150rpx); box-sizing: border-box; }
.visibility-note { padding: 8rpx 6rpx 20rpx; font-size: 23rpx; line-height: 1.6; color: #64748b; }
.form-card { padding: 8rpx 28rpx 24rpx; border-radius: 26rpx; background: rgba(255, 255, 255, 0.94); box-shadow: 0 12rpx 30rpx rgba(15, 23, 42, 0.055); }
.field-block { padding: 24rpx 0 22rpx; }
.field-head { display: flex; align-items: center; justify-content: space-between; gap: 20rpx; }
.field-label { font-size: 28rpx; font-weight: 700; color: #0f172a; }
.field-count { font-size: 20rpx; color: #94a3b8; }
.field-input, .field-textarea { width: 100%; margin-top: 16rpx; padding: 20rpx 22rpx; border-radius: 18rpx; background: #f8fafc; box-sizing: border-box; font-size: 27rpx; color: #0f172a; }
.field-input { height: 82rpx; }
.field-divider { height: 1rpx; background: rgba(226, 232, 240, 0.9); }
.content-field { padding-bottom: 0; }
.template-link { font-size: 23rpx; font-weight: 600; color: #2563eb; }
.field-textarea { min-height: 440rpx; line-height: 1.7; }
.textarea-count { margin-top: 10rpx; text-align: right; }
.bottom-action-wrap { position: fixed; left: 0; right: 0; bottom: 0; z-index: 20; padding: 18rpx 24rpx calc(env(safe-area-inset-bottom) + 18rpx); background: linear-gradient(180deg, rgba(238, 243, 249, 0), rgba(238, 243, 249, 0.96) 28%); box-sizing: border-box; }
.bottom-action { height: 88rpx; border-radius: 24rpx; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, #2563eb 0%, #0ea5e9 100%); color: #fff; font-size: 29rpx; font-weight: 700; box-shadow: 0 16rpx 36rpx rgba(37, 99, 235, 0.24); }
.bottom-action.checking, .bottom-action.disabled { background: #94a3b8; box-shadow: none; }
.bottom-action.warn { background: linear-gradient(135deg, #f97316 0%, #ea580c 100%); box-shadow: 0 16rpx 34rpx rgba(234, 88, 12, 0.2); }
.bottom-action.retry { background: #475569; box-shadow: 0 14rpx 30rpx rgba(71, 85, 105, 0.2); }
</style>
