<template>
	<view class="page">
		<view class="header">
			<view class="header-btn" @click="goBack"><uni-icons type="left" size="22" color="#111827"></uni-icons></view>
			<view class="header-title">调课管理</view>
			<view class="header-btn add" @click="openCreate">新建</view>
		</view>

		<scroll-view scroll-y class="page-scroll">
			<view class="intro-card">
				<view class="intro-title">调课计划</view>
				<view class="intro-text">按学期、学生身份和年级设置课程日期调整。已发布规则会自动显示在符合条件的学生课表中。</view>
			</view>
			<view v-if="loading" class="state-row">正在加载调课计划…</view>
			<view v-else-if="errorText" class="state-row warn" @click="loadPlans">{{ errorText }}，点击重试</view>
			<view v-else-if="!plans.length" class="state-row">暂无调课计划</view>
			<view v-else class="plan-list">
				<view v-for="plan in plans" :key="plan.id" class="plan-card">
					<view class="plan-head">
						<view class="plan-title-wrap">
							<view class="plan-title">{{ plan.name }}</view>
							<view class="plan-meta">{{ semesterLabel(plan.semester_mark) }} · {{ plan.rules.length }} 条规则</view>
						</view>
						<view class="status-pill" :class="plan.status">{{ statusLabel(plan.status) }}</view>
					</view>
					<view class="rule-list">
						<view v-for="rule in plan.rules" :key="rule.id" class="rule-row">
							<view class="rule-scope">{{ scopeLabel(rule) }}</view>
							<view class="rule-route">第{{ rule.source_week }}周周{{ weekdayLabel(rule.source_weekday) }} → 第{{ rule.target_week }}周周{{ weekdayLabel(rule.target_weekday) }}</view>
						</view>
					</view>
					<view v-if="plan.notice_title" class="notice-line">依据：{{ plan.notice_title }}</view>
					<view class="plan-actions">
						<view v-if="plan.status !== 'archived'" class="action-btn" @click="openEdit(plan)">编辑</view>
						<view v-if="plan.status === 'draft'" class="action-btn primary" @click="confirmPublish(plan)">发布</view>
						<view v-if="plan.status === 'published'" class="action-btn warn" @click="confirmStop(plan)">停止</view>
						<view v-if="plan.status !== 'archived'" class="action-btn danger" @click="confirmArchive(plan)">归档</view>
					</view>
				</view>
			</view>
		</scroll-view>

		<view v-if="editorOpen" class="editor-mask" @click="closeEditor" @touchmove.prevent>
			<view class="editor" @click.stop @touchmove.stop>
				<view class="editor-grip"></view>
				<view class="editor-head">
					<view class="editor-title">{{ form.id ? '编辑调课计划' : '新建调课计划' }}</view>
					<view class="editor-close" @click="closeEditor">取消</view>
				</view>
				<view class="editor-scroll">
					<view class="field-label">计划名称</view>
					<input v-model="form.name" class="text-input" maxlength="120" placeholder="例如：节假日前后课程调整" />
					<view class="field-label">所属学期</view>
					<picker :range="semesterOptions" range-key="label" @change="selectSemester">
						<view class="select-input">{{ semesterLabel(form.semesterMark) }}<uni-icons type="down" size="16" color="#64748b"></uni-icons></view>
					</picker>
					<view class="field-label">通知标题</view>
					<input v-model="form.noticeTitle" class="text-input" maxlength="200" placeholder="选填" />
					<view class="field-label">通知链接</view>
					<input v-model="form.noticeUrl" class="text-input" maxlength="500" placeholder="选填，以 https:// 开头" />

					<view class="rule-heading">
						<view>
							<view class="rule-heading-title">调整规则</view>
							<view class="rule-heading-tip">一份计划可以包含多条课程日期调整。</view>
						</view>
						<view class="add-rule" @click="addRule">添加规则</view>
					</view>
					<view v-for="(rule, index) in form.rules" :key="rule.localKey" class="rule-editor">
						<view class="rule-editor-head">
							<view class="rule-index">规则 {{ index + 1 }}</view>
							<view v-if="form.rules.length > 1" class="remove-rule" @click="removeRule(index)">删除</view>
						</view>
						<view class="select-grid">
							<picker :range="educationOptions" range-key="label" @change="selectRuleOption(index, 'educationType', educationOptions, $event)">
								<view class="mini-select"><text class="mini-label">身份</text>{{ educationLabel(rule.educationType) }}</view>
							</picker>
							<picker :range="yearOptions" range-key="label" @change="selectRuleOption(index, 'entryYear', yearOptions, $event)">
								<view class="mini-select"><text class="mini-label">年级</text>{{ yearLabel(rule.entryYear) }}</view>
							</picker>
						</view>
						<view class="route-title">来源课程</view>
						<view class="select-grid">
							<picker :range="weekOptions" range-key="label" @change="selectRuleOption(index, 'sourceWeek', weekOptions, $event)"><view class="mini-select">第{{ rule.sourceWeek }}周</view></picker>
							<picker :range="weekdayOptions" range-key="label" @change="selectRuleOption(index, 'sourceWeekday', weekdayOptions, $event)"><view class="mini-select">周{{ weekdayLabel(rule.sourceWeekday) }}</view></picker>
						</view>
						<view class="route-arrow">调整到</view>
						<view class="select-grid">
							<picker :range="weekOptions" range-key="label" @change="selectRuleOption(index, 'targetWeek', weekOptions, $event)"><view class="mini-select">第{{ rule.targetWeek }}周</view></picker>
							<picker :range="weekdayOptions" range-key="label" @change="selectRuleOption(index, 'targetWeekday', weekdayOptions, $event)"><view class="mini-select">周{{ weekdayLabel(rule.targetWeekday) }}</view></picker>
						</view>
					</view>
				</view>
				<view class="editor-footer">
					<view class="save-btn" :class="{ disabled: saving }" @click="savePlan">{{ saving ? '正在保存…' : '保存计划' }}</view>
				</view>
			</view>
		</view>
	</view>
</template>

<script setup>
import { ref } from 'vue'
import {
	archiveScheduleAdjustmentPlan,
	getScheduleAdjustmentPlans,
	publishScheduleAdjustmentPlan,
	saveScheduleAdjustmentPlan,
	stopScheduleAdjustmentPlan
} from '@/api/scheduleAdjustments.js'
import { getErrorMessage } from '@/utils/http.js'

const loading = ref(true)
const errorText = ref('')
const plans = ref([])
const editorOpen = ref(false)
const saving = ref(false)
let localRuleSeed = 0

const currentYear = new Date().getFullYear()
const currentSemester = `${currentYear}-${new Date().getMonth() + 1 >= 8 ? 'fall' : 'spring'}`
const semesterOptions = []
for (let year = currentYear - 1; year <= currentYear + 1; year++) {
	semesterOptions.push({ value: `${year}-spring`, label: `${year}年春季学期` })
	semesterOptions.push({ value: `${year}-fall`, label: `${year}年秋季学期` })
}
const educationOptions = [
	{ value: 'undergraduate', label: '本科生' },
	{ value: 'graduate', label: '研究生' }
]
const yearOptions = [{ value: 0, label: '全部年级' }]
for (let year = currentYear + 1; year >= currentYear - 8; year--) yearOptions.push({ value: year, label: `${year}级` })
const weekOptions = Array.from({ length: 30 }, (_, index) => ({ value: index + 1, label: `第${index + 1}周` }))
const weekdayOptions = ['一', '二', '三', '四', '五', '六', '日'].map((label, index) => ({ value: index + 1, label: `周${label}` }))

const newRule = () => ({
	localKey: `rule-${Date.now()}-${++localRuleSeed}`,
	educationType: 'undergraduate', entryYear: currentYear,
	sourceWeek: 1, sourceWeekday: 1, targetWeek: 1, targetWeekday: 2
})
const emptyForm = () => ({ id: 0, name: '', semesterMark: currentSemester, noticeTitle: '', noticeUrl: '', rules: [newRule()] })
const form = ref(emptyForm())

const goBack = () => uni.navigateBack()
const semesterLabel = (value) => semesterOptions.find(item => item.value === value)?.label || value || '请选择学期'
const educationLabel = (value) => educationOptions.find(item => item.value === value)?.label || '本科生'
const yearLabel = (value) => Number(value) === 0 ? '全部年级' : `${value}级`
const weekdayLabel = (value) => ['一', '二', '三', '四', '五', '六', '日'][Number(value) - 1] || '-'
const scopeLabel = (rule) => `${educationLabel(rule.education_type)} · ${yearLabel(rule.entry_year)}`
const statusLabel = (status) => ({ draft: '草稿', published: '生效中', archived: '已归档' }[status] || status)

const loadPlans = async () => {
	loading.value = true
	errorText.value = ''
	try {
		const response = await getScheduleAdjustmentPlans()
		plans.value = response?.data?.items || []
	} catch (error) {
		errorText.value = getErrorMessage(error, '调课计划加载失败')
	} finally {
		loading.value = false
	}
}

const openCreate = () => { form.value = emptyForm(); editorOpen.value = true }
const openEdit = (plan) => {
	form.value = {
		id: plan.id,
		name: plan.name,
		semesterMark: plan.semester_mark,
		noticeTitle: plan.notice_title || '',
		noticeUrl: plan.notice_url || '',
		rules: plan.rules.map(rule => ({
			localKey: `rule-${rule.id}-${++localRuleSeed}`,
			educationType: rule.education_type,
			entryYear: rule.entry_year,
			sourceWeek: rule.source_week,
			sourceWeekday: rule.source_weekday,
			targetWeek: rule.target_week,
			targetWeekday: rule.target_weekday
		}))
	}
	editorOpen.value = true
}
const closeEditor = () => { if (!saving.value) editorOpen.value = false }
const selectSemester = (event) => { form.value.semesterMark = semesterOptions[Number(event.detail.value)]?.value || currentSemester }
const selectRuleOption = (index, field, options, event) => {
	const option = options[Number(event.detail.value)]
	if (option) form.value.rules[index][field] = option.value
}
const addRule = () => form.value.rules.push(newRule())
const removeRule = (index) => { if (form.value.rules.length > 1) form.value.rules.splice(index, 1) }

const savePlan = async () => {
	if (saving.value) return
	if (!form.value.name.trim()) return uni.showToast({ title: '请填写计划名称', icon: 'none' })
	saving.value = true
	try {
		await saveScheduleAdjustmentPlan({
			id: form.value.id,
			name: form.value.name.trim(),
			semester_mark: form.value.semesterMark,
			notice_title: form.value.noticeTitle.trim(),
			notice_url: form.value.noticeUrl.trim(),
			rules: form.value.rules.map(rule => ({
				education_type: rule.educationType,
				entry_year: rule.entryYear,
				source_week: rule.sourceWeek,
				source_weekday: rule.sourceWeekday,
				target_week: rule.targetWeek,
				target_weekday: rule.targetWeekday
			}))
		})
		editorOpen.value = false
		await loadPlans()
		uni.showToast({ title: '计划已保存', icon: 'success' })
	} catch (error) {
		uni.showToast({ title: getErrorMessage(error, '保存失败'), icon: 'none' })
	} finally { saving.value = false }
}

const confirmAction = (title, content, confirmText, handler) => uni.showModal({
	title, content, confirmText, cancelText: '取消',
	success: async (result) => {
		if (!result.confirm) return
		try { await handler(); await loadPlans(); uni.showToast({ title: '操作成功', icon: 'success' }) }
		catch (error) { uni.showToast({ title: getErrorMessage(error, '操作失败'), icon: 'none' }) }
	}
})
const confirmPublish = (plan) => confirmAction('发布调课计划', '发布后，符合规则的学生课表将立即显示调整后的课程。', '确认发布', () => publishScheduleAdjustmentPlan(plan.id))
const confirmStop = (plan) => confirmAction('停止调课计划', '停止后，课表将不再应用该计划中的调整规则。', '确认停止', () => stopScheduleAdjustmentPlan(plan.id))
const confirmArchive = (plan) => confirmAction('归档调课计划', '归档后不能继续编辑或重新发布。', '确认归档', () => archiveScheduleAdjustmentPlan(plan.id))

onShow(loadPlans)
</script>

<style lang="scss" scoped>
.page { min-height: 100vh; background: #f3f6fa; color: #0f172a; }
.header { height: calc(env(safe-area-inset-top) + 92rpx); padding: env(safe-area-inset-top) 24rpx 0; display: flex; align-items: center; justify-content: space-between; box-sizing: border-box; }
.header-btn { width: 76rpx; height: 64rpx; display: flex; align-items: center; justify-content: center; font-size: 23rpx; color: #2563eb; }
.header-btn.add { font-weight: 700; }
.header-title { font-size: 34rpx; font-weight: 700; }
.page-scroll { height: calc(100vh - env(safe-area-inset-top) - 92rpx); padding: 12rpx 24rpx calc(env(safe-area-inset-bottom) + 36rpx); box-sizing: border-box; }
.intro-card, .plan-card, .state-row { background: #fff; border-radius: 22rpx; }
.intro-card { padding: 26rpx; margin-bottom: 20rpx; }
.intro-title { font-size: 30rpx; font-weight: 750; }
.intro-text { margin-top: 9rpx; font-size: 23rpx; line-height: 1.55; color: #64748b; }
.state-row { padding: 30rpx; color: #64748b; font-size: 24rpx; }
.state-row.warn { color: #b45309; }
.plan-list { display: flex; flex-direction: column; gap: 18rpx; }
.plan-card { padding: 24rpx; }
.plan-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 18rpx; }
.plan-title-wrap { min-width: 0; flex: 1; }
.plan-title { font-size: 28rpx; font-weight: 750; line-height: 1.35; }
.plan-meta, .notice-line { color: #64748b; font-size: 21rpx; line-height: 1.45; }
.plan-meta { margin-top: 6rpx; }
.status-pill { flex: 0 0 auto; padding: 7rpx 13rpx; border-radius: 999rpx; font-size: 20rpx; font-weight: 700; background: #f1f5f9; color: #64748b; }
.status-pill.published { background: #dcfce7; color: #15803d; }
.status-pill.archived { background: #e2e8f0; color: #475569; }
.rule-list { margin-top: 20rpx; border-top: 1rpx solid #e2e8f0; }
.rule-row { min-height: 76rpx; display: flex; align-items: center; justify-content: space-between; gap: 18rpx; border-bottom: 1rpx solid #eef2f7; }
.rule-scope { flex: 0 0 auto; font-size: 21rpx; color: #475569; }
.rule-route { text-align: right; font-size: 22rpx; font-weight: 650; color: #1e3a8a; }
.notice-line { margin-top: 14rpx; word-break: break-word; }
.plan-actions { margin-top: 18rpx; display: flex; flex-wrap: wrap; gap: 12rpx; }
.action-btn { padding: 12rpx 19rpx; border-radius: 13rpx; background: #f1f5f9; color: #334155; font-size: 22rpx; font-weight: 700; }
.action-btn.primary { background: #2563eb; color: #fff; }
.action-btn.warn { background: #fff7ed; color: #c2410c; }
.action-btn.danger { background: #fff1f2; color: #be123c; }
/* 层级需低于 uni-app 原生 picker（uni-mask/uni-picker-container = 999），否则下拉选择层会被本遮罩盖住 */
.editor-mask { position: fixed; inset: 0; z-index: 900; background: rgba(15, 23, 42, .42); display: flex; align-items: flex-end; }
.editor { width: 100%; max-height: 92vh; background: #f8fafc; border-radius: 28rpx 28rpx 0 0; display: flex; flex-direction: column; overflow: hidden; padding-bottom: env(safe-area-inset-bottom); }
.editor-grip { width: 76rpx; height: 8rpx; margin: 14rpx auto 4rpx; border-radius: 999rpx; background: #cbd5e1; }
.editor-head { height: 78rpx; padding: 0 26rpx; display: flex; align-items: center; justify-content: space-between; }
.editor-title { font-size: 30rpx; font-weight: 750; }
.editor-close { font-size: 23rpx; color: #64748b; }
.editor-scroll { flex: 1; min-height: 0; overflow-y: auto; -webkit-overflow-scrolling: touch; padding: 0 24rpx 28rpx; box-sizing: border-box; }
.field-label { margin: 20rpx 0 9rpx; font-size: 23rpx; font-weight: 700; color: #334155; }
.text-input, .select-input { min-height: 78rpx; padding: 0 20rpx; border-radius: 16rpx; background: #fff; box-shadow: inset 0 0 0 1rpx #dbe3ee; box-sizing: border-box; font-size: 24rpx; }
.select-input { display: flex; align-items: center; justify-content: space-between; }
.rule-heading { margin-top: 28rpx; display: flex; align-items: flex-end; justify-content: space-between; }
.rule-heading-title { font-size: 28rpx; font-weight: 750; }
.rule-heading-tip { margin-top: 5rpx; font-size: 21rpx; color: #64748b; }
.add-rule { padding: 11rpx 16rpx; border-radius: 12rpx; background: #dbeafe; color: #1d4ed8; font-size: 22rpx; font-weight: 700; }
.rule-editor { margin-top: 16rpx; padding: 20rpx; border-radius: 20rpx; background: #fff; box-shadow: inset 0 0 0 1rpx #e2e8f0; }
.rule-editor-head { display: flex; align-items: center; justify-content: space-between; }
.rule-index { font-size: 24rpx; font-weight: 750; }
.remove-rule { color: #dc2626; font-size: 21rpx; }
.select-grid { margin-top: 14rpx; display: grid; grid-template-columns: 1fr 1fr; gap: 12rpx; }
.mini-select { min-height: 68rpx; padding: 0 16rpx; border-radius: 14rpx; background: #f8fafc; display: flex; align-items: center; justify-content: space-between; font-size: 22rpx; color: #1e293b; }
.mini-label { color: #64748b; margin-right: 12rpx; }
.route-title, .route-arrow { margin-top: 16rpx; font-size: 21rpx; color: #64748b; }
.route-arrow { text-align: center; color: #2563eb; font-weight: 700; }
.editor-footer { padding: 14rpx 24rpx 18rpx; background: #fff; border-top: 1rpx solid #e2e8f0; }
.save-btn { height: 76rpx; border-radius: 17rpx; background: linear-gradient(90deg, #2563eb, #0ea5e9); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 25rpx; font-weight: 750; }
.save-btn.disabled { opacity: .55; }
</style>

