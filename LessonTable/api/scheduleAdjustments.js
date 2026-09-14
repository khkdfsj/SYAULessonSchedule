import { requestRaw } from '@/utils/http.js'
import { buildAuthPayload } from '@/utils/auth.js'

const ADJUSTMENT_API_URL = '/LessonSchedule/scheduleAdjustmentApi.php'

const requestAdjustment = (payload) => requestRaw({
	url: ADJUSTMENT_API_URL,
	data: buildAuthPayload(payload)
})

export const getScheduleAdjustmentPlans = () => requestAdjustment({ action: 'list' })

export const saveScheduleAdjustmentPlan = (payload) => requestAdjustment({
	action: 'save',
	...payload
})

export const publishScheduleAdjustmentPlan = (id) => requestAdjustment({ action: 'publish', id })

export const stopScheduleAdjustmentPlan = (id) => requestAdjustment({ action: 'unpublish', id })

export const archiveScheduleAdjustmentPlan = (id) => requestAdjustment({ action: 'archive', id })

