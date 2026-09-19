<template>
	<view class="page">
		<view class="header">
			<view class="header-btn" @click="goBack">
				<uni-icons type="left" size="22" color="#111827"></uni-icons>
			</view>
			<view class="header-title">课表调研</view>
			<view class="header-btn"></view>
		</view>

		<scroll-view scroll-y class="page-scroll" :class="{ 'with-footer': !!mode }">
			<view v-if="loading" class="state-card">正在加载…</view>

			<view v-else-if="!enabled" class="state-card">
				<view class="state-title">当前没有进行中的问卷</view>
				<view class="state-text">下一期开放时会在这里显示，感谢关注。</view>
			</view>

			<view v-else-if="allFilled" class="state-card">
				<view class="state-title">两种问卷你都填过了</view>
				<view class="state-text">非常感谢！你的反馈我们都会逐条查看。</view>
			</view>

			<template v-else-if="!mode">
				<view class="group-card">
					<view class="group-title">{{ round.title || '课表调研' }}</view>
					<view v-if="round.intro" class="round-intro">{{ round.intro }}</view>
				</view>

				<view class="entry-card" :class="{ done: filled.quick }" @click="startMode('quick')">
					<view class="entry-main">
						<view class="entry-title">快速问卷</view>
						<view class="entry-sub">4 题 · 约 30 秒 · 含 1 道随机体验题</view>
					</view>
					<view class="entry-action">{{ filled.quick ? '已提交' : '开始' }}</view>
				</view>

				<view class="entry-card primary" :class="{ done: filled.full }" @click="startMode('full')">
					<view class="entry-main">
						<view class="entry-title">完整问卷</view>
						<view class="entry-sub">使用体验全部 + 未来方向 + 推荐意愿 · 约 2 分钟</view>
					</view>
					<view class="entry-action">{{ filled.full ? '已提交' : '开始' }}</view>
				</view>

				<view class="tips-card">
					<view class="tips-line">· 不强制填写，随时可以退出。</view>
					<view class="tips-line">· 回答仅用于整体统计，不会公开你的个人回答。</view>
					<view class="tips-line">· 两种问卷都可以填，提交后不可修改。</view>
				</view>
			</template>

			<template v-else>
				<view v-for="block in visibleBlocks" :key="block.key" class="group-card">
					<view class="group-title">{{ block.title }}</view>
					<view v-if="block.subtitle" class="group-subtitle">{{ block.subtitle }}</view>

					<view v-for="item in block.items" :key="item.key" class="question">
						<view class="question-title">
							{{ item.title }}
							<text v-if="!item.required" class="optional">选填</text>
						</view>
						<view v-if="item.hint" class="question-hint">{{ item.hint }}</view>

						<view v-if="item.type === 'single'" class="option-list">
							<view v-for="option in item.options" :key="option.key" class="option"
								:class="{ active: answers[item.key] === option.key }" @click="pickSingle(item, option.key)">
								{{ option.label }}
							</view>
						</view>

						<view v-else-if="item.type === 'multi'" class="option-list">
							<view v-for="option in item.options" :key="option.key" class="option"
								:class="{ active: isPicked(item.key, option.key) }" @click="toggleMulti(item, option.key)">
								{{ option.label }}
							</view>
						</view>

						<view v-else-if="item.type === 'scale'" class="scale-row">
							<view v-for="n in scaleValues(item)" :key="n" class="scale-dot"
								:class="{ active: Number(answers[item.key]) === n }" @click="answers[item.key] = n">
								{{ n }}
							</view>
						</view>

						<view v-else-if="item.type === 'rank'" class="option-list">
							<view v-for="option in item.options" :key="option.key" class="option rank"
								:class="{ active: rankIndex(item.key, option.key) > 0 }" @click="toggleRank(item, option.key)">
								<view class="rank-badge">{{ rankIndex(item.key, option.key) || '-' }}</view>
								<view class="option-text">{{ option.label }}</view>
							</view>
						</view>

						<textarea v-else-if="item.type === 'text'" class="text-input" :value="answers[item.key] || ''"
							:maxlength="item.maxLength || 120" :placeholder="item.hint || '可留空'"
							@input="onTextInput(item, $event)" />

						<view v-if="shouldAskFollowUp(item)" class="followup">
							<view class="followup-title">{{ item.followUp.title }}</view>
							<view v-if="item.followUp.hint" class="question-hint">{{ item.followUp.hint }}</view>
							<view class="option-list">
								<view v-for="option in item.followUp.options" :key="option.key" class="option"
									:class="{ active: isFollowPicked(item, option.key) }" @click="toggleFollow(item, option.key)">
									{{ option.label }}
								</view>
							</view>
						</view>
					</view>
				</view>
			</template>
		</scroll-view>

		<view v-if="mode" class="footer">
			<view class="submit-btn" :class="{ disabled: submitting }" @click="submit">
				{{ submitting ? '正在提交…' : '提交问卷' }}
			</view>
		</view>
	</view>
</template>

<script setup>
import { computed, ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { getSurveyEntry, getSurveyQuestions, submitSurvey } from '@/api/surveys.js'
import { getErrorMessage } from '@/utils/http.js'

const loading = ref(true)
const enabled = ref(false)
const round = ref({})
const filled = ref({ quick: false, full: false })
const mode = ref('')
const blocks = ref([])
const items = ref([])
const answers = ref({})
const submitting = ref(false)

const allFilled = computed(() => filled.value.quick && filled.value.full)

const visibleBlocks = computed(() => {
	const map = new Map()
	blocks.value.forEach((block) => {
		map.set(block.key, { ...block, items: [] })
	})
	items.value.forEach((item) => {
		const block = map.get(item.block)
		if (block) block.items.push(item)
	})
	return Array.from(map.values()).filter(block => block.items.length > 0)
})

const loadEntry = async () => {
	loading.value = true
	try {
		const response = await getSurveyEntry()
		const data = response?.data || {}
		enabled.value = !!data.enabled
		round.value = data.round || {}
		filled.value = data.filled || { quick: false, full: false }
	} catch (error) {
		uni.showToast({ title: getErrorMessage(error, '问卷状态加载失败'), icon: 'none' })
		enabled.value = false
	} finally {
		loading.value = false
	}
}

const startMode = async (target) => {
	if (filled.value[target]) {
		uni.showToast({ title: '这份问卷你已经提交过了', icon: 'none' })
		return
	}
	try {
		uni.showLoading({ title: '加载题目…' })
		const response = await getSurveyQuestions(target)
		const data = response?.data || {}
		mode.value = target
		blocks.value = data.blocks ? Object.keys(data.blocks).map(key => ({ key, ...data.blocks[key] })) : []
		items.value = data.items || []
		answers.value = {}
	} catch (error) {
		uni.showToast({ title: getErrorMessage(error, '题目加载失败'), icon: 'none' })
	} finally {
		uni.hideLoading()
	}
}

const pickSingle = (item, key) => {
	answers.value = { ...answers.value, [item.key]: key }
}

const isPicked = (itemKey, optionKey) => {
	const value = answers.value[itemKey]
	return Array.isArray(value) && value.includes(optionKey)
}

const toggleMulti = (item, optionKey) => {
	const current = Array.isArray(answers.value[item.key]) ? [...answers.value[item.key]] : []
	const index = current.indexOf(optionKey)
	if (index >= 0) {
		current.splice(index, 1)
	} else {
		const max = Number(item.max) || 0
		if (max > 0 && current.length >= max) {
			uni.showToast({ title: `最多选择 ${max} 项`, icon: 'none' })
			return
		}
		current.push(optionKey)
	}
	answers.value = { ...answers.value, [item.key]: current }
}

const scaleValues = (item) => {
	const min = Number(item.min) || 0
	const max = Number(item.max) || 10
	const list = []
	for (let n = min; n <= max; n++) list.push(n)
	return list
}

const rankOrder = (itemKey) => {
	const value = answers.value[itemKey]
	return Array.isArray(value) ? value : []
}

const rankIndex = (itemKey, optionKey) => {
	const index = rankOrder(itemKey).indexOf(optionKey)
	return index >= 0 ? index + 1 : 0
}

const toggleRank = (item, optionKey) => {
	const current = [...rankOrder(item.key)]
	const index = current.indexOf(optionKey)
	if (index >= 0) {
		current.splice(index, 1)
	} else {
		if (current.length >= item.options.length) {
			uni.showToast({ title: '已经排满了', icon: 'none' })
			return
		}
		current.push(optionKey)
	}
	answers.value = { ...answers.value, [item.key]: current }
}

const onTextInput = (item, event) => {
	answers.value = { ...answers.value, [item.key]: event.detail.value }
}

const shouldAskFollowUp = (item) => {
	if (!item.followUp) return false
	const when = item.followUp.when || []
	return when.includes(String(answers.value[item.key] || ''))
}

const isFollowPicked = (item, optionKey) => {
	const value = answers.value[item.followUp.key]
	return Array.isArray(value) && value.includes(optionKey)
}

const toggleFollow = (item, optionKey) => {
	const key = item.followUp.key
	const current = Array.isArray(answers.value[key]) ? [...answers.value[key]] : []
	const index = current.indexOf(optionKey)
	if (index >= 0) {
		current.splice(index, 1)
	} else {
		const max = Number(item.followUp.max) || 0
		if (max > 0 && current.length >= max) {
			uni.showToast({ title: `最多选择 ${max} 项`, icon: 'none' })
			return
		}
		current.push(optionKey)
	}
	answers.value = { ...answers.value, [key]: current }
}

const validate = () => {
	for (const item of items.value) {
		const value = answers.value[item.key]
		const isEmpty = value === undefined || value === null || value === ''
			|| (Array.isArray(value) && value.length === 0)
		if (item.required && isEmpty) {
			uni.showToast({ title: '还有必答题没有完成', icon: 'none' })
			return false
		}
		if (item.type === 'rank' && !isEmpty && value.length !== item.options.length) {
			uni.showToast({ title: '请把每一项都排好序', icon: 'none' })
			return false
		}
	}
	return true
}

const submit = async () => {
	if (submitting.value || !validate()) return
	submitting.value = true
	try {
		await submitSurvey(mode.value, answers.value)
		filled.value = { ...filled.value, [mode.value]: true }
		uni.showToast({ title: '提交成功，感谢反馈', icon: 'success' })
		setTimeout(() => {
			mode.value = ''
			items.value = []
			answers.value = {}
		}, 600)
	} catch (error) {
		uni.showToast({ title: getErrorMessage(error, '提交失败，请稍后再试'), icon: 'none' })
	} finally {
		submitting.value = false
	}
}

const goBack = () => {
	if (mode.value) {
		uni.showModal({
			title: '退出填写',
			content: '当前问卷还没有提交，退出后已选内容不会保存。',
			success: (res) => {
				if (res.confirm) {
					mode.value = ''
					items.value = []
					answers.value = {}
				}
			}
		})
		return
	}
	uni.navigateBack()
}

onLoad(() => {
	loadEntry()
})
</script>

<style lang="scss" scoped>
.page { min-height: 100vh; background: #f3f6fa; color: #0f172a; }
.header { height: calc(env(safe-area-inset-top) + 92rpx); padding: env(safe-area-inset-top) 24rpx 0; display: flex; align-items: center; justify-content: space-between; box-sizing: border-box; }
.header-btn { width: 76rpx; height: 64rpx; display: flex; align-items: center; justify-content: center; }
.header-title { font-size: 34rpx; font-weight: 700; }
.page-scroll { height: calc(100vh - env(safe-area-inset-top) - 92rpx); padding: 12rpx 24rpx calc(env(safe-area-inset-bottom) + 40rpx); box-sizing: border-box; }
.page-scroll.with-footer { height: calc(100vh - env(safe-area-inset-top) - 92rpx - 150rpx); }

.state-card, .group-card, .tips-card, .entry-card { background: #fff; border-radius: 22rpx; box-shadow: 0 8rpx 22rpx rgba(15, 23, 42, 0.05); }
.state-card { padding: 40rpx 26rpx; text-align: center; }
.state-title { font-size: 30rpx; font-weight: 700; }
.state-text { margin-top: 12rpx; font-size: 23rpx; line-height: 1.6; color: #64748b; }

.group-card { padding: 24rpx; margin-bottom: 18rpx; }
.group-title { font-size: 30rpx; font-weight: 750; }
.group-subtitle { margin-top: 6rpx; font-size: 22rpx; color: #64748b; }
.round-intro { margin-top: 10rpx; font-size: 23rpx; line-height: 1.6; color: #475569; }

.entry-card { display: flex; align-items: center; justify-content: space-between; gap: 18rpx; padding: 26rpx 24rpx; margin-bottom: 18rpx; box-shadow: inset 0 0 0 2rpx #dbe3ee; }
.entry-card.primary { background: linear-gradient(135deg, #2563eb, #0ea5e9); box-shadow: 0 10rpx 26rpx rgba(37, 99, 235, 0.28); }
.entry-card.primary .entry-title, .entry-card.primary .entry-sub, .entry-card.primary .entry-action { color: #fff; }
.entry-card.done { opacity: 0.55; }
.entry-main { min-width: 0; flex: 1; }
.entry-title { font-size: 29rpx; font-weight: 750; }
.entry-sub { margin-top: 8rpx; font-size: 22rpx; line-height: 1.5; color: #64748b; }
.entry-action { flex: 0 0 auto; padding: 12rpx 22rpx; border-radius: 999rpx; background: rgba(37, 99, 235, 0.12); color: #1d4ed8; font-size: 23rpx; font-weight: 700; }
.entry-card.primary .entry-action { background: rgba(255, 255, 255, 0.22); }

.tips-card { padding: 22rpx 24rpx; }
.tips-line { font-size: 22rpx; line-height: 1.8; color: #64748b; }

.question { margin-top: 22rpx; padding-top: 20rpx; border-top: 1rpx solid #eef2f7; }
.question:first-of-type { border-top: none; padding-top: 6rpx; }
.question-title { font-size: 26rpx; font-weight: 700; line-height: 1.5; }
.optional { margin-left: 10rpx; font-size: 20rpx; font-weight: 500; color: #94a3b8; }
.question-hint { margin-top: 6rpx; font-size: 21rpx; color: #94a3b8; }
.option-list { margin-top: 14rpx; display: flex; flex-direction: column; gap: 12rpx; }
.option { padding: 20rpx 22rpx; border-radius: 16rpx; background: #f8fafc; box-shadow: inset 0 0 0 1rpx #e2e8f0; font-size: 24rpx; color: #1e293b; }
.option.active { background: #eff6ff; box-shadow: inset 0 0 0 2rpx #2563eb; color: #1d4ed8; font-weight: 700; }
.option.rank { display: flex; align-items: center; gap: 16rpx; }
.rank-badge { flex: 0 0 auto; width: 40rpx; height: 40rpx; border-radius: 999rpx; display: flex; align-items: center; justify-content: center; background: #e2e8f0; color: #475569; font-size: 22rpx; font-weight: 700; }
.option.rank.active .rank-badge { background: #2563eb; color: #fff; }
.option-text { min-width: 0; flex: 1; }

.scale-row { margin-top: 14rpx; display: flex; flex-wrap: wrap; gap: 10rpx; }
.scale-dot { width: 62rpx; height: 62rpx; border-radius: 14rpx; display: flex; align-items: center; justify-content: center; background: #f8fafc; box-shadow: inset 0 0 0 1rpx #e2e8f0; font-size: 24rpx; color: #1e293b; }
.scale-dot.active { background: #2563eb; color: #fff; box-shadow: none; font-weight: 700; }

.text-input { margin-top: 14rpx; width: 100%; min-height: 130rpx; padding: 18rpx 20rpx; border-radius: 16rpx; background: #f8fafc; box-shadow: inset 0 0 0 1rpx #e2e8f0; box-sizing: border-box; font-size: 24rpx; }

.followup { margin-top: 18rpx; padding: 18rpx; border-radius: 16rpx; background: #f8fafc; }
.followup-title { font-size: 24rpx; font-weight: 700; color: #1e293b; }

.footer { position: fixed; left: 0; right: 0; bottom: 0; padding: 14rpx 24rpx calc(env(safe-area-inset-bottom) + 18rpx); background: #fff; border-top: 1rpx solid #e2e8f0; }
.submit-btn { height: 84rpx; border-radius: 18rpx; background: linear-gradient(90deg, #2563eb, #0ea5e9); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 27rpx; font-weight: 750; }
.submit-btn.disabled { opacity: 0.55; }
</style>
