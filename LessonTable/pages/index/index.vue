<template>
	<view class="page-root">
	<!-- 背景层单独包裹并自建层叠上下文：.page-root 若自身形成层叠上下文（isolation），
	     会把内部 2199/2200 的弹窗层级一并限制住，导致弹窗被 uni-tabbar(998) 盖住 -->
	<view class="bg-layer">
		<image class="bgImg" src="/common/images/backgroundImg.png" />
		<view class="bgMask"></view>
	</view>
	<view v-if="isTestBuild" class="test-build-badge">管理员内测 v{{ APP_VERSION }}</view>
	<view class="layout" :class="['mode-' + layoutMetrics.mode, animationEnabled ? 'anim-on' : 'anim-off']"
		@click="handleGlobalTap()">
		<view class="navbar">
			<view class="statusBar" :style="{ height: getStatusBarHeight() + 'px' }"></view>
			<view class="titleBar"
				:style="{ height: getTitleBarHeight() + 'px', paddingLeft: getLeftIconLeft() + 'px' }">
				<view v-if="currentWeekHasScheduleAdjustment" class="adjustment-view-switch ripple-host ripple-clip"
					:class="{ original: currentScheduleViewMode === 'original' }" @click.stop="toggleScheduleAdjustmentView($event)">
					<text>{{ currentScheduleViewMode === 'original' ? '原课表' : '调课后' }}</text>
					<UniIcons type="loop" color="currentColor" :size="getAdjustmentSwitchIconSize()"></UniIcons>
				</view>
				<view class="week-switch ripple-host ripple-clip" @click="SelectWeeksPopup($event)">
					<view class="title">第{{ ScheduleData.TemporaryWeek }}周</view>
					<UniIcons class="icon" :class="{ open: activeSheet === 'week' && bottomSheetVisible }" type="down" color="#1f2937"
						:size="getWeekSwitchIconSize()"></UniIcons>
				</view>
				<view class="setting-btn ripple-host ripple-clip" @click="goToSettings($event)">
					<UniIcons type="gear-filled" :size="getSettingIconSize()" color="#334155"></UniIcons>
				</view>
			</view>
			<view class="week-list">
				<view class="now-month" :style="{ width: layoutMetrics.timeColumnWidth + 'px' }"></view>
				<view class="week-item" v-for="(item, index) in ScheduleData.weekDayCount"
					:key="'week-' + index" :style="getWeekItemStyle()"
					:class="getTodayMonthDate() == ScheduleData.weekCalendar[index] ? 'active-day' : ''">
					<text class="week-name"
						:class="getTodayMonthDate() == ScheduleData.weekCalendar[index] ? 'active' : ''">周{{
							ScheduleData.weekIndexText[index] }}</text>
					<text class="week-date"
						:class="getTodayMonthDate() == ScheduleData.weekCalendar[index] ? 'active' : ''">{{
							ScheduleData.weekCalendar[index] }}</text>
				</view>
			</view>
		</view>
		<view class="fill" :style="{ height: getHeaderHeight() + 'px' }">
		</view>
	</view>
	<scroll-view class="container schedule-scroll" scroll-y :style="getScrollViewportStyle()"
		:class="['mode-' + layoutMetrics.mode, animationEnabled ? 'anim-on' : 'anim-off']"
		@click="handleGlobalTap()">

		<view class="course-content" :style="getCourseContentStyle()">
			<view class="course-nums" :style="{ width: layoutMetrics.timeColumnWidth + 'px', height: getCourseAreaHeight() + 'px' }">
				<view class="course-num" v-for="(item, index) in ScheduleData.ClassesCountPerDay" :key="'course-num-' + index"
					:style="getCourseNumStyle(index)">
					<view class="course-num-text">
						{{ item }}
					</view>
					<view class="course-num-Time">
						{{ ScheduleData.currentScheduleTime[index] ? ScheduleData.currentScheduleTime[index][0] : '' }}
						{{ ScheduleData.currentScheduleTime[index] ? ScheduleData.currentScheduleTime[index][1] : '' }}
					</view>
				</view>
			</view>

			<swiper class="course-swpier" :current="swiperCurrent" @change="swiperSwitchWeek" :style="getSwiperStyle()">
				<swiper-item v-for="panel in displayWeekPanels" :key="panel.key">
					<view class="course-list" :style="getCourseListStyle()">
						<view class="course-grid" :style="getCourseGridStyle()">
							<view class="grid-hline" v-for="line in getGridLineList()" :key="line.key" :style="getGridLineStyle(line.top)">
							</view>
						</view>
						<template v-for="slot in getWeekEmptySlots(panel.week)" :key="slot.key">
							<view class="empty-slot-hit ripple-host ripple-clip"
								:style="getEmptySlotStyle(slot)"
								@click.stop="handleEmptySlotTap(slot, $event)">
								<view v-if="isEmptySlotArmed(slot)" class="empty-slot-plus">+</view>
							</view>
						</template>
						<template v-for="item in getWeekCourseRenderList(panel.week)" :key="item.renderKey">
							<view class="course-item" :class="{ 'is-conflict': item.isConflict, 'is-adjusted': item.isScheduleAdjustment || item.hasScheduleAdjustment }"
								:style="getCourseItemStyle(item)">
								<view class="course-item__content ripple-host ripple-clip" :style="getCourseCardStyle(item)"
									@click="GetCourseDetails(item, $event)">
									<view v-if="item.isConflict || item.isScheduleAdjustment || item.hasScheduleAdjustment" class="course-status-badges">
										<view v-if="item.isConflict" class="course-status-badge conflict">冲突</view>
										<view v-if="item.isScheduleAdjustment || item.hasScheduleAdjustment" class="course-status-badge adjusted">调课</view>
									</view>
									<view class="course-item__content_name">
										{{ item.name || '未知课程' }}
									</view>
									<view class="course-item__content_address">
										@{{ item.address ? item.address : '未知' }}
									</view>
									<view class="course-item__content_teacher">
										{{ item.teacher ? item.teacher : '未知' }}
									</view>
								</view>
							</view>
						</template>
					</view>
				</swiper-item>
			</swiper>
			<template v-for="restItem in restBreaks" :key="'full-break-' + restItem.label">
				<view class="course-rest-band" v-if="restItem.afterSection < ScheduleData.ClassesCountPerDay"
					:style="getRestBandStyle(restItem.afterSection)">
					<text>{{ restItem.label }}</text>
				</view>
			</template>

		</view>
	</scroll-view>

	<view v-if="debugState" class="admin-debug-float" @click.stop>
		<view class="admin-debug-copy">
			<text class="admin-debug-label">调试身份</text>
			<text class="admin-debug-user">{{ debugState.targetUserId }}</text>
		</view>
		<view class="admin-debug-exit" @click.stop="confirmExitAdminDebug">返回本人</view>
	</view>

	<view v-if="bottomSheetVisible" class="sheet-mask" :class="{ show: sheetOpen, motionless: !animationEnabled }"
		@click="handleSheetMaskTap($event)"></view>
	<view v-if="bottomSheetVisible" class="bottom-sheet"
		:class="{ show: sheetOpen, dragging: sheetDragging, motionless: !animationEnabled }"
		:style="getBottomSheetStyle()" @click.stop="handleGlobalTap()">
		<view class="switch-week__popup" :class="{ 'course-sheet-popup': activeSheet === 'course' }">
			<view class="sheet-drag-zone"
				@touchstart.stop="handleSheetDragStart"
				@touchmove.stop.prevent="handleSheetDragMove"
				@touchend.stop="handleSheetDragEnd"
				@touchcancel.stop="handleSheetDragEnd">
				<view class="sheet-grip"></view>
			</view>
			<template v-if="activeSheet === 'week'">
				<view class="switch-week__title">切换周数</view>
				<view class="switch-week__list">
					<view class="switch-week__item" v-for="(item, index) in ScheduleData.totalWeek" :key="index">
						<view class="switch-week__item-box ripple-host ripple-clip" :class="ScheduleData.nowWeek == item ? 'active' : ''"
							@click="ViewWeeklySchedule(item, $event)">{{ item }}
							<view class="nowWeekTip">
								{{ ScheduleData.nowWeek == item ? '当前' : '' }}
							</view>
						</view>
					</view>
				</view>
			</template>
			<template v-else-if="activeSheet === 'course'">
				<view class="sheet-title-wrap">
					<view class="switch-week__title">课程详情</view>
					<view class="sheet-action-group">
						<view class="sheet-comment-btn ripple-host ripple-clip" v-if="canOpenCourseComments"
							@click="goCourseComments(DetailedCourseData, $event)">
							<image class="sheet-comment-icon" src="/static/images/icon-comment.svg" mode="aspectFit"></image>
						</view>
						<view class="sheet-edit-btn ripple-host ripple-clip" v-if="canEditDetailedCourse"
							@click="goEditCourse(DetailedCourseData, $event)">
							<image class="sheet-edit-icon" src="/static/images/icon-edit.svg" mode="aspectFit"></image>
						</view>
					</view>
				</view>
				<view class="course-details">
					<view class="course-name-row">
						<view class="course-name">
							{{ DetailedCourseData.name || '未知课程' }}
						</view>
						<view v-if="hasDetailedCourseConflict" class="course-conflict-switch ripple-host ripple-clip"
							@click.stop="cycleDetailedConflictCourse">
							切换 {{ detailedConflictIndex + 1 }}/{{ detailedConflictCourses.length }}
						</view>
					</view>
					<view v-if="hasDetailedCourseConflict" class="course-conflict-tip">
						当前时间段存在多门课程，点击“切换”可查看其他课程。
					</view>
					<view v-if="DetailedCourseData.isScheduleAdjustment" class="course-adjustment-tip">
						<view class="course-adjustment-title">调整后的课程</view>
						<view>{{ getScheduleAdjustmentSummary(DetailedCourseData) }}</view>
						<view v-if="DetailedCourseData.scheduleAdjustment?.noticeTitle" class="course-adjustment-notice"
							@click.stop="openScheduleAdjustmentNotice(DetailedCourseData)">
							{{ DetailedCourseData.scheduleAdjustment.noticeTitle }}<text v-if="DetailedCourseData.scheduleAdjustment?.noticeUrl"> · 查看通知</text>
						</view>
					</view>
					<view class="course-Introduce">
						<p class="course-Introduce-text"> 星期{{ ScheduleData.weekIndexText[DetailedCourseData.week - 1] || '-' }}
							&nbsp;
							| &nbsp;
							第{{ DetailedCourseData.section || '-' }}-{{ getCourseEndSection(DetailedCourseData) }}节
							&nbsp;| &nbsp; {{ DetailedCourseData.weekText || '-' }}
						</p>
						<p class="course-Introduce-text">学分：{{ DetailedCourseData.credit || '-' }} &nbsp; | &nbsp;
							课程属性：{{ DetailedCourseData.category || '-' }}&nbsp;</p>
						<p class="course-Introduce-text">课程号：{{ DetailedCourseData.num || '-' }} &nbsp; | &nbsp;
							学时：{{ DetailedCourseData.totalHours || '-' }}&nbsp;</p>
						<p class="course-Introduce-text">{{ DetailedCourseData.address || '-' }} &nbsp; | &nbsp;
							{{ DetailedCourseData.teacher || '-' }}
						</p>
					</view>
					<view v-if="hasDetailedCourseConflict" class="course-priority-panel">
						<view class="course-priority-copy">
							<view class="course-priority-title">课表优先展示</view>
							<view class="course-priority-description">发生课程冲突时，优先在课表中显示这门课程。</view>
						</view>
						<view class="course-priority-btn ripple-host ripple-clip"
							:class="{ active: isDetailedCoursePreferred }"
							@click.stop="setDetailedCoursePreferred">
							{{ isDetailedCoursePreferred ? '当前优先展示' : '设为优先展示' }}
						</view>
					</view>
				</view>
				<!-- 作为弹窗固定底栏展示，避免小屏设备需要滚动后才能看到课程群入口。 -->
				<view class="course-group-action-wrap" v-if="showCourseGroupAction">
					<button class="course-group-action-btn"
						:class="courseGroupButtonClass"
						:disabled="courseGroupButtonDisabled"
						@click.stop="handleCourseGroupButtonTap">
						{{ courseGroupButtonText }}
					</button>
				</view>
			</template>
		</view>
	</view>

	<!-- 提示窗示例 -->
	<UniPopup ref="updatePromptRef" type="dialog">
		<UniPopupDialog type="info" cancelText="取消" confirmText="确定" title="提示" content="确认更新课表吗？"
			@confirm="UpdatePromptConfirm" @close="UpdatePromptClose"></UniPopupDialog>
	</UniPopup>
	</view>
</template>

<script setup>
import { ref, computed, nextTick, onMounted, onUnmounted } from "vue";
import {
	getStatusBarHeight,
	getTitleBarHeight,
	getNavBarHeight,
	getLeftIconLeft
} from "@/utils/system.js"
import {
	GetCourseInfo
} from "@/api/apis.js"
import { APP_VERSION } from "@/utils/app.js"
import {
	requestCourseGroup
} from "@/api/courseGroups.js"
import {
	getFeedbackSession
} from "@/api/feedback.js"
import {
	clearAuthSession,
	clearComWxAutoAuthAttempt,
	exitAdminDebugSession,
	getAdminDebugState,
	getAuthSession,
	hydrateCurrentUserFromManualState,
	markComWxAutoAuthAttempt,
	saveAuthSessionFromRoute,
	setAuthSession,
	setCurrentUserId,
	shouldAttemptComWxAutoAuth
} from "@/utils/auth.js"
import {
	getPreferredDataSource,
	normalizeDataSourcePreference
} from "@/utils/dataSource.js"
import UniPopup from "@/uni_modules/uni-popup/components/uni-popup/uni-popup.vue"
import UniPopupDialog from "@/uni_modules/uni-popup/components/uni-popup-dialog/uni-popup-dialog.vue"
import UniIcons from "@/uni_modules/uni-icons/components/uni-icons/uni-icons.vue"

defineOptions({
	inheritAttrs: false
})

const CUSTOM_COURSE_STORE_KEY = 'CustomCoursesByUser'
const CUSTOM_COURSE_META_KEY = 'CustomCourseMetaByUser'
const DATA_SOURCE_MIGRATION_NOTICE_KEY = 'LessonSchedule.DataSourceAutomaticMigration.v1'
const COURSE_CONFLICT_PREFERENCE_KEY = 'LessonSchedule.CourseConflictPreferences.v1'
const COURSE_CONFLICT_NOTICE_KEY = 'LessonSchedule.CourseConflictNotice.v1'
const SCHEDULE_ADJUSTMENT_VIEW_KEY = 'LessonSchedule.ScheduleAdjustmentViews.v1'
let legacyManualCacheDetected = false
let entryNoticeSequenceStarted = false
const isTestBuild = typeof window !== 'undefined' && window.location.pathname.startsWith('/LessonSchedule-test/')
const debugState = ref(getAdminDebugState())
var ScheduleData = ref({
	UserID: '',
	semesterMark: '',
	TemporaryWeek: 1,
	nowWeek: 1,
	totalWeek: 20,
	weekDayCount: 7,
	weekIndexText: ['一', '二', '三', '四', '五', '六', '日'],
	SummerScheduleTime: [
		["8:00", "8:45"],
		["8:55", "9:40"],
		["10:00", "10:45"],
		["10:55", "11:40"],
		["14:00", "14:45"],
		["14:55", "15:40"],
		["16:00", "16:45"],
		["16:55", "17:40"],
		["19:00", "19:45"],
		["19:55", "20:40"],
		["21:00", "21:45"],
		["21:55", "22:40"]
	],
	WinterScheduleTime: [
		["8:00", "8:45"],
		["8:55", "9:40"],
		["10:00", "10:45"],
		["10:55", "11:40"],
		["13:30", "14:15"],
		["14:25", "15:10"],
		["15:30", "16:15"],
		["16:25", "17:10"],
		["18:30", "19:15"],
		["19:25", "20:10"],
		["20:30", "21:15"],
		["21:25", "22:10"]
	],
	currentScheduleTime: [],
	startDate: '',
	ClassesCountPerDay: 12,
	weekCalendar: [],
	colorList: [
		"#e6c7d4", "#e6c6d8", "#e6e3a6", "#e9c6e6", "#c3e8d9", "#c9d6e6", "#c3e1d2", "#eef5b0", "#f7d7e8", "#c3e6d9",
		"#c4c8e6", "#e6b2a8", "#c3b8e6", "#b2f7b0", "#f7e6c7", "#d8e6c6", "#a6e6e3", "#e6e9c6", "#d9c3e8", "#e6c9d6",
		"#d2c3e1", "#b0eef5", "#e8f7d7", "#d9c3e6", "#e6c4c8", "#a8e6b2", "#b8c3e6", "#b0b2f7"
	],
	courseColor: {},
	courseList: [],
	onlineCourseList: [],
	originalOnlineCourseList: [],
	customCourseList: []
})

const updatePromptRef = ref(null);
const DetailedCourseData = ref({})
const detailedConflictCourses = ref([])
const detailedConflictIndex = ref(0)
const courseConflictPreferences = ref({})
const storedAdjustmentViews = uni.getStorageSync(SCHEDULE_ADJUSTMENT_VIEW_KEY)
const scheduleAdjustmentViewPreferences = ref(
	storedAdjustmentViews && typeof storedAdjustmentViews === 'object' ? storedAdjustmentViews : {}
)
const hasDetailedCourseConflict = computed(() => detailedConflictCourses.value.length > 1)
const canEditDetailedCourse = computed(() => !!DetailedCourseData.value?.isLocal)
const canOpenCourseComments = computed(() => {
	return !DetailedCourseData.value?.isLocal && !!DetailedCourseData.value?.name && !!DetailedCourseData.value?.teacher
})
// 同一课程号、课序号、教师和学期的所有课表记录共用这一份课程群状态。
// 这里只合并操作状态，不合并或改变课表中的课程卡片与上课时间。
const courseGroupStateMap = ref({})
const courseGroupMetadataLoading = ref(false)
const courseGroupMetadataError = ref('')
const COURSE_GROUP_TESTERS = ['2023195077', '2023140018', '2023132007', '2023195023', '2022170118']

const getCourseGroupKey = (course) => {
	if (!course || course.isLocal) return ''
	const courseNumber = `${course.num || ''}`.trim().toUpperCase()
	const courseOrder = `${course.courseOrder || ''}`.trim()
	const teacherId = `${course.teacherUserID || ''}`.trim()
	const planNumber = `${course.planNumber || ''}`.trim()
	if (!courseNumber || !courseOrder || !teacherId || !planNumber) return ''
	return [planNumber, courseNumber, courseOrder, teacherId].join('|')
}

const currentCourseGroupKey = computed(() => getCourseGroupKey(DetailedCourseData.value))
const currentCourseGroupState = computed(() => {
	const key = currentCourseGroupKey.value
	return key ? (courseGroupStateMap.value[key] || null) : null
})
const currentCourseGroupRole = computed(() => {
	const userId = `${ScheduleData.value.UserID || ''}`
	const identity = userId.slice(4, 5)
	if (identity === '5' || identity === '6') return 'teacher'
	return COURSE_GROUP_TESTERS.includes(userId) ? 'tester' : 'student'
})
const showCourseGroupAction = computed(() => !DetailedCourseData.value?.isLocal)
const courseGroupButtonText = computed(() => {
	if (!currentCourseGroupKey.value) {
		if (courseGroupMetadataLoading.value) return '正在更新课程群信息…'
		if (courseGroupMetadataError.value) return '课程信息更新失败，点击重试'
		return '正在补充课程群信息…'
	}
	const state = currentCourseGroupState.value
	if (!state || state.loading) return '正在获取课程群状态…'
	if (state.acting) return currentCourseGroupRole.value === 'student' ? '正在加入课程群…' : '正在创建课程群…'
	if (state.error) return '加载失败，点击重试'
	if (currentCourseGroupRole.value === 'student') {
		if (!state.existing) return '暂无课程群'
		return state.joined ? '已加入课程群' : '加入课程群'
	}
	return state.existing ? '课程群已创建' : '创建课程群'
})
const courseGroupButtonDisabled = computed(() => {
	if (!currentCourseGroupKey.value) return courseGroupMetadataLoading.value || !courseGroupMetadataError.value
	const state = currentCourseGroupState.value
	if (!state || state.loading || state.acting) return true
	if (state.error) return false
	if (currentCourseGroupRole.value === 'student') return !state.existing || !!state.joined
	return !!state.existing
})
const courseGroupButtonClass = computed(() => {
	const state = currentCourseGroupState.value
	return {
		'is-disabled': courseGroupButtonDisabled.value,
		'is-created': !!state?.existing && currentCourseGroupRole.value !== 'student',
		'is-joined': !!state?.joined,
		'is-unavailable': currentCourseGroupRole.value === 'student' && state && !state.existing,
		'is-error': !!state?.error
	}
})
const activeSheet = ref('')
const bottomSheetVisible = ref(false)
const sheetOpen = ref(false)
const sheetDragging = ref(false)
const sheetDragStartY = ref(0)
const sheetDragOffsetY = ref(0)
const animationEnabled = ref(true)
const armedEmptySlot = ref(null)
// 当前周在滑动面板中的索引（第1周=0、中间=1、最后一周=末位；随面板结构自动计算）
const swiperCurrent = computed(() => {
	const currentWeek = clampWeekNumber(ScheduleData.value.TemporaryWeek || 1)
	const idx = displayWeekPanels.value.findIndex(p => p.week === currentWeek)
	return idx >= 0 ? idx : 0
})
let sheetCloseTimer = null
const layoutMetrics = ref({
	mode: 'default',
	timeColumnWidth: 46,
	slotHeight: 72,
	cellGap: 4,
	breakBandHeight: 30,
	breakInset: 3,
	nameFontSize: 14,
	metaFontSize: 11,
	cardPaddingX: 7,
	cardPaddingY: 6
})

const restBreaks = [{
	label: '午休',
	afterSection: 4
}, {
	label: '晚休',
	afterSection: 8
}]

const clampWeekNumber = (week) => {
	const maxWeek = Math.max(1, Number(ScheduleData.value.totalWeek) || 20)
	const parsedWeek = Number(week)
	if (!Number.isFinite(parsedWeek)) return 1
	return Math.max(1, Math.min(Math.round(parsedWeek), maxWeek))
}

const normalizeWeekList = (weeks) => {
	const source = Array.isArray(weeks) ? weeks : []
	const list = Array.from(new Set(
		source
			.map(item => Number(item))
			.filter(item => Number.isFinite(item))
			.map(item => Math.round(item))
			.filter(item => item >= 1 && item <= 60)
	)).sort((a, b) => a - b)
	if (list.length) return list
	const maxWeek = Math.max(1, Number(ScheduleData.value.totalWeek) || 20)
	return Array.from({
		length: maxWeek
	}, (_, index) => index + 1)
}

const formatWeekText = (weeks) => {
	if (!Array.isArray(weeks) || !weeks.length) return '自定义周次'
	const sections = []
	let start = weeks[0]
	let prev = weeks[0]
	for (let i = 1; i < weeks.length; i++) {
		const current = weeks[i]
		if (current === prev + 1) {
			prev = current
			continue
		}
		sections.push(start === prev ? `${start}` : `${start}-${prev}`)
		start = current
		prev = current
	}
	sections.push(start === prev ? `${start}` : `${start}-${prev}`)
	return `${sections.join(',')}周`
}

const normalizeCourseItem = (item, source = 'online') => {
	const weekList = normalizeWeekList(item?.weeks)
	const section = Math.max(1, Math.min(Number(item?.section) || 1, Number(ScheduleData.value.ClassesCountPerDay) || 12))
	const sectionCount = Math.max(1, Number(item?.sectionCount) || 1)
	const weekDay = Math.max(1, Math.min(Number(item?.week) || 1, 7))
	const idPrefix = source === 'local' ? 'local' : 'online'
	const finalId = item?.id !== undefined && item?.id !== null && item.id !== '' ? `${item.id}` : `${idPrefix}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`
	return {
		...item,
		id: finalId,
		name: item?.name || '',
		week: `${weekDay}`,
		section: `${section}`,
		sectionCount: `${sectionCount}`,
		weeks: weekList,
		weekText: item?.weekText || formatWeekText(weekList),
		address: item?.address || '',
		teacher: item?.teacher || '',
		category: item?.category || (source === 'local' ? '自定义课程' : ''),
		CourseAttribute: item?.CourseAttribute || (source === 'local' ? '自定义' : ''),
		source: source === 'local' ? '本地数据' : (item?.source || '在线数据'),
		isLocal: source === 'local' || !!item?.isLocal
	}
}

const normalizeCourseList = (list, source = 'online') => {
	if (!Array.isArray(list)) return []
	return list.map(item => normalizeCourseItem(item, source))
}

// 版本号比较：a>b 返回1，a<b 返回-1，相等返回0（主.次.修订 三位比较）
const compareVersions = (a, b) => {
	const pa = String(a || '').split('.').map(n => parseInt(n, 10) || 0)
	const pb = String(b || '').split('.').map(n => parseInt(n, 10) || 0)
	const len = Math.max(pa.length, pb.length)
	for (let i = 0; i < len; i++) {
		const x = pa[i] || 0
		const y = pb[i] || 0
		if (x !== y) return x > y ? 1 : -1
	}
	return 0
}

const parseCourseListFromResponse = (payload) => {
	if (Array.isArray(payload)) return payload
	const nested = payload?.data?.courseInfo
	if (Array.isArray(nested)) return nested
	const flat = payload?.courseInfo
	if (Array.isArray(flat)) return flat
	return []
}

const parseOriginalCourseListFromResponse = (payload) => {
	const nested = payload?.data?.originalCourseInfo
	if (Array.isArray(nested)) return nested
	const flat = payload?.originalCourseInfo
	if (Array.isArray(flat)) return flat
	return parseCourseListFromResponse(payload).filter(item => !item?.isScheduleAdjustment)
}

const getCustomCourseBucket = () => {
	const bucket = uni.getStorageSync(CUSTOM_COURSE_STORE_KEY)
	return bucket && typeof bucket === 'object' ? bucket : {}
}

const getCurrentSemesterMark = () => {
	const today = new Date()
	const year = today.getFullYear()
	const month = today.getMonth() + 1
	return month >= 2 && month <= 7 ? `${year}-spring` : `${year}-fall`
}

const ensureCustomCourseMeta = () => {
	const meta = uni.getStorageSync(CUSTOM_COURSE_META_KEY)
	return meta && typeof meta === 'object' ? meta : {}
}

const clearUserCustomCourses = (userID) => {
	if (!userID) return false
	const bucket = getCustomCourseBucket()
	const hadCourses = Array.isArray(bucket[userID]) && bucket[userID].length > 0
	bucket[userID] = []
	uni.setStorageSync(CUSTOM_COURSE_STORE_KEY, bucket)

	const meta = ensureCustomCourseMeta()
	meta[userID] = getCurrentSemesterMark()
	uni.setStorageSync(CUSTOM_COURSE_META_KEY, meta)
	return hadCourses
}

const ensureCustomCoursesForSemester = (userID) => {
	if (!userID) return
	const currentSemester = getCurrentSemesterMark()
	const meta = ensureCustomCourseMeta()
	if (meta[userID] === currentSemester) return
	const hadCourses = clearUserCustomCourses(userID)
	if (hadCourses) {
		uni.showToast({
			title: '新学期已清空自定义课程',
			icon: 'none',
			duration: 2200
		})
	}
}

const showFeatureNoticeOnce = (delay = 350) => {
	if (typeof window === 'undefined') return
	window.LessonScheduleAnnouncements?.showCurrent({ delay })
}

const getCourseConflictNoticeToken = () => getCourseConflictSemesterScope()

const showSemesterCourseConflictNoticeOnce = (delay = 350) => {
	if (!hasSemesterCourseConflicts()) return Promise.resolve(false)
	const token = getCourseConflictNoticeToken()
	if (!token) return Promise.resolve(false)
	const stored = uni.getStorageSync(COURSE_CONFLICT_NOTICE_KEY)
	const seen = stored && typeof stored === 'object' ? stored : {}
	if (seen[token]) return Promise.resolve(false)

	return new Promise((resolve) => {
		setTimeout(() => {
			uni.showModal({
				title: '发现课程时间冲突',
				content: '本学期有部分课程安排在同一周、同一时间。课表会用红色边框标记冲突课程；点击课程卡片后，可切换查看其他课程，也可将当前课程设为优先展示。优先展示不会消除冲突，请留意学校的最终课程安排。',
				showCancel: false,
				confirmText: '知道了',
				success: (result) => {
					if (result.confirm) {
						uni.setStorageSync(COURSE_CONFLICT_NOTICE_KEY, {
							...seen,
							[token]: Date.now()
						})
					}
					resolve(true)
				},
				fail: () => resolve(false)
			})
		}, Math.max(0, Number(delay) || 0))
	})
}

const scheduleEntryNotices = async (delay = 350) => {
	if (entryNoticeSequenceStarted) return
	entryNoticeSequenceStarted = true
	const conflictNoticeShown = await showSemesterCourseConflictNoticeOnce(delay)
	showFeatureNoticeOnce(conflictNoticeShown ? 250 : delay)
}

const getLocalCoursesByUser = (userID) => {
	if (!userID) return []
	const bucket = getCustomCourseBucket()
	return normalizeCourseList(bucket[userID] || [], 'local')
}

const extractOnlineCoursesFromCache = (cacheData) => {
	if (Array.isArray(cacheData?.onlineCourseList) && cacheData.onlineCourseList.length) {
		return normalizeCourseList(cacheData.onlineCourseList, 'online')
	}
	if (!Array.isArray(cacheData?.courseList)) return []
	return normalizeCourseList(
		cacheData.courseList.filter(item => !item?.isLocal),
		'online'
	)
}

const extractOriginalOnlineCoursesFromCache = (cacheData) => {
	if (Array.isArray(cacheData?.originalOnlineCourseList)) {
		return normalizeCourseList(cacheData.originalOnlineCourseList, 'online')
	}
	return extractOnlineCoursesFromCache(cacheData).filter(item => !item?.isScheduleAdjustment)
}

const mergeCourseData = (onlineList, customList, originalOnlineList) => {
	const normalizedOnline = normalizeCourseList(onlineList, 'online')
	const normalizedCustom = normalizeCourseList(customList, 'local')
	const normalizedOriginal = Array.isArray(originalOnlineList)
		? normalizeCourseList(originalOnlineList, 'online')
		: (Array.isArray(ScheduleData.value.originalOnlineCourseList) && ScheduleData.value.originalOnlineCourseList.length
			? ScheduleData.value.originalOnlineCourseList
			: normalizedOnline.filter(item => !item?.isScheduleAdjustment))
	ScheduleData.value.onlineCourseList = normalizedOnline
	ScheduleData.value.originalOnlineCourseList = normalizedOriginal
	ScheduleData.value.customCourseList = normalizedCustom
	ScheduleData.value.courseList = [...normalizedOnline, ...normalizedCustom]
	buildCourseColor(ScheduleData.value)
}

const refreshLocalCourses = () => {
	mergeCourseData(ScheduleData.value.onlineCourseList, getLocalCoursesByUser(ScheduleData.value.UserID))
}

const loadCourseConflictPreferences = () => {
	const stored = uni.getStorageSync(COURSE_CONFLICT_PREFERENCE_KEY)
	courseConflictPreferences.value = stored && typeof stored === 'object' ? stored : {}
}

const getCourseConflictSemesterScope = () => {
	const userID = `${ScheduleData.value.UserID || ''}`.trim()
	const semester = `${ScheduleData.value.semesterMark || ScheduleData.value.startDate || getCurrentSemesterMark()}`.trim()
	return userID && semester ? `${userID}|${semester}` : ''
}

const getCourseConflictIdentity = (course) => {
	if (!course) return ''
	if (course.isLocal) {
		return ['local', course.id || '', course.name || ''].join('|')
	}
	return [
		'online',
		course.num || '',
		course.courseOrder || '',
		course.planNumber || '',
		course.name || ''
	].map(item => `${item}`.trim()).join('|')
}

const getCourseOccurrenceIdentity = (course) => {
	return [
		getCourseConflictIdentity(course),
		Number(course?.week) || 0,
		Number(course?.section) || 0,
		Math.max(1, Number(course?.sectionCount) || 1)
	].join('|')
}

const getCourseStartSection = (course) => Math.max(1, Number(course?.section) || 1)

const getCourseLastSection = (course) => {
	return getCourseStartSection(course) + Math.max(1, Number(course?.sectionCount) || 1) - 1
}

const getCourseDefaultConflictRank = (course) => {
	const description = `${course?.CourseAttribute || ''} ${course?.category || ''}`
	if (/重修|补修|自学/.test(description)) return 2
	if (course?.isLocal) return 3
	return 1
}

const getScopedCourseConflictPriorities = () => {
	const scope = getCourseConflictSemesterScope()
	if (!scope) return {}
	const scoped = courseConflictPreferences.value?.[scope]
	return scoped && typeof scoped === 'object' ? scoped : {}
}

const getPreferredConflictCourse = (courses) => {
	if (!Array.isArray(courses) || !courses.length) return null
	const priorities = getScopedCourseConflictPriorities()
	let preferred = null
	let preferredAt = 0
	for (const course of courses) {
		const selectedAt = Number(priorities[getCourseConflictIdentity(course)]) || 0
		if (selectedAt > preferredAt) {
			preferred = course
			preferredAt = selectedAt
		}
	}
	if (preferred) return preferred
	return [...courses].sort((left, right) => {
		const rankDiff = getCourseDefaultConflictRank(left) - getCourseDefaultConflictRank(right)
		if (rankDiff !== 0) return rankDiff
		return getCourseOccurrenceIdentity(left).localeCompare(getCourseOccurrenceIdentity(right))
	})[0]
}

const buildWeekCourseGroups = (weekNumber) => {
	const coursesByDay = new Map()
	for (const course of getWeekCourses(weekNumber)) {
		const weekDay = Number(course.week) || 1
		if (!coursesByDay.has(weekDay)) coursesByDay.set(weekDay, [])
		coursesByDay.get(weekDay).push(course)
	}

	const renderGroups = []
	for (const [weekDay, dayCourses] of coursesByDay.entries()) {
		const sorted = [...dayCourses].sort((left, right) => {
			return getCourseStartSection(left) - getCourseStartSection(right)
				|| getCourseLastSection(left) - getCourseLastSection(right)
		})
		let component = []
		let componentEnd = 0

		const flushComponent = () => {
			if (!component.length) return
			const distinctCourses = []
			const distinctKeys = new Set()
			for (const course of component) {
				const identity = getCourseConflictIdentity(course)
				if (!identity || distinctKeys.has(identity)) continue
				distinctKeys.add(identity)
				distinctCourses.push(course)
			}

			if (distinctCourses.length <= 1) {
				renderGroups.push(...component)
				component = []
				componentEnd = 0
				return
			}

			const startSection = Math.min(...component.map(getCourseStartSection))
			const endSection = Math.max(...component.map(getCourseLastSection))
			const preferredCourse = getPreferredConflictCourse(distinctCourses) || distinctCourses[0]
			const groupKey = [
				weekDay,
				...distinctCourses.map(getCourseConflictIdentity).sort()
			].join('|')
				renderGroups.push({
					...preferredCourse,
				section: `${startSection}`,
				sectionCount: `${endSection - startSection + 1}`,
					isConflict: true,
					hasScheduleAdjustment: distinctCourses.some(item => !!item.isScheduleAdjustment),
				conflictGroupKey: groupKey,
				conflictCourses: distinctCourses,
				conflictCount: distinctCourses.length
			})
			component = []
			componentEnd = 0
		}

		for (const course of sorted) {
			const startSection = getCourseStartSection(course)
			const endSection = getCourseLastSection(course)
			if (component.length && startSection > componentEnd) flushComponent()
			component.push(course)
			componentEnd = Math.max(componentEnd, endSection)
		}
		flushComponent()
	}

	return renderGroups
}

const hasSemesterCourseConflicts = () => {
	const maxWeek = Math.max(1, Number(ScheduleData.value.totalWeek) || 20)
	for (let weekNumber = 1; weekNumber <= maxWeek; weekNumber++) {
		if (buildWeekCourseGroups(weekNumber).some(item => item.isConflict)) return true
	}
	return false
}

const clamp = (value, min, max) => Math.min(max, Math.max(min, value))

const getSystemWindowInfo = () => {
	try {
		return uni.getSystemInfoSync ? uni.getSystemInfoSync() : {}
	} catch (error) {
		return {}
	}
}

const updateLayoutMetrics = () => {
	const systemInfo = getSystemWindowInfo()
	const viewportWidth = typeof window !== 'undefined' ? window.innerWidth : (systemInfo.windowWidth || 375)
	const viewportHeight = typeof window !== 'undefined' ? window.innerHeight : (systemInfo.windowHeight || 667)
	const isPhonePortrait = viewportWidth <= 500 && viewportHeight > viewportWidth
	const isPadLandscape = viewportWidth >= 900 && viewportWidth > viewportHeight

	if (isPhonePortrait) {
		layoutMetrics.value.mode = 'phone-portrait'
		layoutMetrics.value.timeColumnWidth = clamp(Math.round(viewportWidth * 0.074), 30, 36)
		layoutMetrics.value.slotHeight = clamp(Math.round(viewportHeight * 0.062), 56, 64)
		layoutMetrics.value.cellGap = 3
		layoutMetrics.value.breakBandHeight = 26
		layoutMetrics.value.breakInset = 3
		layoutMetrics.value.nameFontSize = 11
		layoutMetrics.value.metaFontSize = 9
		layoutMetrics.value.cardPaddingX = 3
		layoutMetrics.value.cardPaddingY = 3
		return
	}

	if (isPadLandscape) {
		layoutMetrics.value.mode = 'pad-landscape'
		layoutMetrics.value.timeColumnWidth = clamp(Math.round(viewportWidth * 0.055), 44, 52)
		layoutMetrics.value.slotHeight = clamp(Math.round(viewportHeight * 0.07), 58, 68)
		layoutMetrics.value.cellGap = 5
		layoutMetrics.value.breakBandHeight = 30
		layoutMetrics.value.breakInset = 4
		layoutMetrics.value.nameFontSize = 12
		layoutMetrics.value.metaFontSize = 10
		layoutMetrics.value.cardPaddingX = 4
		layoutMetrics.value.cardPaddingY = 3
		return
	}

	layoutMetrics.value.mode = 'default'
	layoutMetrics.value.timeColumnWidth = clamp(Math.round(viewportWidth * 0.09), 40, 60)
	layoutMetrics.value.slotHeight = clamp(Math.round(viewportHeight * 0.068), 56, 78)
	layoutMetrics.value.cellGap = 4
	layoutMetrics.value.breakBandHeight = 28
	layoutMetrics.value.breakInset = 3
	layoutMetrics.value.nameFontSize = 14
	layoutMetrics.value.metaFontSize = 11
	layoutMetrics.value.cardPaddingX = 6
	layoutMetrics.value.cardPaddingY = 5
}

const getHeaderHeight = () => {
	if (layoutMetrics.value.mode === 'phone-portrait') {
		return getNavBarHeight() + 50
	}
	if (layoutMetrics.value.mode === 'pad-landscape') {
		return getNavBarHeight() + 54
	}
	return getNavBarHeight() + 58
}

const getWeekSwitchIconSize = () => layoutMetrics.value.mode === 'phone-portrait' ? 14 : 16

const getSettingIconSize = () => layoutMetrics.value.mode === 'phone-portrait' ? 16 : 18

const getAdjustmentSwitchIconSize = () => layoutMetrics.value.mode === 'phone-portrait' ? 12 : 14

const getScrollViewportStyle = () => ({
	height: `calc(100vh - ${getHeaderHeight()}px)`
})

const clearSheetCloseTimer = () => {
	if (sheetCloseTimer) {
		clearTimeout(sheetCloseTimer)
		sheetCloseTimer = null
	}
}

const clearVisualEffects = () => {}

const getEventPoint = (event) => {
	const touch = event?.touches?.[0] || event?.changedTouches?.[0]
	if (touch) {
		return {
			x: Number(touch.clientX || touch.pageX || 0),
			y: Number(touch.clientY || touch.pageY || 0)
		}
	}
	if (event && typeof event.clientX === 'number' && typeof event.clientY === 'number') {
		return {
			x: event.clientX,
			y: event.clientY
		}
	}
	if (event?.detail && typeof event.detail.x === 'number' && typeof event.detail.y === 'number') {
		return {
			x: event.detail.x,
			y: event.detail.y
		}
	}
	return null
}

const handleGlobalTap = () => {
	clearArmedEmptySlot()
}

const handleSheetMaskTap = () => {
	closeBottomSheet()
}

const triggerRipple = () => {}

const getTouchY = (event) => {
	return getEventPoint(event)?.y || 0
}

const openBottomSheet = async (type) => {
	clearSheetCloseTimer()
	clearArmedEmptySlot()
	activeSheet.value = type
	sheetDragOffsetY.value = 0
	sheetDragging.value = false

	if (!bottomSheetVisible.value) {
		bottomSheetVisible.value = true
		sheetOpen.value = false
		await nextTick()
		if (animationEnabled.value) {
			setTimeout(() => {
				sheetOpen.value = true
			}, 16)
			return
		}
	}
	sheetOpen.value = true
}

const closeBottomSheet = () => {
	clearSheetCloseTimer()
	if (!bottomSheetVisible.value) {
		activeSheet.value = ''
		return
	}
	sheetOpen.value = false
	sheetDragging.value = false
	sheetDragOffsetY.value = 0

	const closeDone = () => {
		bottomSheetVisible.value = false
		activeSheet.value = ''
	}

	if (animationEnabled.value) {
		sheetCloseTimer = setTimeout(closeDone, 260)
		return
	}

	closeDone()
}

const handleSheetDragStart = (event) => {
	if (!animationEnabled.value || !sheetOpen.value) return
	sheetDragging.value = true
	sheetDragStartY.value = getTouchY(event)
	sheetDragOffsetY.value = 0
}

const handleSheetDragMove = (event) => {
	if (!animationEnabled.value || !sheetDragging.value) return
	const delta = getTouchY(event) - sheetDragStartY.value
	sheetDragOffsetY.value = Math.max(0, Math.min(380, delta))
}

const handleSheetDragEnd = () => {
	if (!sheetDragging.value) return
	const shouldClose = sheetDragOffsetY.value > 96
	sheetDragging.value = false
	if (shouldClose) {
		closeBottomSheet()
		return
	}
	sheetDragOffsetY.value = 0
}

const getBottomSheetStyle = () => {
	if (sheetDragOffsetY.value <= 0) return {}
	return {
		transform: `translateY(${sheetDragOffsetY.value}px)`,
		transition: 'none'
	}
}

const getCourseEndSection = (course) => {
	const section = Number(course?.section)
	const sectionCount = Number(course?.sectionCount)
	if (!section || !sectionCount) return '-'
	return section + sectionCount - 1
}

const openPopupSafely = (popupRef, type) => {
	const popupRaw = popupRef?.value
	const popup = Array.isArray(popupRaw) ? popupRaw[0] : popupRaw
	if (!popup) return
	const targets = [popup, popup?.$?.exposed, popup?.$?.proxy, popup?.proxy, popup?.ctx, popup?.$vm].filter(Boolean)

	for (const target of targets) {
		if (typeof target.open === 'function') {
			type ? target.open(type) : target.open()
			return
		}
	}

	console.warn('popup 实例不支持 open 方法', popup, targets.map(item => Object.keys(item || {})))
}

const closePopupSafely = (popupRef, type) => {
	const popupRaw = popupRef?.value
	const popup = Array.isArray(popupRaw) ? popupRaw[0] : popupRaw
	if (!popup) return
	const targets = [popup, popup?.$?.exposed, popup?.$?.proxy, popup?.proxy, popup?.ctx, popup?.$vm].filter(Boolean)

	for (const target of targets) {
		if (typeof target.close === 'function') {
			type ? target.close(type) : target.close()
			return
		}
	}

	console.warn('popup 实例不支持 close 方法', popup, targets.map(item => Object.keys(item || {})))
}

const getCourseAreaHeight = () => {
	return getSectionTop(ScheduleData.value.ClassesCountPerDay + 1)
}

const getBreakOffsetBeforeSection = (section) => {
	if (section <= 1) return 0
	const breakCount = restBreaks.filter(item => item.afterSection < section && item.afterSection < ScheduleData.value.ClassesCountPerDay).length
	return breakCount * layoutMetrics.value.breakBandHeight
}

const getSectionTop = (section) => {
	return (section - 1) * layoutMetrics.value.slotHeight + getBreakOffsetBeforeSection(section)
}

const getRestBreakTop = (afterSection) => {
	return getSectionTop(afterSection + 1) - layoutMetrics.value.breakBandHeight
}

const getCourseGridStyle = () => {
	const weekCount = ScheduleData.value.weekDayCount || 7
	return {
		'--week-count': String(weekCount),
		'--slot-height': `${layoutMetrics.value.slotHeight}px`
	}
}

const getCourseContentStyle = () => {
	return {
		'--time-column-width': `${layoutMetrics.value.timeColumnWidth}px`
	}
}

const getRestBandStyle = (afterSection) => {
	const inset = layoutMetrics.value.breakInset || 0
	const height = Math.max(layoutMetrics.value.breakBandHeight - inset * 2, 12)
	return {
		top: `${getRestBreakTop(afterSection) + inset}px`,
		height: `${height}px`
	}
}

const getGridLineList = () => {
	const lines = []
	for (let section = 1; section <= ScheduleData.value.ClassesCountPerDay; section++) {
		lines.push({
			key: `line-${section}`,
			top: getSectionTop(section)
		})
	}
	lines.push({
		key: 'line-end',
		top: getCourseAreaHeight()
	})
	return lines
}

const getGridLineStyle = (top) => ({
	top: `${top}px`
})

const getWeekItemStyle = () => {
	return {
		width: `calc((100% - ${layoutMetrics.value.timeColumnWidth}px) / ${ScheduleData.value.weekDayCount})`
	}
}

const getCourseNumStyle = (index) => {
	const section = index + 1
	return {
		top: `${getSectionTop(section)}px`,
		height: `${layoutMetrics.value.slotHeight}px`
	}
}

const getSwiperStyle = () => {
	return {
		width: `calc(100% - ${layoutMetrics.value.timeColumnWidth}px)`,
		height: `${getCourseAreaHeight()}px`
	}
}

const getCourseListStyle = () => {
	return {
		height: `${getCourseAreaHeight()}px`
	}
}

const getCourseItemStyle = (courseItem) => {
	const weekCount = ScheduleData.value.weekDayCount || 7
	const weekIndex = Math.max(1, Math.min(Number(courseItem.week) || 1, weekCount))
	const section = Math.max(1, Number(courseItem.renderSection ?? courseItem.section) || 1)
	const sectionCount = Math.max(1, Number(courseItem.renderSectionCount ?? courseItem.sectionCount) || 1)
	const gap = layoutMetrics.value.cellGap
	const itemHeight = Math.max(sectionCount * layoutMetrics.value.slotHeight - gap * 2, 26)

	return {
		top: `${getSectionTop(section) + gap}px`,
		left: `calc(${weekIndex - 1} * (100% / ${weekCount}) + ${gap}px)`,
		height: `${itemHeight}px`,
		width: `calc(100% / ${weekCount} - ${gap * 2}px)`
	}
}

const isCourseInWeek = (course, weekNumber) => {
	return Array.isArray(course?.weeks) && course.weeks.includes(weekNumber)
}

const displayWeekPanels = computed(() => {
	const currentWeek = clampWeekNumber(ScheduleData.value.TemporaryWeek || 1)
	const maxWeek = Math.max(1, Number(ScheduleData.value.totalWeek) || 20)
	// 边界裁剪：第1周不生成"上一周"面板、最后一周不生成"下一周"面板，
	// 避免出现可滑到的重复周（"两个第1周"）
	const panels = []
	if (currentWeek > 1) {
		panels.push({ key: `${currentWeek}-prev`, week: currentWeek - 1 })
	}
	panels.push({ key: `${currentWeek}-current`, week: currentWeek })
	if (currentWeek < maxWeek) {
		panels.push({ key: `${currentWeek}-next`, week: currentWeek + 1 })
	}
	return panels
})

const getScheduleAdjustmentCoursesForWeek = (weekNumber) => {
	return (ScheduleData.value.onlineCourseList || []).filter(item => {
		return !!item?.isScheduleAdjustment && isCourseInWeek(item, weekNumber)
	})
}

const getScheduleAdjustmentViewScope = (weekNumber) => {
	const courses = getScheduleAdjustmentCoursesForWeek(weekNumber)
	if (!courses.length) return ''
	const planTokens = Array.from(new Set(courses.map(item => {
		const adjustment = item?.scheduleAdjustment || {}
		return `${adjustment.planId || 0}:${adjustment.planPublishedAt || ''}`
	}))).sort().join(',')
	const userId = `${ScheduleData.value.UserID || ''}`.trim()
	const semester = `${ScheduleData.value.semesterMark || ScheduleData.value.startDate || getCurrentSemesterMark()}`.trim()
	return userId && semester ? `${userId}|${semester}|${weekNumber}|${planTokens}` : ''
}

const getScheduleAdjustmentViewMode = (weekNumber) => {
	const scope = getScheduleAdjustmentViewScope(weekNumber)
	return scope && scheduleAdjustmentViewPreferences.value?.[scope] === 'original' ? 'original' : 'adjusted'
}

const currentWeekHasScheduleAdjustment = computed(() => {
	return !!getScheduleAdjustmentViewScope(clampWeekNumber(ScheduleData.value.TemporaryWeek || 1))
})

const currentScheduleViewMode = computed(() => {
	return getScheduleAdjustmentViewMode(clampWeekNumber(ScheduleData.value.TemporaryWeek || 1))
})

const toggleScheduleAdjustmentView = (event) => {
	triggerRipple(event)
	const weekNumber = clampWeekNumber(ScheduleData.value.TemporaryWeek || 1)
	const scope = getScheduleAdjustmentViewScope(weekNumber)
	if (!scope) return
	const nextMode = getScheduleAdjustmentViewMode(weekNumber) === 'original' ? 'adjusted' : 'original'
	const nextPreferences = { ...scheduleAdjustmentViewPreferences.value }
	if (nextMode === 'original') nextPreferences[scope] = 'original'
	else delete nextPreferences[scope]
	scheduleAdjustmentViewPreferences.value = nextPreferences
	uni.setStorageSync(SCHEDULE_ADJUSTMENT_VIEW_KEY, nextPreferences)
	uni.showToast({
		title: nextMode === 'original' ? '已切换至原课表' : '已切换至调课后',
		icon: 'none',
		duration: 1200
	})
}

const getWeekCourses = (weekNumber) => {
	const onlineCourses = getScheduleAdjustmentViewMode(weekNumber) === 'original'
		? (ScheduleData.value.originalOnlineCourseList || [])
		: (ScheduleData.value.onlineCourseList || [])
	const activeCourses = [...onlineCourses, ...(ScheduleData.value.customCourseList || [])]
	return activeCourses.filter(item => {
		return isCourseInWeek(item, weekNumber) && Number(item.week) <= ScheduleData.value.weekDayCount
	})
}

const splitCourseByRestBreaks = (courseItem) => {
	const maxSection = Number(ScheduleData.value.ClassesCountPerDay) || 12
	const startSection = Math.max(1, Math.min(Number(courseItem?.section) || 1, maxSection))
	const endSection = Math.max(startSection, Math.min(startSection + Math.max(1, Number(courseItem?.sectionCount) || 1) - 1, maxSection))
	const splitPoints = restBreaks
		.filter(item => item.afterSection >= startSection && item.afterSection < endSection && item.afterSection < maxSection)
		.map(item => item.afterSection)
		.sort((a, b) => a - b)

	const rawSegments = []
	let currentStart = startSection

	for (const splitPoint of splitPoints) {
		if (splitPoint >= currentStart) {
			rawSegments.push({
				startSection: currentStart,
				endSection: splitPoint
			})
		}
		currentStart = splitPoint + 1
	}

	rawSegments.push({
		startSection: currentStart,
		endSection
	})

	return rawSegments
		.filter(segment => segment.endSection >= segment.startSection)
		.map((segment, index) => ({
			...courseItem,
			renderKey: `course-${courseItem.id}-${startSection}-${endSection}-${segment.startSection}-${segment.endSection}-${index}`,
			renderSection: segment.startSection,
			renderSectionCount: segment.endSection - segment.startSection + 1,
			isFirstSegment: index === 0,
			isLastSegment: index === rawSegments.length - 1,
			segmentIndex: index,
			segmentTotal: rawSegments.length
		}))
}

const getWeekCourseRenderList = (weekNumber) => {
	return buildWeekCourseGroups(weekNumber).flatMap(splitCourseByRestBreaks)
}

const isSlotOccupied = (weekNumber, weekDay, section) => {
	for (const course of getWeekCourses(weekNumber)) {
		if (Number(course.week) !== weekDay) continue
		const startSection = Number(course.section) || 1
		const sectionCount = Math.max(1, Number(course.sectionCount) || 1)
		const endSection = startSection + sectionCount - 1
		if (section >= startSection && section <= endSection) {
			return true
		}
	}
	return false
}

const getWeekEmptySlots = (weekNumber) => {
	const slots = []
	const weekCount = ScheduleData.value.weekDayCount || 7
	const sectionCount = Number(ScheduleData.value.ClassesCountPerDay) || 12
	for (let weekDay = 1; weekDay <= weekCount; weekDay++) {
		for (let section = 1; section <= sectionCount; section++) {
			if (isSlotOccupied(weekNumber, weekDay, section)) continue
			slots.push({
				key: `empty-${weekNumber}-${weekDay}-${section}`,
				weekNumber,
				weekDay,
				section
			})
		}
	}
	return slots
}

const getEmptySlotStyle = (slot) => {
	const gap = layoutMetrics.value.cellGap
	const weekCount = ScheduleData.value.weekDayCount || 7
	return {
		top: `${getSectionTop(slot.section) + gap}px`,
		left: `calc(${slot.weekDay - 1} * (100% / ${weekCount}) + ${gap}px)`,
		height: `${Math.max(layoutMetrics.value.slotHeight - gap * 2, 24)}px`,
		width: `calc(100% / ${weekCount} - ${gap * 2}px)`
	}
}

const isEmptySlotArmed = (slot) => {
	return armedEmptySlot.value?.key === slot.key
}

const clearArmedEmptySlot = () => {
	armedEmptySlot.value = null
}

const gotoAddCoursePage = (slot) => {
	const query = `?weekNumber=${slot.weekNumber}&weekDay=${slot.weekDay}&section=${slot.section}&totalWeek=${ScheduleData.value.totalWeek}`
	uni.navigateTo({
		url: `/pages/course-edit/course-edit${query}`
	})
}

const handleEmptySlotTap = (slot, event) => {
	triggerRipple(event)
	if (isEmptySlotArmed(slot)) {
		gotoAddCoursePage(slot)
		return
	}
	armedEmptySlot.value = slot
}

const getCourseCardStyle = (course) => {
	const cardColor = ScheduleData.value.courseColor[course?.name] || '#dbeafe'
	const isConflict = !!course?.isConflict
	const isAdjusted = !!course?.isScheduleAdjustment || !!course?.hasScheduleAdjustment
	return {
		backgroundColor: hexToRgba(cardColor, 0.56),
		color: darkenColor(cardColor, 0.42),
		'--course-border-color': isConflict ? '#ef4444' : (isAdjusted ? '#2563eb' : 'rgba(255, 255, 255, 0.78)'),
		'--course-border-width': isConflict || isAdjusted ? '2px' : '1px',
		'--course-glass-shadow': isConflict
			? `0 0 0 1px rgba(239, 68, 68, 0.18), 0 8px 20px ${hexToRgba(cardColor, 0.2)}`
			: (isAdjusted
				? `0 0 0 1px rgba(37, 99, 235, 0.16), 0 8px 20px ${hexToRgba(cardColor, 0.2)}`
				: `0 8px 20px ${hexToRgba(cardColor, 0.2)}`),
		'--course-name-size': `${layoutMetrics.value.nameFontSize}px`,
		'--course-meta-size': `${layoutMetrics.value.metaFontSize}px`,
		'--course-card-padding-x': `${layoutMetrics.value.cardPaddingX}px`,
		'--course-card-padding-y': `${layoutMetrics.value.cardPaddingY}px`
	}
}

// 设置按钮点击事件
const goToSettings = (event) => {
	triggerRipple(event)
	uni.navigateTo({
		url: '/pages/settings/settings'
	});
}

const confirmExitAdminDebug = () => {
	if (!debugState.value) return
	uni.showModal({
		title: '退出调试模式',
		content: '将清除测试用户产生的本机缓存，并恢复管理员本人身份。',
		confirmText: '返回本人',
		cancelText: '取消',
		success: (result) => {
			if (!result.confirm) return
			if (!exitAdminDebugSession()) {
				uni.showToast({ title: '管理员身份恢复失败，请重新认证', icon: 'none' })
				return
			}
			if (typeof window !== 'undefined' && window.location?.origin) {
				const restartUrl = new URL('/LessonSchedule/', window.location.origin)
				restartUrl.searchParams.set('debug_exit', `${Date.now()}`)
				window.location.replace(restartUrl.href)
				return
			}
			uni.reLaunch({ url: '/pages/index/index' })
		}
	})
}

const SelectWeeksPopup = (event) => {
	triggerRipple(event)
	openBottomSheet('week')
};

const prepareDetailedCourseServices = (course) => {
	if (!course) return
	const groupKey = getCourseGroupKey(course)
	if (!groupKey) {
		// 旧缓存缺少课程群唯一键时按需补齐，不影响冲突课程切换。
		hydrateCourseGroupMetadata(course)
		return
	}
	refreshCourseGroupState(course, {
		silent: !!courseGroupStateMap.value[groupKey]
	})
}

const selectDetailedConflictCourse = (course, index = -1) => {
	if (!course) return
	DetailedCourseData.value = course
	const resolvedIndex = index >= 0
		? index
		: detailedConflictCourses.value.findIndex(item => getCourseOccurrenceIdentity(item) === getCourseOccurrenceIdentity(course))
	detailedConflictIndex.value = resolvedIndex >= 0 ? resolvedIndex : 0
	prepareDetailedCourseServices(course)
}

const formatAdjustmentDate = (value) => {
	const matched = `${value || ''}`.match(/^(\d{4})-(\d{2})-(\d{2})$/)
	if (!matched) return ''
	return `${matched[1]}年${Number(matched[2])}月${Number(matched[3])}日`
}

const getScheduleAdjustmentSummary = (course) => {
	const adjustment = course?.scheduleAdjustment || {}
	const sourceDay = ScheduleData.value.weekIndexText[(Number(adjustment.sourceWeekday) || 1) - 1] || '-'
	const targetDay = ScheduleData.value.weekIndexText[(Number(adjustment.targetWeekday) || 1) - 1] || '-'
	const sourceDate = formatAdjustmentDate(adjustment.sourceDate)
	const targetDate = formatAdjustmentDate(adjustment.targetDate)
	const sourceText = `第${adjustment.sourceWeek || '-'}周周${sourceDay}${sourceDate ? `（${sourceDate}）` : ''}`
	const targetText = `第${adjustment.targetWeek || '-'}周周${targetDay}${targetDate ? `（${targetDate}）` : ''}`
	return `本课程由${sourceText}调整至${targetText}，上课节次、地点及任课教师以当前课程信息为准。`
}

const openScheduleAdjustmentNotice = (course) => {
	const url = `${course?.scheduleAdjustment?.noticeUrl || ''}`.trim()
	if (!/^https?:\/\//i.test(url)) return
	if (typeof window !== 'undefined') {
		window.location.href = url
	}
}

const GetCourseDetails = (course, event) => {
	triggerRipple(event)
	if (course) {
		const conflictCourses = Array.isArray(course.conflictCourses) && course.conflictCourses.length > 1
			? course.conflictCourses
			: [course]
		detailedConflictCourses.value = conflictCourses
		const selectedIdentity = getCourseConflictIdentity(course)
		const selectedIndex = conflictCourses.findIndex(item => getCourseConflictIdentity(item) === selectedIdentity)
		selectDetailedConflictCourse(conflictCourses[selectedIndex >= 0 ? selectedIndex : 0], selectedIndex)
		openBottomSheet('course')
	}
};

const cycleDetailedConflictCourse = () => {
	if (!hasDetailedCourseConflict.value) return
	const nextIndex = (detailedConflictIndex.value + 1) % detailedConflictCourses.value.length
	selectDetailedConflictCourse(detailedConflictCourses.value[nextIndex], nextIndex)
}

const isDetailedCoursePreferred = computed(() => {
	if (!hasDetailedCourseConflict.value) return false
	const preferred = getPreferredConflictCourse(detailedConflictCourses.value)
	return getCourseConflictIdentity(preferred) === getCourseConflictIdentity(DetailedCourseData.value)
})

const setDetailedCoursePreferred = () => {
	if (!hasDetailedCourseConflict.value || !DetailedCourseData.value) return
	const scope = getCourseConflictSemesterScope()
	const courseIdentity = getCourseConflictIdentity(DetailedCourseData.value)
	if (!scope || !courseIdentity) {
		uni.showToast({ title: '暂时无法保存，请稍后重试', icon: 'none' })
		return
	}
	const scoped = {
		...(courseConflictPreferences.value[scope] || {}),
		[courseIdentity]: Date.now()
	}
	courseConflictPreferences.value = {
		...courseConflictPreferences.value,
		[scope]: scoped
	}
	uni.setStorageSync(COURSE_CONFLICT_PREFERENCE_KEY, courseConflictPreferences.value)
	uni.showToast({ title: '已设为优先展示', icon: 'success' })
}

const isSameCourseOccurrence = (left, right) => {
	if (!left || !right) return false
	return `${left.num || ''}` === `${right.num || ''}`
		&& `${left.teacher || ''}` === `${right.teacher || ''}`
		&& `${left.week || ''}` === `${right.week || ''}`
		&& `${left.section || ''}` === `${right.section || ''}`
}

/**
 * 为旧缓存按需补齐课程群唯一键字段。
 * 获取成功后替换在线课程数据并立即保存，后续打开同一课程的任意上课记录都直接复用新字段。
 */
const hydrateCourseGroupMetadata = async (course) => {
	if (!course || course.isLocal || courseGroupMetadataLoading.value) return
	const requestedOccurrence = getCourseOccurrenceIdentity(course)
	courseGroupMetadataLoading.value = true
	courseGroupMetadataError.value = ''
	try {
		const payload = await GetCourseInfo({ UserID: ScheduleData.value.UserID })
		const freshOnline = normalizeCourseList(parseCourseListFromResponse(payload), 'online')
		const freshOriginal = normalizeCourseList(parseOriginalCourseListFromResponse(payload), 'online')
		const matched = freshOnline.find(item => isSameCourseOccurrence(item, course))
		if (!matched || !getCourseGroupKey(matched)) {
			throw new Error('接口暂未返回课程群所需字段')
		}
		mergeCourseData(freshOnline, getLocalCoursesByUser(ScheduleData.value.UserID), freshOriginal)
		detailedConflictCourses.value = detailedConflictCourses.value.map(item => {
			return getCourseOccurrenceIdentity(item) === requestedOccurrence ? matched : item
		})
		if (getCourseOccurrenceIdentity(DetailedCourseData.value) === requestedOccurrence) {
			DetailedCourseData.value = matched
		}
		persistScheduleCache()
		courseGroupMetadataLoading.value = false
		await refreshCourseGroupState(matched)
	} catch (error) {
		courseGroupMetadataLoading.value = false
		courseGroupMetadataError.value = error?.msg || error?.message || '课程信息更新失败'
	}
}

const setCourseGroupState = (course, patch) => {
	const key = getCourseGroupKey(course)
	if (!key) return
	courseGroupStateMap.value = {
		...courseGroupStateMap.value,
		[key]: {
			...(courseGroupStateMap.value[key] || {}),
			...patch,
			updatedAt: Date.now()
		}
	}
}

const refreshCourseGroupState = async (course, options = {}) => {
	const key = getCourseGroupKey(course)
	if (!key) return
	const previous = courseGroupStateMap.value[key]
	setCourseGroupState(course, {
		loading: !options.silent && !previous,
		error: false
	})
	try {
		const response = await requestCourseGroup('status', course)
		if (Number(response?.code) !== 1) {
			throw response
		}
		setCourseGroupState(course, {
			loading: false,
			acting: false,
			error: false,
			existing: !!response.existing,
			joined: !!response.joined,
			role: response.role || currentCourseGroupRole.value,
			ChatProperties: response.ChatProperties || ''
		})
	} catch (error) {
		setCourseGroupState(course, {
			loading: false,
			acting: false,
			error: true,
			errorCode: Number(error?.code) || 502,
			errorMessage: error?.msg || '课程群状态获取失败，请点击重试'
		})
	}
}

const showCourseGroupModal = (options) => new Promise((resolve) => {
	uni.showModal({
		...options,
		success: resolve,
		fail: () => resolve({ confirm: false, cancel: true })
	})
})

const markCourseGroupActionResult = (course, response, action) => {
	setCourseGroupState(course, {
		loading: false,
		acting: false,
		error: false,
		existing: true,
		joined: action === 'join' ? true : !!currentCourseGroupState.value?.joined,
		ChatProperties: response?.ChatProperties || currentCourseGroupState.value?.ChatProperties || ''
	})
	uni.showToast({
		title: response?.msg || (action === 'join' ? '加入成功' : '课程群创建成功'),
		icon: 'none',
		duration: 2600
	})
}

const handleCourseGroupError = async (course, response) => {
	setCourseGroupState(course, {
		loading: false,
		acting: false,
		error: false
	})
	if (Number(response?.code) === 401) {
		const result = await showCourseGroupModal({
			title: '身份认证已失效',
			content: '需要重新认证后才能操作课程群。',
			confirmText: '重新认证'
		})
		if (result.confirm) redirectToLoginPage()
		return
	}
	await showCourseGroupModal({
		title: '课程群操作失败',
		content: response?.msg || '服务暂时不可用，请稍后重试',
		showCancel: false,
		confirmText: '知道了'
	})
}

const processCourseGroupResponse = async (course, response, action) => {
	const code = Number(response?.code)
	if (code === 1 || code === 101 || (code === 409 && response?.existing)) {
		markCourseGroupActionResult(course, response, action)
		return
	}

	if (code === 60111) {
		setCourseGroupState(course, { acting: false })
		const result = await showCourseGroupModal({
			title: '有成员尚未加入企业微信',
			content: `${response?.msg || '有成员无法加入课程群'}\n是否跳过该成员并继续创建？`,
			cancelText: '暂不创建',
			confirmText: '跳过并继续'
		})
		if (!result.confirm) return
		await runCourseGroupRequest(course, 'skipMissing', {})
		return
	}

	if (code === 20010) {
		setCourseGroupState(course, { acting: false })
		const summary = `检测到同一学年上学期的同一课程群。\n上学期 ${response.firstCount || 0} 人，本学期 ${response.currentCount || 0} 人，名单相似度 ${response.similarityPercent || 0}%。`
		const choice = await showCourseGroupModal({
			title: '发现可复用课程群',
			content: summary,
			cancelText: '新建群',
			confirmText: '复用旧群'
		})
		if (choice.confirm) {
			let removeOld = false
			if (Number(response.firstOnlyCount) > 0) {
				const removeChoice = await showCourseGroupModal({
					title: '处理上学期成员',
					content: `有 ${response.firstOnlyCount} 名上学期成员不在本学期名单中，是否将他们移出旧群？`,
					cancelText: '保留成员',
					confirmText: '移出成员'
				})
				removeOld = !!removeChoice.confirm
			}
			await runCourseGroupRequest(course, 'resolve', { decision: 'reuse', removeOld })
			return
		}

		const confirmNew = await showCourseGroupModal({
			title: '新建独立课程群',
			content: '确定不复用上学期群，为本学期新建一个独立课程群吗？',
			cancelText: '取消',
			confirmText: '确定新建'
		})
		if (confirmNew.confirm) {
			await runCourseGroupRequest(course, 'resolve', { decision: 'new', removeOld: false })
		}
		return
	}

	await handleCourseGroupError(course, response)
}

const runCourseGroupRequest = async (course, action, extra = {}) => {
	setCourseGroupState(course, { acting: true, error: false })
	uni.showLoading({
		title: action === 'join' ? '正在加入…' : '正在处理…',
		mask: true
	})
	try {
		const response = await requestCourseGroup(action, course, extra)
		uni.hideLoading()
		await processCourseGroupResponse(course, response, action)
	} catch (error) {
		uni.hideLoading()
		await handleCourseGroupError(course, error)
	}
}

const handleCourseGroupButtonTap = async () => {
	const course = DetailedCourseData.value
	const state = currentCourseGroupState.value
	if (!course || state?.loading || state?.acting) return
	if (!currentCourseGroupKey.value) {
		if (courseGroupMetadataError.value) await hydrateCourseGroupMetadata(course)
		return
	}
	if (state?.error) {
		if (Number(state.errorCode) === 401) {
			await handleCourseGroupError(course, { code: 401, msg: state.errorMessage })
			return
		}
		await refreshCourseGroupState(course)
		return
	}
	if (currentCourseGroupRole.value === 'student') {
		if (state?.existing && !state?.joined) {
			await runCourseGroupRequest(course, 'join')
		}
		return
	}
	if (!state?.existing) {
		const result = await showCourseGroupModal({
			title: '创建课程群',
			content: `确定为“${course.name || '当前课程'}”创建课程群吗？`,
			cancelText: '取消',
			confirmText: '开始创建'
		})
		if (result.confirm) await runCourseGroupRequest(course, 'create')
	}
}

const goEditCourse = (course, event) => {
	triggerRipple(event)
	if (!course?.isLocal) return
	const query = `?courseId=${encodeURIComponent(course.id)}&weekNumber=${ScheduleData.value.TemporaryWeek}&weekDay=${course.week}&section=${course.section}&totalWeek=${ScheduleData.value.totalWeek}`
	closeBottomSheet()
	setTimeout(() => {
		uni.navigateTo({
			url: `/pages/course-edit/course-edit${query}`
		})
	}, animationEnabled.value ? 120 : 0)
}

const goCourseComments = (course, event) => {
	triggerRipple(event)
	if (!course || course.isLocal || !course.name || !course.teacher) return
	const query = `?courseName=${encodeURIComponent(course.name)}&teacherName=${encodeURIComponent(course.teacher)}`
	closeBottomSheet()
	setTimeout(() => {
		uni.navigateTo({
			url: `/pages/course-comments/index${query}`
		})
	}, animationEnabled.value ? 120 : 0)
}

const indexOf = function (arr, value) {
	return arr && arr.indexOf(value) > -1;
}

const swiperSwitchWeek = function (e) {
	const index = Number(e.detail.current)
	const panel = displayWeekPanels.value[index]
	if (!panel) return
	// 面板已按边界裁剪（第1周无"上一周"面板、最后一周无"下一周"面板），
	// 不存在可滑到的重复周，直接切到目标面板的周次即可
	switchWeekFn(panel.week)
}

//底部poppup切换选中周数
const ViewWeeklySchedule = function (ActiveWeek, event) {
	triggerRipple(event)
	switchWeekFn(ActiveWeek)
	closeBottomSheet()
}

//切换顶部周数与日期
const switchWeekFn = function (week) {
	clearArmedEmptySlot()
	ScheduleData.value.TemporaryWeek = clampWeekNumber(week);
	getWeekDates(ScheduleData.value.startDate, ScheduleData.value.TemporaryWeek, ScheduleData.value.weekDayCount)
}

//构建顶部日期
const getWeekDates = function (startDate, TemporaryWeek, weekDayCount) {
	if (!startDate) {
		console.warn('开学日期未设置，无法生成日期');
		ScheduleData.value.weekCalendar = Array(weekDayCount).fill('--/--');
		return;
	}
	
	try {
		// 处理日期格式
		let startDateObj;
		if (startDate.includes('/')) {
			startDateObj = new Date(startDate);
		} else if (startDate.includes('-')) {
			startDateObj = new Date(startDate.replace(/-/g, '/'));
		} else {
			throw new Error('无效的日期格式');
		}
		
		if (isNaN(startDateObj.getTime())) {
			throw new Error('无效的开学日期');
		}
		
		// 计算指定周的第一天（周一）
		const weekStartDate = new Date(startDateObj);
		weekStartDate.setDate(startDateObj.getDate() + (TemporaryWeek - 1) * 7);
		
		// 调整到该周的周一
		const dayOfWeek = weekStartDate.getDay();
		const daysToMonday = dayOfWeek === 0 ? -6 : 1 - dayOfWeek; // 周日(0)变成周一，周一(1)不变
		weekStartDate.setDate(weekStartDate.getDate() + daysToMonday);
		
		const calendar = [];
		
		for (let i = 0; i < weekDayCount; i++) {
			const day = new Date(weekStartDate);
			day.setDate(weekStartDate.getDate() + i);
			const month = String(day.getMonth() + 1);
			const date = String(day.getDate()).padStart(2, '0');
			calendar.push(`${month}/${date}`);
		}
		ScheduleData.value.weekCalendar = calendar;
	} catch (error) {
		console.error('生成周历出错:', error);
		ScheduleData.value.weekCalendar = Array(weekDayCount).fill('--/--');
	}
};

// 获取当前为第几周
const getWeekNumber = function (startDate) {
	if (!startDate) return 1;
	
	try {
		let startDateObj;
		if (startDate.includes('/')) {
			startDateObj = new Date(startDate);
		} else if (startDate.includes('-')) {
			startDateObj = new Date(startDate.replace(/-/g, '/'));
		} else {
			return 1;
		}
		
		if (isNaN(startDateObj.getTime())) {
			console.error('无效的开学日期:', startDate);
			return 1;
		}
		
		const today = new Date();
		
		// 如果今天在开学日期之前，返回第1周
		if (today < startDateObj) return 1;
		
		// 计算天数差
		const diffTime = today - startDateObj;
		const diffDays = Math.floor(diffTime / (1000 * 60 * 60 * 24));
		
		// 计算周数（向上取整）
		const weekNumber = Math.ceil((diffDays + 1) / 7); // +1是因为第一天算第1周
		
		return Math.max(1, Math.min(weekNumber, ScheduleData.value.totalWeek));
	} catch (error) {
		console.error('计算周数时出错:', error);
		return 1;
	}
};

const normalizeWeek = (week) => {
	const totalWeek = Number(ScheduleData.value.totalWeek) || 1
	const currentWeek = Number(week) || 1
	return Math.max(1, Math.min(currentWeek, totalWeek))
}

const persistCurrentWeek = (week) => {
	const currentSettings = uni.getStorageSync('scheduleSettings') || {}
	uni.setStorageSync('scheduleSettings', {
		...currentSettings,
		enableAnimation: currentSettings.enableAnimation !== false,
		currentWeek: week
	})
}

const syncWeekToToday = (persist = false) => {
	if (!ScheduleData.value.startDate) {
		getWeekDates('', ScheduleData.value.TemporaryWeek || 1, ScheduleData.value.weekDayCount)
		return 1
	}
	const todayWeek = normalizeWeek(getWeekNumber(ScheduleData.value.startDate))
	ScheduleData.value.nowWeek = todayWeek
	ScheduleData.value.TemporaryWeek = todayWeek
	getWeekDates(ScheduleData.value.startDate, todayWeek, ScheduleData.value.weekDayCount)
	if (persist) {
		persistCurrentWeek(todayWeek)
	}
	return todayWeek
}

const getTodayMonthDate = function () {
	const today = new Date();
	const month = today.getMonth() + 1;
	const date = today.getDate().toString().padStart(2, '0');
	return `${month}/${date}`;
}

//建立课表颜色对应
const buildCourseColor = (data) => {
	let colorIndex = 0
	const courseColor = {}
	const list = Array.isArray(data?.courseList) ? data.courseList : []
	list.forEach(item => {
		if (item?.name && item?.customColor) {
			courseColor[item.name] = item.customColor
		}
	})
	list.forEach(item => {
		if (item.name && !courseColor[item.name]) {
			courseColor[item.name] = data.colorList[colorIndex % data.colorList.length]
			colorIndex++
		}
	})
	ScheduleData.value.courseColor = courseColor
}

// 分析课表数据，找出最大的周数
const analyzeCourseWeeks = (courseList) => {
	if (!courseList || courseList.length === 0) return null;
	
	let maxWeek = 0;
	let allWeeks = new Set();
	
	courseList.forEach(course => {
		if (course.weeks && course.weeks.length > 0) {
			course.weeks.forEach(week => {
				if (week > maxWeek) maxWeek = week;
				allWeeks.add(week);
			});
		}
	});
	
	return {
		maxWeek,
		allWeeks: Array.from(allWeeks).sort((a, b) => a - b)
	};
}

// 根据月份自动选择上下课时间
const autoSelectScheduleTime = () => {
	const today = new Date();
	const month = today.getMonth() + 1;
	
	// 5月-9月使用夏季作息，其他时间使用冬季作息
	if (month >= 5 && month <= 9) {
		ScheduleData.value.currentScheduleTime = ScheduleData.value.SummerScheduleTime;
	} else {
		ScheduleData.value.currentScheduleTime = ScheduleData.value.WinterScheduleTime;
	}
}

function hexToRgba(hexColor, alpha = 1) {
	if (!hexColor || hexColor.length !== 7) return `rgba(255,255,255,${alpha})`
	const red = parseInt(hexColor.slice(1, 3), 16)
	const green = parseInt(hexColor.slice(3, 5), 16)
	const blue = parseInt(hexColor.slice(5, 7), 16)
	return `rgba(${red}, ${green}, ${blue}, ${alpha})`
}

// 处理颜色加深
function darkenColor(hexColor, opacity) {
	if (!hexColor || hexColor.length !== 7) return '#000000';
	
	const red = parseInt(hexColor.slice(1, 3), 16);
	const green = parseInt(hexColor.slice(3, 5), 16);
	const blue = parseInt(hexColor.slice(5, 7), 16);
	
	const hslColor = rgbToHsl(red, green, blue);
	const newLuminance = hslColor.l * opacity;
	const newRgbColor = hslToRgb(hslColor.h, hslColor.s, newLuminance);
	
	return `#${newRgbColor.r.toString(16).padStart(2, '0')}${newRgbColor.g.toString(16).padStart(2, '0')}${newRgbColor.b.toString(16).padStart(2, '0')}`;
}

// 辅助函数：将RGB值转换为HSL值
function rgbToHsl(r, g, b) {
	r /= 255; g /= 255; b /= 255;
	const max = Math.max(r, g, b);
	const min = Math.min(r, g, b);
	let h, s, l = (max + min) / 2;
	
	if (max === min) {
		h = s = 0;
	} else {
		const d = max - min;
		s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
		switch (max) {
			case r: h = (g - b) / d + (g < b ? 6 : 0); break;
			case g: h = (b - r) / d + 2; break;
			case b: h = (r - g) / d + 4; break;
		}
		h /= 6;
	}
	return { h, s, l };
}

// 辅助函数：将HSL值转换为RGB值
function hslToRgb(h, s, l) {
	let r, g, b;
	
	if (s === 0) {
		r = g = b = l;
	} else {
		const hue2rgb = (p, q, t) => {
			if (t < 0) t += 1;
			if (t > 1) t -= 1;
			if (t < 1/6) return p + (q - p) * 6 * t;
			if (t < 1/2) return q;
			if (t < 2/3) return p + (q - p) * (2/3 - t) * 6;
			return p;
		};
		
		const q = l < 0.5 ? l * (1 + s) : l + s - l * s;
		const p = 2 * l - q;
		r = hue2rgb(p, q, h + 1/3);
		g = hue2rgb(p, q, h);
		b = hue2rgb(p, q, h - 1/3);
	}
	
	return {
		r: Math.round(r * 255),
		g: Math.round(g * 255),
		b: Math.round(b * 255)
	};
}

// 计算开学日期 - 简单准确算法
const calculateStartDate = (courseList) => {
	if (!courseList || courseList.length === 0) {
		console.warn('没有课表数据，无法计算开学日期');
		return null;
	}
	
	const today = new Date();
	const currentYear = today.getFullYear();
	const currentMonth = today.getMonth() + 1; // 1-12月
	
	// 分析课表数据
	const weekAnalysis = analyzeCourseWeeks(courseList);
	if (!weekAnalysis) {
		console.warn('无法分析课表周数');
		return null;
	}
	
	const { maxWeek, allWeeks } = weekAnalysis;
	console.log('课表分析结果:', { maxWeek, allWeeks });
	
	// 计算本学期总周数
	const totalWeeks = maxWeek;
	
	// 尝试常见的开学日期：8月25日（秋季）和3月1日（春季）
	let possibleStartDates = [];
	
	if (currentMonth >= 1 && currentMonth <= 7) {
		// 1-7月，可能是春季学期
		// 尝试今年3月1日
		const springStart = new Date(currentYear, 2, 1); // 3月1日
		// 调整到周一
		const springStartDay = springStart.getDay();
		if (springStartDay !== 1) {
			const daysToMonday = springStartDay === 0 ? 1 : 8 - springStartDay;
			springStart.setDate(springStart.getDate() + daysToMonday);
		}
		possibleStartDates.push({
			date: springStart,
			type: 'spring',
			weekDiff: calculateWeekDifference(springStart, today)
		});
		
		// 尝试去年8月25日（秋季学期）
		const fallStart = new Date(currentYear - 1, 7, 25); // 去年8月25日
		const fallStartDay = fallStart.getDay();
		if (fallStartDay !== 1) {
			const daysToMonday = fallStartDay === 0 ? 1 : 8 - fallStartDay;
			fallStart.setDate(fallStart.getDate() + daysToMonday);
		}
		possibleStartDates.push({
			date: fallStart,
			type: 'fall',
			weekDiff: calculateWeekDifference(fallStart, today)
		});
	} else {
		// 8-12月，可能是秋季学期
		// 尝试今年8月25日
		const fallStart = new Date(currentYear, 7, 25); // 8月25日
		const fallStartDay = fallStart.getDay();
		if (fallStartDay !== 1) {
			const daysToMonday = fallStartDay === 0 ? 1 : 8 - fallStartDay;
			fallStart.setDate(fallStart.getDate() + daysToMonday);
		}
		possibleStartDates.push({
			date: fallStart,
			type: 'fall',
			weekDiff: calculateWeekDifference(fallStart, today)
		});
		
		// 尝试今年9月1日
		const sepStart = new Date(currentYear, 8, 1); // 9月1日
		const sepStartDay = sepStart.getDay();
		if (sepStartDay !== 1) {
			const daysToMonday = sepStartDay === 0 ? 1 : 8 - sepStartDay;
			sepStart.setDate(sepStart.getDate() + daysToMonday);
		}
		possibleStartDates.push({
			date: sepStart,
			type: 'fall_sep',
			weekDiff: calculateWeekDifference(sepStart, today)
		});
	}
	
	console.log('可能的开学日期:', possibleStartDates);
	
	// 选择最合理的开学日期
	// 规则：计算出的周数应该在1到maxWeek之间，且最好在课表周数范围内
	let bestStartDate = null;
	let bestScore = -Infinity;
	
	possibleStartDates.forEach(item => {
		const calculatedWeek = Math.ceil(item.weekDiff / 7);
		
		// 计算分数
		let score = 0;
		
		// 周数在1到maxWeek之间
		if (calculatedWeek >= 1 && calculatedWeek <= maxWeek) {
			score += 10;
		}
		
		// 周数在课表周数范围内
		if (allWeeks.includes(calculatedWeek)) {
			score += 5;
		}
		
		// 周数不要太极端
		if (calculatedWeek > 5 && calculatedWeek < maxWeek - 5) {
			score += 3;
		}
		
		// 如果是秋季学期且当前是下半年，加分
		if (item.type === 'fall' && currentMonth >= 8) {
			score += 2;
		}
		
		// 如果是春季学期且当前是上半年，加分
		if (item.type === 'spring' && currentMonth <= 6) {
			score += 2;
		}
		
		console.log(`开学日期 ${item.date.toLocaleDateString()} (${item.type}) 计算周数: ${calculatedWeek}, 得分: ${score}`);
		
		if (score > bestScore) {
			bestScore = score;
			bestStartDate = item;
		}
	});
	
	if (bestStartDate) {
		// 计算当前周数
		const currentWeek = Math.ceil(bestStartDate.weekDiff / 7);
		
		// 确保周数在合理范围内
		const finalCurrentWeek = Math.max(1, Math.min(currentWeek, totalWeeks));
		
		// 格式化日期
		const year = bestStartDate.date.getFullYear();
		const month = String(bestStartDate.date.getMonth() + 1).padStart(2, '0');
		const day = String(bestStartDate.date.getDate()).padStart(2, '0');
		
		console.log(`最佳开学日期: ${year}/${month}/${day}, 总周数: ${totalWeeks}, 当前周数: ${finalCurrentWeek}`);
		
		return {
			startDate: `${year}/${month}/${day}`,
			totalWeeks: totalWeeks,
			currentWeek: finalCurrentWeek
		};
	}
	
	// 如果没有合适的，使用默认值
	console.log('没有找到合适的开学日期，使用默认值');
	const todayDate = new Date();
	const year = todayDate.getFullYear();
	
	if (currentMonth >= 1 && currentMonth <= 7) {
		return {
			startDate: `${year}/03/01`,
			totalWeeks: totalWeeks || 20,
			currentWeek: 1
		};
	} else {
		return {
			startDate: `${year}/08/25`,
			totalWeeks: totalWeeks || 20,
			currentWeek: 1
		};
	}
}

// 计算两个日期之间的天数差
const calculateWeekDifference = (startDate, endDate) => {
	const diffTime = endDate - startDate;
	return Math.floor(diffTime / (1000 * 60 * 60 * 24));
}

// 检查日期是否是周一
const isMonday = (dateStr) => {
	try {
		const date = new Date(dateStr.replace(/-/g, '/'));
		return date.getDay() === 1; // 1 表示周一
	} catch (e) {
		return false;
	}
}

// 检查是否需要学期切换（当前日期与开学日期不在同一学期）
const needSemesterSwitch = (startDateStr) => {
	try {
		const startDate = new Date(startDateStr.replace(/-/g, '/'));
		const today = new Date();
		
		const startMonth = startDate.getMonth() + 1; // 开学日期月份
		const currentMonth = today.getMonth() + 1;   // 当前月份
		
		// 春季学期：2-7月，秋季学期：8-次年1月
		const isStartSpring = startMonth >= 2 && startMonth <= 7;
		const isCurrentSpring = currentMonth >= 2 && currentMonth <= 7;
		
		// 如果开学日期和当前日期不在同一学期类型，可能需要切换
		if (isStartSpring !== isCurrentSpring) {
			// 检查是否跨过了学期分界线（2月或8月）
			const startYear = startDate.getFullYear();
			const currentYear = today.getFullYear();
			
			// 如果年份不同，且月份跨过学期分界线
			if (currentYear > startYear) {
				return true;
			}
			
			// 同年但跨过学期分界线（如8月开学，当前是9月；或3月开学，当前是1月）
			if (currentYear === startYear) {
				// 春季学期开学，当前是秋季（8月后）
				if (isStartSpring && !isCurrentSpring && currentMonth >= 8) {
					return true;
				}
				// 秋季学期开学，当前是春季（2月后但开学在8月前，这不可能，因为秋季8月开学）
				// 这种情况是：去年秋季开学，今年春季了
			}
		}
		
		return false;
	} catch (e) {
		console.error('检查学期切换出错:', e);
		return false;
	}
}

// 显示引导去设置的提示
const showGoToSettingsTip = (title, content) => {
	uni.showModal({
		title: title,
		content: content,
		confirmText: '去设置',
		cancelText: '知道了',
		success: (res) => {
			if (res.confirm) {
				goToSettings();
			}
		}
	});
}

const persistScheduleCache = () => {
	uni.setStorageSync('ScheduleData', ScheduleData.value)
}

const GetScheduleData = async () => {
	try {
		const payload = await GetCourseInfo({
			UserID: ScheduleData.value.UserID
		});

		// 版本管理：每次进入校验服务端版本，非最新则强制更新（带缓存破坏参数刷新）
		const serverVersion = payload?.data?.appVersion || ''
		if (serverVersion && compareVersions(serverVersion, APP_VERSION) > 0) {
			console.log(`发现新版本 v${serverVersion}（当前 v${APP_VERSION}），强制更新`);
			uni.showModal({
				title: '发现新版本',
				content: `当前版本 v${APP_VERSION}，最新版本 v${serverVersion}，正在为您更新…`,
				showCancel: false,
				confirmText: '立即更新',
				success: () => {
					if (typeof window !== 'undefined') {
						const base = window.location.pathname
						window.location.href = base + '?v=' + serverVersion
					}
				}
			});
			return
		}

		const onlineCourseList = normalizeCourseList(parseCourseListFromResponse(payload), 'online')
		const originalOnlineCourseList = normalizeCourseList(parseOriginalCourseListFromResponse(payload), 'online')
		// 方案B：后端统一下发的开学日期与学期标记
		const serverStartDate = payload?.data?.semesterStartDate || payload?.semesterStartDate || ''
		const serverMark = payload?.data?.semesterMark || payload?.semesterMark || ''
		if (onlineCourseList.length > 0) {
			mergeCourseData(onlineCourseList, getLocalCoursesByUser(ScheduleData.value.UserID), originalOnlineCourseList)
			console.log('获取到的在线课表数据:', onlineCourseList);
			
			// 从本地存储加载用户设置
			const userSettings = normalizeDataSourcePreference(uni.getStorageSync('scheduleSettings') || {});
			
			// 分析课表数据获取最大周数
			const weekAnalysis = analyzeCourseWeeks(onlineCourseList);
			let maxWeek = 20;
			if (weekAnalysis && weekAnalysis.maxWeek > 0) {
				maxWeek = weekAnalysis.maxWeek;
			}
			
			if (serverStartDate) {
				// 方案B：后端统一管理开学日期（服务端优先；semesterMark 变化时强制重置旧设置）
				console.log('使用服务端开学日期:', serverStartDate, serverMark);
				ScheduleData.value.startDate = serverStartDate;
				ScheduleData.value.semesterMark = serverMark;
				ScheduleData.value.totalWeek = maxWeek;
				ScheduleData.value.nowWeek = getWeekNumber(serverStartDate);
				ScheduleData.value.TemporaryWeek = ScheduleData.value.nowWeek;
				uni.setStorageSync('scheduleSettings', {
					...userSettings,
					startDate: serverStartDate,
					semesterMark: serverMark,
					totalWeeks: maxWeek,
					currentWeek: ScheduleData.value.nowWeek,
					dataSource: getPreferredDataSource(userSettings),
					dataSourcePreference: getPreferredDataSource(userSettings),
					isFirstUse: false
				});
				persistCurrentWeek(ScheduleData.value.nowWeek);
			} else if (userSettings && userSettings.startDate) {
				// 使用用户保存的设置（日期已统一走后端，不再采用旧的手动日期）
				console.log('使用用户保存的设置:', userSettings);
				// 开学日期一律以服务端/缓存为准，不再用 settings 里的旧值覆盖（旧值可能是历史手动日期）
				// ScheduleData.value.startDate = userSettings.startDate;
				ScheduleData.value.totalWeek = userSettings.totalWeeks || maxWeek;
				ScheduleData.value.nowWeek = userSettings.currentWeek || 1;
				ScheduleData.value.TemporaryWeek = ScheduleData.value.nowWeek;
				
				// 重新计算当前周数
				const calculatedWeek = normalizeWeek(getWeekNumber(ScheduleData.value.startDate));
				console.log(`根据开学日期计算出的当前周数: ${calculatedWeek}`);
				
				// 检测1/检测2（开学日期周一校验、学期切换提示）：已随"手动日期"功能一并取消，
				// 日期由后端校历服务统一管理，不再提示用户手动修正
				/*
				if (!userSettings.startDateNotMonday && !isMonday(ScheduleData.value.startDate)) {
					console.warn('开学日期不是周一:', ScheduleData.value.startDate);
					setTimeout(() => {
						showGoToSettingsTip('开学日期异常', '开学日期通常应该是星期一，请检查并手动设置正确的开学日期。如确实非周一，请到设置中开启"本学期开学日期非周一"选项。');
					}, 1000);
				}
				else if (needSemesterSwitch(ScheduleData.value.startDate)) {
					console.warn('检测到可能需要切换学期');
					setTimeout(() => {
						showGoToSettingsTip('新学期开始', '检测到可能已进入新学期，请检查并更新开学日期。');
					}, 1000);
				}
				*/
				
				// 始终与今天同步，避免停留在历史周次
				if (calculatedWeek !== ScheduleData.value.nowWeek || calculatedWeek !== ScheduleData.value.TemporaryWeek) {
					console.log(`同步到今天对应周次: 第${calculatedWeek}周`);
					ScheduleData.value.nowWeek = calculatedWeek;
					ScheduleData.value.TemporaryWeek = calculatedWeek;
				}
				persistCurrentWeek(calculatedWeek);
			} else {
				// 首次使用，自动计算开学日期
				const calculatedResult = calculateStartDate(onlineCourseList);
				
				if (calculatedResult) {
					console.log('自动计算的开学日期结果:', calculatedResult);
					
					ScheduleData.value.startDate = calculatedResult.startDate;
					ScheduleData.value.totalWeek = calculatedResult.totalWeeks;
					ScheduleData.value.nowWeek = calculatedResult.currentWeek;
					ScheduleData.value.TemporaryWeek = calculatedResult.currentWeek;
					
					// 保存到本地设置
					const settings = {
						startDate: ScheduleData.value.startDate,
						totalWeeks: ScheduleData.value.totalWeek,
						currentWeek: ScheduleData.value.nowWeek,
						showWeekend: ScheduleData.value.weekDayCount === 7,
						dataSource: 'online', // 数据来源：在线
						dataSourcePreference: 'online',
						enableAnimation: userSettings?.enableAnimation !== false,
						isFirstUse: true // 标记首次使用
					};
					uni.setStorageSync('scheduleSettings', settings);
					console.log('已保存自动计算的设置:', settings);
					
					// 首次使用提示：开学日期由后端校历服务统一管理，无需手动设置
					setTimeout(() => {
						showGoToSettingsTip('首次使用提示', `开学日期已按学校校历设置为 ${calculatedResult.startDate}，系统将自动同步。`);
					}, 1500);
				} else {
					// 使用默认值
					console.log('使用默认开学日期');
					const today = new Date();
					const year = today.getFullYear();
					const month = today.getMonth() + 1;
					
					if (month >= 1 && month <= 7) {
						ScheduleData.value.startDate = `${year}/03/01`;
					} else {
						ScheduleData.value.startDate = `${year}/08/25`;
					}
					
					ScheduleData.value.totalWeek = maxWeek;
					ScheduleData.value.nowWeek = 1;
					ScheduleData.value.TemporaryWeek = 1;
					
					// 保存到本地设置
					const settings = {
						startDate: ScheduleData.value.startDate,
						totalWeeks: ScheduleData.value.totalWeek,
						currentWeek: ScheduleData.value.nowWeek,
						showWeekend: ScheduleData.value.weekDayCount === 7,
						dataSource: 'online',
						dataSourcePreference: 'online',
						enableAnimation: userSettings?.enableAnimation !== false,
						isFirstUse: true
					};
					uni.setStorageSync('scheduleSettings', settings);
					
					// 首次使用提示：开学日期由后端校历服务统一管理，无需手动设置
					setTimeout(() => {
						showGoToSettingsTip('首次使用提示', `开学日期已按学校校历设置，系统将自动同步。`);
					}, 1500);
				}
			}
			
			// 跳转到今天对应周次并生成周历
			syncWeekToToday(true);
			
			// 保存完整的课表数据到本地存储
			persistScheduleCache()
			
			// 显示成功提示
			uni.showToast({
				title: '课表加载成功',
				icon: 'success',
				duration: 1500
			});
			scheduleEntryNotices(1700)
		} else {
			console.warn('没有获取到有效的课表数据');
			uni.showModal({
				title: '当前无课程信息',
				content: '请时刻关注教务处官方信息',
				showCancel: false,
				confirmText: '知道了',
				success: () => showFeatureNoticeOnce()
			});
		}
	} catch (error) {
		console.error('获取课表数据失败:', error);
		if (error?.data?.staleCacheRejected) {
			// 服务端已判定本机/数据库中的课表属于旧学期，立即停止展示旧课程。
			mergeCourseData([], getLocalCoursesByUser(ScheduleData.value.UserID), [])
			persistScheduleCache()
			uni.showModal({
				title: '旧学期课表已清除',
				content: '暂时未能获取本学期课表，请稍后重新进入。系统恢复后会自动同步最新数据。',
				showCancel: false,
				confirmText: '知道了'
			})
			return
		}
		uni.showToast({
			title: '加载课表失败',
			icon: 'error',
			duration: 2000
		});
	}
}

const courseDataRefresh = function () {
	openPopupSafely(updatePromptRef)
}

const UpdatePromptConfirm = function () {
	uni.removeStorageSync('ScheduleData');
	GetScheduleData()
	closePopupSafely(updatePromptRef)
}

const UpdatePromptClose = function () {
	closePopupSafely(updatePromptRef)
}

const envjudge = () => {
	if (typeof window === 'undefined' || typeof navigator === 'undefined') {
		return 'other'
	}
	const isMobile = window.navigator.userAgent.match(
		/(phone|pad|pod|iPhone|iPod|ios|iPad|Android|Mobile|BlackBerry|IEMorowser|JUC|Fennec|wOSBrowser|BrowserNG|WebOS|Symbian|Winbile|MQQBdows Phone)/i
	)
	const isWx = /micromessenger/i.test(navigator.userAgent)
	const isComWx = /wxwork/i.test(navigator.userAgent)

	if (isComWx && isMobile) {
		return 'com-wx-mobile'
	} else if (isComWx && !isMobile) {
		return 'com-wx-pc'
	} else if (isWx && isMobile) {
		return 'wx-mobile'
	} else if (isWx && !isMobile) {
		return 'wx-pc'
	} else {
		return 'other'
	}
}

const redirectToLoginPage = () => {
	uni.reLaunch({
		url: '/pages/login/login'
	})
}

const buildComWxAuthEntryUrl = () => {
	if (typeof window === 'undefined' || !window.location?.origin) {
		return 'https://syauinfo.syau.edu.cn/LessonSchedule/index.php'
	}
	const appRootUrl = new URL('/LessonSchedule/', window.location.origin).href
	return `https://syauinfo.syau.edu.cn/LessonSchedule/index.php?kind=${encodeURIComponent(appRootUrl)}`
}

const isEnterpriseServiceOfflineTime = () => {
	const beijingNow = new Date(Date.now() + 8 * 60 * 60 * 1000)
	const hour = beijingNow.getUTCHours()
	return hour >= 22 || hour < 6
}

const showNightServiceNotice = (hasCache) => {
	uni.showModal({
		title: hasCache ? '当前使用缓存课表' : '夜间认证服务暂不可用',
		content: hasCache
			? '当前为夜间服务关闭时段（22:00–次日06:00），已为你加载本机缓存课表。实时更新、反馈和课程评论等功能请在白天重新认证后使用。'
			: '当前为夜间服务关闭时段（22:00–次日06:00），企业微信认证和学校实时课表服务暂不可用。当前设备没有可确认身份的课表缓存，请在白天重新进入。',
		showCancel: false,
		confirmText: '知道了',
		success: () => scheduleEntryNotices(250)
	})
}

const validateServerSession = async () => {
	try {
		const response = await getFeedbackSession()
		if (response?.data?.authenticated && response.data.user_id) {
			setCurrentUserId(response.data.user_id)
			clearComWxAutoAuthAttempt()
			return response.data
		}
	} catch (error) {
		console.warn('会话校验失败:', error)
		const existingSession = getAuthSession()
		if (existingSession?.userId) {
			setCurrentUserId(existingSession.userId)
			return {
				authenticated: true,
				validation_unavailable: true,
				is_admin: false,
				user_id: existingSession.userId,
				auth_exp: existingSession.authExp
			}
		}
	}

	clearAuthSession()
	setCurrentUserId('')
	return null
}

const ensureDataSourcePreference = () => {
	const savedSettings = uni.getStorageSync('scheduleSettings') || {}
	const usedManualCache = savedSettings.dataSource === 'cache' || savedSettings.dataSourcePreference === 'cache'
	const normalizedSettings = normalizeDataSourcePreference(savedSettings)
	if (savedSettings.dataSource !== normalizedSettings.dataSource ||
		savedSettings.dataSourcePreference !== normalizedSettings.dataSourcePreference) {
		uni.setStorageSync('scheduleSettings', normalizedSettings)
	}
	if (usedManualCache && uni.getStorageSync(DATA_SOURCE_MIGRATION_NOTICE_KEY) !== '1') {
		legacyManualCacheDetected = true
	}
	return normalizedSettings
}

const showDataSourceMigrationNotice = () => {
	if (!legacyManualCacheDetected || uni.getStorageSync(DATA_SOURCE_MIGRATION_NOTICE_KEY) === '1') {
		return Promise.resolve()
	}
	legacyManualCacheDetected = false
	return new Promise((resolve) => {
		uni.showModal({
			title: '课表数据模式已更新',
			content: '原手动缓存模式已取消。课表将于白天自动获取最新数据，22:00至次日06:00自动使用缓存，白天会恢复在线数据。',
			showCancel: false,
			confirmText: '知道了',
			success: () => {
				uni.setStorageSync(DATA_SOURCE_MIGRATION_NOTICE_KEY, '1')
				resolve()
			},
			fail: () => resolve()
		})
	})
}

const loadScheduleBySource = (options = {}) => {
	const cacheOnly = options.cacheOnly === true
	ensureCustomCoursesForSemester(ScheduleData.value.UserID)

	// 自动选择上下课时间表
	autoSelectScheduleTime();

	// 加载设置
	loadSettings();

	const cachedScheduleData = uni.getStorageSync('ScheduleData');
	const userSettings = ensureDataSourcePreference();

	if (cachedScheduleData && typeof cachedScheduleData === 'object' && Object.keys(cachedScheduleData).length > 0 &&
		cachedScheduleData.UserID == ScheduleData.value.UserID) {
		if (cacheOnly) {
			ScheduleData.value = {
				...ScheduleData.value,
				...cachedScheduleData
			};
			// 日期一律以服务端确认值为准（semesterMark 存在说明来自后端下发）：
			// 1) 缓存本身带 semesterMark（服务端日期已随在线加载写入缓存）→ 保留缓存日期
			// 2) 否则用 settings 中服务端确认过的日期
			// 3) 都没有 → 历史手动修改的日期强制清除（禁止再使用）
			if (cachedScheduleData.semesterMark) {
				ScheduleData.value.startDate = cachedScheduleData.startDate || '';
				ScheduleData.value.semesterMark = cachedScheduleData.semesterMark;
			} else if (userSettings && userSettings.semesterMark && userSettings.startDate) {
				ScheduleData.value.startDate = userSettings.startDate;
				ScheduleData.value.semesterMark = userSettings.semesterMark;
			} else {
				ScheduleData.value.startDate = '';
				ScheduleData.value.semesterMark = '';
				if (userSettings && userSettings.startDate) {
					uni.setStorageSync('scheduleSettings', {
						...userSettings,
						startDate: '',
						semesterMark: ''
					});
				}
			}
			const cachedOnline = extractOnlineCoursesFromCache(cachedScheduleData)
			const cachedOriginal = extractOriginalOnlineCoursesFromCache(cachedScheduleData)
			mergeCourseData(cachedOnline, getLocalCoursesByUser(ScheduleData.value.UserID), cachedOriginal)
			syncWeekToToday(true);
			// 缓存模式（含夜间）也异步拉取服务端开学日期，避免顶部日期空白
			refreshSemesterDateFromServer();
			return
		}
	}

	if (cacheOnly) {
		uni.showToast({
			title: '当前没有可用的缓存课表',
			icon: 'none'
		})
		setTimeout(() => {
			redirectToLoginPage()
		}, 180)
		return
	}

	GetScheduleData();
}

// 缓存模式（含夜间 cacheOnly）也异步拉取一次服务端开学日期并写入本地。
// 接口部署在公网 bm，夜间内网 114 端口关闭不影响；服务端夜间会返回数据库缓存且带 semesterStartDate。
const refreshSemesterDateFromServer = async () => {
	try {
		const uid = ScheduleData.value.UserID
		if (!uid) return
		const payload = await GetCourseInfo({ UserID: uid })
		const startDate = payload?.data?.semesterStartDate || payload?.semesterStartDate || ''
		const mark = payload?.data?.semesterMark || payload?.semesterMark || ''
		if (startDate && mark) {
			ScheduleData.value.startDate = startDate
			ScheduleData.value.semesterMark = mark
			const cur = uni.getStorageSync('scheduleSettings') || {}
			uni.setStorageSync('scheduleSettings', { ...cur, startDate, semesterMark: mark })
			persistScheduleCache()
			syncWeekToToday(true)
		}
	} catch (error) {
		console.warn('缓存模式拉取服务端开学日期失败:', error)
	}
}

const bootstrapIndexPage = async (routeParams = {}) => {
	updateLayoutMetrics();
	closeBottomSheet();
	ensureDataSourcePreference()
	loadCourseConflictPreferences()
	await showDataSourceMigrationNotice()
	const activeDebugState = getAdminDebugState()
	if (activeDebugState && activeDebugState.expiresAt * 1000 <= Date.now()) {
		exitAdminDebugSession()
		if (typeof window !== 'undefined' && window.location?.origin) {
			const restartUrl = new URL('/LessonSchedule/', window.location.origin)
			restartUrl.searchParams.set('debug_expired', `${Date.now()}`)
			window.location.replace(restartUrl.href)
			return
		}
		uni.reLaunch({ url: '/pages/index/index' })
		return
	}

	const previousSession = getAuthSession()
	const routeSession = saveAuthSessionFromRoute(routeParams)
	let sessionInfo = await validateServerSession()

	if (!sessionInfo && routeSession && previousSession) {
		setAuthSession(previousSession)
		sessionInfo = await validateServerSession()
	}

	let resolvedUserId = sessionInfo?.user_id || ''
	const cacheEntry = `${routeParams.cache_entry || ''}` === '1'

	if (!resolvedUserId && cacheEntry) {
		resolvedUserId = hydrateCurrentUserFromManualState() || ''
		if (resolvedUserId) {
			setCurrentUserId(resolvedUserId)
		}
	}

	if (!resolvedUserId && isEnterpriseServiceOfflineTime()) {
		const cachedScheduleData = uni.getStorageSync('ScheduleData')
		const rememberedUserId = previousSession?.userId || ''
		const cachedUserId = `${cachedScheduleData?.UserID || ''}`.trim()
		const canUseCache = !!rememberedUserId && cachedUserId === rememberedUserId &&
			cachedScheduleData && typeof cachedScheduleData === 'object'

		if (canUseCache) {
			ScheduleData.value.UserID = rememberedUserId
			setCurrentUserId(rememberedUserId)
			loadScheduleBySource({ cacheOnly: true })
			showNightServiceNotice(true)
			return
		}

		showNightServiceNotice(false)
		return
	}

	if (!resolvedUserId) {
		const env = envjudge();
		if ((env === 'com-wx-mobile' || env === 'com-wx-pc') && shouldAttemptComWxAutoAuth()) {
			markComWxAutoAuthAttempt()
			if (typeof window !== 'undefined') {
				window.location.href = buildComWxAuthEntryUrl()
				return
			}
		}

		redirectToLoginPage()
		return
	}

	ScheduleData.value.UserID = resolvedUserId
	setCurrentUserId(resolvedUserId)
	const cacheOnly = isEnterpriseServiceOfflineTime() || (cacheEntry && !sessionInfo?.authenticated)
	loadScheduleBySource({ cacheOnly })
	if (cacheOnly && !isEnterpriseServiceOfflineTime()) {
		scheduleEntryNotices(500)
	}
}

// 加载数据
onLoad((e) => {
	bootstrapIndexPage(e || {})
});

// 一次性清除历史手动开学日期：代码更新前用户自定义的开学日期（无服务端 semesterMark 标记）一律擦除
const cleanupLegacyManualStartDate = () => {
	try {
		const cur = uni.getStorageSync('scheduleSettings') || {}
		if (cur && cur.startDate && !cur.semesterMark) {
			uni.setStorageSync('scheduleSettings', { ...cur, startDate: '' })
			console.log('已清除历史手动开学日期:', cur.startDate)
		}
	} catch (error) {
		console.warn('清除历史手动开学日期失败:', error)
	}
}

// 从本地存储加载设置
const loadSettings = () => {
	// 先清除历史手动开学日期（无 semesterMark 的旧自定义日期），日期一律以后端下发为准
	cleanupLegacyManualStartDate()
	const settings = uni.getStorageSync('scheduleSettings');
	animationEnabled.value = settings?.enableAnimation !== false
	if (!animationEnabled.value) {
		clearVisualEffects()
	}
	if (settings) {
		console.log('加载用户设置:', settings);
		
		// 加载显示周末设置
		if (settings.showWeekend !== undefined) {
			ScheduleData.value.weekDayCount = settings.showWeekend ? 7 : 5;
			ScheduleData.value.weekIndexText = settings.showWeekend ? 
				['一', '二', '三', '四', '五', '六', '日'] : 
				['一', '二', '三', '四', '五'];
		}
		
		// 加载开学日期设置（已取消：日期一律以后端校历服务下发的 ScheduleData.startDate 为准）
		// if (settings.startDate) {
		// 	ScheduleData.value.startDate = settings.startDate;
		// }
		
		// 加载总周数设置
		if (settings.totalWeeks) {
			ScheduleData.value.totalWeek = settings.totalWeeks;
		}
		
		// 加载当前周数设置
		if (settings.currentWeek) {
			ScheduleData.value.nowWeek = settings.currentWeek;
			ScheduleData.value.TemporaryWeek = settings.currentWeek;
		}
		
		// 打开页面时始终跳到今天周次
		syncWeekToToday(false);
	}
};

// 监听页面显示事件，更新设置
onShow(() => {
	updateLayoutMetrics();
	closeBottomSheet();
	clearArmedEmptySlot()
	if (ScheduleData.value.courseList.length) scheduleEntryNotices(600)

	// 重新加载设置
	loadSettings();
	refreshLocalCourses()
	
	// 重新计算周历
	if (ScheduleData.value.startDate) {
		syncWeekToToday(false);
	}
});

const handleSettingsUpdated = (newSettings) => {
	if (newSettings) {
		console.log('接收到设置更新:', newSettings);
		animationEnabled.value = newSettings.enableAnimation !== false
		if (!animationEnabled.value) {
			clearVisualEffects()
		}
		
		// 更新显示周末设置
		if (newSettings.showWeekend !== undefined) {
			ScheduleData.value.weekDayCount = newSettings.showWeekend ? 7 : 5;
			ScheduleData.value.weekIndexText = newSettings.showWeekend ? 
				['一', '二', '三', '四', '五', '六', '日'] : 
				['一', '二', '三', '四', '五'];
		}
		
		// 更新开学日期（已取消：日期由后端统一管理，禁止用户修改）
		// if (newSettings.startDate) {
		// 	ScheduleData.value.startDate = newSettings.startDate;
		// }
		
		// 更新总周数
		if (newSettings.totalWeeks) {
			ScheduleData.value.totalWeek = newSettings.totalWeeks;
		}
		
		// 更新当前周数
		if (newSettings.currentWeek) {
			ScheduleData.value.nowWeek = newSettings.currentWeek;
			ScheduleData.value.TemporaryWeek = newSettings.currentWeek;
		}
		
		// 设置页修改后保持跳到今天周次
		syncWeekToToday(true);
	}
}

const handleCustomCoursesChanged = () => {
	refreshLocalCourses()
	persistScheduleCache()
}

const handleWindowResize = () => {
	updateLayoutMetrics();
	if (ScheduleData.value.startDate) {
		getWeekDates(ScheduleData.value.startDate, ScheduleData.value.TemporaryWeek, ScheduleData.value.weekDayCount);
	}
}

let automaticDataSourceTimer = null
let lastAutomaticNightState = null

const syncAutomaticDataSource = () => {
	ensureDataSourcePreference()

	const currentNightState = isEnterpriseServiceOfflineTime()
	if (lastAutomaticNightState === null) {
		lastAutomaticNightState = currentNightState
		return
	}
	if (currentNightState === lastAutomaticNightState || !ScheduleData.value.UserID) return

	lastAutomaticNightState = currentNightState
	if (currentNightState) {
		loadScheduleBySource({ cacheOnly: true })
		return
	}
	GetScheduleData()
}

const handleVisibilityChange = () => {
	if (typeof document === 'undefined' || document.visibilityState === 'visible') {
		syncAutomaticDataSource()
	}
}

// 监听设置更新事件
onMounted(() => {
	updateLayoutMetrics();
	uni.$on('settingsUpdated', handleSettingsUpdated);
	uni.$on('customCoursesChanged', handleCustomCoursesChanged);
	lastAutomaticNightState = isEnterpriseServiceOfflineTime()
	automaticDataSourceTimer = setInterval(syncAutomaticDataSource, 30000)

	if (typeof window !== 'undefined' && window.addEventListener) {
		window.addEventListener('resize', handleWindowResize);
	}
	if (typeof document !== 'undefined' && document.addEventListener) {
		document.addEventListener('visibilitychange', handleVisibilityChange)
	}
});

onUnmounted(() => {
	clearSheetCloseTimer()
	clearVisualEffects()
	uni.$off('settingsUpdated', handleSettingsUpdated);
	uni.$off('customCoursesChanged', handleCustomCoursesChanged);
	if (automaticDataSourceTimer) {
		clearInterval(automaticDataSourceTimer)
		automaticDataSourceTimer = null
	}

	if (typeof window !== 'undefined' && window.removeEventListener) {
		window.removeEventListener('resize', handleWindowResize);
	}
	if (typeof document !== 'undefined' && document.removeEventListener) {
		document.removeEventListener('visibilitychange', handleVisibilityChange)
	}
});
</script>

<style lang="scss" scoped>
.page-root {
	position: relative;
	min-height: 100vh;
	background: linear-gradient(180deg, #a7b7ff 0%, #e5c4e8 34%, #ffd7b9 64%, #fff3d2 100%);
}

/* 背景图层：隔离在本层内，避免固定定位的背景参与页面根的层叠顺序 */
.bg-layer {
	position: fixed;
	top: 0;
	left: 0;
	width: 100vw;
	height: 100vh;
	z-index: 0;
	isolation: isolate;
	pointer-events: none;
}

.bgImg {
	position: fixed;
	width: 100vw;
	height: 100vh;
	top: 0;
	left: 0;
	z-index: 0;
	object-fit: cover;
	pointer-events: none;
}

.bgMask {
	position: fixed;
	top: 0;
	left: 0;
	width: 100vw;
	height: 100vh;
	z-index: 1;
	background: linear-gradient(180deg, rgba(249, 251, 255, 0.36) 0%, rgba(255, 255, 255, 0.16) 100%);
	pointer-events: none;
}

.test-build-badge {
	position: fixed;
	left: 12px;
	bottom: calc(env(safe-area-inset-bottom) + 76px);
	z-index: 120;
	padding: 7px 11px;
	border-radius: 999px;
	background: rgba(37, 99, 235, 0.92);
	box-shadow: 0 8px 22px rgba(37, 99, 235, 0.24);
	color: #fff;
	font-size: 11px;
	font-weight: 700;
	pointer-events: none;
}

.admin-debug-float {
	position: fixed;
	right: 14px;
	bottom: calc(env(safe-area-inset-bottom) + 76px);
	z-index: 90;
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 9px 10px 9px 13px;
	border-radius: 16px;
	background: rgba(15, 23, 42, 0.92);
	box-shadow: 0 10px 28px rgba(15, 23, 42, 0.24);
	backdrop-filter: blur(12px);
}

.admin-debug-copy {
	display: flex;
	flex-direction: column;
	gap: 1px;
}

.admin-debug-label {
	font-size: 10px;
	color: #cbd5e1;
}

.admin-debug-user {
	font-size: 13px;
	font-weight: 700;
	color: #fff;
}

.admin-debug-exit {
	padding: 7px 10px;
	border-radius: 10px;
	background: #fff;
	font-size: 12px;
	font-weight: 700;
	color: #c2410c;
}

.layout {
	position: relative;
	z-index: 10;

	.navbar {
		position: fixed;
		top: 0;
		left: 0;
		width: 100%;
		z-index: 10;
		background: rgba(248, 251, 255, 0.62);
		backdrop-filter: blur(16px);
		border-bottom: 1px solid rgba(148, 163, 184, 0.2);

		.statusBar {}

		.titleBar {
			position: relative;
			width: 100%;
			box-sizing: border-box;
			display: flex;
			align-items: center;
			justify-content: center;
			padding: 0 8px 0 8px;

			.adjustment-view-switch {
				position: absolute;
				left: 8px;
				top: 50%;
				transform: translateY(-50%);
				z-index: 5;
				display: flex;
				align-items: center;
				justify-content: center;
				gap: 4px;
				min-width: 66px;
				height: 32px;
				padding: 0 9px;
				box-sizing: border-box;
				border-radius: 999px;
				background: rgba(37, 99, 235, 0.12);
				border: 1px solid rgba(37, 99, 235, 0.42);
				color: #1d4ed8;
				font-size: 12px;
				font-weight: 700;
				box-shadow: 0 4px 10px rgba(37, 99, 235, 0.12);
			}

			.adjustment-view-switch.original {
				background: rgba(255, 255, 255, 0.62);
				border-color: rgba(100, 116, 139, 0.32);
				color: #475569;
				box-shadow: 0 4px 10px rgba(71, 85, 105, 0.1);
			}

			.week-switch {
				display: flex;
				align-items: center;
				justify-content: center;
				gap: 4px;
				padding: 5px 14px;
				max-width: calc(100% - 64px);
				border-radius: 999px;
				background: rgba(255, 255, 255, 0.56);
				box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.45);
			}

			.title {
				font-family: 'Microsoft YaHei', 'PingFang SC', sans-serif;
				font-size: 20px;
				font-weight: bold;
				color: #1f2937;
			}

			.icon {
				margin-top: 1px;
				transition: transform 0.24s ease;
			}

			.icon.open {
				transform: rotate(180deg);
			}
			
			.setting-btn {
				position: absolute;
				right: 8px;
				top: 50%;
				transform: translateY(-50%);
				width: 34px;
				height: 34px;
				display: flex;
				align-items: center;
				justify-content: center;
				z-index: 5;
				border-radius: 10px;
				background: rgba(255, 255, 255, 0.58);
				border: 1px solid rgba(148, 163, 184, 0.26);
				box-shadow: 0 4px 10px rgba(71, 85, 105, 0.12);
			}
		}

		.week-list {
			height: 60px;
			display: flex;
			align-items: center;
			padding: 0 2px 6px 2px;

			.now-month {
				height: 100%;
				flex-shrink: 0;
			}

			.week-item {
				height: 100%;
				display: flex;
				flex-direction: column;
				align-items: center;
				justify-content: center;
				border-radius: 12px;
				transition: background-color 0.2s ease;
				
				&.active-day {
					background: rgba(59, 130, 246, 0.1);
				}

				.week-name {
					font-size: 13px;
					color: #334155;
				}

				.week-date {
					font-size: 12px;
					color: #64748b;
					margin-top: 2px;
				}

				.week-name.active,
				.week-date.active {
					color: #0f5ec8;
					font-weight: 600;
				}
			}
		}
	}

	.fill {
		width: 100%;
	}
}

.container {
	position: relative;
	z-index: 2;
	padding-bottom: calc(env(safe-area-inset-bottom) + 70px);
	box-sizing: border-box;
	overflow: hidden;

	.course-content {
		position: relative;
		width: 100%;
		padding: 6px 0 calc(env(safe-area-inset-bottom) + 24px);
		display: flex;
		align-items: flex-start;
		overflow: hidden;
		isolation: isolate;
		--rest-band-bg: linear-gradient(180deg, rgba(248, 251, 255, 0.82) 0%, rgba(243, 247, 255, 0.74) 100%);
		--rest-band-border: rgba(255, 255, 255, 0.9);
		--rest-band-line: rgba(255, 255, 255, 0.5);
		--rest-band-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.24), 0 6px 18px rgba(148, 163, 184, 0.08);
		--rest-band-pill-bg: rgba(248, 250, 255, 0.92);
		--rest-band-pill-color: #8b95a5;
		--rest-band-pill-shadow: 0 4px 12px rgba(148, 163, 184, 0.12);

		&::before {
			content: '';
			position: absolute;
			inset: 0;
			z-index: 0;
			pointer-events: none;
			background:
				linear-gradient(180deg,
					rgba(214, 224, 255, 0.14) 0%,
					rgba(244, 212, 231, 0.12) 42%,
					rgba(255, 239, 198, 0.16) 100%);
		}

		.course-nums {
			flex-shrink: 0;
			position: relative;
			align-items: stretch;
			z-index: 1;
			overflow: hidden;

			.course-num {
				position: absolute;
				left: 0;
				right: 0;
				width: 100%;
				box-sizing: border-box;
				display: flex;
				flex-direction: column;
				align-items: center;
				justify-content: center;
				z-index: 1;
				border: 1px solid rgba(255, 255, 255, 0.42);
				background: rgba(255, 255, 255, 0.22);
				box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.12);
				backdrop-filter: blur(8px) saturate(120%);
				-webkit-backdrop-filter: blur(8px) saturate(120%);

				.course-num-text {
					font-size: 14px;
					font-weight: 600;
					text-align: center;
					color: #1f2937;
					line-height: 1.2;
				}

				.course-num-Time {
					text-align: center;
					font-size: 9.5px;
					color: #6b7280;
					line-height: 1.25;
					margin-top: 3px;
				}
			}

		}

		.course-rest-band {
			position: absolute;
			left: 0;
			right: 0;
			display: flex;
			align-items: center;
			justify-content: flex-start;
			z-index: 4;
			background: var(--rest-band-bg);
			border-top: 1px solid var(--rest-band-border);
			border-bottom: 1px solid var(--rest-band-border);
			backdrop-filter: blur(14px) saturate(140%);
			-webkit-backdrop-filter: blur(14px) saturate(140%);
			box-shadow: var(--rest-band-shadow);
			pointer-events: none;
			overflow: hidden;

			&::before {
				content: '';
				position: absolute;
				left: 0;
				right: 0;
				top: 50%;
				height: 1px;
				background: var(--rest-band-line);
			}

			&::after {
				content: '';
				position: absolute;
				inset: 0;
				background: linear-gradient(90deg,
					rgba(248, 251, 255, 0.18) 0,
					rgba(248, 251, 255, 0.18) var(--time-column-width),
					rgba(248, 251, 255, 0.08) calc(var(--time-column-width) + 1px),
					rgba(248, 251, 255, 0.08) 100%);
				pointer-events: none;
			}

			text {
				position: absolute;
				z-index: 1;
				left: calc(var(--time-column-width) + ((100% - var(--time-column-width)) / 2));
				transform: translateX(-50%);
				padding: 0 12px;
				font-size: 12px;
				line-height: 20px;
				color: var(--rest-band-pill-color);
				border-radius: 999px;
				background: var(--rest-band-pill-bg);
				box-shadow: var(--rest-band-pill-shadow);
			}
		}
	}

	.course-swpier {
		flex: 1;
		position: relative;
		z-index: 1;
		will-change: transform;

		.course-list {
			width: 100%;
			height: 100%;
			position: relative;
			overflow: hidden;
			
			.course-grid {
				position: absolute;
				inset: 0;
				pointer-events: none;
				z-index: 0;
				background-image: linear-gradient(to right, rgba(255, 255, 255, 0.64) 1px, transparent 1px);
				background-size: calc(100% / var(--week-count, 7)) 100%;
				background-position: left top;
			}

			.grid-hline {
				position: absolute;
				left: 0;
				right: 0;
				height: 1px;
				background: rgba(255, 255, 255, 0.64);
			}

			.course-item {
				position: absolute;
				padding: 0;
				box-sizing: border-box;
				z-index: 2;

				.course-item__content {
					position: relative;
					font-family: 'Microsoft YaHei', sans-serif;
					width: 100%;
					height: 100%;
					border-radius: 10px;
					padding: var(--course-card-padding-y, 5px) var(--course-card-padding-x, 6px);
					box-sizing: border-box;
					border: var(--course-border-width, 1px) solid var(--course-border-color, rgba(255, 255, 255, 0.78));
					box-shadow: var(--course-glass-shadow, 0 8px 18px rgba(80, 91, 122, 0.12));
					backdrop-filter: blur(10px) saturate(130%);
					-webkit-backdrop-filter: blur(10px) saturate(130%);
					overflow: hidden;
					transition: transform 0.16s ease, box-shadow 0.18s ease, opacity 0.18s ease;

					&:active {
						transform: scale(0.985);
						box-shadow: var(--course-glass-shadow, 0 10px 22px rgba(80, 91, 122, 0.16));
					}

					.course-item__content_name {
						font-size: var(--course-name-size, 14px);
						text-align: left;
						font-weight: 700;
						line-height: 1.25;
						white-space: normal;
					}

					.course-status-badges {
						position: absolute;
						top: 2px;
						right: 2px;
						z-index: 2;
						display: flex;
						flex-direction: column;
						align-items: flex-end;
						gap: 2px;
					}

					.course-status-badge {
						padding: 1px 3px;
						border-radius: 5px;
						color: #fff;
						font-size: 8px;
						font-weight: 700;
						line-height: 1.25;
						white-space: nowrap;
					}

					.course-status-badge.conflict {
						background: #dc2626;
						box-shadow: 0 2px 6px rgba(153, 27, 27, 0.3);
					}

					.course-status-badge.adjusted {
						background: #2563eb;
						box-shadow: 0 2px 6px rgba(30, 64, 175, 0.28);
					}

					.course-item__content_address {
						margin-top: 2px;
						font-size: var(--course-meta-size, 11px);
						text-align: left;
						line-height: 1.3;
						opacity: 0.92;
						word-break: break-all;
						white-space: normal;
					}

					.course-item__content_teacher {
						margin-top: 2px;
						font-size: var(--course-meta-size, 11px);
						text-align: left;
						line-height: 1.3;
						opacity: 0.92;
						overflow: hidden;
						text-overflow: ellipsis;
						white-space: nowrap;
					}
				}
			}

			.empty-slot-hit {
				position: absolute;
				border-radius: 10px;
				z-index: 1;
				display: flex;
				align-items: center;
				justify-content: center;
				transition: background-color 0.16s ease;
			}

			.empty-slot-plus {
				width: 52%;
				height: 74%;
				max-width: 60px;
				max-height: 96px;
				min-width: 34px;
				min-height: 34px;
				border-radius: 12px;
				background: rgba(255, 255, 255, 0.82);
				border: 1px solid rgba(255, 255, 255, 0.95);
				backdrop-filter: blur(10px) saturate(118%);
				-webkit-backdrop-filter: blur(10px) saturate(118%);
				color: #9ca3af;
				display: flex;
				align-items: center;
				justify-content: center;
				font-size: 26px;
				line-height: 1;
				box-shadow: 0 8px 20px rgba(148, 163, 184, 0.24);
				transition: transform 0.16s ease, background-color 0.16s ease;
			}

			.empty-slot-hit:active .empty-slot-plus {
				transform: scale(0.96);
				background: rgba(255, 255, 255, 0.92);
			}
		}
	}
}

.sheet-mask {
	position: fixed;
	inset: 0;
	background: rgba(15, 23, 42, 0.34);
	z-index: 2199;
	opacity: 0;
	transition: opacity 0.24s ease;
}

.sheet-mask.show {
	opacity: 1;
}

.sheet-mask.motionless {
	transition: none;
}

.bottom-sheet {
	position: fixed;
	left: 0;
	right: 0;
	bottom: 0;
	display: flex;
	justify-content: center;
	z-index: 2200;
	transform: translateY(100%);
	transition: transform 0.3s cubic-bezier(0.22, 1, 0.36, 1);
	pointer-events: none;
}

.bottom-sheet.show {
	transform: translateY(0);
	pointer-events: auto;
}

.bottom-sheet.dragging,
.bottom-sheet.motionless {
	transition: none;
}

	.switch-week__popup {
	width: min(100%, 1200px);
	background-color: rgba(255, 255, 255, 0.94);
	backdrop-filter: blur(18px);
	padding: 8px 16px calc(env(safe-area-inset-bottom) + 18px);
	border-radius: 18px 18px 0 0;
	margin-bottom: 0;
	border-top: 1px solid rgba(255, 255, 255, 0.75);
	box-shadow: 0 -16px 34px rgba(51, 65, 85, 0.22);
	max-height: min(74vh, 620px);
	overflow-y: auto;

	&.course-sheet-popup {
		display: flex;
		flex-direction: column;
		overflow: hidden;
	}

	&.course-sheet-popup .course-details {
		min-height: 0;
		overflow-y: auto;
	}

	.sheet-drag-zone {
		padding: 2px 0 8px;
		display: flex;
		justify-content: center;
	}

	.sheet-grip {
		width: 44px;
		height: 5px;
		border-radius: 999px;
		background: rgba(148, 163, 184, 0.45);
		margin: 0 auto;
	}

	.sheet-title-wrap {
		position: relative;
		display: flex;
		align-items: center;
		justify-content: center;
		min-height: 34px;
	}

	.sheet-action-group {
		position: absolute;
		right: 0;
		top: 50%;
		transform: translateY(-50%);
		display: flex;
		align-items: center;
		gap: 8px;
	}

	.switch-week__title {
		text-align: center;
		font-size: 18px;
		font-weight: 600;
		color: #1f2937;
	}

	.sheet-comment-btn,
	.sheet-edit-btn {
		width: 34px;
		height: 34px;
		border-radius: 10px;
		background: rgba(241, 245, 249, 0.88);
		box-shadow: inset 0 0 0 1px rgba(203, 213, 225, 0.72);
		display: flex;
		align-items: center;
		justify-content: center;
	}

	.sheet-edit-icon {
		width: 17px;
		height: 17px;
	}

	.sheet-comment-icon {
		width: 18px;
		height: 18px;
	}

	.course-details {
		margin-top: 14px;
		padding: 0 6px;

		.course-name-row {
			display: flex;
			align-items: flex-start;
			justify-content: space-between;
			gap: 12px;
		}

		.course-name {
			flex: 1;
			min-width: 0;
			font-family: 'Microsoft YaHei', 'PingFang SC', sans-serif;
			text-align: left;
			font-size: 19px;
			font-weight: 700;
			color: #1f2937;
		}

		.course-conflict-switch {
			flex: 0 0 auto;
			padding: 7px 10px;
			border-radius: 10px;
			background: #fff1f2;
			box-shadow: inset 0 0 0 1px #fecaca;
			color: #dc2626;
			font-size: 12px;
			font-weight: 700;
			white-space: nowrap;
		}

		.course-conflict-tip {
			margin-top: 9px;
			padding: 9px 11px;
			border-radius: 10px;
			background: #fff7ed;
			color: #9a3412;
			font-size: 12px;
			line-height: 1.45;
		}

		.course-adjustment-tip {
			margin-top: 9px;
			padding: 10px 11px;
			border-radius: 10px;
			background: #eff6ff;
			box-shadow: inset 0 0 0 1px #bfdbfe;
			color: #1e3a8a;
			font-size: 12px;
			line-height: 1.55;
		}

		.course-adjustment-title {
			margin-bottom: 3px;
			font-weight: 700;
			color: #1d4ed8;
		}

		.course-adjustment-notice {
			margin-top: 7px;
			color: #2563eb;
			font-weight: 600;
			word-break: break-word;
		}

		.course-Introduce {
			margin-top: 10px;
			text-align: left;
			font-size: 14px;

			.course-Introduce-text {
				color: #64748b;
				margin-top: 8px;
				line-height: 1.45;
			}
		}

		.course-priority-panel {
			margin-top: 14px;
			padding: 12px;
			border-radius: 12px;
			background: #f8fafc;
			box-shadow: inset 0 0 0 1px #e2e8f0;
			display: flex;
			align-items: center;
			justify-content: space-between;
			gap: 12px;
		}

		.course-priority-copy {
			flex: 1;
			min-width: 0;
		}

		.course-priority-title {
			color: #1f2937;
			font-size: 14px;
			font-weight: 700;
		}

		.course-priority-description {
			margin-top: 4px;
			color: #64748b;
			font-size: 12px;
			line-height: 1.4;
		}

		.course-priority-btn {
			flex: 0 0 auto;
			padding: 8px 10px;
			border-radius: 10px;
			background: #2563eb;
			color: #fff;
			font-size: 12px;
			font-weight: 700;
			white-space: nowrap;

			&.active {
				background: #dcfce7;
				color: #15803d;
				box-shadow: inset 0 0 0 1px #bbf7d0;
			}
		}

	}

	/*
	 * 课程群入口只属于课程详情弹窗，不改变课表卡片和原有弹窗结构。
	 * 入口作为固定底栏，确保小屏设备打开详情后也能立即看到。
	 */
	.course-group-action-wrap {
		flex: 0 0 auto;
		margin-top: 14px;
		padding: 14px 6px 0;
		border-top: 1px solid #e5e7eb;
		background: rgba(255, 255, 255, 0.96);
	}

	.course-group-action-btn {
		display: flex;
		align-items: center;
		justify-content: center;
		width: 100%;
		height: 44px;
		margin: 0;
		padding: 0 16px;
		border: 0;
		border-radius: 10px;
		background: #2563eb;
		color: #fff;
		font-size: 15px;
		font-weight: 600;
		line-height: 1;
		box-shadow: none;
		transition: opacity 0.18s ease, transform 0.18s ease, background-color 0.18s ease;

		&::after {
			border: 0;
		}

		&:active:not(.is-disabled) {
			transform: scale(0.985);
			opacity: 0.9;
		}

		&.is-joined {
			background: #0f766e;
		}

		&.is-disabled {
			background: #eef2f7;
			color: #64748b;
			font-weight: 500;
		}
	}

	.switch-week__list {
		display: grid;
		grid-template-columns: repeat(auto-fill, minmax(72px, 1fr));
		gap: 10px;
		margin-top: 14px;

		.switch-week__item {
			width: 100%;

			.switch-week__item-box {
				display: flex;
				flex-direction: column;
				width: 100%;
				min-height: 56px;
				background-color: #eef2ff;
				align-items: center;
				justify-content: center;
				border-radius: 12px;
				font-size: 14px;
				color: #475569;
				font-weight: 600;
				transition: transform 0.22s ease, box-shadow 0.22s ease, background-color 0.22s ease;

				&:active {
					transform: scale(0.97);
				}

				.nowWeekTip {
					font-size: 11px;
					margin-top: 1px;
				}
			}

			.switch-week__item-box.active {
				color: #fff;
				background: linear-gradient(135deg, #0ea5e9 0%, #2563eb 100%);
				box-shadow: 0 8px 16px rgba(37, 99, 235, 0.24);
			}
		}
	}
}

:deep(.ripple-host) {
	position: relative;
	transform: translateZ(0);
	transition: transform 0.16s ease, opacity 0.16s ease, box-shadow 0.18s ease;
}

:deep(.ripple-host:active) {
	transform: scale(0.98);
	opacity: 0.94;
}

:deep(.ripple-clip) {
	overflow: hidden;
}

.layout.mode-phone-portrait {
	.navbar {
		.titleBar {
			padding: 0 6px;

			.adjustment-view-switch {
				left: 4px;
				min-width: 58px;
				height: 27px;
				padding: 0 6px;
				gap: 2px;
				font-size: 10px;
			}

			.week-switch {
				padding: 2px 8px;
				max-width: calc(100% - 54px);
			}

			.title {
				font-size: 16px;
			}

			.setting-btn {
				width: 28px;
				height: 28px;
				border-radius: 9px;
				right: 4px;
			}
		}

		.week-list {
			height: 50px;
			padding: 0 1px 2px 1px;

			.week-item {
				.week-name {
					font-size: 11px;
				}

				.week-date {
					font-size: 9px;
					margin-top: 1px;
				}
			}
		}
	}
}

.container.mode-phone-portrait {
	padding-bottom: calc(env(safe-area-inset-bottom) + 64px);

	.course-content {
		padding-top: 2px;
	}

	.course-content .course-nums .course-num {
		.course-num-text {
			font-size: 12px;
		}

		.course-num-Time {
			font-size: 8.5px;
			line-height: 1.2;
			margin-top: 1px;
		}
	}

	.course-content .course-swpier .course-list .course-item {
		padding: 0;
	}

	.course-content .course-swpier .course-list .course-item .course-item__content {
		border-radius: 8px;
		box-shadow: 0 4px 10px rgba(80, 91, 122, 0.12);

		.course-item__content_address,
		.course-item__content_teacher {
			line-height: 1.2;
		}
	}
}

.layout.mode-pad-landscape {
	.navbar .week-list {
		height: 56px;
		padding-bottom: 4px;
	}
}

.layout.anim-off,
.container.anim-off {
	* {
		transition: none !important;
		animation: none !important;
	}
}

.container.mode-pad-landscape {
	.course-content {
		padding-top: 4px;
	}

	.course-content .course-nums .course-num {
		.course-num-text {
			font-size: 13px;
		}

		.course-num-Time {
			font-size: 9px;
		}
	}

	.course-content .course-swpier .course-list .course-item {
		padding: 0;
	}
}

@media screen and (min-width: 900px) {
	.layout .navbar {
		max-width: 1200px;
		left: 50%;
		transform: translateX(-50%);
		border-bottom-left-radius: 12px;
		border-bottom-right-radius: 12px;
	}

	.container {
		max-width: 1200px;
		margin: 0 auto;
	}
}
</style>
