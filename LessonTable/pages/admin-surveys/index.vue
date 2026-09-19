<template>
	<view class="page">
		<view class="header">
			<view class="header-btn" @click="goBack"><uni-icons type="left" size="22" color="#111827"></uni-icons></view>
			<view class="header-title">问卷管理</view>
			<view class="header-btn add" @click="openCreate">新建</view>
		</view>

		<scroll-view scroll-y class="page-scroll">
			<view class="intro-card">
				<view class="intro-title">调研问卷</view>
				<view class="intro-text">同一时间只保留一期"进行中"的问卷，课表顶部入口由这里的开关控制。管理端只管理期次与发布状态，题目在代码中维护。</view>
			</view>

			<view v-if="loading" class="state-row">正在加载问卷期次…</view>
			<view v-else-if="errorText" class="state-row warn" @click="loadRounds">{{ errorText }}，点击重试</view>
			<view v-else-if="!rounds.length" class="state-row">还没有创建过问卷期次</view>

			<view v-else class="round-list">
				<view v-for="item in rounds" :key="item.id" class="round-card">
					<view class="round-head">
						<view class="round-title-wrap">
							<view class="round-title">{{ item.title }}</view>
							<view class="round-meta">
								回收 {{ item.responses.total }} 份 · 快速 {{ item.responses.quick }} · 完整 {{ item.responses.full }}
							</view>
						</view>
						<view class="status-pill" :class="item.status">{{ statusLabel(item.status) }}</view>
					</view>
					<view v-if="item.intro" class="round-intro">{{ item.intro }}</view>
					<view class="switch-row">
						<view class="switch-copy">
							<view class="switch-title">课表顶部入口</view>
							<view class="switch-tip">关闭后课表页不显示调研入口</view>
						</view>
						<switch :checked="item.entry_enabled" color="#2563eb" @change="toggleEntry(item, $event)" />
					</view>
					<view class="round-actions">
						<view class="action-btn" @click="openEdit(item)">编辑</view>
						<view v-if="item.status !== 'active'" class="action-btn primary" @click="changeStatus(item, 'active')">发布</view>
						<view v-if="item.status === 'active'" class="action-btn warn" @click="changeStatus(item, 'closed')">结束</view>
						<view v-if="item.status !== 'draft'" class="action-btn" @click="changeStatus(item, 'draft')">下架</view>
						<view class="action-btn" @click="openResults(item)">查看数据</view>
					</view>
				</view>
			</view>
		</scroll-view>

		<view v-if="editorOpen" class="editor-mask" @click="closeEditor" @touchmove.prevent>
			<view class="editor" @click.stop @touchmove.stop>
				<view class="editor-grip"></view>
				<view class="editor-head">
					<view class="editor-title">{{ form.id ? '编辑问卷期次' : '新建问卷期次' }}</view>
					<view class="editor-close" @click="closeEditor">取消</view>
				</view>
				<view class="editor-scroll">
					<view class="field-label">期次标题</view>
					<input v-model="form.title" class="text-input" maxlength="120" placeholder="例如：课表调研 · 第 1 期" />
					<view class="field-label">简介（显示在问卷首页）</view>
					<textarea v-model="form.intro" class="text-area" maxlength="300"
						placeholder="例如：想了解大家的使用感受，帮我们把下一步做对。" />
					<view class="field-label">发布状态</view>
					<view class="option-list">
						<view v-for="option in statusOptions" :key="option.key" class="option"
							:class="{ active: form.status === option.key }" @click="form.status = option.key">
							{{ option.label }}
						</view>
					</view>
					<view class="switch-row">
						<view class="switch-copy">
							<view class="switch-title">课表顶部入口</view>
							<view class="switch-tip">发布后课表页显示调研入口</view>
						</view>
						<switch :checked="form.entry_enabled" color="#2563eb" @change="form.entry_enabled = $event.detail.value" />
					</view>
				</view>
				<view class="editor-footer">
					<view class="save-btn" :class="{ disabled: saving }" @click="saveRound">{{ saving ? '正在保存…' : '保存' }}</view>
				</view>
			</view>
		</view>

		<view v-if="resultsOpen" class="editor-mask" @click="closeResults" @touchmove.prevent>
			<view class="editor" @click.stop @touchmove.stop>
				<view class="editor-grip"></view>
				<view class="editor-head">
					<view class="editor-title">数据 · {{ resultsTitle }}</view>
					<view class="editor-close" @click="closeResults">关闭</view>
				</view>
				<view class="editor-scroll">
					<view v-if="resultsLoading" class="state-row">正在统计…</view>
					<template v-else>
						<view class="result-summary">
							回收 {{ results.totalResponses }} 份（快速 {{ results.responses.quick }} · 完整 {{ results.responses.full }}）
						</view>
						<view v-for="item in resultItems" :key="item.key" class="result-item">
							<view class="result-title">
								{{ item.title }}
								<text class="result-count">已答 {{ item.answered }}</text>
							</view>

							<view v-if="item.type === 'single' || item.type === 'multi'" class="result-options">
								<view v-for="(option, key) in item.options" :key="key" class="result-option">
									<view class="result-option-head">
										<text>{{ option.label }}</text>
										<text>{{ option.count }} · {{ percent(option.count, item.answered) }}%</text>
									</view>
									<view class="bar-track"><view class="bar-fill" :style="{ width: percent(option.count, item.answered) + '%' }"></view></view>
								</view>
							</view>

							<view v-else-if="item.type === 'scale'" class="result-scale">
								平均分 {{ item.average === null ? '-' : item.average }}
								<view v-for="(option, key) in item.options" :key="key" class="result-option">
									<view class="result-option-head">
										<text>{{ option.label }}</text>
										<text>{{ option.count }} · {{ percent(option.count, item.answered) }}%</text>
									</view>
									<view class="bar-track"><view class="bar-fill" :style="{ width: percent(option.count, item.answered) + '%' }"></view></view>
								</view>
							</view>

							<view v-else-if="item.type === 'rank'" class="result-options">
								<view v-for="(option, key) in item.options" :key="key" class="result-option">
									<view class="result-option-head">
										<text>{{ option.label }}</text>
										<text>平均第 {{ item.rankAverage[key] === null || item.rankAverage[key] === undefined ? '-' : item.rankAverage[key] }} 位</text>
									</view>
								</view>
							</view>

							<view v-else-if="item.type === 'text'" class="result-texts">
								<view v-if="!item.texts.length" class="result-empty">暂无文字回答</view>
								<view v-for="(text, index) in item.texts" :key="index" class="result-text">· {{ text }}</view>
							</view>

							<view v-for="followUp in (item.followUps || [])" :key="followUp.key" class="result-followup">
								<view class="result-title">
									{{ followUp.title }}
									<text class="result-count">已答 {{ followUp.answered }}</text>
								</view>
								<view v-for="(option, key) in followUp.options" :key="key" class="result-option">
									<view class="result-option-head">
										<text>{{ option.label }}</text>
										<text>{{ option.count }} · {{ percent(option.count, followUp.answered) }}%</text>
									</view>
									<view class="bar-track"><view class="bar-fill" :style="{ width: percent(option.count, followUp.answered) + '%' }"></view></view>
								</view>
							</view>
						</view>
					</template>
				</view>
			</view>
		</view>
	</view>
</template>

<script setup>
import { ref } from 'vue'
import {
	getSurveyResults,
	listSurveyRounds,
	saveSurveyRound,
	setSurveyRoundStatus
} from '@/api/surveys.js'
import { getErrorMessage } from '@/utils/http.js'

const loading = ref(true)
const errorText = ref('')
const rounds = ref([])
const editorOpen = ref(false)
const saving = ref(false)
const resultsOpen = ref(false)
const resultsLoading = ref(false)
const resultsTitle = ref('')
const results = ref({ responses: { quick: 0, full: 0 }, totalResponses: 0, items: [] })

const statusOptions = [
	{ key: 'draft', label: '草稿（不显示）' },
	{ key: 'active', label: '进行中（显示入口）' },
	{ key: 'closed', label: '已结束' }
]

const form = ref({
	id: 0,
	title: '',
	intro: '',
	status: 'draft',
	entry_enabled: true
})

const statusLabel = (status) => {
	if (status === 'active') return '进行中'
	if (status === 'closed') return '已结束'
	return '草稿'
}

const resultItems = ref([])

const percent = (count, total) => {
	const base = Number(total) || 0
	if (base <= 0) return 0
	return Math.round((Number(count) || 0) * 100 / base)
}

const loadRounds = async () => {
	loading.value = true
	errorText.value = ''
	try {
		const response = await listSurveyRounds()
		rounds.value = response?.data?.items || []
	} catch (error) {
		errorText.value = getErrorMessage(error, '加载失败')
	} finally {
		loading.value = false
	}
}

const openCreate = () => {
	const nextIndex = rounds.value.length + 1
	form.value = {
		id: 0,
		title: `课表调研 · 第 ${nextIndex} 期`,
		intro: '想了解大家的使用感受，帮我们把下一步做对。',
		status: 'draft',
		entry_enabled: true
	}
	editorOpen.value = true
}

const openEdit = (item) => {
	form.value = {
		id: item.id,
		title: item.title,
		intro: item.intro || '',
		status: item.status,
		entry_enabled: !!item.entry_enabled
	}
	editorOpen.value = true
}

const closeEditor = () => {
	editorOpen.value = false
}

const saveRound = async () => {
	if (saving.value) return
	if (!form.value.title.trim()) {
		uni.showToast({ title: '请填写期次标题', icon: 'none' })
		return
	}
	saving.value = true
	try {
		await saveSurveyRound({
			id: form.value.id,
			title: form.value.title.trim(),
			intro: form.value.intro.trim(),
			status: form.value.status,
			entry_enabled: form.value.entry_enabled
		})
		uni.showToast({ title: '已保存', icon: 'success' })
		editorOpen.value = false
		await loadRounds()
	} catch (error) {
		uni.showToast({ title: getErrorMessage(error, '保存失败'), icon: 'none' })
	} finally {
		saving.value = false
	}
}

const changeStatus = async (item, status) => {
	try {
		const response = await setSurveyRoundStatus(item.id, status)
		uni.showToast({ title: response?.msg || '已更新', icon: 'none' })
		await loadRounds()
	} catch (error) {
		uni.showToast({ title: getErrorMessage(error, '操作失败'), icon: 'none' })
	}
}

const toggleEntry = async (item, event) => {
	const enabled = !!event.detail.value
	try {
		await saveSurveyRound({
			id: item.id,
			title: item.title,
			intro: item.intro || '',
			status: item.status,
			entry_enabled: enabled
		})
		item.entry_enabled = enabled
		uni.showToast({ title: enabled ? '已开启顶部入口' : '已关闭顶部入口', icon: 'none' })
	} catch (error) {
		uni.showToast({ title: getErrorMessage(error, '操作失败'), icon: 'none' })
		await loadRounds()
	}
}

const openResults = async (item) => {
	resultsOpen.value = true
	resultsLoading.value = true
	resultsTitle.value = item.title
	results.value = { responses: { quick: 0, full: 0 }, totalResponses: 0, items: [] }
	resultItems.value = []
	try {
		const response = await getSurveyResults(item.id)
		const data = response?.data || {}
		results.value = {
			responses: data.responses || { quick: 0, full: 0 },
			totalResponses: data.totalResponses || 0,
			items: data.items || []
		}
		resultItems.value = data.items || []
	} catch (error) {
		uni.showToast({ title: getErrorMessage(error, '数据加载失败'), icon: 'none' })
		resultsOpen.value = false
	} finally {
		resultsLoading.value = false
	}
}

const closeResults = () => {
	resultsOpen.value = false
}

const goBack = () => {
	uni.navigateBack()
}

loadRounds()
</script>

<style lang="scss" scoped>
.page { min-height: 100vh; background: #f3f6fa; color: #0f172a; }
.header { height: calc(env(safe-area-inset-top) + 92rpx); padding: env(safe-area-inset-top) 24rpx 0; display: flex; align-items: center; justify-content: space-between; box-sizing: border-box; }
.header-btn { width: 76rpx; height: 64rpx; display: flex; align-items: center; justify-content: center; font-size: 23rpx; color: #2563eb; }
.header-btn.add { font-weight: 700; }
.header-title { font-size: 34rpx; font-weight: 700; }
.page-scroll { height: calc(100vh - env(safe-area-inset-top) - 92rpx); padding: 12rpx 24rpx calc(env(safe-area-inset-bottom) + 36rpx); box-sizing: border-box; }

.intro-card, .round-card, .state-row { background: #fff; border-radius: 22rpx; }
.intro-card { padding: 26rpx; margin-bottom: 20rpx; }
.intro-title { font-size: 30rpx; font-weight: 750; }
.intro-text { margin-top: 9rpx; font-size: 23rpx; line-height: 1.55; color: #64748b; }
.state-row { padding: 30rpx; color: #64748b; font-size: 24rpx; }
.state-row.warn { color: #b45309; }

.round-list { display: flex; flex-direction: column; gap: 18rpx; }
.round-card { padding: 24rpx; }
.round-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 18rpx; }
.round-title-wrap { min-width: 0; flex: 1; }
.round-title { font-size: 28rpx; font-weight: 750; line-height: 1.35; }
.round-meta { margin-top: 6rpx; color: #64748b; font-size: 21rpx; }
.round-intro { margin-top: 12rpx; font-size: 22rpx; line-height: 1.55; color: #475569; }
.status-pill { flex: 0 0 auto; padding: 7rpx 13rpx; border-radius: 999rpx; font-size: 20rpx; font-weight: 700; background: #f1f5f9; color: #64748b; }
.status-pill.active { background: #dcfce7; color: #15803d; }
.status-pill.closed { background: #e2e8f0; color: #475569; }

.switch-row { margin-top: 18rpx; padding-top: 16rpx; border-top: 1rpx solid #eef2f7; display: flex; align-items: center; justify-content: space-between; gap: 18rpx; }
.switch-copy { min-width: 0; flex: 1; }
.switch-title { font-size: 24rpx; font-weight: 700; }
.switch-tip { margin-top: 4rpx; font-size: 21rpx; color: #64748b; }

.round-actions { margin-top: 18rpx; display: flex; flex-wrap: wrap; gap: 12rpx; }
.action-btn { padding: 12rpx 19rpx; border-radius: 13rpx; background: #f1f5f9; color: #334155; font-size: 22rpx; font-weight: 700; }
.action-btn.primary { background: #2563eb; color: #fff; }
.action-btn.warn { background: #fff7ed; color: #c2410c; }

/* 层级需低于 uni-app 原生 picker（999），否则下拉层会被遮罩盖住 */
.editor-mask { position: fixed; inset: 0; z-index: 900; background: rgba(15, 23, 42, .42); display: flex; align-items: flex-end; }
.editor { width: 100%; max-height: 92vh; background: #f8fafc; border-radius: 28rpx 28rpx 0 0; display: flex; flex-direction: column; overflow: hidden; padding-bottom: env(safe-area-inset-bottom); }
.editor-grip { width: 76rpx; height: 8rpx; margin: 14rpx auto 4rpx; border-radius: 999rpx; background: #cbd5e1; }
.editor-head { height: 78rpx; padding: 0 26rpx; display: flex; align-items: center; justify-content: space-between; }
.editor-title { font-size: 30rpx; font-weight: 750; }
.editor-close { font-size: 23rpx; color: #64748b; }
.editor-scroll { flex: 1; min-height: 0; overflow-y: auto; -webkit-overflow-scrolling: touch; padding: 0 24rpx 28rpx; box-sizing: border-box; }
.editor-footer { padding: 14rpx 24rpx 18rpx; background: #fff; border-top: 1rpx solid #e2e8f0; }
.save-btn { height: 76rpx; border-radius: 17rpx; background: linear-gradient(90deg, #2563eb, #0ea5e9); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 25rpx; font-weight: 750; }
.save-btn.disabled { opacity: .55; }

.field-label { margin: 20rpx 0 9rpx; font-size: 23rpx; font-weight: 700; color: #334155; }
.text-input, .text-area { width: 100%; padding: 0 20rpx; border-radius: 16rpx; background: #fff; box-shadow: inset 0 0 0 1rpx #dbe3ee; box-sizing: border-box; font-size: 24rpx; }
.text-input { min-height: 78rpx; }
.text-area { min-height: 150rpx; padding: 18rpx 20rpx; }
.option-list { display: flex; flex-direction: column; gap: 12rpx; }
.option { padding: 18rpx 20rpx; border-radius: 16rpx; background: #fff; box-shadow: inset 0 0 0 1rpx #e2e8f0; font-size: 23rpx; color: #1e293b; }
.option.active { background: #eff6ff; box-shadow: inset 0 0 0 2rpx #2563eb; color: #1d4ed8; font-weight: 700; }

.result-summary { padding: 16rpx 20rpx; border-radius: 16rpx; background: #eff6ff; color: #1d4ed8; font-size: 23rpx; font-weight: 700; }
.result-item { margin-top: 22rpx; padding-top: 18rpx; border-top: 1rpx solid #eef2f7; }
.result-title { font-size: 25rpx; font-weight: 750; line-height: 1.5; }
.result-count { margin-left: 10rpx; font-size: 20rpx; font-weight: 500; color: #94a3b8; }
.result-options { margin-top: 12rpx; display: flex; flex-direction: column; gap: 12rpx; }
.result-option { font-size: 22rpx; color: #334155; }
.result-option-head { display: flex; align-items: center; justify-content: space-between; gap: 12rpx; }
.bar-track { margin-top: 6rpx; height: 10rpx; border-radius: 999rpx; background: #eef2f7; overflow: hidden; }
.bar-fill { height: 100%; border-radius: 999rpx; background: linear-gradient(90deg, #2563eb, #0ea5e9); }
.result-scale { margin-top: 12rpx; font-size: 23rpx; font-weight: 700; color: #1d4ed8; }
.result-texts { margin-top: 12rpx; }
.result-text { font-size: 22rpx; line-height: 1.6; color: #334155; }
.result-empty { font-size: 22rpx; color: #94a3b8; }
.result-followup { margin-top: 18rpx; padding: 16rpx; border-radius: 16rpx; background: #f8fafc; }
</style>
