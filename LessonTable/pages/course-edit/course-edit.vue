<template>
	<view class="edit-page">
		<view class="header">
			<view class="header-btn" @click="goBack">
				<uni-icons type="left" size="24" color="#1f2937"></uni-icons>
			</view>
			<view class="header-title">编辑课程</view>
			<view class="header-btn header-btn-save" @click="saveCourse()">
				<image class="header-save-icon" src="/static/images/icon-check.svg" mode="aspectFit"></image>
			</view>
		</view>

		<scroll-view scroll-y class="edit-scroll">
			<view class="form-card">
				<view class="field-group">
					<view class="field">
						<view class="field-label">课程名</view>
						<input class="field-input" v-model="form.name" placeholder="必填" />
						<view class="field-required">必填</view>
					</view>

					<view class="field">
						<view class="field-label">教室</view>
						<input class="field-input" v-model="form.address" placeholder="非必填" />
						<view class="field-optional">非必填</view>
					</view>

					<view class="field">
						<view class="field-label">老师</view>
						<input class="field-input" v-model="form.teacher" placeholder="非必填" />
						<view class="field-optional">非必填</view>
					</view>

					<view class="field clickable" @click="openSectionPicker">
						<view class="field-label">上课时间</view>
						<view class="time-value">星期{{ weekDayText }} 第{{ form.sectionStart }}-{{ form.sectionEnd }}节</view>
						<uni-icons type="right" size="18" color="#94a3b8"></uni-icons>
					</view>
				</view>

				<view class="week-block">
					<view class="week-block-title">上课周数</view>
					<view class="week-mode-row">
						<view class="week-mode-item" :class="{ active: weekMode === 'odd' }" @click="applyWeekMode('odd')">
							<text class="dot"></text>
							<text>单周</text>
						</view>
						<view class="week-mode-item" :class="{ active: weekMode === 'even' }" @click="applyWeekMode('even')">
							<text class="dot"></text>
							<text>双周</text>
						</view>
						<view class="week-mode-item" :class="{ active: weekMode === 'all' }" @click="applyWeekMode('all')">
							<text class="dot"></text>
							<text>全选</text>
						</view>
					</view>
					<view class="week-grid">
						<view v-for="week in weekOptions" :key="`week-${week}`"
							class="week-chip"
							:class="{ active: selectedWeeks.includes(week) }"
							@click="toggleWeek(week)">
							{{ week }}
						</view>
					</view>
				</view>

				<view class="color-block">
					<view class="color-title">颜色</view>
					<view class="color-grid">
						<view v-for="color in colorPalette" :key="color"
							class="color-chip"
							:style="{ backgroundColor: color }"
							:class="{ active: selectedColor === color }"
							@click="selectedColor = color">
						</view>
					</view>
				</view>
			</view>

			<view class="delete-wrap">
				<button class="create-btn" @click="saveCourse({ forceCreate: true })">{{ isEditMode ? '另存为新课程' : '创建课程' }}</button>
				<button class="delete-btn" :disabled="!isEditMode" @click="deleteCourse">删除课程</button>
			</view>
		</scroll-view>

		<view v-if="showSectionPicker" class="picker-mask" @click="cancelSectionPicker">
			<view class="picker-panel" @click.stop>
				<view class="picker-title">选择节数</view>
				<view class="picker-column-labels">
					<text>开始节</text>
					<text>结束节</text>
				</view>
				<view class="picker-view-wrap">
					<picker-view class="picker-view"
						:value="[pickerStartIndex, pickerEndIndex]"
						:indicator-style="pickerIndicatorStyle"
						:mask-style="pickerMaskStyle"
						@change="onSectionPickerChange">
						<picker-view-column>
							<view v-for="(section, idx) in sectionOptions" :key="`start-${idx}`" class="picker-item">{{ section }}节</view>
						</picker-view-column>
						<picker-view-column>
							<view v-for="(section, idx) in sectionOptions" :key="`end-${idx}`" class="picker-item">{{ section }}节</view>
						</picker-view-column>
					</picker-view>
				</view>
				<view class="picker-actions">
					<button class="picker-btn cancel" @click="cancelSectionPicker">取消</button>
					<button class="picker-btn confirm" @click="confirmSectionPicker">确定</button>
				</view>
			</view>
		</view>
	</view>
</template>

<script setup>
import { computed, ref } from 'vue'

const CUSTOM_COURSE_STORE_KEY = 'CustomCoursesByUser'
const CUSTOM_COURSE_META_KEY = 'CustomCourseMetaByUser'

const form = ref({
	name: '',
	address: '',
	teacher: '',
	weekDay: 1,
	sectionStart: 1,
	sectionEnd: 1
})

const weekMode = ref('all')
const totalWeeks = ref(20)
const currentWeek = ref(1)
const selectedWeeks = ref([])
const selectedColor = ref('#cfe2f3')
const isEditMode = ref(false)
const editingCourseId = ref('')
const userID = ref('')
const showSectionPicker = ref(false)
const pickerStartIndex = ref(0)
const pickerEndIndex = ref(0)
const pickerIndicatorStyle = 'height: 88rpx; border-top: 1px solid rgba(148,163,184,0.26); border-bottom: 1px solid rgba(148,163,184,0.26); border-radius: 20rpx; background: rgba(37,132,255,0.08);'
const pickerMaskStyle = 'background-image: linear-gradient(to bottom, rgba(255,255,255,0.92), rgba(255,255,255,0.46) 30%, rgba(255,255,255,0) 50%, rgba(255,255,255,0.46) 70%, rgba(255,255,255,0.92));'

const weekDayMap = ['一', '二', '三', '四', '五', '六', '日']

const colorPalette = [
	'#9fd3ff', '#ffd6a5', '#9be7c4', '#c8b8ff', '#ffe27a', '#ffb4c8',
	'#88b3ff', '#7fded4', '#ff9f9f', '#b7f58b', '#f9bb6f', '#98c1ff'
]

const sectionOptions = computed(() => Array.from({
	length: 12
}, (_, index) => index + 1))

const weekOptions = computed(() => Array.from({
	length: Math.max(1, Number(totalWeeks.value) || 20)
}, (_, index) => index + 1))

const weekDayText = computed(() => {
	return weekDayMap[Math.max(1, Math.min(7, Number(form.value.weekDay) || 1)) - 1]
})

const sortWeeks = (weeks) => {
	return Array.from(new Set(weeks.map(item => Number(item)).filter(item => Number.isFinite(item)))).sort((a, b) => a - b)
}

const formatWeekText = (weeks) => {
	if (!Array.isArray(weeks) || !weeks.length) return '自定义周次'
	const parts = []
	let start = weeks[0]
	let prev = weeks[0]
	for (let i = 1; i < weeks.length; i++) {
		const current = weeks[i]
		if (current === prev + 1) {
			prev = current
			continue
		}
		parts.push(start === prev ? `${start}` : `${start}-${prev}`)
		start = current
		prev = current
	}
	parts.push(start === prev ? `${start}` : `${start}-${prev}`)
	return `${parts.join(',')}周`
}

const getCourseBucket = () => {
	const bucket = uni.getStorageSync(CUSTOM_COURSE_STORE_KEY)
	return bucket && typeof bucket === 'object' ? bucket : {}
}

const getCurrentSemesterMark = () => {
	const today = new Date()
	const year = today.getFullYear()
	const month = today.getMonth() + 1
	return month >= 2 && month <= 7 ? `${year}-spring` : `${year}-fall`
}

const saveCustomSemesterMark = () => {
	if (!userID.value) return
	const meta = uni.getStorageSync(CUSTOM_COURSE_META_KEY)
	const map = meta && typeof meta === 'object' ? meta : {}
	map[userID.value] = getCurrentSemesterMark()
	uni.setStorageSync(CUSTOM_COURSE_META_KEY, map)
}

const getUserCourseList = () => {
	if (!userID.value) return []
	const bucket = getCourseBucket()
	return Array.isArray(bucket[userID.value]) ? bucket[userID.value] : []
}

const saveUserCourseList = (list) => {
	if (!userID.value) return
	const bucket = getCourseBucket()
	bucket[userID.value] = list
	uni.setStorageSync(CUSTOM_COURSE_STORE_KEY, bucket)
	saveCustomSemesterMark()
}

const getOnlineCourseList = () => {
	const scheduleData = uni.getStorageSync('ScheduleData') || {}
	if (Array.isArray(scheduleData.onlineCourseList) && scheduleData.onlineCourseList.length) {
		return scheduleData.onlineCourseList
	}
	if (Array.isArray(scheduleData.courseList)) {
		return scheduleData.courseList.filter(item => !item?.isLocal)
	}
	return []
}

const isRangeOverlap = (startA, endA, startB, endB) => {
	return Math.max(startA, startB) <= Math.min(endA, endB)
}

const hasWeekOverlap = (courseWeeks, selected) => {
	if (!Array.isArray(courseWeeks) || !courseWeeks.length || !Array.isArray(selected) || !selected.length) return false
	const weekSet = new Set(courseWeeks.map(item => Number(item)))
	return selected.some(week => weekSet.has(Number(week)))
}

const findOnlineConflictCourse = ({ weekDay, sectionStart, sectionEnd, weeks }) => {
	const onlineList = getOnlineCourseList()
	for (const course of onlineList) {
		const courseWeek = Number(course?.week)
		if (courseWeek !== Number(weekDay)) continue
		const courseStart = Number(course?.section) || 1
		const courseEnd = courseStart + Math.max(1, Number(course?.sectionCount) || 1) - 1
		if (!isRangeOverlap(courseStart, courseEnd, sectionStart, sectionEnd)) continue
		if (!hasWeekOverlap(course?.weeks || [], weeks)) continue
		return course
	}
	return null
}

const validateOnlineConflict = ({ sectionStart, sectionEnd, weeks, showMessage = true }) => {
	const conflict = findOnlineConflictCourse({
		weekDay: form.value.weekDay,
		sectionStart,
		sectionEnd,
		weeks
	})
	if (!conflict || !showMessage) return conflict
	uni.showModal({
		title: '无法创建',
		content: `该时间段与在线课程冲突：${conflict.name || '未知课程'}（第${conflict.section || '?'}-${(Number(conflict.section) || 1) + Math.max(1, Number(conflict.sectionCount) || 1) - 1}节，${conflict.weekText || '指定周次'}）`,
		showCancel: false
	})
	return conflict
}

const applyWeekMode = (mode) => {
	const targetWeeks = mode === 'odd'
		? weekOptions.value.filter(week => week % 2 === 1)
		: mode === 'even'
			? weekOptions.value.filter(week => week % 2 === 0)
			: [...weekOptions.value]

	const isRepeatedAllSelection = mode === 'all' &&
		weekMode.value === 'all' &&
		targetWeeks.length === selectedWeeks.value.length &&
		targetWeeks.every((week, index) => week === selectedWeeks.value[index])

	if (isRepeatedAllSelection) {
		selectedWeeks.value = []
		weekMode.value = 'custom'
		return
	}

	weekMode.value = mode
	selectedWeeks.value = targetWeeks
}

const toggleWeek = (week) => {
	if (selectedWeeks.value.includes(week)) {
		selectedWeeks.value = selectedWeeks.value.filter(item => item !== week)
	} else {
		selectedWeeks.value = sortWeeks([...selectedWeeks.value, week])
	}
	weekMode.value = selectedWeeks.value.length === weekOptions.value.length ? 'all' : 'custom'
}

const openSectionPicker = () => {
	pickerStartIndex.value = Math.max(0, form.value.sectionStart - 1)
	pickerEndIndex.value = Math.max(0, form.value.sectionEnd - 1)
	showSectionPicker.value = true
}

const onSectionPickerChange = (event) => {
	const [startIndex, endIndex] = event.detail.value || [0, 0]
	pickerStartIndex.value = Math.max(0, startIndex || 0)
	pickerEndIndex.value = Math.max(0, endIndex || 0)
}

const cancelSectionPicker = () => {
	showSectionPicker.value = false
}

const confirmSectionPicker = () => {
	let start = sectionOptions.value[pickerStartIndex.value] || 1
	let end = sectionOptions.value[pickerEndIndex.value] || start
	if (end < start) {
		const temp = start
		start = end
		end = temp
	}
	const selected = sortWeeks(selectedWeeks.value)
	if (selected.length && validateOnlineConflict({
		sectionStart: start,
		sectionEnd: end,
		weeks: selected
	})) {
		return
	}
	form.value.sectionStart = start
	form.value.sectionEnd = end
	showSectionPicker.value = false
}

const buildLocalCourse = (forceCreate = false) => {
	const weeks = sortWeeks(selectedWeeks.value)
	const sectionCount = form.value.sectionEnd - form.value.sectionStart + 1
	const timestamp = Date.now()
	const localId = !forceCreate && editingCourseId.value ? editingCourseId.value : `local-${timestamp}-${Math.random().toString(36).slice(2, 7)}`
	return {
		id: localId,
		name: form.value.name.trim(),
		num: `custom-${timestamp}`,
		credit: '',
		week: String(form.value.weekDay),
		totalHours: '',
		category: '自定义课程',
		CourseAttribute: '自定义',
		teacher: form.value.teacher.trim(),
		teacherUserID: '',
		section: String(form.value.sectionStart),
		sectionCount: String(Math.max(1, sectionCount)),
		address: form.value.address.trim(),
		weekText: formatWeekText(weeks),
		weeks,
		customColor: selectedColor.value,
		source: '本地数据',
		isLocal: true
	}
}

const saveCourse = (options = {}) => {
	const forceCreate = options?.forceCreate === true
	if (!form.value.name.trim()) {
		uni.showToast({
			title: '课程名不能为空',
			icon: 'none'
		})
		return
	}
	if (!selectedWeeks.value.length) {
		uni.showToast({
			title: '请至少选择一周',
			icon: 'none'
		})
		return
	}
	if (validateOnlineConflict({
		sectionStart: form.value.sectionStart,
		sectionEnd: form.value.sectionEnd,
		weeks: sortWeeks(selectedWeeks.value)
	})) {
		return
	}
	const list = getUserCourseList()
	const newCourse = buildLocalCourse(forceCreate)
	const existedIndex = list.findIndex(item => `${item.id}` === `${newCourse.id}`)
	if (existedIndex > -1) {
		list.splice(existedIndex, 1, newCourse)
	} else {
		list.push(newCourse)
	}
	saveUserCourseList(list)
	uni.$emit('customCoursesChanged')
	uni.showToast({
		title: forceCreate ? '课程已创建' : (isEditMode.value ? '课程已更新' : '课程已添加'),
		icon: 'success'
	})
	setTimeout(() => {
		uni.navigateBack()
	}, 180)
}

const deleteCourse = () => {
	if (!isEditMode.value || !editingCourseId.value) return
	uni.showModal({
		title: '删除课程',
		content: '确认删除这条自定义课程吗？',
		success: (res) => {
			if (!res.confirm) return
			const list = getUserCourseList().filter(item => `${item.id}` !== `${editingCourseId.value}`)
			saveUserCourseList(list)
			uni.$emit('customCoursesChanged')
			uni.showToast({
				title: '已删除',
				icon: 'success'
			})
			setTimeout(() => {
				uni.navigateBack()
			}, 180)
		}
	})
}

const goBack = () => {
	uni.navigateBack()
}

onLoad((query) => {
	const settings = uni.getStorageSync('scheduleSettings') || {}
	userID.value = uni.getStorageSync('UserID') || ''
	totalWeeks.value = Math.max(1, Number(query?.totalWeek) || Number(settings.totalWeeks) || 20)
	currentWeek.value = Math.max(1, Number(query?.weekNumber) || Number(settings.currentWeek) || 1)
	form.value.weekDay = Math.max(1, Math.min(7, Number(query?.weekDay) || 1))
	form.value.sectionStart = Math.max(1, Math.min(12, Number(query?.section) || 1))
	form.value.sectionEnd = form.value.sectionStart
	applyWeekMode('all')
	selectedColor.value = colorPalette[0]

	const courseId = query?.courseId ? `${query.courseId}` : ''
	if (!courseId) return
	const localCourse = getUserCourseList().find(item => `${item.id}` === courseId)
	if (!localCourse) return
	isEditMode.value = true
	editingCourseId.value = courseId
	form.value.name = localCourse.name || ''
	form.value.address = localCourse.address || ''
	form.value.teacher = localCourse.teacher || ''
	form.value.weekDay = Math.max(1, Math.min(7, Number(localCourse.week) || form.value.weekDay))
	form.value.sectionStart = Math.max(1, Math.min(12, Number(localCourse.section) || form.value.sectionStart))
	const count = Math.max(1, Number(localCourse.sectionCount) || 1)
	form.value.sectionEnd = Math.max(form.value.sectionStart, Math.min(12, form.value.sectionStart + count - 1))
	selectedWeeks.value = sortWeeks(Array.isArray(localCourse.weeks) ? localCourse.weeks : [])
	if (!selectedWeeks.value.length) {
		applyWeekMode('all')
	}
	selectedColor.value = localCourse.customColor || colorPalette[0]
})
</script>

<style lang="scss" scoped>
.edit-page {
	min-height: 100vh;
	background: #f5f6fa;
}

.header {
	height: 96rpx;
	background: rgba(255, 255, 255, 0.96);
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 0 26rpx;
	border-bottom: 1rpx solid rgba(15, 23, 42, 0.08);
	position: sticky;
	top: 0;
	z-index: 30;
}

.header-btn {
	width: 56rpx;
	height: 56rpx;
	display: flex;
	align-items: center;
	justify-content: center;
}

.header-btn-save {
	border-radius: 16rpx;
}

.header-save-icon {
	width: 44rpx;
	height: 44rpx;
}

.header-title {
	font-size: 38rpx;
	font-weight: 700;
	color: #1f2937;
}

.edit-scroll {
	height: calc(100vh - 96rpx);
}

.form-card {
	margin: 24rpx 24rpx 0;
	padding: 24rpx;
	background: rgba(255, 255, 255, 0.95);
	border-radius: 26rpx;
	box-shadow: 0 12rpx 32rpx rgba(15, 23, 42, 0.06);
}

.field-group {
	display: flex;
	flex-direction: column;
	gap: 16rpx;
}

.field {
	height: 98rpx;
	background: #f2f4f8;
	border-radius: 22rpx;
	display: flex;
	align-items: center;
	padding: 0 28rpx;
}

.field.clickable {
	margin-top: 4rpx;
}

.field-label {
	font-size: 40rpx;
	font-weight: 600;
	color: #111827;
	min-width: 146rpx;
}

.field-input {
	flex: 1;
	font-size: 34rpx;
	color: #111827;
}

.field-required {
	font-size: 34rpx;
	color: #ef4444;
	font-weight: 600;
}

.field-optional {
	font-size: 34rpx;
	color: #a3a3a3;
}

.time-value {
	flex: 1;
	text-align: right;
	font-size: 33rpx;
	color: #6b7280;
	margin-right: 10rpx;
}

.week-block {
	margin-top: 26rpx;
}

.week-block-title {
	font-size: 42rpx;
	font-weight: 600;
	color: #111827;
}

.week-mode-row {
	margin-top: 20rpx;
	display: flex;
	justify-content: flex-end;
	gap: 30rpx;
}

.week-mode-item {
	display: flex;
	align-items: center;
	gap: 8rpx;
	font-size: 28rpx;
	color: #3b82f6;
}

.week-mode-item .dot {
	width: 18rpx;
	height: 18rpx;
	border: 2rpx solid #d1d5db;
	border-radius: 50%;
	box-sizing: border-box;
}

.week-mode-item.active .dot {
	border-color: #2584ff;
	background: #2584ff;
}

.week-grid {
	margin-top: 18rpx;
	display: grid;
	grid-template-columns: repeat(6, minmax(0, 1fr));
	gap: 12rpx 10rpx;
}

.week-chip {
	height: 64rpx;
	border-radius: 20rpx;
	background: #dbeafe;
	color: #2563eb;
	font-size: 30rpx;
	font-weight: 600;
	display: flex;
	align-items: center;
	justify-content: center;
}

.week-chip.active {
	background: #2584ff;
	color: #fff;
}

.color-block {
	margin-top: 30rpx;
}

.color-title {
	font-size: 42rpx;
	font-weight: 600;
	color: #111827;
}

.color-grid {
	margin-top: 22rpx;
	display: grid;
	grid-template-columns: repeat(6, minmax(0, 1fr));
	gap: 16rpx 10rpx;
}

.color-chip {
	width: 72rpx;
	height: 72rpx;
	justify-self: center;
	border-radius: 50%;
	border: 2rpx solid rgba(255, 255, 255, 0.92);
	box-sizing: border-box;
	box-shadow: 0 3rpx 10rpx rgba(71, 85, 105, 0.14);
}

.color-chip.active {
	border-color: rgba(37, 132, 255, 0.95);
	box-shadow: 0 0 0 2rpx rgba(37, 132, 255, 0.24);
}

.delete-wrap {
	padding: 32rpx 24rpx calc(env(safe-area-inset-bottom) + 24rpx);
}

.create-btn {
	height: 84rpx;
	line-height: 84rpx;
	border-radius: 999rpx;
	background: #2584ff;
	color: #fff;
	border: none;
	font-size: 34rpx;
	margin-bottom: 14rpx;
}

.delete-btn {
	height: 84rpx;
	line-height: 84rpx;
	border-radius: 999rpx;
	background: #e5e7eb;
	color: #6b7280;
	border: none;
	font-size: 34rpx;
}

.delete-btn[disabled] {
	opacity: 0.65;
}

.picker-mask {
	position: fixed;
	inset: 0;
	background: rgba(15, 23, 42, 0.28);
	z-index: 80;
	display: flex;
	align-items: flex-end;
}

.picker-panel {
	width: 100%;
	background: #fefefe;
	border-radius: 32rpx 32rpx 0 0;
	padding: 22rpx 24rpx calc(env(safe-area-inset-bottom) + 24rpx);
}

.picker-title {
	font-size: 40rpx;
	font-weight: 600;
	color: #1f2937;
	text-align: center;
}

.picker-column-labels {
	margin-top: 10rpx;
	display: flex;
	justify-content: space-around;
	font-size: 28rpx;
	color: #64748b;
}

.picker-view-wrap {
	margin-top: 10rpx;
	border-radius: 22rpx;
	background: rgba(248, 250, 252, 0.9);
	padding: 4rpx 0;
}

.picker-view {
	height: 360rpx;
}

.picker-item {
	height: 88rpx;
	line-height: 88rpx;
	text-align: center;
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 48rpx;
	font-weight: 600;
	color: #6b7280;
}

.picker-actions {
	display: flex;
	gap: 18rpx;
	margin-top: 18rpx;
}

.picker-btn {
	flex: 1;
	height: 84rpx;
	line-height: 84rpx;
	border: none;
	border-radius: 999rpx;
	font-size: 34rpx;
}

.picker-btn.cancel {
	background: #e5e7eb;
	color: #4b5563;
}

.picker-btn.confirm {
	background: #2584ff;
	color: #fff;
}
</style>
