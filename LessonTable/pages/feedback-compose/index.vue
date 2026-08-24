<template>
	<view class="page">
		<view class="nav">
			<view class="nav-btn" @click="goBack"><uni-icons type="left" size="22" color="#111827"></uni-icons></view>
			<view class="nav-title">{{ pageTitle }}</view>
			<view class="nav-placeholder"></view>
		</view>

		<scroll-view scroll-y class="page-scroll">
			<view class="visibility-note">{{ pageSubtitle }}</view>
			<view class="submission-guide">
				<view class="guide-title">{{ type === 'issue' ? '提交前请先完成自助排查' : '请完整说明你的建议' }}</view>
				<view v-if="type === 'issue'" class="guide-text">白天请先前往“设置 → 维护工具”，使用“清空缓存并重新认证”。重新进入课表后仍未解决，再按模板提交工单。</view>
				<view v-else class="guide-text">请按模板说明当前问题、改进方式和预期效果，便于准确理解和评估。</view>
			</view>
			<view class="form-card">
				<view class="field-block">
					<view class="field-head"><view class="field-label">{{ titleLabel }}</view><view class="field-count">{{ form.title.length }} / 120</view></view>
					<input v-model="form.title" class="field-input" :placeholder="titlePlaceholder" maxlength="120" />
				</view>
				<view class="field-divider"></view>
				<view class="field-block content-field">
					<view class="field-head">
						<view class="field-label">{{ contentLabel }}</view>
						<view class="template-link" @click="applyTemplate">重置填写模板</view>
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
	issue: '【异常现象】\n[请填写具体表现和页面提示]\n\n【发生时间】\n[请填写大致日期和时间]\n\n【操作过程】\n[请填写出现问题前进行的操作]\n\n【已尝试方法】\n[请说明是否已在白天清空缓存并重新认证，以及结果]\n\n【设备环境】\n[请填写手机型号、系统版本和企业微信版本]',
	suggestion: '【目前的问题】\n[请填写目前哪里不够方便]\n\n【希望如何改进】\n[请填写希望增加或调整的功能]\n\n【预期效果】\n[请填写改进后能够解决什么问题]'
}

const requiredSections = {
	issue: ['异常现象', '发生时间', '操作过程', '已尝试方法', '设备环境'],
	suggestion: ['目前的问题', '希望如何改进', '预期效果']
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
			title: '重置填写模板',
			content: '重置模板会替换当前已填写的内容，是否继续？',
			confirmText: '继续',
			cancelText: '取消',
			success: (res) => { if (res.confirm) form.value.content = templateMap[type.value] }
		})
		return
	}
	form.value.content = templateMap[type.value]
}
const isTemplateCompleted = () => {
	const content = form.value.content
	return requiredSections[type.value].every((section, index, sections) => {
		const startMark = `【${section}】`
		const start = content.indexOf(startMark)
		if (start < 0) return false
		const answerStart = start + startMark.length
		const nextMark = sections[index + 1] ? `【${sections[index + 1]}】` : ''
		const end = nextMark ? content.indexOf(nextMark, answerStart) : content.length
		if (end < 0) return false
		const answer = content.slice(answerStart, end).trim()
		return answer.length >= 4 && !answer.includes('[请填写')
	})
}
const showIncompleteFormNotice = (content) => uni.showModal({
	title: '请完整填写工单',
	content,
	showCancel: false,
	confirmText: '继续填写'
})
const showOfflineTimeNotice = () => uni.showModal({
	title: '当前无法重新认证',
	content: '22:00至次日06:00认证服务暂停，请在白天重新认证。',
	showCancel: false,
	confirmText: '知道了'
})
const showNightDataNotice = () => uni.showModal({
	title: '夜间数据提示',
	content: '22:00至次日06:00课表自动使用缓存，暂时无法获取最新数据。如课表信息不准确，请在白天重新进入课表并刷新后再提交反馈。',
	showCancel: false,
	confirmText: '知道了'
})
const showDaySelfHelpNotice = () => uni.showModal({
	title: '提交前请先自助排查',
	content: '多数课表显示异常可通过重新获取本地数据解决。请先前往“设置 → 维护工具”，点击“清空缓存并重新认证”，重新进入课表确认。仍未解决时，再按模板完整填写工单。',
	confirmText: '去设置',
	cancelText: '继续填写',
	success: (result) => {
		if (result.confirm) uni.navigateTo({ url: '/pages/settings/settings' })
	}
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
		showIncompleteFormNotice('请填写具体标题，并按照模板补充完整内容。')
		return
	}
	if (form.value.title.trim().length < 6) {
		showIncompleteFormNotice('标题不能只写“课表”或“有问题”，请用不少于6个字概括具体异常。')
		return
	}
	if (!isTemplateCompleted()) {
		showIncompleteFormNotice('请保留模板中的全部栏目，并逐项填写具体信息后再提交。')
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
	form.value.content = templateMap[type.value]
	if (type.value === 'issue' && isEnterpriseAuthOfflineTime()) {
		setTimeout(showNightDataNotice, 80)
	} else if (type.value === 'issue') {
		setTimeout(showDaySelfHelpNotice, 80)
	}
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
.submission-guide { margin-bottom: 18rpx; padding: 22rpx 24rpx; border: 1rpx solid rgba(245, 158, 11, 0.32); border-radius: 22rpx; background: #fffbeb; }
.guide-title { font-size: 27rpx; font-weight: 700; color: #92400e; }
.guide-text { margin-top: 10rpx; font-size: 23rpx; line-height: 1.65; color: #a16207; }
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
